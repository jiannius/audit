<?php

namespace Jiannius\Audit\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Jiannius\Audit\Category;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Retention\Pruner;
use Jiannius\Audit\Retention\RetentionPolicy;
use Throwable;

/**
 * Targeted, manual clean-up. It needs --before and at least one filter, confirms a run outside
 * `local` with --force, and never cuts below the configured retention without --below-retention.
 */
class PurgeCommand extends Command
{
    protected $signature = 'audit:purge
        {--before= : Delete rows created before this date (required)}
        {--type= : Only rows about this record type (the morph alias)}
        {--event= : Only rows with this event}
        {--category= : Only rows in this category: record, auth or action}
        {--pretend : Show how many rows would be deleted, and delete nothing}
        {--force : Confirm a run outside the local environment}
        {--below-retention : Allow deleting rows the configured retention would still keep}';

    protected $description = 'Delete audit rows matching a filter, older than a date';

    public function handle(Pruner $pruner): int
    {
        $filters = array_filter([
            'subject_type' => $this->option('type') !== null ? Relation::getMorphAlias((string) $this->option('type')) : null,
            'event' => $this->option('event'),
            'category' => $this->option('category'),
        ], fn ($value) => $value !== null && $value !== '');

        if (($refusal = $this->usageProblem($filters)) !== null) {
            return $this->refuse($refusal);
        }

        $before = CarbonImmutable::parse((string) $this->option('before'));
        $pretend = (bool) $this->option('pretend');
        $force = (bool) $this->option('force');
        $belowRetention = (bool) $this->option('below-retention');

        if (! $force && ! $this->laravel->environment('local')) {
            return $this->refuse('Refusing to purge audit rows in the ['.$this->laravel->environment().'] environment without --force.');
        }

        try {
            $retention = RetentionPolicy::fromConfig()->retentionFor($filters);
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        if (! $belowRetention) {
            if ($retention['forever']) {
                return $this->refuse('The configured retention keeps rows matching this filter forever. Add --below-retention to purge them anyway; --force does not allow it.');
            }

            if ($retention['cutoff'] !== null && $before->greaterThan($retention['cutoff'])) {
                return $this->refuse(sprintf(
                    '--before (%s) is later than the configured retention cutoff for rows matching this filter (%s). Add --below-retention to purge them anyway; --force does not allow it.',
                    $before->toDateTimeString(),
                    $retention['cutoff']->toDateTimeString(),
                ));
            }
        }

        $deleted = $pruner->delete(function ($query) use ($filters, $before) {
            foreach ($filters as $column => $value) {
                $query->where($column, $value);
            }

            return $query->where('created_at', '<', $before->format('Y-m-d H:i:s'));
        }, $pretend);

        if ($pretend) {
            $this->components->info("Pretending: {$deleted} audit rows would be deleted. Nothing was deleted or recorded.");

            return self::SUCCESS;
        }

        Auditor::record('audits_pruned', category: Category::ACTION, meta: [
            'command' => 'purge',
            'filters' => $filters,
            'before' => $before->toIso8601String(),
            'deleted' => $deleted,
            'below_retention' => $belowRetention,
            'forced' => $force,
        ]);

        $this->components->info("Deleted {$deleted} audit rows.");

        return self::SUCCESS;
    }

    /** @param  array<string, string>  $filters */
    private function usageProblem(array $filters): ?string
    {
        if ($this->option('before') === null || $this->option('before') === '') {
            return 'The --before option is required, for example --before=2026-01-01.';
        }

        if ($filters === []) {
            return 'Give at least one of --type, --event or --category, so a purge never covers the whole table.';
        }

        if (isset($filters['category']) && ! in_array($filters['category'], Category::all(), true)) {
            return 'The --category option must be one of: '.implode(', ', Category::all()).'.';
        }

        try {
            CarbonImmutable::parse((string) $this->option('before'));
        } catch (Throwable) {
            return 'The --before option is not a date: ['.$this->option('before').'].';
        }

        return null;
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
