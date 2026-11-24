<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\PendingAudit;

/** Write resolver: stamps the current team. */
class TeamScope
{
    public function __invoke(PendingAudit $audit): int|string|null
    {
        return CurrentTeam::$id;
    }
}
