<?php

namespace Jiannius\Audit\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Jiannius\Audit\Retention\Pruner;
use Jiannius\Audit\Retention\PruneSchedule;
use Jiannius\Audit\Retention\RetentionPolicy;
use Throwable;

/**
 * The only prune path. It deletes, rule by rule, the rows older than each rule's cutoff and then
 * writes one `audits_pruned` row, even when nothing went, so the row doubles as a scheduler heartbeat.
 * A run that fails part-way writes its row too, with what it had deleted so far and `failed: true`.
 */
class PruneCommand extends Command
{
    use RecordsRun;

    protected $signature = 'audit:prune {--pretend : Show how many rows would be deleted, and delete nothing}';

    protected $description = 'Delete audit rows that are older than the configured retention';

    public function handle(Pruner $pruner): int
    {
        if ($this->auditingIsOff()) {
            return self::FAILURE;
        }

        try {
            $policy = RetentionPolicy::fromConfig();

            // The scheduler only reports a bad schedule, so the command is where it fails loudly.
            PruneSchedule::validate(config('audit.prune.schedule'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $pretend = (bool) $this->option('pretend');
        $summary = [];
        $total = 0;
        $failure = null;

        foreach ($policy->rules() as $rule) {
            $deleted = 0;

            try {
                if ($rule->cutoff !== null) {
                    $pruner->delete(fn ($query) => $rule->constrain($query), $pretend, $deleted);
                }
            } catch (Throwable $e) {
                $failure = $e;
            }

            $total += $deleted;
            $summary[] = $rule->describe() + ['deleted' => $deleted];

            if ($failure !== null) {
                break;
            }
        }

        if (! $pretend) {
            $this->recordRun(['command' => 'prune', 'rules' => $summary], $failure);
        }

        if ($failure !== null) {
            throw $failure;
        }

        $this->table(
            ['Rule', 'Keep until', $pretend ? 'Would delete' : 'Deleted'],
            array_map(fn (array $entry) => [
                isset($entry['override']) ? $this->overrideLabel($entry['override']) : 'category '.$entry['category'],
                $entry['cutoff'] ?? 'forever',
                $entry['deleted'],
            ], $summary),
        );

        $this->components->info($pretend
            ? "Pretending: {$total} audit rows would be deleted. Nothing was deleted or recorded."
            : "Deleted {$total} audit rows.");

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $override */
    private function overrideLabel(array $override): string
    {
        $parts = [];

        foreach (['subject_type', 'event', 'category'] as $column) {
            if (isset($override[$column])) {
                $parts[] = "{$column} {$override[$column]}";
            }
        }

        return 'override '.implode(', ', $parts);
    }
}
