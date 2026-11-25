<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jiannius\Audit\Audit;
use Jiannius\Audit\Category;
use Jiannius\Audit\Exceptions\AuditWriteFailed;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Tests\Fixtures\Attachment;
use Jiannius\Audit\Tests\Fixtures\AuditedInvoice;
use Jiannius\Audit\Tests\Fixtures\AuditedLine;
use Jiannius\Audit\Tests\Fixtures\AuditedUser;
use Jiannius\Audit\Tests\Fixtures\Document;
use Jiannius\Audit\Tests\Fixtures\Team;

describe('Auditor::update', function () {
    it('records one row per changed record, with the changed columns only', function () {
        [$a, $b, $c] = rawDocuments([['title' => 'A', 'total' => 10], ['title' => 'B', 'total' => 20], ['title' => 'C', 'total' => 30]]);

        $count = Auditor::update(Document::query()->where('total', '>', 10), ['status' => 'sent', 'total' => 99]);

        expect($count)->toBe(2);

        $audits = $this->audits();

        expect($audits)->toHaveCount(2)
            ->and($audits->pluck('subject_id')->all())->toBe([(string) $b, (string) $c])
            ->and($audits->pluck('subject_type')->unique()->all())->toBe(['document'])
            ->and($audits->pluck('category')->unique()->all())->toBe([Category::RECORD])
            ->and($audits->pluck('event')->unique()->all())->toBe(['updated'])
            ->and($audits[0]->old_values)->toEqual(['status' => 'draft', 'total' => 20])
            ->and($audits[0]->new_values)->toEqual(['status' => 'sent', 'total' => 99])
            ->and($audits[0]->subject_label)->toBe('B');

        // The row outside the WHERE clause is untouched and unrecorded.
        expect(DB::table('documents')->where('id', $a)->first())->toMatchObject(['status' => 'draft', 'total' => 10])
            ->and($audits->pluck('subject_id')->contains((string) $a))->toBeFalse();
    });

    it('reads expressions back, so DB::raw increments get a per-row diff', function () {
        rawDocuments([['title' => 'A', 'total' => 10], ['title' => 'B', 'total' => 25]]);

        Auditor::update(Document::query(), ['total' => DB::raw('total * 2 + 1')]);

        $audits = $this->audits();

        expect($audits[0]->old_values)->toEqual(['total' => 10])
            ->and($audits[0]->new_values)->toEqual(['total' => 21])
            ->and($audits[1]->old_values)->toEqual(['total' => 25])
            ->and($audits[1]->new_values)->toEqual(['total' => 51]);
    });

    it('records nothing for rows the update left as they were', function () {
        rawDocuments([['title' => 'A', 'status' => 'sent'], ['title' => 'B', 'status' => 'draft']]);

        $count = Auditor::update(Document::query(), ['status' => 'sent']);

        expect($count)->toBe(2)
            ->and($this->audits()->pluck('subject_label')->all())->toBe(['B']);
    });

    it('records nothing at all for an update that changes no row', function () {
        rawDocuments([['status' => 'sent']]);

        Auditor::update(Document::query(), ['status' => 'sent', 'total' => DB::raw('total + 0')]);

        expect($this->audits())->toHaveCount(0);
    });

    it('does nothing and returns 0 when no row matches', function () {
        rawDocuments([['title' => 'A']]);

        expect(Auditor::update(Document::query()->where('title', 'nobody'), ['status' => 'sent']))->toBe(0)
            ->and($this->audits())->toHaveCount(0);
    });

    it('uses the event and meta it is given, on every row', function () {
        rawDocuments([['title' => 'A'], ['title' => 'B']]);

        Auditor::update(Document::query(), ['status' => 'void'], event: 'voided', meta: ['reason' => 'duplicate']);

        expect($this->audits()->pluck('event')->unique()->all())->toBe(['voided'])
            ->and($this->audits()->pluck('meta')->unique()->all())->toEqual([['reason' => 'duplicate']]);
    });

    it('takes the actor and source from the context', function () {
        rawDocuments([['title' => 'A']]);
        $user = makeUser();

        Auditor::actingAs($user, fn () => Auditor::withSource('backfill:status', fn () => Auditor::update(Document::query(), ['status' => 'sent'])));

        $audit = $this->audits()->first();

        expect($audit->actor_id)->toBe((string) $user->getKey())
            ->and($audit->source)->toBe('backfill:status');
    });

    it('works on a relation, with the label and the parent of the model', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        $other = AuditedInvoice::create(['number' => 'INV-2']);
        DB::table('invoice_lines')->insert([
            ['invoice_id' => $invoice->id, 'description' => 'Widget', 'quantity' => 1],
            ['invoice_id' => $invoice->id, 'description' => 'Gadget', 'quantity' => 2],
            ['invoice_id' => $other->id, 'description' => 'Other', 'quantity' => 3],
        ]);
        DB::table('audits')->delete();

        Auditor::update($invoice->lines(), ['quantity' => DB::raw('quantity + 10')], meta: ['cascade' => 'invoice_cancelled']);

        $audits = $this->audits();

        expect($audits)->toHaveCount(2)
            ->and($audits->pluck('subject_label')->all())->toBe(['Widget', 'Gadget'])
            ->and($audits->pluck('subject_type')->unique()->all())->toBe(['audited-line'])
            ->and($audits->pluck('parent_type')->unique()->all())->toBe(['audited-invoice'])
            ->and($audits->pluck('parent_id')->unique()->all())->toBe([$invoice->id])
            ->and($audits[0]->new_values)->toEqual(['quantity' => 11])
            ->and($audits[0]->meta)->toEqual(['cascade' => 'invoice_cancelled'])
            ->and(DB::table('invoice_lines')->where('invoice_id', $other->id)->value('quantity'))->toBe(3);
    });

    it('works on a many-to-many relation, whose query joins the pivot table', function () {
        $user = AuditedUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
        $teams = array_map(fn (string $name) => Team::create(['name' => $name])->getKey(), ['T1', 'T2', 'T3']);
        $user->teams()->attach([$teams[0], $teams[1]]);
        DB::table('audits')->delete();

        $count = Auditor::update($user->teams(), ['name' => 'Renamed']);

        $audits = $this->audits();

        expect($count)->toBe(2)
            ->and($audits->pluck('subject_id')->all())->toBe([(string) $teams[0], (string) $teams[1]])
            ->and($audits->pluck('subject_type')->unique()->all())->toBe(['team'])
            ->and($audits[0]->old_values)->toEqual(['name' => 'T1'])
            ->and($audits[0]->new_values)->toEqual(['name' => 'Renamed'])
            ->and(DB::table('teams')->where('id', $teams[2])->value('name'))->toBe('T3');
    });

    it('works on an Eloquent builder that selects only some columns, under strict mode', function () {
        $id = rawDocuments([['title' => 'A', 'total' => 1]])[0];

        Auditor::update(Document::query()->select('id', 'title')->where('id', $id), ['total' => 2]);

        expect($this->audits()->first()->new_values)->toEqual(['total' => 2])
            ->and($this->audits()->first()->subject_label)->toBe('A');
    });

    it('does not record updated_at, which an Eloquent update adds, and skips an excluded-only change', function () {
        rawDocuments([['title' => 'A']]);

        Auditor::update(Document::query(), ['internal_ref' => 'abc', 'api_token' => 'tok-1']);

        expect($this->audits())->toHaveCount(0)
            ->and(DB::table('documents')->value('internal_ref'))->toBe('abc');
    });

    it('drops excluded columns from a row that also has real changes', function () {
        rawDocuments([['title' => 'A']]);

        Auditor::update(Document::query(), ['status' => 'sent', 'internal_ref' => 'abc']);

        expect($this->audits()->first()->new_values)->toEqual(['status' => 'sent'])
            ->and($this->audits()->first()->old_values)->toEqual(['status' => 'draft']);
    });

    it('redacts a secret column in a bulk update and never stores its value', function () {
        rawDocuments([['title' => 'A', 'pin' => 'old-pin-1111'], ['title' => 'B', 'pin' => null]]);

        Auditor::update(Document::query(), ['pin' => 'new-pin-2222']);

        $audits = $this->audits();

        expect($audits[0]->old_values)->toEqual(['pin' => '[redacted]'])
            ->and($audits[0]->new_values)->toEqual(['pin' => '[redacted]'])
            ->and($audits[1]->old_values)->toEqual(['pin' => null])
            ->and($audits[1]->new_values)->toEqual(['pin' => '[redacted]'])
            ->and(everythingAudited())->not->toContain('old-pin-1111')->not->toContain('new-pin-2222');
    });

    it('still records a secret that changed from one secret to another', function () {
        rawDocuments([['pin' => 'aaaa']]);

        Auditor::update(Document::query(), ['pin' => 'bbbb']);

        expect($this->audits())->toHaveCount(1);
    });

    it('handles a qualified column name and a JSON path', function () {
        rawDocuments([['total' => 1, 'settings' => '{"theme":"dark"}']]);

        Auditor::update(Document::query(), ['documents.total' => 2, 'settings->theme' => 'light']);

        $new = $this->audits()->first()->new_values;

        // MySQL re-serialises the JSON it edits, so compare the decoded value.
        expect($new['total'])->toBe(2)
            ->and(json_decode($new['settings'], true))->toBe(['theme' => 'light']);
    });

    it('leaves soft-deleted rows out, as the model\'s own scope does', function () {
        [$live, $trashed] = rawDocuments([['title' => 'Live'], ['title' => 'Trashed', 'deleted_at' => '2026-01-01 00:00:00']]);

        Auditor::update(Document::query(), ['status' => 'sent']);

        expect($this->audits()->pluck('subject_id')->all())->toBe([(string) $live])
            ->and(DB::table('documents')->where('id', $trashed)->value('status'))->toBe('draft');
    });

    it('refuses to audit an update of the primary key', function () {
        rawDocuments([['title' => 'A']]);

        expect(fn () => Auditor::update(Document::query(), ['id' => 99]))->toThrow(InvalidArgumentException::class, 'primary key');
        expect(DB::table('documents')->value('id'))->not->toBe(99);
    });

    it('follows the model\'s auditEvents: a standard event it does not record is skipped, a custom one is recorded', function () {
        DB::table('invoice_lines')->insert(['invoice_id' => 'x', 'description' => 'Doc', 'quantity' => 1]);

        // Attachment records only `deleted`.
        Auditor::update(Attachment::query(), ['quantity' => 2]);
        expect($this->audits())->toHaveCount(0)
            ->and(DB::table('invoice_lines')->value('quantity'))->toBe(2);

        Auditor::update(Attachment::query(), ['quantity' => 3], event: 'recounted');
        expect($this->audits()->pluck('event')->all())->toBe(['recounted']);
    });

    it('writes nothing and still updates while auditing is off', function () {
        rawDocuments([['title' => 'A']]);
        config(['audit.enabled' => false]);

        $count = Auditor::update(Document::query(), ['status' => 'sent']);

        expect($count)->toBe(1)
            ->and(DB::table('documents')->value('status'))->toBe('sent')
            ->and(DB::table('audits')->count())->toBe(0);
    });

    it('stays correct for a large update, across more than one reselect chunk', function () {
        $rows = [];

        foreach (range(1, 1200) as $i) {
            $rows[] = ['title' => "Doc $i", 'status' => 'draft', 'total' => $i];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('documents')->insert($chunk);
        }

        // Every third row is already sent: it is not a change, so it gets no row.
        DB::table('documents')->whereRaw('id % 3 = 0')->update(['status' => 'sent']);

        $count = Auditor::update(Document::query(), ['status' => 'sent', 'total' => DB::raw('total + 1000')]);

        $audits = Audit::query()->orderBy('id')->get();

        expect($count)->toBe(1200)
            ->and($audits)->toHaveCount(1200)
            ->and($audits->pluck('subject_id')->unique())->toHaveCount(1200);

        $first = $audits->first();
        $third = $audits->firstWhere('subject_label', 'Doc 3');
        $last = $audits->last();

        expect($first->old_values)->toEqual(['status' => 'draft', 'total' => 1])
            ->and($first->new_values)->toEqual(['status' => 'sent', 'total' => 1001])
            ->and($third->old_values)->toEqual(['total' => 3])
            ->and($third->new_values)->toEqual(['total' => 1003])
            ->and($last->new_values['total'])->toBe(2200);
    });

    it('rolls the update back when the audit insert fails', function () {
        rawDocuments([['title' => 'A', 'total' => 1], ['title' => 'B', 'total' => 2]]);
        Schema::drop('audits');

        expect(fn () => Auditor::update(Document::query(), ['total' => DB::raw('total + 1')]))->toThrow(AuditWriteFailed::class);

        expect(DB::table('documents')->orderBy('id')->pluck('total')->all())->toBe([1, 2]);
    });

    it('rolls a relation update back when the audit insert fails', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        AuditedLine::create(['invoice_id' => $invoice->id, 'description' => 'Widget', 'quantity' => 1]);
        Schema::drop('audits');

        expect(fn () => Auditor::update($invoice->lines(), ['quantity' => 5]))->toThrow(AuditWriteFailed::class);

        expect(DB::table('invoice_lines')->value('quantity'))->toBe(1);
    });

    it('lets the caller\'s transaction roll the rows back with the update', function () {
        rawDocuments([['title' => 'A']]);

        DB::transaction(function () {
            Auditor::update(Document::query(), ['status' => 'sent']);
            DB::rollBack();
        });

        expect(DB::table('documents')->value('status'))->toBe('draft')
            ->and(DB::table('audits')->count())->toBe(0);
    });
});

describe('Auditor::update on a base query builder', function () {
    it('takes a morph-map alias as the subject type', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        DB::table('audits')->delete();

        $count = Auditor::update(
            DB::table('invoices')->where('id', $invoice->id),
            ['number' => 'INV-1-VOID'],
            event: 'voided',
            meta: ['reason' => 'duplicate'],
            subjectType: 'invoice',
        );

        $audit = $this->audits()->first();

        expect($count)->toBe(1)
            ->and($audit->event)->toBe('voided')
            ->and($audit->subject_type)->toBe('invoice')
            ->and($audit->subject_id)->toBe($invoice->id)
            ->and($audit->subject_label)->toBe('INV-1-VOID')
            ->and($audit->old_values)->toEqual(['number' => 'INV-1'])
            ->and($audit->new_values)->toEqual(['number' => 'INV-1-VOID'])
            ->and($audit->meta)->toEqual(['reason' => 'duplicate']);
    });

    it('takes a model class as the subject type, and applies that model\'s rules', function () {
        rawDocuments([['title' => 'A', 'pin' => 'abc']]);

        Auditor::update(DB::table('documents'), ['pin' => 'def', 'internal_ref' => 'x'], subjectType: Document::class);

        $audit = $this->audits()->first();

        expect($audit->subject_type)->toBe('document')
            ->and($audit->new_values)->toEqual(['pin' => '[redacted]']);
    });

    it('infers the subject type from the morph map by table name', function () {
        DB::table('orders')->insert(['title' => 'Order 1']);

        Auditor::update(DB::table('orders'), ['title' => 'Order 1b']);

        expect($this->audits()->first()->subject_type)->toBe('order')
            ->and($this->audits()->first()->subject_label)->toBe('Order 1b');
    });

    it('works with a table alias', function () {
        DB::table('orders')->insert(['title' => 'Order 1']);

        Auditor::update(DB::table('orders as o')->where('o.id', 1), ['title' => 'Order 1b']);

        expect($this->audits())->toHaveCount(1);
    });

    it('throws when the table matches no morph-map model', function () {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0]);

        expect(fn () => Auditor::update(DB::table('jobs'), ['queue' => 'other']))
            ->toThrow(InvalidArgumentException::class, 'subjectType');

        expect(DB::table('jobs')->value('queue'))->toBe('default');
    });

    it('throws when more than one morph-map model uses the table', function () {
        rawDocuments([['title' => 'A']]);

        expect(fn () => Auditor::update(DB::table('documents'), ['status' => 'sent']))
            ->toThrow(InvalidArgumentException::class, 'Pass the subjectType');

        expect(DB::table('documents')->value('status'))->toBe('draft');
    });

    it('throws for a subject type that is not a model', function () {
        expect(fn () => Auditor::update(DB::table('orders'), ['title' => 'x'], subjectType: 'nonsense'))
            ->toThrow(InvalidArgumentException::class, 'Unknown subject type');
    });

    it('rolls back when the audit insert fails', function () {
        $invoice = AuditedInvoice::create(['number' => 'INV-1']);
        Schema::drop('audits');

        expect(fn () => Auditor::update(DB::table('invoices'), ['number' => 'X'], subjectType: 'invoice'))->toThrow(AuditWriteFailed::class);

        expect(DB::table('invoices')->value('number'))->toBe('INV-1');
    });
});

describe('Auditor::update: table aliases and qualifiers', function () {
    it('records a column qualified with the query\'s alias', function () {
        [$id] = rawDocuments([['title' => 'A', 'total' => 1]]);

        $count = Auditor::update(DB::table('documents as d')->where('d.id', $id), ['d.total' => 5], subjectType: 'document');

        expect($count)->toBe(1)
            ->and($this->audits())->toHaveCount(1)
            ->and($this->audits()->first()->old_values)->toEqual(['total' => 1])
            ->and($this->audits()->first()->new_values)->toEqual(['total' => 5]);
    });

    it('records an unqualified column on an aliased Eloquent query', function () {
        [$id] = rawDocuments([['title' => 'A', 'total' => 1]]);

        Auditor::update(Document::query()->from('documents as d')->where('d.id', $id), ['total' => 5]);

        expect($this->audits()->first()->new_values)->toEqual(['total' => 5]);
    });

    it('throws for a column qualified with something that is neither the table nor its alias, and writes nothing', function () {
        [$id] = rawDocuments([['title' => 'A', 'total' => 1]]);

        expect(fn () => Auditor::update(DB::table('documents as d')->where('d.id', $id), ['x.total' => 5], subjectType: 'document'))
            ->toThrow(InvalidArgumentException::class, 'neither the audited table [documents] nor its alias [d]');

        expect(DB::table('documents')->value('total'))->toBe(1)
            ->and($this->audits())->toHaveCount(0);
    });
});

describe('Auditor::update and delete: the audited set is the written set', function () {
    it('refuses a limit or an offset on a joined query, which the database would ignore', function () {
        $user = AuditedUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
        $team = Team::create(['name' => 'T1']);
        $user->teams()->attach($team->id);
        DB::table('audits')->delete();

        expect(fn () => Auditor::update($user->teams()->limit(1), ['name' => 'X']))->toThrow(InvalidArgumentException::class, 'joins')
            ->and(fn () => Auditor::update($user->teams()->offset(1), ['name' => 'X']))->toThrow(InvalidArgumentException::class, 'joins')
            ->and(fn () => Auditor::delete($user->teams()->limit(1)))->toThrow(InvalidArgumentException::class, 'joins');

        expect(DB::table('teams')->value('name'))->toBe('T1');
    });

    it('rolls an update back when the write touches a row the audit did not see', function () {
        rawDocuments([['title' => 'A', 'total' => 1]]);
        $armed = true;

        // A row that matches the WHERE appears between the locked select and the write.
        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_starts_with($query->sql, 'select')) {
                $armed = false;
                DB::table('documents')->insert(['title' => 'Phantom', 'status' => 'draft', 'total' => 1]);
            }
        });

        expect(fn () => Auditor::update(Document::query()->where('total', 1), ['total' => 2]))
            ->toThrow(RuntimeException::class, 'affected 2 rows but only 1 were audited');

        expect(DB::table('documents')->pluck('total')->all())->toBe([1])
            ->and($this->audits())->toHaveCount(0);
    });

    it('rolls a delete back when the write touches a row the audit did not see', function () {
        rawDocuments([['title' => 'A', 'total' => 1]]);
        $armed = true;

        DB::listen(function ($query) use (&$armed) {
            if ($armed && str_starts_with($query->sql, 'select')) {
                $armed = false;
                DB::table('documents')->insert(['title' => 'Phantom', 'status' => 'draft', 'total' => 1]);
            }
        });

        expect(fn () => Auditor::delete(Document::query()->where('total', 1), event: 'force_deleted'))
            ->toThrow(RuntimeException::class, 'affected 2 rows but only 1 were audited');

        // The phantom was inserted inside the transaction, so it went with it: only the original is left.
        expect(DB::table('documents')->pluck('title')->all())->toBe(['A'])
            ->and($this->audits())->toHaveCount(0);
    });

    it('locks the rows in primary key order', function () {
        rawDocuments([['title' => 'A'], ['title' => 'B']]);
        $selects = [];
        DB::listen(function ($query) use (&$selects) {
            if (str_starts_with($query->sql, 'select')) {
                $selects[] = $query->sql;
            }
        });

        Auditor::update(Document::query(), ['status' => 'sent']);

        expect($selects[0])->toMatch('/order by [`"]documents[`"]\.[`"]id[`"]/');
    });
});

describe('Auditor::update and delete through a has-many-through relation', function () {
    it('updates the far records and records one row each, with the line\'s own parent', function () {
        $user = AuditedUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
        $mine = AuditedInvoice::create(['number' => 'INV-1', 'user_id' => $user->id]);
        $theirs = AuditedInvoice::create(['number' => 'INV-2', 'user_id' => $user->id + 100]);
        DB::table('invoice_lines')->insert([
            ['invoice_id' => $mine->id, 'description' => 'Widget', 'quantity' => 1],
            ['invoice_id' => $mine->id, 'description' => 'Gadget', 'quantity' => 2],
            ['invoice_id' => $theirs->id, 'description' => 'Other', 'quantity' => 3],
        ]);
        DB::table('audits')->delete();

        $count = Auditor::update($user->lines(), ['quantity' => DB::raw('quantity + 10')]);

        $audits = $this->audits();

        expect($count)->toBe(2)
            ->and($audits->pluck('subject_label')->all())->toBe(['Widget', 'Gadget'])
            ->and($audits->pluck('parent_id')->unique()->all())->toBe([$mine->id])
            ->and($audits[0]->new_values)->toEqual(['quantity' => 11])
            ->and(DB::table('invoice_lines')->where('description', 'Other')->value('quantity'))->toBe(3);
    });

    it('deletes the far records with a row each', function () {
        $user = AuditedUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
        $mine = AuditedInvoice::create(['number' => 'INV-1', 'user_id' => $user->id]);
        $theirs = AuditedInvoice::create(['number' => 'INV-2', 'user_id' => $user->id + 100]);
        DB::table('invoice_lines')->insert([
            ['invoice_id' => $mine->id, 'description' => 'Widget'],
            ['invoice_id' => $theirs->id, 'description' => 'Other'],
        ]);
        DB::table('audits')->delete();

        $count = Auditor::delete($user->lines());

        expect($count)->toBe(1)
            ->and(DB::table('invoice_lines')->pluck('description')->all())->toBe(['Other'])
            ->and($this->audits()->pluck('subject_label')->all())->toBe(['Widget']);
    });
});

/** Six documents whose status order is not their id order, with an index on status the database may scan instead. */
function limitFixture(): void
{
    Schema::table('documents', fn ($table) => $table->index('status'));

    rawDocuments([
        ['title' => 't-a', 'status' => 'f', 'total' => 1],
        ['title' => 't-b', 'status' => 'c', 'total' => 1],
        ['title' => 't-c', 'status' => 'b', 'total' => 1],
        ['title' => 't-d', 'status' => 'c', 'total' => 1],
        ['title' => 't-e', 'status' => 'b', 'total' => 1],
        ['title' => 't-f', 'status' => 'e', 'total' => 1],
    ]);
}

describe('Auditor::update and delete with a limit or an offset', function () {
    it('audits exactly the rows it writes, for a limit with no order and an index the database could scan', function () {
        limitFixture();

        $count = Auditor::update(Document::query()->where('status', '>', 'a')->limit(2), ['total' => 7]);

        $written = DB::table('documents')->where('total', 7)->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();

        expect($count)->toBe(2)
            ->and($written)->toHaveCount(2)
            ->and($this->audits()->pluck('subject_id')->all())->toBe($written)
            ->and($this->audits()->pluck('new_values')->unique()->all())->toEqual([['total' => 7]]);
    });

    it('audits exactly the rows it writes, for an order with ties', function () {
        limitFixture();

        Auditor::update(Document::query()->orderBy('status')->limit(3), ['total' => 7]);

        $written = DB::table('documents')->where('total', 7)->orderBy('id')->pluck('title')->all();

        // The two `b` rows, then the first of the `c` rows by the primary key tie-break.
        expect($written)->toBe(['t-b', 't-c', 't-e'])
            ->and($this->audits()->pluck('subject_label')->all())->toBe($written);
    });

    it('audits exactly the rows it writes, for a limit and an offset', function () {
        limitFixture();

        Auditor::update(Document::query()->orderBy('title')->offset(1)->limit(2), ['total' => 7]);

        expect(DB::table('documents')->where('total', 7)->orderBy('id')->pluck('title')->all())->toBe(['t-b', 't-c'])
            ->and($this->audits()->pluck('subject_label')->all())->toBe(['t-b', 't-c']);
    });

    it('does the same on a base query builder', function () {
        limitFixture();

        Auditor::update(DB::table('documents')->orderBy('status')->orderBy('title', 'desc')->limit(2), ['total' => 7], subjectType: 'document');

        expect(DB::table('documents')->where('total', 7)->orderBy('id')->pluck('title')->all())->toBe(['t-c', 't-e'])
            ->and($this->audits()->pluck('subject_label')->all())->toBe(['t-c', 't-e']);
    });

    it('still touches updated_at and respects the model\'s scopes when it narrows the write', function () {
        limitFixture();
        DB::table('documents')->where('title', 't-c')->update(['deleted_at' => '2026-01-01 00:00:00']);

        Auditor::update(Document::query()->orderBy('status')->limit(2), ['total' => 7]);

        // The trashed t-c is out of reach, so the two `b` rows are t-e and then the first `c` row.
        expect(DB::table('documents')->where('total', 7)->orderBy('id')->pluck('title')->all())->toBe(['t-b', 't-e'])
            ->and($this->audits()->pluck('subject_label')->all())->toBe(['t-b', 't-e'])
            ->and(DB::table('documents')->where('title', 't-b')->value('updated_at'))->not->toBeNull();
    });

    it('audits exactly the rows it soft deletes', function () {
        limitFixture();

        $count = Auditor::delete(Document::query()->where('status', '>', 'a')->limit(2));

        $deleted = DB::table('documents')->whereNotNull('deleted_at')->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();

        expect($count)->toBe(2)
            ->and($deleted)->toHaveCount(2)
            ->and($this->audits()->pluck('subject_id')->all())->toBe($deleted)
            ->and($this->audits()->pluck('meta')->unique()->all())->toEqual([['soft' => true]]);
    });

    it('audits exactly the rows it hard deletes, with an order that has ties and an offset', function () {
        limitFixture();

        Auditor::delete(Document::query()->orderBy('status')->offset(1)->limit(3), event: 'force_deleted');

        // By status then id: t-c, t-e, t-b, t-d, t-f, t-a. Skip one, take three. A hard delete's rows follow that order.
        expect(DB::table('documents')->orderBy('id')->pluck('title')->all())->toBe(['t-a', 't-c', 't-f'])
            ->and($this->audits()->pluck('subject_label')->all())->toBe(['t-e', 't-b', 't-d']);
    });

    it('audits exactly the rows it hard deletes on a base query builder', function () {
        limitFixture();

        Auditor::delete(DB::table('documents')->orderBy('status')->limit(2), subjectType: 'document');

        expect(DB::table('documents')->orderBy('id')->pluck('title')->all())->toBe(['t-a', 't-b', 't-d', 't-f'])
            ->and($this->audits()->pluck('subject_label')->all())->toBe(['t-c', 't-e']);
    });

    it('handles a limit larger than one bind-list chunk', function () {
        $rows = [];

        foreach (range(1, 1500) as $i) {
            $rows[] = ['title' => "Doc $i", 'status' => 'draft', 'total' => 0];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('documents')->insert($chunk);
        }

        $count = Auditor::update(Document::query()->orderBy('id')->limit(1200), ['total' => 1]);

        expect($count)->toBe(1200)
            ->and(DB::table('documents')->where('total', 1)->count())->toBe(1200)
            ->and(DB::table('audits')->count())->toBe(1200);
    });
});
