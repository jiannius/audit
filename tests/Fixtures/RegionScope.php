<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\PendingAudit;

/** A resolver that reads the pending audit: the region follows the event name. */
class RegionScope
{
    public function __invoke(PendingAudit $audit): int
    {
        return $audit->event === 'sent' ? 7 : 1;
    }
}
