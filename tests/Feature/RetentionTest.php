<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Jiannius\Audit\Audit;
use Jiannius\Audit\Exceptions\ImmutableAuditException;
use Jiannius\Audit\Retention\RetentionPolicy;

/** The ids still in the table, by the label they were given. */
function keptIds(): array
{
    return DB::table('audits')->where('event', '<>', 'audits_pruned')->orderBy('id')->pluck('id')->all();
}

function pruneRows(): array
{
    return Audit::query()->where('event', 'audits_pruned')->orderBy('id')->get()->all();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00'));
});

afterEach(fn () => Carbon::setTestNow());

describe('the policy', function () {
    it('keeps everything by default', function () {
        $a = agedRow('record', 'updated', 'invoice', 9000);
        $b = agedRow('auth', 'login', null, 9000);
        $c = agedRow('action', 'sent', 'invoice', 9000);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(keptIds())->toBe([$a, $b, $c]);
    });

    it('prunes each category at its own cutoff', function () {
        config(['audit.retention' => ['record' => '1 year', 'action' => '30 days', 'auth' => '6 months', 'overrides' => []]]);

        $oldRecord = agedRow('record', 'updated', 'invoice', 400);
        $newRecord = agedRow('record', 'updated', 'invoice', 300);
        $oldAction = agedRow('action', 'sent', 'invoice', 31);
        $newAction = agedRow('action', 'sent', 'invoice', 29);
        $oldAuth = agedRow('auth', 'login', null, 200);
        $newAuth = agedRow('auth', 'login', null, 100);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(keptIds())->toBe([$newRecord, $newAction, $newAuth])
            ->and(DB::table('audits')->whereIn('id', [$oldRecord, $oldAction, $oldAuth])->count())->toBe(0);
    });

    it('keeps a category whose retention is null while pruning the others', function () {
        config(['audit.retention' => ['record' => null, 'action' => '30 days']]);

        $record = agedRow('record', 'updated', 'invoice', 9000);
        agedRow('action', 'sent', 'invoice', 40);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(keptIds())->toBe([$record]);
    });

    it('lets the first matching override win', function () {
        config(['audit.retention' => ['record' => null, 'overrides' => [
            ['subject_type' => 'attachment', 'event' => 'created', 'keep' => '90 days'],
            ['subject_type' => 'attachment', 'keep' => '1 year'],
        ]]]);

        $createdOld = agedRow('record', 'created', 'attachment', 100);   // first override: 90 days, so gone
        $updatedOld = agedRow('record', 'updated', 'attachment', 100);   // only the second: 1 year, so kept
        $updatedAncient = agedRow('record', 'updated', 'attachment', 400);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(keptIds())->toBe([$updatedOld])
            ->and(DB::table('audits')->whereIn('id', [$createdOld, $updatedAncient])->count())->toBe(0);
    });

    it('reverses the outcome when the overrides are listed the other way round', function () {
        config(['audit.retention' => ['record' => null, 'overrides' => [
            ['subject_type' => 'attachment', 'keep' => '1 year'],
            ['subject_type' => 'attachment', 'event' => 'created', 'keep' => '90 days'],
        ]]]);

        $createdOld = agedRow('record', 'created', 'attachment', 100);   // the broad override wins: 1 year, kept

        $this->artisan('audit:prune')->assertSuccessful();

        expect(keptIds())->toBe([$createdOld]);
    });

    it('lets an override keep rows longer than their category', function () {
        config(['audit.retention' => ['record' => '1 year', 'overrides' => [
            ['subject_type' => 'invoice', 'keep' => '5 years'],
        ]]]);

        $invoice = agedRow('record', 'updated', 'invoice', 800);
        agedRow('record', 'updated', 'order', 800);
        $recentOrder = agedRow('record', 'updated', 'order', 100);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(keptIds())->toBe([$invoice, $recentOrder]);
    });

    it('lets an override that keeps forever shield its rows from the category', function () {
        config(['audit.retention' => ['record' => '1 year', 'overrides' => [
            ['subject_type' => 'invoice', 'event' => 'voided', 'keep' => null],
        ]]]);

        $voided = agedRow('record', 'voided', 'invoice', 9000);
        agedRow('record', 'updated', 'invoice', 9000);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(keptIds())->toBe([$voided]);
    });

    it('lets an override prune sooner than its category', function () {
        config(['audit.retention' => ['auth' => '2 years', 'overrides' => [
            ['category' => 'auth', 'event' => 'login_failed', 'keep' => '1 year'],
        ]]]);

        agedRow('auth', 'login_failed', null, 400);
        $login = agedRow('auth', 'login', null, 400);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(keptIds())->toBe([$login]);
    });

    it('treats a row with no subject as not matching an override on the subject', function () {
        // `NOT (subject_type = 'attachment' AND event = 'created')` is NULL for a row with no subject,
        // and a NULL would keep a row the category rule should delete.
        config(['audit.retention' => ['auth' => '1 year', 'overrides' => [
            ['subject_type' => 'attachment', 'event' => 'created', 'keep' => '90 days'],
        ]]]);

        $kept = agedRow('auth', 'created', null, 100);
        agedRow('auth', 'created', null, 400);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(keptIds())->toBe([$kept]);
    });

    it('deletes in chunks of prune.chunk', function () {
        config(['audit.retention.record' => '30 days', 'audit.prune.chunk' => 3]);

        foreach (range(1, 10) as $i) {
            agedRow('record', 'updated', 'invoice', 60);
        }

        $kept = agedRow('record', 'updated', 'invoice', 1);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with($query->sql, 'delete')) {
                $queries[] = $query->sql;
            }
        });

        $this->artisan('audit:prune')->assertSuccessful();

        expect(keptIds())->toBe([$kept])
            ->and($queries)->toHaveCount(4)   // 3 + 3 + 3 + 1
            ->and(pruneRows()[0]->meta['rules'][0]['deleted'])->toBe(10);
    });

    it('rejects a retention period it cannot read, and an override that would match too much', function (array $retention, string $message) {
        config(['audit.retention' => $retention]);

        expect(fn () => RetentionPolicy::fromConfig())->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'a period' => [['record' => 'sometime'], 'not a period'],
        'an override without a keep' => [['overrides' => [['subject_type' => 'invoice']]], 'needs a keep'],
        'an override without a criterion' => [['overrides' => [['keep' => '1 year']]], 'at least one of'],
        'a misspelt criterion' => [['overrides' => [['subject_type' => 'invoice', 'evnt' => 'created', 'keep' => '1 year']]], 'unknown keys'],
        'a category that does not exist' => [['overrides' => [['category' => 'nope', 'keep' => '1 year']]], 'must be one of'],
    ]);

    it('has its prune command stop with the reason when the config is wrong', function () {
        config(['audit.retention' => ['record' => 'sometime']]);

        agedRow('record', 'updated', 'invoice', 9000);

        $this->artisan('audit:prune')->assertFailed();

        expect(DB::table('audits')->count())->toBe(1);
    });
});

describe('audit:prune', function () {
    it('writes one audits_pruned row with each rule, its cutoff and its count', function () {
        config(['audit.retention' => ['record' => '1 year', 'action' => null, 'auth' => '6 months', 'overrides' => [
            ['subject_type' => 'attachment', 'event' => 'created', 'keep' => '90 days'],
        ]]]);

        agedRow('record', 'updated', 'invoice', 400);
        agedRow('record', 'updated', 'invoice', 500);
        agedRow('record', 'created', 'attachment', 100);
        agedRow('auth', 'login', null, 400);

        $this->artisan('audit:prune')->assertSuccessful();

        $row = pruneRows();

        expect($row)->toHaveCount(1)
            ->and($row[0]->category)->toBe('action')
            ->and($row[0]->event)->toBe('audits_pruned')
            ->and($row[0]->subject_type)->toBeNull()
            ->and($row[0]->meta)->toEqual([
                'command' => 'prune',
                'rules' => [
                    ['override' => ['subject_type' => 'attachment', 'event' => 'created', 'keep' => '90 days'], 'cutoff' => '2026-08-27T12:00:00+00:00', 'deleted' => 1],
                    ['category' => 'record', 'cutoff' => '2025-11-25T12:00:00+00:00', 'deleted' => 2],
                    ['category' => 'auth', 'cutoff' => '2026-05-25T12:00:00+00:00', 'deleted' => 1],
                    ['category' => 'action', 'cutoff' => null, 'deleted' => 0],
                ],
            ]);
    });

    it('writes the row even when nothing was deleted, as a heartbeat', function () {
        $this->artisan('audit:prune')->assertSuccessful();
        $this->artisan('audit:prune')->assertSuccessful();

        $rows = pruneRows();

        expect($rows)->toHaveCount(2)
            ->and(collect($rows[0]->meta['rules'])->pluck('deleted')->sum())->toBe(0)
            ->and(collect($rows[0]->meta['rules'])->pluck('cutoff')->filter()->all())->toBe([]);
    });

    it('prunes its own old heartbeat rows like any other', function () {
        config(['audit.retention.action' => '1 day']);

        $this->artisan('audit:prune')->assertSuccessful();

        Carbon::setTestNow(now()->addDays(2));

        $this->artisan('audit:prune')->assertSuccessful();

        // The first run's heartbeat is two days old, so the second run prunes it and writes its own.
        expect(pruneRows())->toHaveCount(1)
            ->and(pruneRows()[0]->meta['rules'][2]['deleted'])->toBe(1);
    });

    it('with --pretend deletes nothing and writes no row', function () {
        config(['audit.retention.record' => '1 year']);

        agedRow('record', 'updated', 'invoice', 500);

        $this->artisan('audit:prune', ['--pretend' => true])
            ->expectsOutputToContain('1 audit rows would be deleted')
            ->assertSuccessful();

        expect(DB::table('audits')->count())->toBe(1)
            ->and(pruneRows())->toBe([]);
    });

    it('never fires the model events, so immutability holds', function () {
        config(['audit.retention.record' => '1 year']);

        $fired = false;
        Event::listen('eloquent.deleting: '.Audit::class, function () use (&$fired) {
            $fired = true;
        });

        agedRow('record', 'updated', 'invoice', 500);

        // The model still refuses to be deleted through an instance.
        expect(fn () => Audit::query()->firstOrFail()->delete())->toThrow(ImmutableAuditException::class);

        $fired = false;
        $this->artisan('audit:prune')->assertSuccessful();

        expect($fired)->toBeFalse()
            ->and(DB::table('audits')->where('event', 'updated')->count())->toBe(0);
    });

    it('is not blocked by a tenant read scope on the model', function () {
        $this->useScopes(['team_id' => ['resolver' => fn () => null, 'read' => fn () => 99]]);
        config(['audit.retention.record' => '1 year']);

        agedRow('record', 'updated', 'invoice', 500, ['team_id' => 5]);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(DB::table('audits')->where('event', 'updated')->count())->toBe(0);
    });

    it('is the only prune path: model:prune never touches audit rows', function () {
        config(['audit.retention' => ['record' => '1 day', 'action' => '1 day', 'auth' => '1 day']]);

        agedRow('record', 'updated', 'invoice', 500);

        Artisan::call('model:prune', ['--model' => [Audit::class]]);
        Artisan::call('model:prune');

        expect(DB::table('audits')->count())->toBe(1)
            ->and(method_exists(Audit::class, 'prunable'))->toBeFalse()
            ->and(class_uses_recursive(Audit::class))->not->toHaveKey(Prunable::class)
            ->and(class_uses_recursive(Audit::class))->not->toHaveKey(MassPrunable::class);
    });
});

describe('the schedule', function () {
    function pruneEvents(): array
    {
        app()->forgetInstance(Schedule::class);

        return array_values(array_filter(app(Schedule::class)->events(), fn ($event) => str_contains($event->command, 'audit:prune')));
    }

    it('is registered daily by default, without overlapping', function () {
        $events = pruneEvents();

        expect($events)->toHaveCount(1)
            ->and($events[0]->expression)->toBe('0 0 * * *')
            ->and($events[0]->withoutOverlapping)->toBeTrue();
    });

    it('takes a frequency method', function () {
        config(['audit.prune.schedule' => 'hourly']);

        expect(pruneEvents()[0]->expression)->toBe('0 * * * *');
    });

    it('takes a cron expression', function () {
        config(['audit.prune.schedule' => '15 3 * * 1']);

        expect(pruneEvents()[0]->expression)->toBe('15 3 * * 1');
    });

    it('is not registered when the schedule is false either', function () {
        config(['audit.prune.schedule' => false]);

        expect(pruneEvents())->toBe([]);

        $this->artisan('audit:prune')->assertSuccessful();
    });

    it('is not registered when the schedule is null', function () {
        config(['audit.prune.schedule' => null]);

        expect(pruneEvents())->toBe([]);
    });

    it('reports a schedule that is not a frequency method with no arguments or a cron expression, and registers nothing', function (string $schedule) {
        Exceptions::fake();
        config(['audit.prune.schedule' => $schedule]);

        // The error must not stop the scheduler resolving: other tasks keep running.
        expect(pruneEvents())->toBe([]);

        Exceptions::assertReported(InvalidArgumentException::class);
    })->with([
        'a method that needs an argument' => 'dailyAt',
        'a method that is not a frequency' => 'run',
        'a word that is nothing' => 'nightly',
        'a broken cron' => '99 99 * * *',
    ]);

    it('has audit:prune fail loudly on a schedule it cannot read, before deleting anything', function () {
        config(['audit.prune.schedule' => 'nightly', 'audit.retention.record' => '1 year']);

        agedRow('record', 'updated', 'invoice', 500);

        $this->artisan('audit:prune')->expectsOutputToContain('audit.prune.schedule [nightly]')->assertFailed();

        expect(DB::table('audits')->count())->toBe(1);
    });

    it('runs on one server only when asked, and not otherwise', function () {
        expect(pruneEvents()[0]->onOneServer)->toBeFalse();

        config(['audit.prune.on_one_server' => true]);

        expect(pruneEvents()[0]->onOneServer)->toBeTrue();
    });
});
