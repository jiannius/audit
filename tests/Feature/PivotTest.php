<?php

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Jiannius\Audit\Exceptions\AuditWriteFailed;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Tests\Fixtures\AuditedUser;
use Jiannius\Audit\Tests\Fixtures\Team;

function teams(int $count = 3): array
{
    return array_map(fn (int $i) => Team::create(['name' => "Team $i"])->getKey(), range(1, $count));
}

function member(): AuditedUser
{
    $user = AuditedUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
    DB::table('audits')->delete();

    return $user;
}

describe('Auditor::pivot', function () {
    it('records a sync as one row on the parent, with the sorted ids before and after', function () {
        [$one, $two, $three] = teams();
        $user = member();
        $user->teams()->attach([$three, $one]);
        DB::table('audits')->delete();

        $result = Auditor::pivot($user->teams(), fn (BelongsToMany $teams) => $teams->sync([$one, $two]));

        $audit = $this->audits()->first();

        expect($this->audits())->toHaveCount(1)
            ->and($result)->toBe(['attached' => [$two], 'detached' => [1 => $three], 'updated' => []])
            ->and($audit->category)->toBe('record')
            ->and($audit->event)->toBe('updated')
            ->and($audit->subject_type)->toBe('audited-user')
            ->and($audit->subject_id)->toBe((string) $user->getKey())
            ->and($audit->subject_label)->toBe('Ada')
            ->and($audit->old_values)->toEqual(['teams' => [$one, $three]])
            ->and($audit->new_values)->toEqual(['teams' => [$one, $two]]);
    });

    it('records attach', function () {
        [$one, $two] = teams();
        $user = member();

        Auditor::pivot($user->teams(), fn ($teams) => $teams->attach([$one, $two]), event: 'joined', meta: ['via' => 'invite']);

        $audit = $this->audits()->first();

        expect($audit->event)->toBe('joined')
            ->and($audit->meta)->toEqual(['via' => 'invite'])
            ->and($audit->old_values)->toEqual(['teams' => []])
            ->and($audit->new_values)->toEqual(['teams' => [$one, $two]]);
    });

    it('records detach', function () {
        [$one, $two] = teams();
        $user = member();
        $user->teams()->attach([$one, $two]);
        DB::table('audits')->delete();

        $count = Auditor::pivot($user->teams(), fn ($teams) => $teams->detach($one));

        $audit = $this->audits()->first();

        expect($count)->toBe(1)
            ->and($audit->old_values)->toEqual(['teams' => [$one, $two]])
            ->and($audit->new_values)->toEqual(['teams' => [$two]]);
    });

    it('records a detach of everything', function () {
        [$one] = teams();
        $user = member();
        $user->teams()->attach($one);
        DB::table('audits')->delete();

        Auditor::pivot($user->teams(), fn ($teams) => $teams->detach());

        expect($this->audits()->first()->new_values)->toEqual(['teams' => []]);
    });

    it('records updateExistingPivot, with a map of id to pivot columns', function () {
        [$one, $two] = teams();
        $user = member();
        $user->roleTeams()->attach([$one => ['role' => 'member'], $two => ['role' => 'member']]);
        DB::table('audits')->delete();

        Auditor::pivot($user->roleTeams(), fn ($teams) => $teams->updateExistingPivot($two, ['role' => 'admin']));

        $audit = $this->audits()->first();

        expect($this->audits())->toHaveCount(1)
            ->and($audit->old_values)->toEqual(['roleTeams' => [$one => ['role' => 'member', 'token' => null], $two => ['role' => 'member', 'token' => null]]])
            ->and($audit->new_values)->toEqual(['roleTeams' => [$one => ['role' => 'member', 'token' => null], $two => ['role' => 'admin', 'token' => null]]]);
    });

    it('leaves timestamps and excluded columns out, and redacts secret ones, without storing them', function () {
        [$one] = teams();
        $user = member();

        Auditor::pivot($user->roleTeams(), fn ($teams) => $teams->attach($one, ['role' => 'admin', 'token' => 'pivot-secret-9', 'api_token' => 'pivot-api-9']));

        $new = $this->audits()->first()->new_values['roleTeams'][$one];

        expect($new)->toBe(['role' => 'admin', 'token' => '[redacted]'])
            ->and(everythingAudited())->not->toContain('pivot-secret-9')->not->toContain('pivot-api-9');
    });

    it('records a change to a secret pivot column, redacted on both sides', function () {
        [$one] = teams();
        $user = member();
        $user->roleTeams()->attach($one, ['token' => 'first-secret']);
        DB::table('audits')->delete();

        Auditor::pivot($user->roleTeams(), fn ($teams) => $teams->updateExistingPivot($one, ['token' => 'second-secret']));

        $audit = $this->audits()->first();

        expect($audit->old_values['roleTeams'][$one]['token'])->toBe('[redacted]')
            ->and($audit->new_values['roleTeams'][$one]['token'])->toBe('[redacted]')
            ->and(everythingAudited())->not->toContain('first-secret')->not->toContain('second-secret');
    });

    it('writes nothing when the pivot is unchanged', function () {
        [$one, $two] = teams();
        $user = member();
        $user->teams()->attach([$one, $two]);
        $user->roleTeams()->updateExistingPivot($one, ['role' => 'admin']);
        DB::table('audits')->delete();

        Auditor::pivot($user->teams(), fn ($teams) => $teams->sync([$two, $one]));
        Auditor::pivot($user->teams(), fn ($teams) => $teams->syncWithoutDetaching([$one]));
        Auditor::pivot($user->teams(), fn ($teams) => $teams->detach(999));
        Auditor::pivot($user->roleTeams(), fn ($teams) => $teams->updateExistingPivot($one, ['role' => 'admin']));
        Auditor::pivot($user->teams(), fn () => null);

        expect($this->audits())->toHaveCount(0);
    });

    it('writes nothing when only a timestamp or an excluded column changes', function () {
        [$one] = teams();
        $user = member();
        $user->roleTeams()->attach($one);
        DB::table('audits')->delete();

        Auditor::pivot($user->roleTeams(), fn ($teams) => $teams->updateExistingPivot($one, ['api_token' => 'changed']));

        expect($this->audits())->toHaveCount(0)
            ->and(DB::table('team_user')->value('api_token'))->toBe('changed');
    });

    it('is not fooled by another user\'s links', function () {
        [$one, $two] = teams();
        $user = member();
        $other = AuditedUser::create(['name' => 'Grace', 'email' => 'grace@example.test', 'password' => 'x']);
        $other->teams()->attach($one);
        DB::table('audits')->delete();

        Auditor::pivot($user->teams(), fn ($teams) => $teams->attach($two));

        expect($this->audits()->first()->old_values)->toEqual(['teams' => []])
            ->and($this->audits()->first()->new_values)->toEqual(['teams' => [$two]]);
    });

    it('returns what the closure returns', function () {
        $user = member();

        expect(Auditor::pivot($user->teams(), fn () => 'done'))->toBe('done');
    });

    it('sorts ids naturally, so 10 follows 9', function () {
        $user = member();
        $ids = teams(10);
        DB::table('audits')->delete();

        Auditor::pivot($user->teams(), fn ($teams) => $teams->attach(array_reverse($ids)));

        expect($this->audits()->first()->new_values['teams'])->toBe($ids);
    });

    it('rolls the change back when the audit insert fails, for attach, detach, sync and updateExistingPivot', function () {
        [$one, $two, $three] = teams();
        $user = member();
        $user->roleTeams()->attach([$one => ['role' => 'member'], $two => ['role' => null]]);
        Schema::drop('audits');

        $attempts = [
            fn () => Auditor::pivot($user->roleTeams(), fn ($teams) => $teams->attach($three)),
            fn () => Auditor::pivot($user->roleTeams(), fn ($teams) => $teams->detach($one)),
            fn () => Auditor::pivot($user->roleTeams(), fn ($teams) => $teams->sync([$three])),
            fn () => Auditor::pivot($user->roleTeams(), fn ($teams) => $teams->updateExistingPivot($one, ['role' => 'admin'])),
        ];

        foreach ($attempts as $attempt) {
            expect($attempt)->toThrow(AuditWriteFailed::class);
        }

        expect(DB::table('team_user')->orderBy('team_id')->get(['team_id', 'role'])->map(fn ($row) => [$row->team_id, $row->role])->all())
            ->toBe([[$one, 'member'], [$two, null]]);
    });

    it('works with auditing switched off, writing nothing', function () {
        [$one] = teams();
        $user = member();
        DB::table('audits')->delete();
        config(['audit.enabled' => false]);

        Auditor::pivot($user->teams(), fn ($teams) => $teams->attach($one));

        expect(DB::table('team_user')->count())->toBe(1)
            ->and(DB::table('audits')->count())->toBe(0);
    });

    it('refuses a parent that is not saved', function () {
        $user = new AuditedUser(['name' => 'Ada']);

        expect(fn () => Auditor::pivot($user->teams(), fn () => null))->toThrow(InvalidArgumentException::class, 'saved');
    });
});

describe('Auditor::pivot field name', function () {
    it('names the field after the relation method', function () {
        [$one] = teams();
        $user = member();

        Auditor::pivot($user->roleTeams(), fn ($teams) => $teams->attach($one));

        // The relation is roleTeams(); its table is team_user, and the related table is teams.
        expect(array_keys($this->audits()->first()->new_values))->toBe(['roleTeams']);
    });

    it('falls back to the related table when the relation name is a backtrace guess', function () {
        [$one] = teams();
        $user = member();
        // Built inside a closure, Laravel guesses the name from the backtrace and gets a closure.
        $relation = (fn () => $user->belongsToMany(Team::class, 'team_user', 'user_id', 'team_id'))();

        Auditor::pivot($relation, fn ($teams) => $teams->attach($one));

        expect(array_keys($this->audits()->first()->new_values))->toBe(['teams'])
            ->and(everythingAudited())->not->toContain('closure')->not->toContain('.php');
    });

    it('takes the field as an argument', function () {
        [$one] = teams();
        $user = member();

        Auditor::pivot($user->teams(), fn ($teams) => $teams->attach($one), field: 'memberships');

        expect($this->audits()->first()->new_values)->toEqual(['memberships' => [$one]]);
    });
});

describe('Auditor::pivot with duplicate links', function () {
    it('records a second link to the same record, as a repeated id in the list', function () {
        [$one, $two] = teams();
        $user = member();
        $user->memberships()->attach([$one, $two]);
        DB::table('audits')->delete();

        Auditor::pivot($user->memberships(), fn ($teams) => $teams->attach($one));

        $audit = $this->audits()->first();

        expect($this->audits())->toHaveCount(1)
            ->and($audit->old_values)->toEqual(['memberships' => [$one, $two]])
            ->and($audit->new_values)->toEqual(['memberships' => [$one, $one, $two]]);
    });

    it('records a duplicate in the map form as a list of the column sets for that id', function () {
        [$one] = teams();
        $user = member();
        $user->roleMemberships()->attach($one, ['role' => 'member']);
        DB::table('audits')->delete();

        Auditor::pivot($user->roleMemberships(), fn ($teams) => $teams->attach($one, ['role' => 'admin']));

        $audit = $this->audits()->first();

        expect($audit->old_values['roleMemberships'][$one])->toEqual(['role' => 'member'])
            ->and($audit->new_values['roleMemberships'][$one])->toEqual([['role' => 'admin'], ['role' => 'member']]);
    });

    it('writes nothing when duplicates are unchanged', function () {
        [$one] = teams();
        $user = member();
        $user->memberships()->attach([$one, $one]);
        DB::table('audits')->delete();

        Auditor::pivot($user->memberships(), fn () => null);

        expect($this->audits())->toHaveCount(0);
    });
});
