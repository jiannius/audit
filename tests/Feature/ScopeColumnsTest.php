<?php

use Jiannius\Audit\Audit;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Tests\Fixtures\CurrentTeam;
use Jiannius\Audit\Tests\Fixtures\Invoice;
use Jiannius\Audit\Tests\Fixtures\RegionScope;
use Jiannius\Audit\Tests\Fixtures\TeamScope;

describe('stamped on write', function () {
    it('fills every configured column from its resolver, in both forms', function () {
        $this->useScopes([
            'team_id' => ['resolver' => TeamScope::class, 'read' => CurrentTeam::class],
            'region_id' => RegionScope::class,
        ]);
        CurrentTeam::$id = 5;

        Auditor::record('created');
        Auditor::record('sent');

        $rows = Audit::withoutGlobalScopes()->orderBy('id')->get();

        expect($rows->pluck('team_id')->all())->toBe([5, 5])
            ->and($rows->pluck('region_id')->all())->toBe([1, 7]);
    });

    it('hands the resolver the pending audit, subject included', function () {
        $seen = null;
        $this->useScopes(['team_id' => function ($audit) use (&$seen) {
            $seen = $audit;

            return 3;
        }]);
        $invoice = Invoice::create(['number' => 'INV-1']);

        Auditor::record('sent', $invoice);

        expect($seen->event)->toBe('sent')
            ->and($seen->subject->is($invoice))->toBeTrue()
            ->and($this->audits()->sole()->team_id)->toBe(3);
    });

    it('stores null when the resolver has no value', function () {
        $this->useScopes(['team_id' => TeamScope::class]);

        Auditor::record('sent');

        expect($this->audits()->sole()->team_id)->toBeNull();
    });

    it('ignores a scope that has no resolver', function () {
        $this->useScopes(['team_id' => ['read' => CurrentTeam::class]]);
        CurrentTeam::$id = 1;

        Auditor::record('sent');

        expect(Audit::withoutGlobalScopes()->sole()->team_id)->toBeNull();
    });
});

describe('isolated on read', function () {
    beforeEach(function () {
        $this->useScopes([
            'team_id' => ['resolver' => TeamScope::class, 'read' => CurrentTeam::class],
            'region_id' => RegionScope::class,
        ]);

        CurrentTeam::$id = 1;
        Auditor::record('team-one');
        CurrentTeam::$id = 2;
        Auditor::record('team-two');
    });

    it('shows only the current tenant when a read resolver is set', function () {
        CurrentTeam::$id = 1;
        expect(Audit::query()->pluck('event')->all())->toBe(['team-one']);

        CurrentTeam::$id = 2;
        expect(Audit::query()->pluck('event')->all())->toBe(['team-two']);
    });

    it('registers a named global scope only for columns with a read resolver', function () {
        Audit::query(); // boots the model

        expect(array_keys((new Audit)->getGlobalScopes()))->toBe(['audit.scope.team_id']);
    });

    it('lets a cross-tenant screen opt out by name', function () {
        CurrentTeam::$id = 1;

        expect(Audit::withoutGlobalScope('audit.scope.team_id')->count())->toBe(2);
    });

    it('returns no rows when the current tenant is null', function () {
        CurrentTeam::$id = null;

        expect(Audit::query()->count())->toBe(0)
            ->and(Audit::withoutGlobalScope('audit.scope.team_id')->count())->toBe(2);
    });

    it('applies to the named scopes as well', function () {
        $invoice = Invoice::create(['number' => 'INV-1']);
        CurrentTeam::$id = 1;
        Auditor::record('sent', $invoice);
        CurrentTeam::$id = 2;
        Auditor::record('sent', $invoice);

        CurrentTeam::$id = 1;
        expect(Audit::forSubject($invoice)->count())->toBe(1);

        CurrentTeam::$id = null;
        expect(Audit::forSubject($invoice)->count())->toBe(0);
    });

    it('reads the current tenant when the query runs, not when the model boots', function () {
        $query = Audit::query();

        CurrentTeam::$id = 2;

        expect($query->pluck('event')->all())->toBe(['team-two']);
    });
});

describe('unrestricted on read', function () {
    it('returns every row when no read resolver is set', function () {
        $this->useScopes(['team_id' => TeamScope::class, 'region_id' => RegionScope::class]);

        CurrentTeam::$id = 1;
        Auditor::record('team-one');
        CurrentTeam::$id = 2;
        Auditor::record('team-two');

        CurrentTeam::$id = null;

        expect(Audit::query()->count())->toBe(2)
            ->and((new Audit)->getGlobalScopes())->toBe([]);
    });

    it('returns every row when no scopes are configured', function () {
        Auditor::record('a');
        Auditor::record('b');

        expect(Audit::query()->count())->toBe(2);
    });
});

it('lets a row keep the tenant it was written with', function () {
    $this->useScopes(['team_id' => ['resolver' => TeamScope::class, 'read' => CurrentTeam::class]]);
    $invoice = Invoice::create(['number' => 'INV-1']);

    CurrentTeam::$id = 1;
    Auditor::record('created', $invoice);
    CurrentTeam::$id = 2;

    // The record "moved" to team 2: the old row stays with team 1.
    expect(Audit::query()->count())->toBe(0);

    CurrentTeam::$id = 1;
    expect(Audit::query()->sole()->team_id)->toBe(1);
});
