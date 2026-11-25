<?php

namespace Jiannius\Audit\Retention;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\ManagesFrequencies;
use Illuminate\Console\Scheduling\Schedule;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * `audit.prune.schedule`: validated, and applied to the scheduled `audit:prune` task.
 *
 * @internal
 */
final class PruneSchedule
{
    /**
     * Throw unless the config value is null (not scheduled), a frequency method that takes no arguments, or a cron expression. `false` means the same as `null`: not scheduled.
     */
    public static function validate(mixed $frequency): void
    {
        if ($frequency === null || $frequency === false) {
            return;
        }

        if (! is_string($frequency) || trim($frequency) === '') {
            throw new InvalidArgumentException('audit.prune.schedule must be a frequency method such as [daily], a cron expression, or null.');
        }

        $frequency = trim($frequency);

        if (preg_match('/^[A-Za-z]+$/', $frequency) === 1) {
            $method = method_exists(ManagesFrequencies::class, $frequency) ? new ReflectionMethod(ManagesFrequencies::class, $frequency) : null;

            if ($method === null || ! $method->isPublic() || $method->getNumberOfRequiredParameters() > 0) {
                throw new InvalidArgumentException("audit.prune.schedule [{$frequency}] is not a schedule frequency method that takes no arguments. Use one such as [daily] or [hourly], or a cron expression.");
            }

            return;
        }

        if (class_exists(CronExpression::class) && ! CronExpression::isValidExpression($frequency)) {
            throw new InvalidArgumentException("audit.prune.schedule [{$frequency}] is not a valid cron expression.");
        }
    }

    /** Register `audit:prune` on the schedule, or nothing at all when the config is null or invalid (the error is thrown). */
    public static function register(Schedule $schedule): void
    {
        $frequency = config('audit.prune.schedule');

        self::validate($frequency);

        if ($frequency === null || $frequency === false) {
            return;
        }

        $event = $schedule->command('audit:prune')->withoutOverlapping();
        $frequency = trim($frequency);

        preg_match('/^[A-Za-z]+$/', $frequency) === 1 ? $event->{$frequency}() : $event->cron($frequency);

        if (config('audit.prune.on_one_server', false)) {
            $event->onOneServer();
        }
    }
}
