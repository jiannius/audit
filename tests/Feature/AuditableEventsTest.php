<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Jiannius\Audit\Audit;
use Jiannius\Audit\Tests\Fixtures\Attachment;
use Jiannius\Audit\Tests\Fixtures\AuditedInvoice;
use Jiannius\Audit\Tests\Fixtures\AuditedLine;
use Jiannius\Audit\Tests\Fixtures\Document;
use Jiannius\Audit\Tests\Fixtures\DocumentStatus;

afterEach(fn () => Carbon::setTestNow());

function makeDocument(array $attributes = []): Document
{
    return Document::create($attributes + [
        'title' => 'Quarterly report',
        'status' => DocumentStatus::Draft,
        'placed_at' => Carbon::parse('2026-01-02 03:04:05'),
        'options' => ['colour' => 'red'],
        'total' => 100,
    ]);
}

describe('created', function () {
    it('records the raw attributes that were inserted', function () {
        $document = makeDocument();

        $audit = $this->audits()->sole();

        expect($audit->event)->toBe('created')
            ->and($audit->category)->toBe('record')
            ->and($audit->subject_type)->toBe('document')
            ->and($audit->subject_id)->toBe((string) $document->id)
            ->and($audit->subject_label)->toBe('Quarterly report')
            ->and($audit->old_values)->toBeNull()
            ->and($audit->new_values)->toEqual([
                'title' => 'Quarterly report',
                'status' => 'draft',
                'placed_at' => '2026-01-02 03:04:05',
                'options' => '{"colour":"red"}',
                'total' => 100,
                'id' => $document->id,
            ]);
    });

    it('stores ULID keys as strings', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);

        $audit = $this->audits()->sole();

        expect($audit->subject_type)->toBe('audited-invoice')
            ->and($audit->subject_id)->toBe($invoice->id)
            ->and($audit->new_values['id'])->toBe($invoice->id);
    });
});

describe('updated', function () {
    it('records only the changed keys, as raw values', function () {
        $document = makeDocument();

        $document->update([
            'status' => DocumentStatus::Sent,
            'placed_at' => Carbon::parse('2026-02-03 04:05:06'),
            'options' => ['colour' => 'blue'],
            'total' => 250,
        ]);

        $audit = $this->audits()->last();

        expect($audit->event)->toBe('updated')
            ->and($audit->old_values)->toEqual([
                'status' => 'draft',
                'placed_at' => '2026-01-02 03:04:05',
                'options' => '{"colour":"red"}',
                'total' => 100,
            ])
            ->and($audit->new_values)->toEqual([
                'status' => 'sent',
                'placed_at' => '2026-02-03 04:05:06',
                'options' => '{"colour":"blue"}',
                'total' => 250,
            ]);
    });

    it('records increment() and decrement()', function () {
        $document = makeDocument();

        $document->increment('total', 5);
        $document->decrement('total', 2);

        $audits = $this->audits();

        expect($audits->pluck('event')->all())->toBe(['created', 'updated', 'updated'])
            ->and($audits[1]->old_values)->toBe(['total' => 100])
            ->and($audits[1]->new_values)->toBe(['total' => 105])
            ->and($audits[2]->old_values)->toBe(['total' => 105])
            ->and($audits[2]->new_values)->toBe(['total' => 103]);
    });

    it('records a change to null', function () {
        $document = makeDocument();

        $document->update(['title' => null]);

        $audit = $this->audits()->last();

        expect($audit->old_values)->toBe(['title' => 'Quarterly report'])
            ->and($audit->new_values)->toBe(['title' => null]);
    });

    it('writes no row for a touch', function () {
        $document = makeDocument();
        $before = $document->fresh()->updated_at;

        Carbon::setTestNow(now()->addMinutes(5));
        $document->touch();

        expect($document->fresh()->updated_at->gt($before))->toBeTrue()
            ->and($this->audits())->toHaveCount(1);
    });

    it('writes no row when nothing is dirty', function () {
        $document = makeDocument();

        $document->save();

        expect($this->audits())->toHaveCount(1);
    });

    it('writes no row for a change to an excluded attribute', function () {
        $document = makeDocument();

        $document->update(['internal_ref' => 'REF-9', 'api_token' => 'tok_123', 'webhook_secret' => 'whsec_123']);

        expect($this->audits())->toHaveCount(1);
    });

    it('leaves excluded keys out of a row that has other changes', function () {
        $document = makeDocument();

        $document->update(['total' => 5, 'internal_ref' => 'REF-9', 'api_token' => 'tok_123']);

        $audit = $this->audits()->last();

        expect($audit->old_values)->toBe(['total' => 100])
            ->and($audit->new_values)->toBe(['total' => 5]);
    });

    it('does not record anything when the event is not in auditEvents()', function () {
        $line = Attachment::create(['invoice_id' => 'x', 'description' => 'a.pdf']);

        $line->update(['description' => 'b.pdf']);

        expect($this->audits())->toHaveCount(0);
    });
});

describe('deleted', function () {
    it('snapshots a hard delete', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);

        $invoice->delete();

        $audit = $this->audits()->last();

        expect($audit->event)->toBe('deleted')
            ->and($audit->old_values)->toEqual(['id' => $invoice->id, 'number' => 'INV-1'])
            ->and($audit->new_values)->toBeNull()
            ->and($audit->meta)->toBe(['soft' => false])
            ->and($audit->subject_label)->toBe('INV-1');
    });

    it('snapshots a soft delete', function () {
        $document = makeDocument();

        $document->delete();

        $audit = $this->audits()->last();

        expect($this->audits())->toHaveCount(2)
            ->and($audit->event)->toBe('deleted')
            ->and($audit->meta)->toBe(['soft' => true])
            ->and($audit->old_values)->toMatchArray([
                'id' => $document->id,
                'title' => 'Quarterly report',
                'status' => 'draft',
                'total' => 100,
            ]);
    });

    it('records a force delete once, as force_deleted', function () {
        $document = makeDocument();

        $document->forceDelete();

        $audits = $this->audits();

        expect($audits->pluck('event')->all())->toBe(['created', 'force_deleted'])
            ->and($audits->last()->old_values)->toMatchArray(['id' => $document->id, 'title' => 'Quarterly report'])
            ->and($audits->last()->meta)->toBeNull();
    });

    it('records only the deletion of a model that narrows auditEvents()', function () {
        $line = Attachment::create(['invoice_id' => 'x', 'description' => 'a.pdf']);

        $line->delete();

        $audit = $this->audits()->sole();

        expect($audit->event)->toBe('deleted')
            ->and($audit->subject_type)->toBe('attachment');
    });
});

describe('restored', function () {
    it('writes one restored row and no updated row', function () {
        $document = makeDocument();
        $document->delete();
        $deletedAt = DB::table('documents')->where('id', $document->id)->value('deleted_at');

        $document->restore();

        $audits = $this->audits();

        expect($audits->pluck('event')->all())->toBe(['created', 'deleted', 'restored'])
            ->and($audits->last()->old_values)->toBe(['deleted_at' => $deletedAt])
            ->and($audits->last()->new_values)->toBe(['deleted_at' => null]);
    });

    it('does not suppress later updates', function () {
        $document = makeDocument();
        $document->delete();
        $document->restore();

        $document->update(['total' => 7]);

        expect($this->audits()->pluck('event')->all())->toBe(['created', 'deleted', 'restored', 'updated']);
    });

    it('writes nothing for a restore of a record that is not trashed', function () {
        $document = makeDocument();

        $document->restore();

        expect($this->audits())->toHaveCount(1);
    });
});

describe('labels and parents', function () {
    it('snapshots the first filled label attribute', function () {
        $document = makeDocument(['title' => null]);

        // title is empty, and none of the other label attributes exist on documents.
        expect($this->audits()->sole()->subject_label)->toBeNull();

        $document->update(['title' => 'Named']);

        expect($this->audits()->last()->subject_label)->toBe('Named');
    });

    it('uses an auditLabel() override', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);

        AuditedLine::create(['invoice_id' => $invoice->id, 'description' => 'Widget']);

        expect($this->audits()->last()->subject_label)->toBe('Widget');
    });

    it('rolls a child up to its parent', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        $line = AuditedLine::create(['invoice_id' => $invoice->id, 'description' => 'Widget']);

        $audit = $this->audits()->last();

        expect($audit->parent_type)->toBe('audited-invoice')
            ->and($audit->parent_id)->toBe($invoice->id);

        expect($invoice->audits)->toHaveCount(1)
            ->and($invoice->auditTrail()->count())->toBe(2)
            ->and($line->audits)->toHaveCount(1);
    });

    it('records no parent when the key is missing', function () {
        $line = AuditedLine::create(['invoice_id' => '', 'description' => 'Orphan']);

        $audit = $this->audits()->sole();

        expect($audit->parent_type)->toBeNull()
            ->and($audit->parent_id)->toBeNull()
            ->and($line->auditTrail()->count())->toBe(1);
    });

    it('hands the subject to the scope resolvers', function () {
        $this->useScopes(['region_id' => fn ($audit) => $audit->subject->getAttributes()['total'] ?? null]);

        makeDocument(['total' => 42]);

        expect($this->audits()->sole()->region_id)->toBe(42);
    });
});

describe('audits() and auditTrail()', function () {
    it('returns only the rows about the model', function () {
        $first = makeDocument();
        $second = makeDocument(['title' => 'Other']);
        $first->update(['total' => 1]);

        expect($first->audits()->orderBy('id')->pluck('event')->all())->toBe(['created', 'updated'])
            ->and($second->audits()->count())->toBe(1)
            ->and(Audit::query()->count())->toBe(3);
    });

    it('binds the subject id as a string', function () {
        $document = makeDocument();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $document->audits()->get();
        $document->load('audits');
        Document::query()->with('audits')->get();

        $bindings = collect(DB::getQueryLog())->pluck('bindings')->flatten();
        DB::disableQueryLog();

        expect($bindings->contains((string) $document->id))->toBeTrue()
            ->and($bindings->contains(fn ($binding) => is_int($binding)))->toBeFalse();
    });

    it('eager loads the right rows', function () {
        $first = makeDocument();
        $second = makeDocument(['title' => 'Other']);
        $second->update(['total' => 1]);

        $loaded = Document::query()->with('audits')->orderBy('id')->get();

        expect($loaded[0]->audits)->toHaveCount(1)
            ->and($loaded[1]->audits)->toHaveCount(2);
    });
});
