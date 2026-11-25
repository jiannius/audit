<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Jiannius\Audit\Audit;
use Jiannius\Audit\Retention\RetentionPolicy;
use Jiannius\Audit\Tests\Fixtures\Attachment;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 12:00:00'));
    app()->detectEnvironment(fn () => 'local');
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/** The rule for the `record` category, as the policy reads it from this config. */
function recordCutoff(mixed $keep): ?string
{
    config(['audit.retention' => ['record' => $keep]]);

    return collect(RetentionPolicy::fromConfig()->rules())->first(fn ($rule) => ($rule->criteria['category'] ?? null) === 'record')->cutoff?->toDateTimeString();
}

describe('periods', function () {
    it('reads a positive period, in any of the units', function (mixed $keep, ?string $cutoff) {
        expect(recordCutoff($keep))->toBe($cutoff);
    })->with([
        'seconds' => ['30 seconds', '2026-11-25 11:59:30'],
        'a singular unit' => ['1 minute', '2026-11-25 11:59:00'],
        'hours' => ['3 hours', '2026-11-25 09:00:00'],
        'days' => ['90 days', '2026-08-27 12:00:00'],
        'weeks' => ['2 weeks', '2026-11-11 12:00:00'],
        'months' => ['6 months', '2026-05-25 12:00:00'],
        'years' => ['7 years', '2019-11-25 12:00:00'],
        'a sequence' => ['1 year 6 months', '2025-05-25 12:00:00'],
        'upper case and spacing' => [' 90  DAYS ', '2026-08-27 12:00:00'],
        'an ISO duration' => ['P90D', '2026-08-27 12:00:00'],
        'an ISO duration with a time' => ['PT36H', '2026-11-24 00:00:00'],
        'a longer ISO duration' => ['P1Y6M', '2025-05-25 12:00:00'],
        'ISO weeks' => ['P1W', '2026-11-18 12:00:00'],
        'null, which keeps forever' => [null, null],
    ]);

    it('refuses a period that is zero, negative, relative or not a period, naming the config path', function (mixed $keep) {
        config(['audit.retention' => ['record' => $keep]]);

        expect(fn () => RetentionPolicy::fromConfig())->toThrow(InvalidArgumentException::class, 'audit.retention.record');
    })->with([
        'zero days' => '0 days',
        'a zero ISO duration' => 'P0D',
        'a zero ISO time' => 'PT0S',
        'a negative number' => '-5 days',
        'a past tense' => '90 days ago',
        'yesterday' => 'yesterday',
        'tomorrow' => 'tomorrow',
        'next week' => 'next week',
        'now' => 'now',
        'a date' => '2020-01-01',
        'a zero in a sequence' => '1 year 0 days',
        'a fraction' => '1.5 days',
        'a bare number' => '5',
        'an abbreviation' => '5 d',
        'an empty string' => '',
        'words' => 'banana',
        'an integer' => 30,
        'true' => true,
        'a bare P' => 'P',
        'a negative ISO duration' => '-P5D',
        'a huge number' => '99999999999 years',
        'a zero interval object' => new DateInterval('PT0S'),
    ]);

    it('refuses a bad period in an override too, with its path', function () {
        config(['audit.retention' => ['overrides' => [['event' => 'login', 'keep' => '0 days']]]]);

        expect(fn () => RetentionPolicy::fromConfig())->toThrow(InvalidArgumentException::class, 'audit.retention.overrides.0.keep');
    });

    it('refuses a negative interval object', function () {
        $interval = new DateInterval('P5D');
        $interval->invert = 1;

        config(['audit.retention' => ['record' => $interval]]);

        expect(fn () => RetentionPolicy::fromConfig())->toThrow(InvalidArgumentException::class);
    });

    it('leaves the table alone when a period is wrong, in prune and purge alike', function () {
        config(['audit.retention' => ['record' => 'yesterday']]);

        $id = DB::table('audits')->insertGetId(['category' => 'record', 'event' => 'updated', 'created_at' => '2026-11-25 11:00:00']);

        $this->artisan('audit:prune')->assertFailed();
        $this->artisan('audit:purge', ['--before' => '2026-11-26', '--category' => 'record', '--below-retention' => true])->assertFailed();

        expect(DB::table('audits')->pluck('id')->all())->toBe([$id]);
    });
});

describe('the config keys', function () {
    it('refuses a top-level key it does not know', function (array $retention, string $key) {
        config(['audit.retention' => $retention]);

        expect(fn () => RetentionPolicy::fromConfig())->toThrow(InvalidArgumentException::class, $key);
    })->with([
        'overrides misspelt' => [['override' => [['event' => 'x', 'keep' => null]]], 'override'],
        'a category misspelt' => [['recrod' => '1 year'], 'recrod'],
    ]);

    it('takes a model class for an override subject type, as --type does', function () {
        config(['audit.retention' => ['record' => '30 days', 'overrides' => [['subject_type' => Attachment::class, 'keep' => null]]]]);

        $kept = DB::table('audits')->insertGetId(['category' => 'record', 'event' => 'updated', 'subject_type' => 'attachment', 'subject_id' => '1', 'created_at' => '2020-01-01 00:00:00']);
        DB::table('audits')->insert(['category' => 'record', 'event' => 'updated', 'subject_type' => 'invoice', 'subject_id' => '1', 'created_at' => '2020-01-01 00:00:00']);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(DB::table('audits')->where('event', 'updated')->pluck('id')->all())->toBe([$kept]);
    });

    it('judges a purge by the alias an override class name stands for', function () {
        config(['audit.retention' => ['record' => '30 days', 'overrides' => [['subject_type' => Attachment::class, 'keep' => null]]]]);

        $this->artisan('audit:purge', ['--before' => '2020-01-01', '--type' => 'attachment'])
            ->expectsOutputToContain('forever')
            ->assertFailed();
    });
});

describe('case and spacing in a purge filter', function () {
    // MySQL's default collation matches `VOIDED` to `voided`, so a filter in another case reaches the rows
    // of an override that keeps them forever. It is judged the same on every database.
    it('is judged against the rule it can reach whatever its case', function (string $option, string $value) {
        config(['audit.retention' => ['record' => '30 days', 'overrides' => [['subject_type' => 'invoice', 'event' => 'voided', 'keep' => null]]]]);

        $id = DB::table('audits')->insertGetId(['category' => 'record', 'event' => 'voided', 'subject_type' => 'invoice', 'subject_id' => '1', 'created_at' => '2020-01-01 00:00:00']);

        $options = ['--before' => '2021-01-01', $option => $value] + ($option === '--type' ? ['--event' => 'voided'] : ['--type' => 'invoice']);

        $this->artisan('audit:purge', $options)->expectsOutputToContain('forever')->assertFailed();

        expect(DB::table('audits')->pluck('id')->all())->toBe([$id]);
    })->with([
        'an upper-case event' => ['--event', 'VOIDED'],
        'a mixed-case event' => ['--event', 'Voided'],
        'a padded event' => ['--event', ' voided '],
        'an upper-case type' => ['--type', 'INVOICE'],
    ]);

    it('still reaches the category for a different value', function () {
        config(['audit.retention' => ['record' => '30 days', 'action' => '30 days', 'auth' => '30 days', 'overrides' => [['subject_type' => 'invoice', 'event' => 'voided', 'keep' => null]]]]);

        // `sent` is not `voided` in any case: the override is out of reach, and the 30 days apply.
        $this->artisan('audit:purge', ['--before' => '2026-10-01', '--event' => 'SENT', '--type' => 'invoice'])->assertSuccessful();
    });
});

describe('the purge date', function () {
    beforeEach(function () {
        config(['audit.retention' => ['record' => '30 days']]);   // the cutoff is 2026-10-26 12:00:00
    });

    it('is converted to the application timezone before it is checked, used and recorded', function () {
        $inside = DB::table('audits')->insertGetId(['category' => 'record', 'event' => 'updated', 'created_at' => '2026-10-26 11:00:00']);
        $outside = DB::table('audits')->insertGetId(['category' => 'record', 'event' => 'updated', 'created_at' => '2026-10-26 09:00:00']);

        // 15:00 at +05:00 is 10:00 UTC, within the cutoff. Read as the wall clock 15:00, it would reach 11:00 UTC, inside retention.
        $this->artisan('audit:purge', ['--before' => '2026-10-26T15:00:00+05:00', '--category' => 'record'])->assertSuccessful();

        expect(DB::table('audits')->where('event', 'updated')->pluck('id')->all())->toBe([$inside])
            ->and(Audit::query()->where('event', 'audits_pruned')->first()->meta['before'])->toBe('2026-10-26T10:00:00+00:00')
            ->and($outside)->toBeInt();
    });

    it('refuses a date in another timezone that is past the cutoff as an instant', function () {
        $id = DB::table('audits')->insertGetId(['category' => 'record', 'event' => 'updated', 'created_at' => '2026-10-26 12:00:00']);

        // 12:30 UTC is after the 12:00 cutoff, whatever way it is written; 17:00 at +05:00 is the cutoff itself.
        $this->artisan('audit:purge', ['--before' => '2026-10-26T07:30:00-05:00', '--category' => 'record'])->assertFailed();
        $this->artisan('audit:purge', ['--before' => '2026-10-26T17:00:00+05:00', '--category' => 'record'])->assertSuccessful();

        expect(DB::table('audits')->where('event', 'updated')->pluck('id')->all())->toBe([$id]);
    });

    it('converts to a non-UTC application timezone', function () {
        config(['app.timezone' => 'Asia/Kuala_Lumpur']);
        date_default_timezone_set('Asia/Kuala_Lumpur');
        Carbon::setTestNow(Carbon::parse('2026-11-25 20:00:00'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 20:00:00'));

        try {
            $this->artisan('audit:purge', ['--before' => '2026-10-26T12:00:00+00:00', '--category' => 'record', '--below-retention' => true])->assertSuccessful();

            expect(Audit::query()->where('event', 'audits_pruned')->first()->meta['before'])->toBe('2026-10-26T20:00:00+08:00');
        } finally {
            date_default_timezone_set('UTC');
        }
    });
});

describe('the boundary', function () {
    it('keeps a row created exactly at the cutoff, and prunes the second before', function () {
        config(['audit.retention' => ['record' => '30 days']]);

        $exact = DB::table('audits')->insertGetId(['category' => 'record', 'event' => 'updated', 'created_at' => '2026-10-26 12:00:00']);
        DB::table('audits')->insert(['category' => 'record', 'event' => 'updated', 'created_at' => '2026-10-26 11:59:59']);

        $this->artisan('audit:prune')->assertSuccessful();

        expect(DB::table('audits')->where('event', 'updated')->pluck('id')->all())->toBe([$exact]);
    });

    it('keeps a row created exactly at --before', function () {
        config(['audit.retention' => ['record' => '30 days']]);

        $exact = DB::table('audits')->insertGetId(['category' => 'record', 'event' => 'updated', 'created_at' => '2026-09-01 00:00:00']);
        DB::table('audits')->insert(['category' => 'record', 'event' => 'updated', 'created_at' => '2026-08-31 23:59:59']);

        $this->artisan('audit:purge', ['--before' => '2026-09-01', '--category' => 'record'])->assertSuccessful();

        expect(DB::table('audits')->where('event', 'updated')->pluck('id')->all())->toBe([$exact]);
    });
});

describe('a run that fails part-way', function () {
    /** Make the nth delete statement throw, like a lost connection or a deadlock would. */
    function failOnDelete(int $nth): void
    {
        $seen = 0;

        DB::listen(function ($query) use (&$seen, $nth) {
            if (str_starts_with($query->sql, 'delete') && ++$seen === $nth) {
                throw new RuntimeException('boom with secret-value');
            }
        });
    }

    it('leaves a row with what it had deleted, the failure and no message, then rethrows', function () {
        config(['audit.retention' => ['record' => '30 days', 'auth' => '30 days'], 'audit.prune.chunk' => 2]);

        foreach (range(1, 5) as $i) {
            agedRow('record', 'updated', 'invoice', 60);
        }

        agedRow('auth', 'login', null, 60);

        failOnDelete(3);   // two chunks of the record rule go, the third fails

        expect(fn () => Artisan::call('audit:prune'))->toThrow(RuntimeException::class);

        $row = Audit::query()->where('event', 'audits_pruned')->sole();

        expect($row->meta)->toEqual([
            'command' => 'prune',
            'rules' => [['category' => 'record', 'cutoff' => '2026-10-26T12:00:00+00:00', 'deleted' => 4]],
            'failed' => true,
            'exception' => RuntimeException::class,
        ])
            ->and(json_encode($row->getAttributes()))->not->toContain('secret-value')
            // The rule after the failing one never ran. (The failing statement itself had already executed when
            // the listener threw, so the fifth row is gone too, and the count is what was confirmed.)
            ->and(DB::table('audits')->where('event', 'login')->count())->toBe(1);
    });

    it('does the same for a purge', function () {
        config(['audit.prune.chunk' => 2]);

        foreach (range(1, 5) as $i) {
            agedRow('record', 'updated', 'invoice', 60);
        }

        failOnDelete(2);

        expect(fn () => Artisan::call('audit:purge', ['--before' => '2026-11-01', '--category' => 'record', '--below-retention' => true]))->toThrow(RuntimeException::class);

        $row = Audit::query()->where('event', 'audits_pruned')->sole();

        expect($row->meta)->toEqual([
            'command' => 'purge',
            'filters' => ['category' => 'record'],
            'before' => '2026-11-01T00:00:00+00:00',
            'deleted' => 2,
            'below_retention' => true,
            'forced' => false,
            'failed' => true,
            'exception' => RuntimeException::class,
        ]);
    });

    it('writes no failed flag on a run that went well', function () {
        $this->artisan('audit:prune')->assertSuccessful();

        expect(Audit::query()->where('event', 'audits_pruned')->sole()->meta)->not->toHaveKeys(['failed', 'exception']);
    });
});

describe('with auditing turned off', function () {
    it('refuses to prune or purge, since the run could not be recorded', function () {
        config(['audit.enabled' => false, 'audit.retention' => ['record' => '30 days']]);

        $id = DB::table('audits')->insertGetId(['category' => 'record', 'event' => 'updated', 'created_at' => '2020-01-01 00:00:00']);

        $this->artisan('audit:prune')->expectsOutputToContain('audit.enabled is false')->assertFailed();
        $this->artisan('audit:prune', ['--pretend' => true])->assertFailed();
        $this->artisan('audit:purge', ['--before' => '2021-01-01', '--category' => 'record', '--below-retention' => true])->expectsOutputToContain('audit.enabled is false')->assertFailed();

        expect(DB::table('audits')->pluck('id')->all())->toBe([$id]);
    });
});
