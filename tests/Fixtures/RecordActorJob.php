<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Jiannius\Audit\Facades\Auditor;

class RecordActorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $label = 'job') {}

    public function handle(): void
    {
        Auditor::record('job_ran', meta: ['label' => $this->label]);
    }
}
