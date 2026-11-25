<?php

namespace Jiannius\Audit\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Jiannius\Audit\Category;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Retention\Pruner;
use Jiannius\Audit\Retention\RetentionPolicy;

/**
 * The only prune path. It deletes, rule by rule, the rows older than each rule's cutoff and then
 * writes one `audits_pruned` row, even when nothing went, so the row doubles as a scheduler heartbeat.
 */
class PruneCommand extends Command
{
    protected $signature = 'audit:prune {--pretend : Show how many rows would be deleted, and delete nothing}';

    protected $description = 'Delete audit rows that are older than the configured retention';

    public function handle(Pruner $pruner): int
    {
        try {
            $policy = RetentionPolicy::fromConfig();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $pretend = (bool) $this->option('pretend');
        $summary = [];
        $total = 0;

        foreach ($policy->rules() as $rule) {
            $deleted = $rule->cutoff === null ? 0 : $pruner->delete(fn ($query) => $rule->constrain($query), $pretend);
            $total += $deleted;

            $summary[] = $rule->describe() + ['deleted' => $deleted];
        }

        $this->table(
            ['Rule', 'Keep until', $pretend ? 'Would delete' : 'Deleted'],
            array_map(fn (array $entry) => [
                isset($entry['override']) ? $this->overrideLabel($entry['override']) : 'category '.$entry['category'],
                $entry['cutoff'] ?? 'forever',
                $entry['deleted'],
            ], $summary),
        );

        if ($pretend) {
            $this->components->info("Pretending: {$total} audit rows would be deleted. Nothing was deleted or recorded.");

            return self::SUCCESS;
        }

        Auditor::record('audits_pruned', category: Category::ACTION, meta: ['command' => 'prune', 'rules' => $summary]);

        $this->components->info("Deleted {$total} audit rows.");

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
