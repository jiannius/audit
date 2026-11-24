<?php

namespace Jiannius\Audit\Context;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

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

    /**
     * First match wins: an actingAs() override, the signed-in user, the origin
     * carried into a queued job, the configured Context key, then nobody.
     */
    public function actor(): ?Actor
    {
        if ($this->actorOverrides !== []) {
            return $this->actorOverrides[array_key_last($this->actorOverrides)];
        }

        // hasUser() on purpose: user() could trigger a remember-cookie login as a side effect.
        $guard = Auth::guard();

        if ($guard->hasUser()) {
            return Actor::from($guard->user());
        }

        return Actor::fromArray($this->origin()['actor'] ?? null) ?? $this->actorFromContextKey();
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
     * What goes into hidden Context when a job is dispatched: the actor, the
     * request id and where it all started. A job dispatched from a job passes
     * the same origin on.
     *
     * @return array{actor: ?array, request_id: string, source: string}
     */
    public function dispatchOrigin(): array
    {
        return [
            'actor' => $this->actor()?->toArray(),
            'request_id' => $this->requestId(),
            'source' => $this->origin()['source'] ?? $this->source(),
        ];
    }

    /** @return array<string, mixed> */
    private function origin(): array
    {
        $origin = Context::getHidden(self::ORIGIN_KEY);

        return is_array($origin) ? $origin : [];
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
