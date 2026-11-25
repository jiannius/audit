<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;

/** A soft-deleting document with a global scope of its own: locked documents are out of reach. */
class LockedDocument extends BaseDocument
{
    protected static function booted(): void
    {
        static::addGlobalScope('open', fn (Builder $query) => $query->where('documents.status', '!=', 'locked'));
    }
}
