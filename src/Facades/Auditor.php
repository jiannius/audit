<?php

namespace Jiannius\Audit\Facades;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void record(string $event, ?\Illuminate\Database\Eloquent\Model $subject = null, array $meta = [], array $old = [], array $new = [], string $category = \Jiannius\Audit\Category::ACTION, ?\Illuminate\Database\Eloquent\Model $parent = null)
 * @method static int update(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation|\Illuminate\Database\Query\Builder $query, array $values, string $event = 'updated', array $meta = [], ?string $subjectType = null)
 * @method static int delete(\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\Relation|\Illuminate\Database\Query\Builder $query, string $event = 'deleted', array $meta = [], ?string $subjectType = null)
 * @method static mixed pivot(\Illuminate\Database\Eloquent\Relations\BelongsToMany $relation, \Closure $write, string $event = 'updated', array $meta = [])
 * @method static mixed actingAs(?Authenticatable $actor, \Closure $callback)
 * @method static mixed withSource(string $source, \Closure $callback)
 *
 * @see \Jiannius\Audit\Auditor
 */
class Auditor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Jiannius\Audit\Auditor::class;
    }
}
