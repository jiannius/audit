<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Jiannius\Audit\Auditable;

class AuditedUser extends User
{
    use Auditable;

    protected $table = 'users';

    /** No pivot columns: recorded as a list of team ids. */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user', 'user_id', 'team_id');
    }

    /** Pivot columns: recorded as a map of team id to columns. `token` is redacted, `api_token` excluded. */
    public function roleTeams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user', 'user_id', 'team_id')->withPivot('role', 'token', 'api_token')->withTimestamps();
    }

    /** A table with no unique key: a team can be linked more than once. */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'memberships', 'user_id', 'team_id');
    }

    public function roleMemberships(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'memberships', 'user_id', 'team_id')->withPivot('role');
    }

    /** The lines of the invoices the user owns. */
    public function lines(): HasManyThrough
    {
        return $this->hasManyThrough(AuditedLine::class, AuditedInvoice::class, 'user_id', 'invoice_id');
    }
}
