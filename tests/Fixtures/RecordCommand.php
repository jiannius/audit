<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Console\Command;
use Jiannius\Audit\Facades\Auditor;

class RecordCommand extends Command
{
    protected $signature = 'audit-test:record {--backfill} {--dispatch}';

    protected $description = 'Records one audit row.';

    public function handle(): void
    {
        if ($this->option('dispatch')) {
            Auditor::record('dispatching');
            RecordActorJob::dispatch('from-console');

            return;
        }

        $this->option('backfill')
            ? Auditor::withSource('backfill:cmd', fn () => Auditor::record('backfilled'))
            : Auditor::record('cli_ran');
    }
}
