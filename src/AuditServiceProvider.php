<?php

namespace Jiannius\Audit;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Jiannius\Audit\Context\AuditContext;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/audit.php', 'audit');

        $this->app->scoped(AuditContext::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(AuditObserver::class);
        $this->app->singleton(Auditor::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/audit.php' => config_path('audit.php'),
            ], 'audit-config');

            // The host owns this migration: it adds its scope columns and controls every ALTER.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations/create_audits_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_audits_table.php'),
            ], 'audit-migrations');
        }

        $this->registerContextHooks();
        $this->registerSourceListeners();
    }

    /** Carry the actor, request id and source into queued jobs through hidden Context. */
    private function registerContextHooks(): void
    {
        Context::dehydrating(function (ContextRepository $context) {
            $context->addHidden(AuditContext::ORIGIN_KEY, $this->app->make(AuditContext::class)->dispatchOrigin());
        });
    }

    /** Keep the source in step with HTTP requests, console commands, scheduled tasks and queued jobs. */
    private function registerSourceListeners(): void
    {
        $context = fn (): AuditContext => $this->app->make(AuditContext::class);

        Event::listen(RouteMatched::class, fn () => $context()->markHttp());
        Event::listen(RequestHandled::class, fn () => $context()->markHttp(false));

        Event::listen(CommandStarting::class, fn (CommandStarting $event) => $context()->enter('console', 'console:'.($event->command ?: 'artisan')));
        Event::listen(CommandFinished::class, fn () => $context()->leave('console'));

        Event::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $event) => $context()->enter('schedule', 'schedule:'.$event->task->getSummaryForDisplay()));
        Event::listen([ScheduledTaskFinished::class, ScheduledTaskFailed::class], fn () => $context()->leave('schedule'));

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $context()->enter('queue', 'queue:'.$event->job->resolveName(), spl_object_id($event->job)));
        Event::listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], fn ($event) => $context()->leave('queue', spl_object_id($event->job)));
    }
}
