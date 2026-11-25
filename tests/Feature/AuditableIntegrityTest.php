<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jiannius\Audit\Exceptions\AuditWriteFailed;
use Jiannius\Audit\Tests\Fixtures\Attachment;
use Jiannius\Audit\Tests\Fixtures\AuditedInvoice;
use Jiannius\Audit\Tests\Fixtures\Document;

function breakAudits(): void
{
    Schema::dropIfExists(config('audit.table'));
}

describe('after a failed audit insert', function () {
    it('a created model does not look persisted, and a retry writes a created row', function () {
        $document = new Document(['title' => 'Memo']);

        breakAudits();
        expect(fn () => $document->save())->toThrow(AuditWriteFailed::class);

        expect($document->exists)->toBeFalse()
            ->and($document->wasRecentlyCreated)->toBeFalse()
            ->and($document->getKey())->toBeNull()
            ->and($document->isDirty('title'))->toBeTrue();

        $this->createAuditsTable();
        $document->save();

        expect(Document::query()->count())->toBe(1)
            ->and($this->audits()->pluck('event')->all())->toBe(['created'])
            ->and($document->exists)->toBeTrue();
    });

    it('an updated model stays dirty, and a retry writes one updated row', function () {
        $document = makeDocument();
        $document->title = 'Changed';

        breakAudits();
        expect(fn () => $document->save())->toThrow(AuditWriteFailed::class);

        expect($document->isDirty('title'))->toBeTrue()
            ->and($document->getOriginal('title'))->toBe('Quarterly report');

        $this->createAuditsTable();
        $document->save();

        expect(DB::table('documents')->value('title'))->toBe('Changed')
            ->and($this->audits()->pluck('event')->all())->toBe(['updated']);
    });

    it('a deleted model still exists, and is not trashed', function () {
        $document = makeDocument();
        breakAudits();

        expect(fn () => $document->delete())->toThrow(AuditWriteFailed::class);

        expect($document->exists)->toBeTrue()
            ->and($document->trashed())->toBeFalse();

        $this->createAuditsTable();
        $document->delete();

        expect($this->audits()->pluck('event')->all())->toBe(['deleted']);
    });

    it('a hard-deleted model still exists', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        breakAudits();

        expect(fn () => $invoice->delete())->toThrow(AuditWriteFailed::class);

        expect($invoice->exists)->toBeTrue()
            ->and(DB::table('invoices')->count())->toBe(1);
    });

    it('a failed force delete does not leave the model force deleting', function () {
        $document = makeDocument();
        breakAudits();

        expect(fn () => $document->forceDelete())->toThrow(AuditWriteFailed::class);

        expect($document->isForceDeleting())->toBeFalse()
            ->and($document->exists)->toBeTrue();

        $this->createAuditsTable();
        $document->delete();

        expect(Document::query()->count())->toBe(0)
            ->and(Document::withTrashed()->count())->toBe(1)
            ->and($this->audits()->pluck('event')->all())->toBe(['deleted']);
    });

    it('a failed restore leaves the model trashed', function () {
        $document = makeDocument();
        $document->delete();
        breakAudits();

        expect(fn () => $document->restore())->toThrow(AuditWriteFailed::class);

        expect($document->trashed())->toBeTrue();
    });
});

describe('increment() and decrement()', function () {
    it('write their row in the same transaction', function () {
        $document = makeDocument();

        $document->increment('total', 5);
        $document->decrement('total', 3);

        expect(DB::table('documents')->value('total'))->toBe(102)
            ->and($this->audits()->pluck('event')->all())->toBe(['created', 'updated', 'updated']);
    });

    it('roll back when the audit insert fails, and leave the model as it was', function () {
        $document = makeDocument();
        breakAudits();

        expect(fn () => $document->increment('total', 5))->toThrow(AuditWriteFailed::class);

        expect(DB::table('documents')->value('total'))->toBe(100)
            ->and($document->total)->toBe(100)
            ->and($document->isDirty())->toBeFalse();

        expect(fn () => $document->decrement('total', 5))->toThrow(AuditWriteFailed::class);
        expect(DB::table('documents')->value('total'))->toBe(100);
    });

    it('roll back incrementEach() too', function () {
        $document = makeDocument(['total' => 10]);
        breakAudits();

        expect(fn () => $document->incrementEach(['total' => 5]))->toThrow(AuditWriteFailed::class);

        expect(DB::table('documents')->value('total'))->toBe(10);
    });

    it('stand with the switch off', function () {
        $document = makeDocument();
        config(['audit.transactional_saves' => false]);
        breakAudits();

        expect(fn () => $document->increment('total', 5))->toThrow(AuditWriteFailed::class);

        expect(DB::table('documents')->value('total'))->toBe(105);
    });
});

describe('the transactional wrapper', function () {
    it('is skipped for an operation whose events the model does not record', function () {
        $levels = [];
        Attachment::saving(function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });
        Attachment::deleting(function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        $line = Attachment::create(['invoice_id' => 'x', 'description' => 'a.pdf']);
        $line->update(['description' => 'b.pdf']);
        $line->delete();

        // save (create), save (update): no wrapper; delete: wrapped.
        expect($levels)->toBe([0, 0, 1]);
    });

    it('is still used for the events the model does record', function () {
        $levels = [];
        Document::saving(function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        $document = makeDocument();
        $document->update(['total' => 1]);

        expect($levels)->toBe([1, 1]);
    });
});
