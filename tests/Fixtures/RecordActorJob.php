<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Jiannius\Audit\Facades\Auditor;

class RecordActorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** $loginAs: the job signs this user in on the worker's guard first, as host job code sometimes does. */
    public function __construct(public string $label = 'job', public ?int $loginAs = null) {}

    public function handle(): void
    {
        if ($this->loginAs !== null) {
            Auth::setUser(User::query()->findOrFail($this->loginAs));
        }

        Auditor::record('job_ran', meta: ['label' => $this->label]);
    }
}
