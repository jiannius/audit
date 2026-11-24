<?php

namespace Jiannius\Audit\Tests\Fixtures;

/** Stands in for a host's "current tenant" lookup, used as the read resolver. */
class CurrentTeam
{
    public static int|string|null $id = null;

    public function __invoke(): int|string|null
    {
        return static::$id;
    }
}
