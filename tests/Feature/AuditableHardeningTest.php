<?php

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;
use Jiannius\Audit\AuditObserver;
use Jiannius\Audit\Tests\Fixtures\Document;
use Jiannius\Audit\Tests\Fixtures\HiddenPinDocument;
use Jiannius\Audit\Tests\Fixtures\StampedRecord;

describe('hidden attributes', function () {
    it('stay redacted after makeVisible()', function () {
        $document = HiddenPinDocument::create(['title' => 'Safe', 'pin' => 'pin-one']);

        $document->makeVisible('pin')->update(['pin' => 'pin-two']);

        expect($this->audits()->last()->new_values)->toBe(['pin' => '[redacted]'])
            ->and(everythingAudited())->not->toContain('pin-one')
            ->and(everythingAudited())->not->toContain('pin-two');
    });

    it('stay redacted after setHidden([])', function () {
        $document = HiddenPinDocument::create(['title' => 'Safe', 'pin' => 'pin-one']);

        $document->setHidden([])->update(['pin' => 'pin-three']);
        $document->setVisible(['title', 'pin'])->delete();

        expect($this->audits()->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
            ->and($this->audits()->last()->old_values)->toMatchArray(['pin' => '[redacted]'])
            ->and(everythingAudited())->not->toContain('pin-one')
            ->and(everythingAudited())->not->toContain('pin-three');
    });
});

describe('the record label', function () {
    it('skips a label attribute that is redacted', function () {
        config(['audit.attributes.label' => ['pin', 'notes', 'password', 'title']]);

        Document::create(['title' => 'Plain title', 'pin' => 'pin-label', 'notes' => 'note-label']);

        expect($this->audits()->sole()->subject_label)->toBe('Plain title')
            ->and(everythingAudited())->not->toContain('pin-label')
            ->and(everythingAudited())->not->toContain('note-label');
    });

    it('skips a label attribute that is excluded', function () {
        config(['audit.attributes.label' => ['internal_ref', 'api_token', 'title']]);

        Document::create(['title' => 'Plain title', 'internal_ref' => 'ref-label', 'api_token' => 'token-label']);

        expect($this->audits()->sole()->subject_label)->toBe('Plain title')
            ->and(everythingAudited())->not->toContain('ref-label')
            ->and(everythingAudited())->not->toContain('token-label');
    });

    it('skips a label attribute that is hidden at class level, even after makeVisible()', function () {
        config(['audit.attributes.label' => ['pin', 'title']]);

        $document = HiddenPinDocument::make(['title' => 'Plain title', 'pin' => 'pin-label'])->makeVisible('pin');
        $document->save();

        expect($this->audits()->sole()->subject_label)->toBe('Plain title');
    });

    it('falls back to no label when every label attribute is secret', function () {
        config(['audit.attributes.label' => ['pin']]);

        Document::create(['pin' => 'pin-label']);

        expect($this->audits()->sole()->subject_label)->toBeNull();
    });
});

describe('default redaction', function () {
    it('redacts API keys and bare tokens, but not every *_key', function () {
        $values = app(AuditObserver::class)->values(new Document, [
            'api_key' => 'a',
            'stripe_api_key' => 'b',
            'secret_key' => 'c',
            'private_key' => 'd',
            'token' => 'e',
            'foreign_key' => 'f',
            'sort_key' => 'g',
        ]);

        expect($values)->toBe([
            'api_key' => '[redacted]',
            'stripe_api_key' => '[redacted]',
            'secret_key' => '[redacted]',
            'private_key' => '[redacted]',
            'token' => '[redacted]',
            'foreign_key' => 'f',
            'sort_key' => 'g',
        ]);
    });
});

describe('timestamp columns', function () {
    it('are excluded when they have custom names', function () {
        $record = StampedRecord::create(['title' => 'Stamped']);

        expect($this->audits()->sole()->new_values)->not->toHaveKeys(['added_at', 'modified_at']);

        $this->travel(5)->minutes();
        $record->touch();

        expect($this->audits())->toHaveCount(1);
    });
});

describe('database-shaped values', function () {
    it('stores booleans as the integers the database holds', function () {
        $record = StampedRecord::create(['title' => 'Flag', 'active' => true]);

        expect($this->audits()->sole()->new_values)->toMatchArray(['active' => 1]);

        $record->update(['active' => false]);

        $audit = $this->audits()->last();

        expect($audit->old_values)->toBe(['active' => 1])
            ->and($audit->new_values)->toBe(['active' => 0]);
    });
});

describe('restoring a partial select', function () {
    it('records the restore even when deleted_at was not selected', function () {
        $document = Document::create(['title' => 'Memo']);
        $document->delete();

        $partial = Document::withTrashed()->select('id')->findOrFail($document->id);
        $partial->restore();

        $audit = $this->audits()->last();

        expect($this->audits()->pluck('event')->all())->toBe(['created', 'deleted', 'restored'])
            ->and($audit->old_values)->toBe(['deleted_at' => null])
            ->and($audit->new_values)->toBe(['deleted_at' => null])
            ->and(Document::query()->count())->toBe(1);
    });
});

describe('truncated values', function () {
    it('are listed in meta.truncated, so an identical-looking update is explained', function () {
        config(['audit.max_value_bytes' => 100]);

        $document = makeDocument(['options' => ['body' => str_repeat('x', 300)]]);
        $document->update(['options' => ['body' => str_repeat('y', 300)], 'total' => 5]);

        $created = $this->audits()->first();
        $updated = $this->audits()->last();

        expect($created->meta)->toBe(['truncated' => ['options']])
            ->and($updated->old_values['options'])->toBe($updated->new_values['options'])
            ->and($updated->old_values['total'])->toBe(100)
            ->and($updated->meta)->toBe(['truncated' => ['options']]);
    });

    it('add nothing to meta when no value was truncated', function () {
        makeDocument();

        expect($this->audits()->sole()->meta)->toBeNull();
    });
});

describe('auditParent()', function () {
    it('throws a clear exception for a class that is not a model', function () {
        $model = new class extends Model
        {
            use Auditable;

            protected $table = 'teams';

            protected $guarded = [];

            public function auditParent(): array
            {
                return [stdClass::class, 1];
            }
        };

        expect(fn () => $model->newInstance(['name' => 'Orphan'])->save())
            ->toThrow(InvalidArgumentException::class, 'is not an Eloquent model');

        expect(DB::table('teams')->count())->toBe(0);
    });
});
