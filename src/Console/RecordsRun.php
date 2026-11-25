<?php

namespace Jiannius\Audit\Console;

use Jiannius\Audit\Category;
use Jiannius\Audit\Facades\Auditor;
use Throwable;

/**
 * What `audit:prune` and `audit:purge` share: refusing to delete while auditing is off, and writing the
 * `audits_pruned` row, including for a run that failed part-way.
 *
 * @internal
 */
trait RecordsRun
{
    /** A run that cannot leave its summary row must not delete anything. */
    private function auditingIsOff(): bool
    {
        if (config('audit.enabled', true)) {
            return false;
        }

        $this->components->error('audit.enabled is false, so this run could not record that it deleted rows. Turn auditing on first.');

        return true;
    }

    /**
     * Write the run's summary row. A run that failed has `failed: true` and the exception's class, never its
     * message (the database's message carries the SQL). If the row itself cannot be written while a failure is
     * pending, that error is reported and the original failure is the one that goes on.
     *
     * @param  array<string, mixed>  $meta
     */
    private function recordRun(array $meta, ?Throwable $failure): void
    {
        if ($failure !== null) {
            $meta += ['failed' => true, 'exception' => $failure::class];
        }

        try {
            Auditor::record('audits_pruned', category: Category::ACTION, meta: $meta);
        } catch (Throwable $e) {
            if ($failure === null) {
                throw $e;
            }

            report($e);
        }
    }
}
