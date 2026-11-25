<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Foundation\Bus\Dispatchable;
use Jiannius\Audit\Facades\Auditor;

/** Not queued: a scheduled Schedule::job() of this runs inside the scheduler process. */
class RecordNowJob
{
    use Dispatchable;

    public function handle(): void
    {
        Auditor::record('ran_now');
    }
}
