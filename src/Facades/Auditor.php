<?php

namespace Jiannius\Audit\Facades;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void record(string $event, ?\Illuminate\Database\Eloquent\Model $subject = null, array $meta = [], array $old = [], array $new = [], string $category = \Jiannius\Audit\Category::ACTION, ?\Illuminate\Database\Eloquent\Model $parent = null)
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
