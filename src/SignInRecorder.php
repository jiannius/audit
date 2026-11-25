<?php

namespace Jiannius\Audit;

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Jiannius\Audit\Context\Actor;
use Stringable;
use Throwable;

/**
 * Turns Laravel's sign-in events into `auth` rows. It goes straight to the
 * Recorder: sign-in rows are not model changes, so the observer's rules do not apply.
 *
 * Nothing here resolves a user (no `Auth::user()`, so no remember-cookie login), loads a
 * relation or reads an attribute that was not loaded. Of the credentials on a failed
 * sign-in, only the configured identifier keys are ever read.
 */
class SignInRecorder
{
    /** @var array<string, array{0: class-string, 1: string}> config name => event class and handler method */
    public const EVENTS = [
        'login' => [Login::class, 'login'],
        'login_failed' => [Failed::class, 'failed'],
        'lockout' => [Lockout::class, 'lockout'],
        'logout' => [Logout::class, 'logout'],
        'logout_current_device' => [CurrentDeviceLogout::class, 'logoutCurrentDevice'],
        'logout_other_devices' => [OtherDeviceLogout::class, 'logoutOtherDevices'],
        'password_reset' => [PasswordReset::class, 'passwordReset'],
    ];

    /** The longest identifier kept, in characters. It matches the actor_identifier column. */
    private const IDENTIFIER_LENGTH = 255;

    public function __construct(private readonly Recorder $recorder) {}

    /**
     * Login fires after the session is written, so a row that cannot be written
     * undoes the login before the error goes on.
     */
    public function login(Login $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $guard = $this->guardName($event->guard);

        try {
            $this->write('login', $event->user, true, [
                'guard' => $guard,
                'via_remember' => $this->viaRemember($guard),
                'remember' => (bool) $event->remember,
            ]);
        } catch (Throwable $e) {
            $this->undoLogin($guard);

            throw $e;
        }
    }

    /** Fails closed: a sign-in attempt that cannot be recorded fails. */
    public function failed(Failed $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        [$key, $value] = $this->identifier(is_array($event->credentials) ? $event->credentials : []);

        $this->write('login_failed', $event->user, false, [
            'guard' => $this->guardName($event->guard),
            'identifier_key' => $key,
            'identifier' => $value,
        ]);
    }

    public function lockout(Lockout $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        [$key, $value] = $this->identifier($event->request->input());

        $this->write('lockout', null, false, [
            'identifier_key' => $key,
            'identifier' => $value,
        ]);
    }

    public function logout(Logout $event): void
    {
        $this->signOut('logout', $event->user, $event->guard);
    }

    public function logoutCurrentDevice(CurrentDeviceLogout $event): void
    {
        $this->signOut('logout_current_device', $event->user, $event->guard);
    }

    public function logoutOtherDevices(OtherDeviceLogout $event): void
    {
        $this->signOut('logout_other_devices', $event->user, $event->guard);
    }

    public function passwordReset(PasswordReset $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->write('password_reset', $event->user, true, []);
    }

    /** Fails open: a broken audit must never stop someone signing out. The error is reported, never thrown. */
    private function signOut(string $event, mixed $user, mixed $guard): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $this->write($event, $user, true, ['guard' => $this->guardName($guard)]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function write(string $event, mixed $user, bool $actsAsUser, array $meta): void
    {
        $user = $user instanceof Authenticatable ? $user : null;

        $audit = new PendingAudit($event, Category::AUTH, $user instanceof Model ? $user : null, meta: $meta);

        // Set explicitly, even to nobody: the context must not guess who a sign-in row belongs to.
        $audit->actor($actsAsUser ? Actor::from($user) : null);

        $this->recorder->record($audit);
    }

    /**
     * The first configured key present in the input, and its value as typed
     * (trimmed, truncated). No other key is read.
     *
     * @param  array<array-key, mixed>  $input
     * @return array{0: ?string, 1: ?string}
     */
    private function identifier(array $input): array
    {
        foreach ((array) config('audit.auth.identifier', []) as $key) {
            if (! is_string($key) || ! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];

            if (is_string($value) || is_int($value) || is_float($value) || $value instanceof Stringable) {
                return [$key, mb_substr(trim((string) $value), 0, self::IDENTIFIER_LENGTH)];
            }
        }

        return [null, null];
    }

    /** Whether this login came from a remember cookie. Reads a flag: it never resolves the user. */
    private function viaRemember(?string $guardName): bool
    {
        $guard = $this->guard($guardName);

        return $guard instanceof SessionGuard && $guard->viaRemember();
    }

    /**
     * Take back a login whose row could not be written: forget the user, remove the
     * login from the session and unqueue the recaller cookie. It does not call
     * logout(), which would resolve the user, rotate the remember token and fire
     * more events. Each step runs on its own, so one failing cannot skip the rest.
     */
    private function undoLogin(?string $guardName): void
    {
        $guard = $this->guard($guardName);

        if ($guard instanceof SessionGuard) {
            $this->attempt(function () use ($guard) {
                // What logout() ends with, without its side effects: no user, and no way back in this request.
                (function () {
                    $this->user = null;
                    $this->loggedOut = true;
                })->call($guard);
            });

            $this->attempt(function () use ($guard) {
                $guard->getSession()->forget([$guard->getName(), 'password_hash_'.$guard->getName()]);
            });

            $this->attempt(fn () => $guard->getCookieJar()->unqueue($guard->getRecallerName()));

            return;
        }

        if ($guard !== null && method_exists($guard, 'forgetUser')) {
            $this->attempt(fn () => $guard->forgetUser());
        }
    }

    private function attempt(callable $step): void
    {
        try {
            $step();
        } catch (Throwable) {
            // The audit error is the one that matters, and it is rethrown by the caller.
        }
    }

    private function guard(?string $name): ?object
    {
        try {
            return Auth::guard($name);
        } catch (Throwable) {
            return null;
        }
    }

    private function guardName(mixed $guard): ?string
    {
        return is_string($guard) && $guard !== '' ? $guard : null;
    }

    private function enabled(): bool
    {
        return (bool) config('audit.enabled', true);
    }
}
