<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Jiannius\Audit\Facades\Auditor;
use RuntimeException;

class ThrowingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        Auditor::record('throwing');

        throw new RuntimeException('boom');
    }
}
