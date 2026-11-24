<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Jiannius\Audit\Audit;
use Jiannius\Audit\Category;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Tests\Fixtures\Invoice;
use Jiannius\Audit\Tests\Fixtures\InvoiceLine;
use Jiannius\Audit\Tests\Fixtures\Order;

enum RecorderTestStatus: string
{
    case Draft = 'draft';
}

describe('subjects', function () {
    it('stores a ULID key as a string, with the morph alias and a label snapshot', function () {
        $invoice = Invoice::create(['number' => 'INV-1']);

        Auditor::record('sent', $invoice);

        $audit = $this->audits()->sole();

        expect($audit->subject_type)->toBe('invoice')
            ->and($audit->subject_id)->toBe($invoice->id)
            ->and($audit->subject_id)->toHaveLength(26)
            ->and($audit->subject_label)->toBe('INV-1');
    });

    it('stores a bigint key as a string', function () {
        $order = Order::create(['title' => 'Chairs']);

        Auditor::record('placed', $order);

        $audit = $this->audits()->sole();

        expect($audit->subject_id)->toBeString()->toBe((string) $order->id)
            ->and($audit->subject_label)->toBe('Chairs');
    });

    it('stores the parent roll-up', function () {
        config(['audit.attributes.label' => ['description']]);
        $invoice = Invoice::create(['number' => 'INV-1']);
        $line = InvoiceLine::create(['invoice_id' => $invoice->id, 'description' => 'Widget']);

        Auditor::record('created', $line, parent: $invoice, category: Category::RECORD);

        $audit = $this->audits()->sole();

        expect($audit->category)->toBe('record')
            ->and($audit->parent_type)->toBe('invoice')
            ->and($audit->parent_id)->toBe($invoice->id)
            ->and($audit->subject_label)->toBe('Widget');
    });

    it('records an event with no subject', function () {
        Auditor::record('exported');

        $audit = $this->audits()->sole();

        expect($audit->subject_type)->toBeNull()
            ->and($audit->subject_id)->toBeNull()
            ->and($audit->subject_label)->toBeNull();
    });

    it('reads the label without touching attributes that were not loaded', function () {
        $invoice = Invoice::create(['number' => 'INV-1']);
        $partial = Invoice::query()->select('id')->findOrFail($invoice->id);

        Auditor::record('sent', $partial);

        expect($this->audits()->sole()->subject_label)->toBeNull();
    });

    it('takes the first filled label attribute', function () {
        config(['audit.attributes.label' => ['title', 'number']]);
        $invoice = Invoice::create(['number' => 'INV-1']);

        Auditor::record('sent', $invoice);

        expect($this->audits()->sole()->subject_label)->toBe('INV-1');
    });

    it('resolves the subject back through the enforced morph map', function () {
        $invoice = Invoice::create(['number' => 'INV-1']);
        Auditor::record('sent', $invoice);

        $audit = Audit::with('subject')->sole();

        expect($audit->subject->is($invoice))->toBeTrue();
    });

    it('resolves the actor back through the enforced morph map', function () {
        $user = makeUser();
        $this->actingAs($user);
        Auditor::record('sent');

        $audit = Audit::with('actor')->sole();

        expect($audit->actor->is($user))->toBeTrue();
    });
});

describe('values', function () {
    it('round-trips old values, new values and meta through the array casts', function () {
        Auditor::record(
            'updated',
            meta: ['reason' => 'typo'],
            old: ['total' => 100, 'status' => 'draft'],
            new: ['total' => 150, 'note' => null],
        );

        $audit = $this->audits()->sole();

        expect($audit->old_values)->toBe(['total' => 100, 'status' => 'draft'])
            ->and($audit->new_values)->toEqual(['total' => 150, 'note' => null])
            ->and($audit->meta)->toBe(['reason' => 'typo']);
    });

    it('stores null rather than an empty object when there are no values', function () {
        Auditor::record('sent');

        $row = DB::table('audits')->first();

        expect($row->old_values)->toBeNull()
            ->and($row->new_values)->toBeNull()
            ->and($row->meta)->toBeNull();
    });

    it('builds field changes from the union of old and new keys', function () {
        Auditor::record('updated', old: ['a' => 1, 'b' => 2], new: ['b' => 3, 'c' => 4]);

        expect($this->audits()->sole()->fieldChanges())->toBe([
            'a' => ['old' => 1, 'new' => null],
            'b' => ['old' => 2, 'new' => 3],
            'c' => ['old' => null, 'new' => 4],
        ]);
    });

    it('stores unicode as written', function () {
        Auditor::record('sent', meta: ['to' => '陈大文']);

        expect(DB::table('audits')->value('meta'))->toContain('陈大文');
    });
});

describe('sanitising', function () {
    it('replaces non-UTF-8 strings with a placeholder', function () {
        Auditor::record('updated', old: ['blob' => "\xB1\x31\xFF"], new: ['nested' => ['blob' => "\xB1\x31"]]);

        $audit = $this->audits()->sole();

        expect($audit->old_values)->toBe(['blob' => '[binary 3 bytes]'])
            ->and($audit->new_values)->toBe(['nested' => ['blob' => '[binary 2 bytes]']]);
    });

    it('does not let an awkward value block the write', function () {
        $handle = fopen('php://memory', 'r');

        Auditor::record('sent', meta: [
            'nan' => NAN,
            'inf' => INF,
            'object' => new stdClass,
            'resource' => $handle,
            'date' => new DateTimeImmutable('2026-01-02 03:04:05'),
            'enum' => RecorderTestStatus::Draft,
        ]);

        fclose($handle);

        // toEqual: MySQL's JSON type does not keep key order.
        expect($this->audits()->sole()->meta)->toEqual([
            'nan' => 'NAN',
            'inf' => 'INF',
            'object' => '[object stdClass]',
            'resource' => '[resource (stream)]',
            'date' => '2026-01-02 03:04:05',
            'enum' => 'draft',
        ]);
    });

    it('truncates labels, sources and identifiers to their column sizes', function () {
        $invoice = Invoice::create(['number' => str_repeat('x', 400)]);
        $user = makeUser(['email' => str_repeat('e', 400).'@example.test', 'name' => str_repeat('n', 400)]);
        $this->actingAs($user);

        Auditor::withSource(str_repeat('s', 400), fn () => Auditor::record(str_repeat('v', 200), $invoice));

        $audit = $this->audits()->sole();

        expect(mb_strlen($audit->subject_label))->toBe(255)
            ->and(mb_strlen($audit->source))->toBe(191)
            ->and(mb_strlen($audit->event))->toBe(64)
            ->and(mb_strlen($audit->actor_name))->toBe(255)
            ->and(mb_strlen($audit->actor_identifier))->toBe(255);
    });

    it('truncates multibyte labels by character, never mid-character', function () {
        $invoice = Invoice::create(['number' => str_repeat('陈', 400)]);

        Auditor::record('sent', $invoice);

        expect($this->audits()->sole()->subject_label)->toBe(str_repeat('陈', 255));
    });

    it('stores a user agent as text, untruncated at a normal size', function () {
        $agent = str_repeat('Mozilla/5.0;', 80);

        $this->withHeaders(['User-Agent' => $agent])->get('/touch');

        expect($this->audits()->sole()->user_agent)->toBe($agent);
    });
});

describe('scopes', function () {
    it('finds rows by subject with the id bound as a string', function () {
        $invoice = Invoice::create(['number' => 'INV-1']);
        $order = Order::create(['title' => 'Chairs']);
        Auditor::record('sent', $invoice);
        Auditor::record('placed', $order);

        expect(Audit::forSubject($invoice)->pluck('event')->all())->toBe(['sent'])
            ->and(Audit::forSubject($order)->pluck('event')->all())->toBe(['placed']);

        expect(DB::table('audits')->where('subject_id', (string) $order->id)->count())->toBe(1);
    });

    it('finds rows about a record and rows rolled up to it', function () {
        $invoice = Invoice::create(['number' => 'INV-1']);
        $line = InvoiceLine::create(['invoice_id' => $invoice->id]);
        $other = Invoice::create(['number' => 'INV-2']);
        Auditor::record('sent', $invoice);
        Auditor::record('created', $line, parent: $invoice);
        Auditor::record('sent', $other);

        expect(Audit::forSubjectAndChildren($invoice)->pluck('event')->all())->toBe(['sent', 'created'])
            ->and(Audit::forSubject($invoice)->count())->toBe(1);
    });

    it('finds rows by actor, category and date range', function () {
        $user = makeUser();
        Auditor::actingAs($user, fn () => Auditor::record('login', category: Category::AUTH));
        Auditor::record('sent');

        expect(Audit::byActor($user)->pluck('event')->all())->toBe(['login'])
            ->and(Audit::inCategory(Category::AUTH)->count())->toBe(1)
            ->and(Audit::inCategory(Category::AUTH, Category::ACTION)->count())->toBe(2)
            ->and(Audit::between(now()->subMinute(), now()->addMinute())->count())->toBe(2)
            ->and(Audit::between(now()->subDays(2), now()->subDay())->count())->toBe(0);
    });
});

it('writes on the subject connection when audit.connection is null', function () {
    $this->addSecondConnection();
    $invoice = Invoice::create(['number' => 'INV-1'])->setConnection('other');

    Auditor::record('sent', $invoice);

    expect(DB::connection('other')->table('audits')->count())->toBe(1)
        ->and(DB::table('audits')->count())->toBe(0);
});

it('writes on the configured connection when audit.connection is set', function () {
    $this->addSecondConnection();
    config(['audit.connection' => 'other']);

    Auditor::record('sent', Invoice::create(['number' => 'INV-1']));

    expect(DB::connection('other')->table('audits')->count())->toBe(1)
        ->and(DB::table('audits')->count())->toBe(0);
});

it('joins the transaction of the change it records', function () {
    $invoice = Invoice::create(['number' => 'INV-1']);

    DB::beginTransaction();
    Auditor::record('sent', $invoice);
    DB::rollBack();

    expect(DB::table('audits')->count())->toBe(0);
});

it('fails loudly when the table is missing, so the caller can roll back', function () {
    DB::statement('drop table audits');

    expect(fn () => Auditor::record('sent'))->toThrow(QueryException::class);
});
