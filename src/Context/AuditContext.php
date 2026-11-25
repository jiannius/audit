<?php

namespace Jiannius\Audit\Context;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * Who is acting, from where, in which request. Registered as a scoped
 * singleton, so it starts fresh for every request and every queued job.
 */
class AuditContext
{
    public const ORIGIN_KEY = 'audit.origin';

    /** @var list<Actor|null> */
    private array $actorOverrides = [];

    /** @var list<string> */
    private array $sourceOverrides = [];

    /** @var list<array{kind: string, source: string, key: ?int}> */
    private array $frames = [];

    private ?string $requestId = null;

    private bool $http = false;

    /** @var array<string, Actor|null> */
    private array $contextKeyActors = [];

    /** @var array<string, Actor|null> */
    private array $sessionActors = [];

    /**
     * First match wins: an actingAs() override; inside a queued job, the origin
     * carried from the dispatching process, then the configured Context key;
     * outside a job, the user signed in on the default guard (or in the
     * session), the carried origin, then the Context key; then nobody.
     *
     * Inside a job the guard is never consulted, with or without a carried
     * origin. A long-running worker keeps its guards between jobs, so a user an
     * earlier job signed in would otherwise be credited with this job's work,
     * and a job pushed from outside Laravel has no one to blame. A job that
     * really does act as someone else says so with actingAs(). Work that runs
     * without queue events, such as dispatchSync() of a job that is not queued,
     * is not a queue frame and uses the guard.
     */
    public function actor(): ?Actor
    {
        if ($this->actorOverrides !== []) {
            return $this->actorOverrides[array_key_last($this->actorOverrides)];
        }

        $origin = $this->origin();

        if ($this->inQueue()) {
            return Actor::fromArray($origin['actor'] ?? null) ?? $this->actorFromContextKey();
        }

        $guard = Auth::guard();

        // hasUser() on purpose: user() could trigger a remember-cookie login as a side effect.
        if ($guard->hasUser()) {
            return Actor::from($guard->user());
        }

        return $this->actorFromSession($guard)
            ?? Actor::fromArray($origin['actor'] ?? null)
            ?? $this->actorFromContextKey();
    }

    public function actingAs(?Authenticatable $actor, Closure $callback): mixed
    {
        $this->actorOverrides[] = Actor::from($actor);

        try {
            return $callback();
        } finally {
            array_pop($this->actorOverrides);
        }
    }

    public function withSource(string $source, Closure $callback): mixed
    {
        $this->sourceOverrides[] = $source;

        try {
            return $callback();
        } finally {
            array_pop($this->sourceOverrides);
        }
    }

    public function source(): string
    {
        if ($this->sourceOverrides !== []) {
            return $this->sourceOverrides[array_key_last($this->sourceOverrides)];
        }

        if ($this->frames !== []) {
            return $this->frames[array_key_last($this->frames)]['source'];
        }

        return $this->isHttp() ? 'web' : 'console';
    }

    /** Enter a source frame (a command, a scheduled task or a job); $key tells nested frames of one kind apart. */
    public function enter(string $kind, string $source, ?int $key = null): void
    {
        $this->frames[] = ['kind' => $kind, 'source' => $source, 'key' => $key];
    }

    /** Leave the most recent frame of the given kind and key. Leaving twice is harmless. */
    public function leave(string $kind, ?int $key = null): void
    {
        for ($i = array_key_last($this->frames); $i !== null && $i >= 0; $i--) {
            if ($this->frames[$i]['kind'] === $kind && $this->frames[$i]['key'] === $key) {
                array_splice($this->frames, $i, 1);

                return;
            }
        }
    }

    /** Name a scheduled task for the source. Never the command line: arguments and paths can carry secrets. */
    public function enterTask(ScheduledEvent $task): void
    {
        $this->enter('schedule', 'schedule:'.$this->taskName($task));
    }

    public function requestId(): string
    {
        $key = config('audit.request_id.context_key');

        if (is_string($key) && $key !== '') {
            $value = Context::get($key) ?? Context::getHidden($key);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        $carried = $this->origin()['request_id'] ?? null;

        if (is_string($carried) && $carried !== '') {
            return $carried;
        }

        return $this->requestId ??= (string) Str::ulid();
    }

    /**
     * Set when a route matches. It is never reset: a PHP process serves HTTP or
     * runs console, and Octane resets this scoped instance between requests.
     * Only a test that serves a request and then runs console work in the same
     * process sees it stay on; use startNewProcess() style resets there.
     */
    public function markHttp(bool $http = true): void
    {
        $this->http = $http;
    }

    public function isHttp(): bool
    {
        return $this->http || ! app()->runningInConsole();
    }

    public function ipAddress(): ?string
    {
        return $this->isHttp() ? request()->ip() : null;
    }

    public function userAgent(): ?string
    {
        return $this->isHttp() ? request()->userAgent() : null;
    }

    /**
     * What goes into hidden Context when a job is dispatched: the actor and the
     * request id. A job dispatched from a job passes the same origin on.
     *
     * A queue dispatch is not an audit write, so this never throws: an actor
     * that cannot be snapshotted (for example a class missing from an enforced
     * morph map) is carried as nobody.
     *
     * @return array{actor: ?array, request_id: string}
     */
    public function dispatchOrigin(): array
    {
        try {
            $actor = $this->actor()?->toArray();
        } catch (Throwable) {
            $actor = null;
        }

        try {
            $requestId = $this->requestId();
        } catch (Throwable) {
            $requestId = (string) Str::ulid();
        }

        return ['actor' => $actor, 'request_id' => $requestId];
    }

    /** @return array<string, mixed> */
    private function origin(): array
    {
        $origin = Context::getHidden(self::ORIGIN_KEY);

        return is_array($origin) ? $origin : [];
    }

    private function inQueue(): bool
    {
        foreach ($this->frames as $frame) {
            if ($frame['kind'] === 'queue') {
                return true;
            }
        }

        return false;
    }

    /**
     * A session login on a route that never resolved the user. The user is
     * looked up from the session's login id through the provider, which has no
     * side effects: no Authenticated event, and never the remember-cookie path.
     */
    private function actorFromSession(Guard $guard): ?Actor
    {
        if (! $guard instanceof SessionGuard) {
            return null;
        }

        $id = $guard->getSession()->get($guard->getName());

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $key = (string) $id;

        if (! array_key_exists($key, $this->sessionActors)) {
            $this->sessionActors[$key] = Actor::from($guard->getProvider()->retrieveById($id));
        }

        return $this->sessionActors[$key];
    }

    private function taskName(ScheduledEvent $task): string
    {
        if (is_string($task->description) && $task->description !== '') {
            return $task->description;
        }

        if ($task instanceof CallbackEvent) {
            return $task->getSummaryForDisplay();
        }

        // A shell task: its name is the artisan command, without arguments. Any other command line stays out.
        if (preg_match('/artisan[\'"]?\s+([\w:.\-]+)/', (string) $task->command, $match)) {
            return $match[1];
        }

        return 'command';
    }

    private function actorFromContextKey(): ?Actor
    {
        $key = config('audit.actor.context_key');

        if (! is_string($key) || $key === '') {
            return null;
        }

        $id = Context::get($key) ?? Context::getHidden($key);

        if (! is_scalar($id) || (string) $id === '') {
            return null;
        }

        return $this->contextKeyActors[(string) $id] ??= $this->retrieveActor($id);
    }

    private function retrieveActor(mixed $id): ?Actor
    {
        $guard = Auth::guard();

        if (! method_exists($guard, 'getProvider')) {
            return null;
        }

        return Actor::from($guard->getProvider()->retrieveById($id));
    }
}
