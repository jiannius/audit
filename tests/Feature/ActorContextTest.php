<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Tests\Fixtures\Invoice;
use Jiannius\Audit\Tests\Fixtures\RecordActorJob;
use Jiannius\Audit\Tests\Fixtures\User;

describe('web', function () {
    it('records the signed-in user, the source, the request id, the ip and the user agent', function () {
        $user = makeUser();

        $this->actingAs($user)->withHeaders(['User-Agent' => 'AuditTest/1.0'])->get('/touch')->assertOk();

        $audit = $this->audits()->sole();

        expect($audit->event)->toBe('touched')
            ->and($audit->category)->toBe('action')
            ->and($audit->actor_type)->toBe('user')
            ->and($audit->actor_id)->toBe((string) $user->id)
            ->and($audit->actor_name)->toBe('Ada Lovelace')
            ->and($audit->actor_identifier)->toBe('ada@example.test')
            ->and($audit->source)->toBe('web')
            ->and($audit->ip_address)->toBe('127.0.0.1')
            ->and($audit->user_agent)->toBe('AuditTest/1.0')
            ->and($audit->request_id)->toHaveLength(26);
    });

    it('records nobody for a guest', function () {
        $this->get('/touch')->assertOk();

        $audit = $this->audits()->sole();

        expect($audit->actor_type)->toBeNull()
            ->and($audit->actor_id)->toBeNull()
            ->and($audit->source)->toBe('web');
    });

    it('gives each request its own request id', function () {
        $this->get('/touch');
        $this->app->forgetScopedInstances(); // what a fresh PHP process, or Octane between requests, does
        $this->get('/touch');

        expect($this->audits()->pluck('request_id')->unique())->toHaveCount(2);
    });

    it('reuses a request id the host already keeps in context', function () {
        config(['audit.request_id.context_key' => 'trace_id']);
        Context::add('trace_id', 'trace-123');

        Auditor::record('sent');

        expect($this->audits()->sole()->request_id)->toBe('trace-123');
    });
});

describe('console', function () {
    beforeEach(function () {
        $this->rerouteConsoleEvents();
    });

    it('records no actor, no ip and the command as the source', function () {
        Artisan::call('audit-test:record');

        $audit = $this->audits()->sole();

        expect($audit->event)->toBe('cli_ran')
            ->and($audit->actor_id)->toBeNull()
            ->and($audit->source)->toBe('console:audit-test:record')
            ->and($audit->ip_address)->toBeNull()
            ->and($audit->user_agent)->toBeNull();
    });

    it('falls back to "console" outside a command', function () {
        Auditor::record('sent');

        expect($this->audits()->sole()->source)->toBe('console');
    });
});

describe('queue', function () {
    it('carries the actor and request id from a request into a database-queue job', function () {
        $user = makeUser();

        $this->actingAs($user)->get('/dispatch')->assertOk();
        expect(DB::table('jobs')->count())->toBe(1);

        // A worker is another process: nobody is signed in and nothing is shared with the request.
        $this->startNewProcess();
        Artisan::call('queue:work', ['--once' => true]);

        [$request, $job] = $this->audits()->all();

        expect($request->event)->toBe('dispatching')
            ->and($job->event)->toBe('job_ran')
            ->and($job->actor_type)->toBe('user')
            ->and($job->actor_id)->toBe((string) $user->id)
            ->and($job->actor_name)->toBe('Ada Lovelace')
            ->and($job->actor_identifier)->toBe('ada@example.test')
            ->and($job->source)->toBe('queue:'.RecordActorJob::class)
            ->and($job->request_id)->toBe($request->request_id)
            ->and($job->ip_address)->toBeNull();
    });

    it('keeps the actor snapshot after the user is deleted', function () {
        $user = makeUser();

        $this->actingAs($user)->get('/dispatch');
        $user->delete();

        $this->startNewProcess();
        Artisan::call('queue:work', ['--once' => true]);

        $job = $this->audits()->last();

        expect($job->actor_id)->toBe((string) $user->id)
            ->and($job->actor_identifier)->toBe('ada@example.test');
    });

    it('carries the actor and request id through a chained job', function () {
        $user = makeUser();

        $this->actingAs($user)->get('/chain')->assertOk();

        $this->startNewProcess();
        Artisan::call('queue:work', ['--once' => true]);
        $this->startNewProcess();
        Artisan::call('queue:work', ['--once' => true]);

        $audits = $this->audits();
        $request = $audits->first();
        $jobs = $audits->skip(1)->values();

        expect($jobs)->toHaveCount(2)
            ->and($jobs->pluck('meta.label')->all())->toBe(['first', 'second'])
            ->and($jobs->pluck('actor_id')->unique()->all())->toBe([(string) $user->id])
            ->and($jobs->pluck('request_id')->unique()->all())->toBe([$request->request_id])
            ->and($jobs->pluck('source')->unique()->all())->toBe(['queue:'.RecordActorJob::class]);
    });

    it('records nobody for a job dispatched with no signed-in user', function () {
        $this->get('/dispatch');

        $this->startNewProcess();
        Artisan::call('queue:work', ['--once' => true]);

        $job = $this->audits()->last();

        expect($job->actor_id)->toBeNull()
            ->and($job->source)->toBe('queue:'.RecordActorJob::class);
    });

    it('records the queue source on the sync driver and restores the previous one afterwards', function () {
        config(['queue.default' => 'sync']);

        RecordActorJob::dispatch('inline');
        Auditor::record('after');

        expect($this->audits()->pluck('source')->all())->toBe(['queue:'.RecordActorJob::class, 'console']);
    });

    it('carries the origin from a console command too', function () {
        Artisan::call('audit-test:record');
        $command = $this->audits()->sole();

        $this->startNewProcess();
        Auditor::actingAs(null, fn () => RecordActorJob::dispatch('from-console'));
        Artisan::call('queue:work', ['--once' => true]);

        $job = $this->audits()->last();

        expect($job->actor_id)->toBeNull()
            ->and($job->request_id)->not->toBe($command->request_id)
            ->and($job->request_id)->toHaveLength(26);
    });
});

describe('schedule', function () {
    it('records nobody and the scheduled task as the source', function () {
        app(Schedule::class)->call(fn () => Auditor::record('ticked'))->name('tick')->everyMinute();

        Artisan::call('schedule:run');

        $audit = $this->audits()->sole();

        expect($audit->event)->toBe('ticked')
            ->and($audit->actor_id)->toBeNull()
            ->and($audit->source)->toBe('schedule:tick');
    });
});

describe('actingAs and withSource', function () {
    it('acts as the given user inside the callback and restores afterwards', function () {
        $ada = makeUser();
        $grace = makeUser(['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'username' => 'grace']);

        Auditor::actingAs($ada, function () use ($grace) {
            Auditor::record('outer');
            Auditor::actingAs($grace, fn () => Auditor::record('inner'));
            Auditor::record('outer-again');
        });
        Auditor::record('after');

        expect($this->audits()->pluck('actor_name')->all())
            ->toBe(['Ada Lovelace', 'Grace Hopper', 'Ada Lovelace', null]);
    });

    it('beats the signed-in user, and null means System', function () {
        $ada = makeUser();
        $grace = makeUser(['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'username' => 'grace']);
        $this->actingAs($ada);

        Auditor::actingAs($grace, fn () => Auditor::record('as-grace'));
        Auditor::actingAs(null, fn () => Auditor::record('as-system'));
        Auditor::record('as-ada');

        expect($this->audits()->pluck('actor_name')->all())->toBe(['Grace Hopper', null, 'Ada Lovelace']);
    });

    it('restores the actor when the callback throws', function () {
        $ada = makeUser();

        try {
            Auditor::actingAs($ada, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        Auditor::record('after');

        expect($this->audits()->sole()->actor_id)->toBeNull();
    });

    it('returns what the callback returns', function () {
        expect(Auditor::actingAs(null, fn () => 'value'))->toBe('value')
            ->and(Auditor::withSource('backfill:x', fn () => 42))->toBe(42);
    });

    it('sets and restores the source', function () {
        Auditor::withSource('backfill:fix-totals', function () {
            Auditor::record('inside');
            Auditor::withSource('backfill:nested', fn () => Auditor::record('nested'));
            Auditor::record('inside-again');
        });
        Auditor::record('after');

        expect($this->audits()->pluck('source')->all())
            ->toBe(['backfill:fix-totals', 'backfill:nested', 'backfill:fix-totals', 'console']);
    });

    it('beats the console command source', function () {
        $this->rerouteConsoleEvents();

        Artisan::call('audit-test:record', ['--backfill' => true]);

        expect($this->audits()->sole()->source)->toBe('backfill:cmd');
    });
});

describe('actor snapshot', function () {
    it('snapshots the configured identifier attribute', function () {
        $user = makeUser();

        $this->actingAs($user);
        Auditor::record('with-email');

        config(['audit.actor.identifier' => 'username']);
        Auditor::record('with-username');

        expect($this->audits()->pluck('actor_identifier')->all())->toBe(['ada@example.test', 'ada']);
    });

    it('snapshots the configured name attribute', function () {
        config(['audit.actor.name' => 'username']);
        $this->actingAs(makeUser());

        Auditor::record('sent');

        expect($this->audits()->sole()->actor_name)->toBe('ada');
    });

    it('is strict-safe when the attributes were not loaded', function () {
        $user = makeUser();
        $partial = User::query()->select('id')->findOrFail($user->id);
        $this->actingAs($partial);

        Auditor::record('sent');

        $audit = $this->audits()->sole();

        expect($audit->actor_id)->toBe((string) $user->id)
            ->and($audit->actor_name)->toBeNull()
            ->and($audit->actor_identifier)->toBeNull();
    });

    it('runs no query to snapshot the actor', function () {
        $this->actingAs(makeUser());

        DB::enableQueryLog();
        Auditor::record('sent');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        expect($queries)->toHaveCount(1)
            ->and($queries->first())->toStartWith('insert into');
    });

    it('does not trigger a remember-cookie login to find the actor', function () {
        $guard = Auth::guard();

        expect($guard->hasUser())->toBeFalse();

        Auditor::record('sent');

        expect($guard->hasUser())->toBeFalse();
    });

    it('falls back to a host context key holding an actor id', function () {
        $user = makeUser();
        config(['audit.actor.context_key' => 'acting_user_id']);
        Context::add('acting_user_id', $user->id);

        Auditor::record('sent');

        expect($this->audits()->sole()->actor_identifier)->toBe('ada@example.test');
    });

    it('prefers the signed-in user over the context key', function () {
        $ada = makeUser();
        $grace = makeUser(['name' => 'Grace Hopper', 'email' => 'grace@example.test']);
        config(['audit.actor.context_key' => 'acting_user_id']);
        Context::add('acting_user_id', $grace->id);
        $this->actingAs($ada);

        Auditor::record('sent');

        expect($this->audits()->sole()->actor_name)->toBe('Ada Lovelace');
    });
});

it('does not write any row while auditing is disabled', function () {
    config(['audit.enabled' => false]);

    Auditor::record('sent', Invoice::create(['number' => 'INV-1']));

    expect($this->audits())->toBeEmpty();
});
