<?php

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jiannius\Audit\Tests\Fixtures\AuditedInvoice;
use Jiannius\Audit\Tests\Fixtures\AuditedLine;
use Jiannius\Audit\Tests\Fixtures\Document;
use Jiannius\Audit\Tests\Fixtures\LoggedDocument;
use Jiannius\Audit\Tests\Fixtures\Unmapped;
use Jiannius\Audit\Tests\Fixtures\UnwrappedDocument;

function dropAuditsTable(): void
{
    Schema::dropIfExists(config('audit.table'));
}

describe('strict mode', function () {
    it('audits a model loaded with a partial select', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        $line = AuditedLine::create(['invoice_id' => $invoice->id, 'description' => 'Widget']);

        $partial = AuditedLine::query()->select('id')->findOrFail($line->id);
        $partial->update(['description' => 'Gadget']);

        $audit = $this->audits()->last();

        expect($audit->event)->toBe('updated')
            ->and($audit->subject_label)->toBe('Gadget')
            ->and($audit->parent_id)->toBeNull()
            ->and($audit->old_values)->toBe(['description' => null])
            ->and($audit->new_values)->toBe(['description' => 'Gadget']);

        $partial = AuditedLine::query()->select('id')->findOrFail($line->id);
        $partial->delete();

        expect($this->audits()->last()->event)->toBe('deleted')
            ->and($this->audits()->last()->old_values)->toEqual(['id' => $line->id]);
    });

    it('audits a soft-deleting model loaded with a partial select', function () {
        $document = Document::create(['title' => 'Memo']);

        $partial = Document::query()->select('id')->findOrFail($document->id);
        $partial->delete();
        $partial->restore();

        expect($this->audits()->pluck('event')->all())->toBe(['created', 'deleted', 'restored']);
    });
});

describe('morph map', function () {
    it('throws for an unmapped model, and nothing is saved', function () {
        expect(fn () => Unmapped::create(['name' => 'Rogue']))->toThrow(ClassMorphViolationException::class);

        expect(DB::table('teams')->count())->toBe(0)
            ->and($this->audits())->toHaveCount(0);
    });

    it('is enforced by the test setup', function () {
        expect(Relation::requiresMorphMap())->toBeTrue();
    });
});

describe('transactional saves', function () {
    it('rolls a create back when the audit insert fails', function () {
        dropAuditsTable();

        expect(fn () => Document::create(['title' => 'Memo']))->toThrow(QueryException::class);

        expect(DB::table('documents')->count())->toBe(0);
    });

    it('rolls an update back when the audit insert fails', function () {
        $document = Document::create(['title' => 'Memo']);
        dropAuditsTable();

        expect(fn () => $document->update(['title' => 'Changed']))->toThrow(QueryException::class);

        expect(DB::table('documents')->value('title'))->toBe('Memo');
    });

    it('rolls a delete back when the audit insert fails', function () {
        $document = Document::create(['title' => 'Memo']);
        dropAuditsTable();

        expect(fn () => $document->delete())->toThrow(QueryException::class);

        expect(DB::table('documents')->value('deleted_at'))->toBeNull();

        expect(fn () => $document->forceDelete())->toThrow(QueryException::class);

        expect(DB::table('documents')->count())->toBe(1);
    });

    it('rolls a restore back when the audit insert fails', function () {
        $document = Document::create(['title' => 'Memo']);
        $document->delete();
        dropAuditsTable();

        expect(fn () => $document->restore())->toThrow(QueryException::class);

        expect(DB::table('documents')->value('deleted_at'))->not->toBeNull();
    });

    it('rolls the save back when a saved hook throws', function () {
        Document::saved(fn () => throw new RuntimeException('hook failed'));

        expect(fn () => Document::create(['title' => 'Memo']))->toThrow(RuntimeException::class);

        expect(DB::table('documents')->count())->toBe(0)
            ->and($this->audits())->toHaveCount(0);
    });

    it('leaves the change standing, and raises the exception, when the switch is off', function () {
        config(['audit.transactional_saves' => false]);
        dropAuditsTable();

        expect(fn () => Document::create(['title' => 'Memo']))->toThrow(QueryException::class);

        expect(DB::table('documents')->count())->toBe(1);
    });

    it('still rolls back with the switch off when the caller has a transaction', function () {
        config(['audit.transactional_saves' => false]);
        dropAuditsTable();

        expect(fn () => DB::transaction(fn () => Document::create(['title' => 'Memo'])))->toThrow(QueryException::class);

        expect(DB::table('documents')->count())->toBe(0);
    });

    it('leaves no row behind when the caller rolls back', function () {
        try {
            DB::transaction(function () {
                $document = Document::create(['title' => 'Memo']);
                $document->update(['title' => 'Changed']);

                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }

        expect(DB::table('documents')->count())->toBe(0)
            ->and($this->audits())->toHaveCount(0);
    });

    it('commits the change and its rows together inside a caller transaction', function () {
        DB::transaction(function () {
            $document = Document::create(['title' => 'Memo']);
            $document->update(['title' => 'Changed']);
        });

        expect($this->audits()->pluck('event')->all())->toBe(['created', 'updated']);
    });

    it('opens its own transaction, and none when the switch is off', function () {
        $levels = [];
        Document::saving(function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        Document::create(['title' => 'On']);
        config(['audit.transactional_saves' => false]);
        Document::create(['title' => 'Off']);

        expect($levels)->toBe([1, 0]);
    });

    it('does nothing special when auditing is disabled', function () {
        config(['audit.enabled' => false]);

        $levels = [];
        Document::saving(function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        $document = Document::create(['title' => 'Memo']);
        $document->update(['title' => 'Changed']);
        $document->delete();

        expect($levels)->toBe([0, 0])
            ->and($this->audits())->toHaveCount(0);
    });

    it('writes the row on the subject\'s connection', function () {
        $this->addSecondConnection();
        $this->createDocumentsTable('other');

        $document = (new Document)->setConnection('other');
        $document->fill(['title' => 'Elsewhere'])->save();

        expect(DB::connection('other')->table('audits')->count())->toBe(1)
            ->and($this->audits())->toHaveCount(0);

        Schema::connection('other')->drop('audits');

        expect(fn () => $document->update(['title' => 'Changed']))->toThrow(QueryException::class);

        expect(DB::connection('other')->table('documents')->value('title'))->toBe('Elsewhere');
    });
});

describe('models that override save()', function () {
    it('stay transactional through the alias', function () {
        LoggedDocument::$saves = 0;
        dropAuditsTable();

        expect(fn () => LoggedDocument::create(['title' => 'Memo']))->toThrow(QueryException::class);

        expect(LoggedDocument::$saves)->toBe(1)
            ->and(DB::table('documents')->count())->toBe(0);
    });

    it('record rows through the alias', function () {
        $document = LoggedDocument::create(['title' => 'Memo']);
        $document->update(['title' => 'Changed']);

        expect($this->audits()->pluck('event')->all())->toBe(['created', 'updated'])
            ->and($this->audits()->first()->subject_type)->toBe('logged-document');
    });

    it('lose atomicity without the alias', function () {
        dropAuditsTable();

        expect(fn () => UnwrappedDocument::create(['title' => 'Memo']))->toThrow(QueryException::class);

        expect(DB::table('documents')->count())->toBe(1);
    });
});
