<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Jiannius\Audit\Audit;
use Jiannius\Audit\Tests\Fixtures\Attachment;

/** A purge as a developer runs it: in the local environment, which is the only one that needs no --force. */
function purge(array $options): PendingCommand
{
    return test()->artisan('audit:purge', $options);
}

function purgeRow(): ?Audit
{
    return Audit::query()->where('event', 'audits_pruned')->first();
}

function purgeableRow(string $category, string $event, ?string $type, int|float $daysOld): int
{
    return DB::table('audits')->insertGetId([
        'category' => $category,
        'event' => $event,
        'subject_type' => $type,
        'subject_id' => $type === null ? null : '1',
        'created_at' => now()->subDays($daysOld)->format('Y-m-d H:i:s'),
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00'));
    app()->detectEnvironment(fn () => 'local');
});

afterEach(fn () => Carbon::setTestNow());

describe('what it needs', function () {
    it('refuses without --before', function () {
        purgeableRow('record', 'updated', 'invoice', 500);

        purge(['--type' => 'invoice', '--below-retention' => true])
            ->expectsOutputToContain('--before option is required')
            ->assertFailed();

        expect(DB::table('audits')->count())->toBe(1);
    });

    it('refuses without a filter, however forceful', function () {
        purgeableRow('record', 'updated', 'invoice', 500);

        purge(['--before' => '2026-01-01', '--force' => true, '--below-retention' => true])
            ->expectsOutputToContain('at least one of --type, --event or --category')
            ->assertFailed();

        expect(DB::table('audits')->count())->toBe(1);
    });

    it('refuses a date it cannot read and a category that does not exist', function () {
        purge(['--before' => 'not a date', '--type' => 'invoice', '--below-retention' => true])->assertFailed();
        purge(['--before' => '2026-01-01', '--category' => 'nope', '--below-retention' => true])->assertFailed();

        expect(purgeRow())->toBeNull();
    });

    it('refuses outside the local environment without --force', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['audit.retention.record' => '1 year']);

        purgeableRow('record', 'updated', 'invoice', 500);

        purge(['--before' => '2025-11-01', '--category' => 'record'])
            ->expectsOutputToContain('without --force')
            ->assertFailed();

        expect(DB::table('audits')->count())->toBe(1);

        purge(['--before' => '2025-11-01', '--category' => 'record', '--force' => true])->assertSuccessful();

        expect(DB::table('audits')->where('event', 'updated')->count())->toBe(0);
    });

    it('never lets --force cut below retention', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['audit.retention.record' => '1 year']);

        $recent = purgeableRow('record', 'updated', 'invoice', 100);

        purge(['--before' => '2026-11-01', '--category' => 'record', '--force' => true])
            ->expectsOutputToContain('--below-retention')
            ->assertFailed();

        expect(DB::table('audits')->pluck('id')->all())->toBe([$recent])
            ->and(purgeRow())->toBeNull();
    });

    it('cuts below retention with --below-retention', function () {
        config(['audit.retention.record' => '1 year']);

        purgeableRow('record', 'updated', 'invoice', 100);

        purge(['--before' => '2026-11-01', '--category' => 'record', '--below-retention' => true])->assertSuccessful();

        expect(DB::table('audits')->where('event', 'updated')->count())->toBe(0);
    });

    it('treats a null retention as forever, so it needs --below-retention too', function () {
        purgeableRow('record', 'updated', 'invoice', 5000);

        purge(['--before' => '2020-01-01', '--category' => 'record', '--force' => true])
            ->expectsOutputToContain('keeps rows matching this filter forever')
            ->assertFailed();

        expect(DB::table('audits')->count())->toBe(1);

        purge(['--before' => '2020-01-01', '--category' => 'record', '--below-retention' => true])->assertSuccessful();

        expect(DB::table('audits')->where('event', 'updated')->count())->toBe(0);
    });

    it('runs with no flag at all when the cut is within retention', function () {
        config(['audit.retention.record' => '1 year']);

        purgeableRow('record', 'updated', 'invoice', 500);
        $recent = purgeableRow('record', 'updated', 'invoice', 100);

        purge(['--before' => '2025-11-01', '--category' => 'record'])->assertSuccessful();

        expect(DB::table('audits')->where('event', 'updated')->pluck('id')->all())->toBe([$recent]);
    });
});

describe('configured retention is the longest of the rules the filter could match', function () {
    beforeEach(function () {
        config(['audit.retention' => ['record' => '7 years', 'action' => '3 years', 'auth' => '2 years', 'overrides' => [
            ['subject_type' => 'attachment', 'event' => 'created', 'keep' => '90 days'],
            ['category' => 'auth', 'event' => 'login_failed', 'keep' => '1 year'],
        ]]]);
    });

    it('is the override alone when the filter pins exactly what the override matches', function () {
        purgeableRow('record', 'created', 'attachment', 100);

        purge(['--before' => '2026-08-20', '--type' => 'attachment', '--event' => 'created'])->assertSuccessful();

        expect(DB::table('audits')->where('event', 'created')->count())->toBe(0);
    });

    it('refuses to cut an override below its own retention', function () {
        purge(['--before' => '2026-10-01', '--type' => 'attachment', '--event' => 'created'])
            ->expectsOutputToContain('later than the configured retention cutoff')
            ->assertFailed();
    });

    it('includes the category default when the filter is wider than the override', function () {
        // Every other attachment row falls under record: 7 years.
        purge(['--before' => '2026-08-01', '--type' => 'attachment'])->assertFailed();
        purge(['--before' => '2019-01-01', '--type' => 'attachment'])->assertSuccessful();
    });

    it('is the category default for a filter that no override can match', function () {
        purgeableRow('auth', 'login', null, 800);

        purge(['--before' => '2025-01-01', '--category' => 'auth'])->assertFailed();         // login_failed keeps 1 year, login 2 years
        purge(['--before' => '2024-11-01', '--category' => 'auth'])->assertSuccessful();

        expect(DB::table('audits')->where('event', 'login')->count())->toBe(0);
    });

    it('is forever when a rule that could match keeps rows forever', function () {
        config(['audit.retention.action' => null]);

        // The filter by event alone could match action rows, which are kept forever.
        purge(['--before' => '2000-01-01', '--event' => 'sent'])
            ->expectsOutputToContain('forever')
            ->assertFailed();
    });

    it('is not widened by rules the filter cannot reach', function () {
        // record is 7 years, but a filter on the auth category cannot match record rows.
        purge(['--before' => '2024-06-01', '--category' => 'auth'])->assertSuccessful();
    });
});

describe('what it deletes', function () {
    it('deletes only the rows matching every filter, before the date', function () {
        config(['audit.retention' => ['record' => null, 'action' => null, 'auth' => null]]);

        purgeableRow('record', 'created', 'attachment', 100);
        $other = [
            purgeableRow('record', 'updated', 'attachment', 100),   // another event
            purgeableRow('record', 'created', 'invoice', 100),      // another type
            purgeableRow('action', 'created', 'attachment', 100),   // another category
            purgeableRow('record', 'created', 'attachment', 10),    // too recent
        ];

        purge(['--before' => '2026-11-01', '--type' => 'attachment', '--event' => 'created', '--category' => 'record', '--below-retention' => true])->assertSuccessful();

        expect(DB::table('audits')->where('event', '<>', 'audits_pruned')->orderBy('id')->pluck('id')->all())->toBe($other);
    });

    it('takes a model class for --type', function () {
        config(['audit.retention' => ['record' => null]]);

        purgeableRow('record', 'created', 'attachment', 100);

        purge(['--before' => '2026-11-01', '--type' => Attachment::class, '--below-retention' => true])->assertSuccessful();

        expect(DB::table('audits')->where('event', 'created')->count())->toBe(0);
    });

    it('deletes in chunks of prune.chunk', function () {
        config(['audit.prune.chunk' => 2]);

        foreach (range(1, 5) as $i) {
            purgeableRow('record', 'created', 'attachment', 100);
        }

        purge(['--before' => '2026-11-01', '--type' => 'attachment', '--below-retention' => true])->assertSuccessful();

        expect(purgeRow()->meta['deleted'])->toBe(5);
    });

    it('with --pretend deletes nothing and writes no row', function () {
        purgeableRow('record', 'created', 'attachment', 100);

        purge(['--before' => '2026-11-01', '--type' => 'attachment', '--below-retention' => true, '--pretend' => true])
            ->expectsOutputToContain('1 audit rows would be deleted')
            ->assertSuccessful();

        expect(DB::table('audits')->count())->toBe(1)
            ->and(purgeRow())->toBeNull();
    });

    it('applies its refusals to --pretend too, so a dry run says what the real one needs', function () {
        purgeableRow('record', 'created', 'attachment', 100);

        purge(['--before' => '2026-11-01', '--type' => 'attachment', '--pretend' => true])->assertFailed();
    });

    it('writes exactly one audits_pruned row with the filters, the date and the flags', function () {
        app()->detectEnvironment(fn () => 'staging');

        purgeableRow('record', 'created', 'attachment', 100);
        purgeableRow('record', 'created', 'attachment', 200);

        purge(['--before' => '2026-11-01', '--type' => 'attachment', '--event' => 'created', '--force' => true, '--below-retention' => true])->assertSuccessful();

        $rows = Audit::query()->where('event', 'audits_pruned')->get();

        expect($rows)->toHaveCount(1)
            ->and($rows[0]->category)->toBe('action')
            ->and($rows[0]->subject_type)->toBeNull()
            ->and($rows[0]->meta)->toEqual([
                'command' => 'purge',
                'filters' => ['subject_type' => 'attachment', 'event' => 'created'],
                'before' => '2026-11-01T00:00:00+00:00',
                'deleted' => 2,
                'below_retention' => true,
                'forced' => true,
            ]);
    });

    it('records the flags that were not given as false', function () {
        config(['audit.retention.record' => '1 year']);

        purge(['--before' => '2025-11-01', '--category' => 'record'])->assertSuccessful();

        expect(purgeRow()->meta)->toEqual([
            'command' => 'purge',
            'filters' => ['category' => 'record'],
            'before' => '2025-11-01T00:00:00+00:00',
            'deleted' => 0,
            'below_retention' => false,
            'forced' => false,
        ]);
    });
});
