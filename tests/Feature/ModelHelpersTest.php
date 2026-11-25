<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jiannius\Audit\Category;
use Jiannius\Audit\Exceptions\AuditWriteFailed;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Tests\Fixtures\Attachment;
use Jiannius\Audit\Tests\Fixtures\AuditedInvoice;
use Jiannius\Audit\Tests\Fixtures\AuditedLine;
use Jiannius\Audit\Tests\Fixtures\Document;

describe('updateQuietlyWithAudit', function () {
    it('updates without model events and records one row with the event and meta', function () {
        $document = makeDocument(['title' => 'Memo', 'status' => 'draft']);
        DB::table('audits')->delete();

        $result = $document->updateQuietlyWithAudit(['status' => 'sent', 'total' => 250], event: 'synced', meta: ['system' => 'ledger']);

        $audit = $this->audits()->first();

        expect($result)->toBeTrue()
            ->and($this->audits())->toHaveCount(1)
            ->and($audit->category)->toBe(Category::RECORD)
            ->and($audit->event)->toBe('synced')
            ->and($audit->subject_id)->toBe((string) $document->getKey())
            ->and($audit->subject_label)->toBe('Memo')
            ->and($audit->old_values)->toEqual(['status' => 'draft', 'total' => 100])
            ->and($audit->new_values)->toEqual(['status' => 'sent', 'total' => 250])
            ->and($audit->meta)->toEqual(['system' => 'ledger'])
            ->and(DB::table('documents')->value('status'))->toBe('sent');
    });

    it('records raw values, as the observer does', function () {
        $document = makeDocument();
        DB::table('audits')->delete();

        $document->updateQuietlyWithAudit(['options' => ['colour' => 'blue'], 'settings' => ['a' => 1]]);

        expect($this->audits()->first()->new_values['options'])->toBe('{"colour":"blue"}');
    });

    it('uses `updated` by default', function () {
        $document = makeDocument();
        DB::table('audits')->delete();

        $document->updateQuietlyWithAudit(['status' => 'sent']);

        expect($this->audits()->first()->event)->toBe('updated');
    });

    it('does not fire the model\'s events', function () {
        $document = makeDocument();
        DB::table('audits')->delete();
        $fired = [];
        Document::updating(function () use (&$fired) {
            $fired[] = 'updating';
        });
        Document::saved(function () use (&$fired) {
            $fired[] = 'saved';
        });

        $document->updateQuietlyWithAudit(['status' => 'sent']);

        expect($fired)->toBe([])
            ->and($this->audits())->toHaveCount(1);
    });

    it('applies the exclude and redact rules', function () {
        $document = makeDocument();
        DB::table('audits')->delete();

        $document->updateQuietlyWithAudit(['pin' => 'quiet-pin-77', 'internal_ref' => 'ref-9', 'api_token' => 'tok-9', 'status' => 'sent']);

        $audit = $this->audits()->first();

        expect($audit->new_values)->toEqual(['pin' => '[redacted]', 'status' => 'sent'])
            ->and(everythingAudited())->not->toContain('quiet-pin-77')->not->toContain('tok-9')->not->toContain('ref-9');
    });

    it('writes nothing when only excluded attributes change', function () {
        $document = makeDocument();
        DB::table('audits')->delete();

        expect($document->updateQuietlyWithAudit(['internal_ref' => 'ref-9']))->toBeTrue()
            ->and($this->audits())->toHaveCount(0)
            ->and(DB::table('documents')->value('internal_ref'))->toBe('ref-9');
    });

    it('does not record an earlier save again when nothing is dirty', function () {
        $document = makeDocument();
        $document->update(['status' => 'sent']);
        DB::table('audits')->delete();

        expect($document->updateQuietlyWithAudit(['status' => 'sent']))->toBeTrue()
            ->and($this->audits())->toHaveCount(0);
    });

    it('returns false for a model that does not exist', function () {
        expect((new Document(['title' => 'x']))->updateQuietlyWithAudit(['status' => 'sent']))->toBeFalse()
            ->and($this->audits())->toHaveCount(0);
    });

    it('follows auditEvents: a standard event the model does not record writes no row, a custom one does', function () {
        $attachment = Attachment::create(['invoice_id' => 'x', 'description' => 'File']);
        DB::table('audits')->delete();

        $attachment->updateQuietlyWithAudit(['description' => 'File 2']);
        expect($this->audits())->toHaveCount(0)
            ->and(DB::table('invoice_lines')->value('description'))->toBe('File 2');

        $attachment->updateQuietlyWithAudit(['description' => 'File 3'], event: 'renamed');
        expect($this->audits()->pluck('event')->all())->toBe(['renamed']);
    });

    it('uses the parent of the model', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        $line = AuditedLine::create(['invoice_id' => $invoice->id, 'description' => 'Widget']);
        DB::table('audits')->delete();

        $line->updateQuietlyWithAudit(['quantity' => 4]);

        expect($this->audits()->first()->parent_id)->toBe($invoice->id);
    });

    it('rolls the update back when the audit insert fails, and puts the model back', function () {
        $document = makeDocument(['status' => 'draft']);
        Schema::drop('audits');

        expect(fn () => $document->updateQuietlyWithAudit(['status' => 'sent', 'total' => 5]))->toThrow(AuditWriteFailed::class);

        expect(DB::table('documents')->value('status'))->toBe('draft')
            ->and($document->exists)->toBeTrue()
            ->and($document->isDirty('status'))->toBeTrue()
            ->and($document->getRawOriginal('status'))->toBe('draft')
            ->and($document->getRawOriginal('total'))->toBe(100);

        $this->createAuditsTable();
        $document->save();

        expect(DB::table('documents')->value('status'))->toBe('sent')
            ->and($this->audits()->pluck('event')->all())->toBe(['updated']);
    });

    it('updates quietly, with no row, while auditing is off', function () {
        $document = makeDocument();
        DB::table('audits')->delete();
        config(['audit.enabled' => false]);

        $document->updateQuietlyWithAudit(['status' => 'sent']);

        expect(DB::table('documents')->value('status'))->toBe('sent')
            ->and(DB::table('audits')->count())->toBe(0);
    });
});

describe('$model->audit()', function () {
    it('records a custom event in the action category, with the subject and meta', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        DB::table('audits')->delete();

        $invoice->audit('sent', meta: ['to' => ['a@example.test']]);

        $audit = $this->audits()->first();

        expect($this->audits())->toHaveCount(1)
            ->and($audit->category)->toBe(Category::ACTION)
            ->and($audit->event)->toBe('sent')
            ->and($audit->subject_type)->toBe('audited-invoice')
            ->and($audit->subject_id)->toBe($invoice->id)
            ->and($audit->subject_label)->toBe('INV-1')
            ->and($audit->meta)->toEqual(['to' => ['a@example.test']])
            ->and($audit->old_values)->toBeNull()
            ->and($audit->new_values)->toBeNull();
    });

    it('takes old and new values, and applies the exclude and redact rules to them', function () {
        $document = makeDocument();
        DB::table('audits')->delete();

        $document->audit('rotated', old: ['pin' => 'old-pin-0001', 'api_token' => 'tok-old', 'state' => 'a'], new: ['pin' => 'new-pin-0002', 'api_token' => 'tok-new', 'state' => 'b']);

        $audit = $this->audits()->first();

        expect($audit->old_values)->toEqual(['pin' => '[redacted]', 'state' => 'a'])
            ->and($audit->new_values)->toEqual(['pin' => '[redacted]', 'state' => 'b'])
            ->and(everythingAudited())->not->toContain('old-pin-0001')->not->toContain('new-pin-0002')->not->toContain('tok-');
    });

    it('rolls up to the parent of the model', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        $line = AuditedLine::create(['invoice_id' => $invoice->id, 'description' => 'Widget']);
        DB::table('audits')->delete();

        $line->audit('priced');

        expect($this->audits()->first()->parent_id)->toBe($invoice->id)
            ->and($invoice->auditTrail()->count())->toBe(1);
    });

    it('writes in the caller\'s transaction', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        DB::table('audits')->delete();

        DB::transaction(function () use ($invoice) {
            $invoice->audit('sent');
            DB::rollBack();
        });

        expect(DB::table('audits')->count())->toBe(0);
    });

    it('throws when the insert fails', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        Schema::drop('audits');

        expect(fn () => $invoice->audit('sent'))->toThrow(AuditWriteFailed::class);
    });

    it('records nothing while auditing is off', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        DB::table('audits')->delete();
        config(['audit.enabled' => false]);

        $invoice->audit('sent');

        expect(DB::table('audits')->count())->toBe(0);
    });

    it('matches Auditor::record for a model with no old or new values', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        DB::table('audits')->delete();

        $invoice->audit('sent', ['a' => 1]);
        Auditor::record('sent', $invoice, ['a' => 1]);

        [$viaModel, $viaFacade] = [$this->audits()[0]->toArray(), $this->audits()[1]->toArray()];

        expect(array_diff_key($viaModel, array_flip(['id', 'created_at'])))->toEqual(array_diff_key($viaFacade, array_flip(['id', 'created_at'])));
    });
});
