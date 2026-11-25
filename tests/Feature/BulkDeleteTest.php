<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jiannius\Audit\Exceptions\AuditWriteFailed;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Tests\Fixtures\Attachment;
use Jiannius\Audit\Tests\Fixtures\AuditedInvoice;
use Jiannius\Audit\Tests\Fixtures\AuditedLine;
use Jiannius\Audit\Tests\Fixtures\Document;
use Jiannius\Audit\Tests\Fixtures\Invoice;
use Jiannius\Audit\Tests\Fixtures\LockedDocument;
use Jiannius\Audit\Tests\Fixtures\Order;

describe('Auditor::delete', function () {
    it('soft deletes a soft-deleting model, as Laravel\'s builder does, and snapshots every row', function () {
        [$a, $b, $c] = rawDocuments([['title' => 'A', 'total' => 1], ['title' => 'B', 'total' => 2], ['title' => 'C', 'total' => 3]]);

        $count = Auditor::delete(Document::query()->where('total', '>', 1));

        expect($count)->toBe(2)
            ->and(Document::query()->pluck('title')->all())->toBe(['A'])
            ->and(Document::withTrashed()->count())->toBe(3);

        $audits = $this->audits();

        expect($audits)->toHaveCount(2)
            ->and($audits->pluck('event')->unique()->all())->toBe(['deleted'])
            ->and($audits->pluck('subject_id')->all())->toBe([(string) $b, (string) $c])
            ->and($audits->pluck('meta')->unique()->all())->toEqual([['soft' => true]])
            ->and($audits[0]->subject_label)->toBe('B')
            ->and($audits[0]->old_values)->toMatchArray(['id' => $b, 'title' => 'B', 'total' => 2])
            ->and($audits[0]->old_values['deleted_at'])->not->toBeNull()
            ->and($audits[0]->new_values)->toBeNull();
    });

    it('records a soft delete in the shape the model\'s own delete() records', function () {
        $viaModel = makeDocument(['title' => 'Same']);
        $viaHelper = makeDocument(['title' => 'Same']);
        DB::table('audits')->delete();

        // Loaded from the database, so both carry every column.
        Document::find($viaModel->getKey())->delete();
        Auditor::delete(Document::query()->whereKey($viaHelper->getKey()));

        [$model, $helper] = [$this->audits()[0], $this->audits()[1]];

        expect($helper->event)->toBe($model->event)
            ->and($helper->meta)->toEqual($model->meta)
            ->and($helper->subject_label)->toBe($model->subject_label)
            ->and(array_keys($helper->old_values))->toEqualCanonicalizing(array_keys($model->old_values))
            ->and($helper->old_values['deleted_at'])->not->toBeNull()
            ->and($model->old_values['deleted_at'])->not->toBeNull();
    });

    it('records a hard delete as the row was', function () {
        rawDocuments([['title' => 'A']]);

        Auditor::delete(Document::query(), event: 'force_deleted');

        expect($this->audits()->first()->old_values['deleted_at'])->toBeNull();
    });

    it('force deletes only past the soft-delete scope: the model\'s own global scopes still apply', function () {
        rawDocuments([['title' => 'Open', 'status' => 'draft'], ['title' => 'Locked', 'status' => 'locked'], ['title' => 'Trashed', 'status' => 'draft', 'deleted_at' => '2026-01-01 00:00:00']]);

        $count = Auditor::delete(LockedDocument::query(), event: 'force_deleted');

        expect($count)->toBe(2)
            ->and(DB::table('documents')->pluck('title')->all())->toBe(['Locked'])
            ->and($this->audits()->pluck('subject_label')->all())->toBe(['Open', 'Trashed']);
    });

    it('soft deletes already-trashed rows again with withTrashed(), and records them', function () {
        [$live, $trashed] = rawDocuments([['title' => 'Live'], ['title' => 'Trashed', 'deleted_at' => '2026-01-01 00:00:00']]);

        Auditor::delete(Document::withTrashed());

        expect($this->audits()->pluck('subject_id')->all())->toBe([(string) $live, (string) $trashed])
            ->and($this->audits()->pluck('event')->unique()->all())->toBe(['deleted']);
    });

    it('works on a soft-deleting model that does not use Auditable', function () {
        DB::table('orders')->insert([['title' => 'O1'], ['title' => 'O2']]);

        Auditor::delete(Order::query());

        expect(Order::query()->count())->toBe(0)
            ->and(Order::withTrashed()->count())->toBe(2)
            ->and($this->audits()->pluck('subject_type')->unique()->all())->toBe(['order'])
            ->and($this->audits()->pluck('meta')->unique()->all())->toEqual([['soft' => true]]);
    });

    it('hard deletes a model that does not soft delete, with meta.soft false', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        AuditedInvoice::create(['number' => 'INV-2']);
        DB::table('audits')->delete();

        Auditor::delete(AuditedInvoice::query()->whereKey($invoice->id));

        $audit = $this->audits()->first();

        expect(DB::table('invoices')->count())->toBe(1)
            ->and($this->audits())->toHaveCount(1)
            ->and($audit->meta)->toEqual(['soft' => false])
            ->and($audit->old_values)->toMatchArray(['id' => $invoice->id, 'number' => 'INV-1']);
    });

    it('deletes a relation, with the parent of each row', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        $other = AuditedInvoice::create(['number' => 'INV-2']);
        DB::table('invoice_lines')->insert([
            ['invoice_id' => $invoice->id, 'description' => 'Widget'],
            ['invoice_id' => $invoice->id, 'description' => 'Gadget'],
            ['invoice_id' => $other->id, 'description' => 'Other'],
        ]);
        DB::table('audits')->delete();

        Auditor::delete($invoice->lines(), meta: ['cascade' => 'invoice_deleted']);

        $audits = $this->audits();

        expect(DB::table('invoice_lines')->pluck('description')->all())->toBe(['Other'])
            ->and($audits->pluck('subject_label')->all())->toBe(['Widget', 'Gadget'])
            ->and($audits->pluck('parent_id')->unique()->all())->toBe([$invoice->id])
            ->and($audits[0]->meta)->toEqual(['cascade' => 'invoice_deleted', 'soft' => false]);
    });

    it('deletes on a base query builder, with and without a subject type', function () {
        $invoice = Invoice::create(['number' => 'INV-1']);
        DB::table('orders')->insert(['title' => 'O1']);

        Auditor::delete(DB::table('invoices'), subjectType: 'invoice');
        // A base builder does not know about soft deletes: it is a hard delete.
        Auditor::delete(DB::table('orders'));

        $audits = $this->audits();

        expect(DB::table('invoices')->count())->toBe(0)
            ->and(DB::table('orders')->count())->toBe(0)
            ->and($audits->pluck('subject_type')->all())->toBe(['invoice', 'order'])
            ->and($audits[0]->subject_id)->toBe($invoice->id)
            ->and($audits->pluck('meta')->unique()->all())->toEqual([['soft' => false]]);
    });

    it('force deletes a soft-deleting model\'s rows, trashed ones too, with one force_deleted row each', function () {
        [$live, $trashed] = rawDocuments([['title' => 'Live'], ['title' => 'Trashed', 'deleted_at' => '2026-01-01 00:00:00']]);

        $count = Auditor::delete(Document::query(), event: 'force_deleted');

        $audits = $this->audits();

        expect($count)->toBe(2)
            ->and(Document::withTrashed()->count())->toBe(0)
            ->and($audits->pluck('event')->unique()->all())->toBe(['force_deleted'])
            ->and($audits->pluck('subject_id')->all())->toBe([(string) $live, (string) $trashed])
            ->and($audits[0]->meta)->toBeNull();
    });

    it('stores a redacted snapshot and drops excluded attributes', function () {
        rawDocuments([['title' => 'A', 'pin' => 'pin-4321', 'internal_ref' => 'ref-1', 'api_token' => 'tok-1']]);

        Auditor::delete(Document::query());

        $old = $this->audits()->first()->old_values;

        expect($old['pin'])->toBe('[redacted]')
            ->and($old)->not->toHaveKeys(['internal_ref', 'api_token', 'created_at', 'updated_at'])
            ->and(everythingAudited())->not->toContain('pin-4321')->not->toContain('tok-1')->not->toContain('ref-1');
    });

    it('records a custom event name', function () {
        rawDocuments([['title' => 'A']]);

        Auditor::delete(Document::query(), event: 'archived');

        expect($this->audits()->first()->event)->toBe('archived');
    });

    it('records a delete for a model whose auditEvents lists deleted', function () {
        DB::table('invoice_lines')->insert(['invoice_id' => 'x', 'description' => 'File']);
        Auditor::delete(Attachment::query());

        expect($this->audits()->pluck('event')->all())->toBe(['deleted']);
    });

    it('returns 0 and writes nothing when no row matches', function () {
        expect(Auditor::delete(Document::query()->where('title', 'nobody')))->toBe(0)
            ->and($this->audits())->toHaveCount(0);
    });

    it('writes nothing and still deletes while auditing is off', function () {
        rawDocuments([['title' => 'A']]);
        config(['audit.enabled' => false]);

        Auditor::delete(Document::query());

        expect(Document::query()->count())->toBe(0)
            ->and(DB::table('audits')->count())->toBe(0);
    });

    it('rolls a soft delete back when the audit insert fails', function () {
        rawDocuments([['title' => 'A'], ['title' => 'B']]);
        Schema::drop('audits');

        expect(fn () => Auditor::delete(Document::query()))->toThrow(AuditWriteFailed::class);

        expect(Document::query()->count())->toBe(2);
    });

    it('rolls a force delete back when the audit insert fails', function () {
        rawDocuments([['title' => 'A']]);
        Schema::drop('audits');

        expect(fn () => Auditor::delete(Document::query(), event: 'force_deleted'))->toThrow(AuditWriteFailed::class);

        expect(DB::table('documents')->count())->toBe(1);
    });

    it('rolls a hard delete on a relation and on a base builder back when the audit insert fails', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        AuditedLine::create(['invoice_id' => $invoice->id, 'description' => 'Widget']);
        Schema::drop('audits');

        expect(fn () => Auditor::delete($invoice->lines()))->toThrow(AuditWriteFailed::class)
            ->and(fn () => Auditor::delete(DB::table('invoices'), subjectType: 'invoice'))->toThrow(AuditWriteFailed::class);

        expect(DB::table('invoice_lines')->count())->toBe(1)
            ->and(DB::table('invoices')->count())->toBe(1);
    });
});
