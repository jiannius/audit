<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Jiannius\Audit\Facades\Auditor;
use Throwable;

/** A worker job that runs other jobs inline, one fine and one throwing. */
class NestingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        Auditor::record('outer_before');

        RecordActorJob::dispatchSync('inner');

        try {
            ThrowingJob::dispatchSync();
        } catch (Throwable) {
        }

        Auditor::record('outer_after');
    }
}
