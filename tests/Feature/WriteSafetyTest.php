<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Jiannius\Audit\Audit;
use Jiannius\Audit\Category;
use Jiannius\Audit\Exceptions\AuditWriteFailed;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Tests\Fixtures\Invoice;
use Jiannius\Audit\Tests\Fixtures\Order;

describe('a failed insert', function () {
    it('throws a package exception that holds no SQL and no bound value', function () {
        $invoice = Invoice::create(['number' => 'INV-SECRET-123']);
        $this->actingAs(makeUser(['email' => 'secret-person@example.test']));
        $this->withHeaders(['User-Agent' => 'secret-agent/9'])->get('/touch'); // proves a request context exists
        DB::statement('drop table audits');

        $caught = null;

        try {
            Auditor::record('sent', $invoice, meta: ['note' => 'top-secret-note'], new: ['total' => 'top-secret-total']);
        } catch (Throwable $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(AuditWriteFailed::class)
            ->and($caught->table)->toBe('audits')
            ->and($caught->sqlState)->not->toBeNull();

        // Neither the message nor any exception in its chain may carry a value or the statement.
        $everything = [];
        for ($e = $caught; $e !== null; $e = $e->getPrevious()) {
            $everything[] = $e->getMessage();
        }
        $everything[] = (string) $caught;
        $text = implode("\n", $everything);

        expect($caught->getPrevious())->toBeNull();

        foreach (['INV-SECRET-123', 'secret-person', 'top-secret-note', 'top-secret-total', 'secret-agent', '127.0.0.1', 'insert into', 'values ('] as $leak) {
            expect($text)->not->toContain($leak);
        }
    });

    it('still propagates, so the surrounding transaction rolls back', function () {
        DB::statement('drop table audits');

        expect(function () {
            DB::transaction(function () {
                Invoice::create(['number' => 'INV-1']);
                Auditor::record('sent');
            });
        })->toThrow(AuditWriteFailed::class);

        expect(Invoice::query()->count())->toBe(0);
    });

    it('keeps a deadlock recognisable to DB::transaction retries', function () {
        $deadlock = AuditWriteFailed::from(
            new QueryException('x', 'insert into audits', ['secret'], new PDOException('Deadlock found: secret')),
            'audits',
        );
        $plain = new AuditWriteFailed('audits', '42S02', 1146);

        expect($deadlock->getMessage())->not->toContain('secret')
            ->and((new AuditWriteFailed('audits', '40001', 1213))->getMessage())->toContain('Deadlock found when trying to get lock')
            ->and($plain->getMessage())->not->toContain('Deadlock');
    });
});

describe('category', function () {
    it('accepts the three categories', function () {
        foreach (Category::all() as $category) {
            Auditor::record('sent', category: $category);
        }

        expect($this->audits()->pluck('category')->all())->toBe(['record', 'auth', 'action']);
    });

    it('refuses an unknown category instead of truncating it', function () {
        expect(fn () => Auditor::record('sent', category: 'records'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => Auditor::record('sent', category: 'a-category-that-is-very-long'))->toThrow(InvalidArgumentException::class);

        expect(DB::table('audits')->count())->toBe(0);
    });
});

describe('value size', function () {
    it('stores a marker for a string over the byte cap, whatever the field', function () {
        config(['audit.max_value_bytes' => 100]);

        Auditor::record('updated',
            meta: ['big' => str_repeat('m', 500)],
            old: ['big' => str_repeat('o', 101), 'fine' => str_repeat('o', 100)],
            new: ['nested' => ['big' => str_repeat('é', 200)]],
        );

        $audit = $this->audits()->sole();

        expect($audit->meta)->toBe(['big' => '[truncated 500 bytes]'])
            ->and($audit->old_values)->toEqual(['big' => '[truncated 101 bytes]', 'fine' => str_repeat('o', 100)])
            ->and($audit->new_values)->toBe(['nested' => ['big' => '[truncated 400 bytes]']]);
    });

    it('defaults to 64 KB per string', function () {
        Auditor::record('updated', new: ['small' => str_repeat('x', 65536), 'big' => str_repeat('x', 65537)]);

        expect($this->audits()->sole()->new_values)->toEqual(['small' => str_repeat('x', 65536), 'big' => '[truncated 65537 bytes]']);
    });

    it('does not apply the cap to labels, which have their own column size', function () {
        config(['audit.max_value_bytes' => 10]);
        $invoice = Invoice::create(['number' => str_repeat('x', 300)]);

        Auditor::record('sent', $invoice);

        expect($this->audits()->sole()->subject_label)->toBe(str_repeat('x', 255));
    });

    it('stores a marker, not null, when the values cannot be encoded, and the save goes on', function () {
        $deep = 'leaf';
        for ($i = 0; $i < 600; $i++) {
            $deep = [$deep];
        }

        Auditor::record('sent', meta: ['deep' => $deep], new: ['ok' => 1]);

        $audit = $this->audits()->sole();

        expect($audit->meta)->toBe(['_audit' => '[values could not be encoded]'])
            ->and($audit->new_values)->toBe(['ok' => 1]);
    });

    it('truncates the user agent by bytes, never mid-character, within a MySQL TEXT column', function () {
        $this->withHeaders(['User-Agent' => str_repeat('é', 40000)])->get('/touch')->assertOk();

        $agent = DB::table('audits')->value('user_agent');

        expect(strlen($agent))->toBeLessThanOrEqual(65535)
            ->and(strlen($agent))->toBeGreaterThan(65000)
            ->and(mb_check_encoding($agent, 'UTF-8'))->toBeTrue();
    });
});

describe('subject relation', function () {
    it('resolves a soft-deleted subject, and still resolves one that cannot be soft-deleted', function () {
        $order = Order::create(['title' => 'Chairs']);
        $invoice = Invoice::create(['number' => 'INV-1']);
        Auditor::record('deleted', $order);
        Auditor::record('sent', $invoice);
        $order->delete();

        $audits = Audit::with('subject')->orderBy('id')->get();

        expect($audits[0]->subject)->not->toBeNull()
            ->and($audits[0]->subject->trashed())->toBeTrue()
            ->and($audits[1]->subject->is($invoice))->toBeTrue();
    });
});
