<?php

namespace Jiannius\Audit;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Jiannius\Audit\Console\PruneCommand;
use Jiannius\Audit\Console\PurgeCommand;
use Jiannius\Audit\Context\AuditContext;
use Jiannius\Audit\Contracts\FormatsAuditValues;
use Jiannius\Audit\Retention\PruneSchedule;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfig();

        $this->app->scoped(AuditContext::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(AuditObserver::class);
        $this->app->singleton(Auditor::class);

        // The formatter the CSV export uses unless it is given another. Rebind it to swap it in one place.
        $this->app->bind(FormatsAuditValues::class, fn ($app) => $app->make(config('audit.formatter')));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/audit.php' => config_path('audit.php'),
            ], 'audit-config');

            // The host owns this migration: it adds its scope columns and controls every ALTER.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations/create_audits_table.php.stub' => static::publishedMigrationPath(database_path('migrations')),
            ], 'audit-migrations');

            $this->commands([PruneCommand::class, PurgeCommand::class]);
        }

        $this->registerContextHooks();
        $this->registerSourceListeners();
        $this->registerSignInListeners();
        $this->registerPruneSchedule();
    }

    /**
     * Schedule `audit:prune` from `audit.prune.schedule`, so the host only needs `schedule:run` in cron.
     * The config is read when the schedule is resolved, so a cached config is honoured and `null` schedules nothing.
     *
     * A schedule that cannot be read is reported and nothing is registered: a typo in this one key must not
     * take down every scheduled task and `schedule:run` with it. `audit:prune` itself fails loudly on it.
     */
    private function registerPruneSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            try {
                PruneSchedule::register($schedule);
            } catch (InvalidArgumentException $e) {
                report($e);
            }
        });
    }

    /** The line the published v1 migration carries, so it can be told from any other `create_audits_table` migration. */
    public const MIGRATION_MARKER = '// jiannius/audit v1';

    /**
     * Where `vendor:publish` puts the migration: the v1 migration the host already published, so publishing
     * twice leaves it alone, or else a new file. A `create_audits_table` migration of the pre-1.0 package is not
     * the v1 one and is never reused, or publishing would skip the v1 table.
     */
    public static function publishedMigrationPath(string $directory): string
    {
        foreach (glob(rtrim($directory, '/').'/*_create_audits_table.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), self::MIGRATION_MARKER)) {
                return $file;
            }
        }

        return rtrim($directory, '/').'/'.date('Y_m_d_His').'_create_audits_table.php';
    }

    /** Carry the actor and request id into queued jobs through hidden Context. */
    private function registerContextHooks(): void
    {
        Context::dehydrating(function (ContextRepository $context) {
            $context->addHidden(AuditContext::ORIGIN_KEY, app(AuditContext::class)->dispatchOrigin());
        });
    }

    /** Keep the source in step with HTTP requests, console commands, scheduled tasks and queued jobs. */
    private function registerSourceListeners(): void
    {
        // Resolve from the current container each time: under Octane the container changes per request.
        $context = fn (): AuditContext => app(AuditContext::class);

        Event::listen(RouteMatched::class, fn () => $context()->markHttp());

        Event::listen(CommandStarting::class, fn (CommandStarting $event) => $context()->enter('console', 'console:'.($event->command ?: 'artisan')));
        Event::listen(CommandFinished::class, fn () => $context()->leave('console'));

        Event::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $context()->enterTask($event->task));
        Event::listen([ScheduledTaskFinished::class, ScheduledTaskFailed::class], fn () => $context()->leave('schedule'));

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $context()->enter('queue', 'queue:'.$event->job->resolveName(), spl_object_id($event->job)));
        Event::listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], fn ($event) => $context()->leave('queue', spl_object_id($event->job)));
    }

    /**
     * Listen for the sign-in events named in `audit.auth.events`; the others are not registered.
     * The listener is resolved from the container on each event, so Octane never shares one across requests.
     */
    public function registerSignInListeners(): void
    {
        foreach ((array) config('audit.auth.events', []) as $name) {
            if (is_string($name) && isset(SignInRecorder::EVENTS[$name])) {
                [$event, $method] = SignInRecorder::EVENTS[$name];

                Event::listen($event, [SignInRecorder::class, $method]);
            }
        }
    }

    /**
     * Merge the package defaults under the host's config, recursively, so a
     * partially published `actor` or `attributes` array keeps its other
     * defaults. Lists (such as `exclude` or `overrides`) are replaced, never
     * merged index by index.
     */
    private function mergeConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $config->set('audit', static::mergeDefaults(require __DIR__.'/../config/audit.php', $config->get('audit', [])));
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function mergeDefaults(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $default = $defaults[$key] ?? null;

            $defaults[$key] = is_array($value) && is_array($default) && ! array_is_list($default) && ($value === [] || ! array_is_list($value))
                ? static::mergeDefaults($default, $value)
                : $value;
        }

        return $defaults;
    }
}
