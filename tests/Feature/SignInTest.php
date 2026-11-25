<?php

use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Jiannius\Audit\Audit;
use Jiannius\Audit\Exceptions\AuditWriteFailed;
use Jiannius\Audit\Tests\Fixtures\UnmappedUser;
use Jiannius\Audit\Tests\Fixtures\User;

/** The last row of an event, which is what these tests read back. */
function lastSignIn(string $event): Audit
{
    return Audit::query()->where('event', $event)->orderByDesc('id')->firstOrFail();
}

function breakSignInAudits(): void
{
    Schema::dropIfExists(config('audit.table'));
}

function recallerName(): string
{
    return Auth::guard()->getRecallerName();
}

/** A request the way a form posts it. */
function formRequest(array $input): Request
{
    return Request::create('/login', 'POST', $input);
}

describe('login', function () {
    it('records a password login with the user as subject and actor', function () {
        $user = makeUser();

        Auth::attempt(['email' => 'ada@example.test', 'password' => 'secret-password']);

        $audit = lastSignIn('login');

        expect($this->audits())->toHaveCount(1)
            ->and($audit->category)->toBe('auth')
            ->and($audit->subject_type)->toBe('user')
            ->and($audit->subject_id)->toBe((string) $user->id)
            ->and($audit->subject_label)->toBe('Ada Lovelace')
            ->and($audit->actor_type)->toBe('user')
            ->and($audit->actor_id)->toBe((string) $user->id)
            ->and($audit->actor_name)->toBe('Ada Lovelace')
            ->and($audit->actor_identifier)->toBe('ada@example.test')
            ->and($audit->old_values)->toBeNull()
            ->and($audit->new_values)->toBeNull()
            ->and($audit->meta)->toEqual(['guard' => 'web', 'via_remember' => false, 'remember' => false]);
    });

    it('records the remember choice, which is not the same as signing in by cookie', function () {
        makeUser();

        Auth::attempt(['email' => 'ada@example.test', 'password' => 'secret-password'], true);

        expect(lastSignIn('login')->meta)->toEqual(['guard' => 'web', 'via_remember' => false, 'remember' => true]);
    });

    it('does not claim a password for a programmatic login', function () {
        Auth::login(makeUser());

        expect(lastSignIn('login')->meta['via_remember'])->toBeFalse();
    });

    it('records via_remember for a login from a remember cookie', function () {
        $user = makeUser();

        $cookie = $this->post('/login', ['email' => 'ada@example.test', 'password' => 'secret-password', 'remember' => '1'])
            ->assertOk()
            ->getCookie(recallerName());

        expect($cookie)->not->toBeNull();

        // A new browser session: nothing in the session, only the cookie.
        $this->startNewProcess();
        $this->flushSession();

        $this->withCookie(recallerName(), $cookie->getValue())->get('/whoami')->assertSee((string) $user->id);

        $logins = Audit::query()->where('event', 'login')->orderBy('id')->get();

        expect($logins)->toHaveCount(2)
            ->and($logins[0]->meta)->toEqual(['guard' => 'web', 'via_remember' => false, 'remember' => true])
            ->and($logins[1]->meta)->toEqual(['guard' => 'web', 'via_remember' => true, 'remember' => true])
            ->and($logins[1]->subject_id)->toBe((string) $user->id)
            ->and($logins[1]->actor_id)->toBe((string) $user->id)
            ->and($logins[1]->source)->toBe('web');
    });
});

describe('login_failed', function () {
    it('records the matched user as subject, nobody as actor, and the identifier as typed', function () {
        $user = makeUser();

        Auth::attempt(['email' => ' Ada@Example.test ', 'password' => 'wrong-password']);
        Auth::attempt(['email' => 'ada@example.test', 'password' => 'wrong-password']);

        $audit = lastSignIn('login_failed');

        expect($audit->category)->toBe('auth')
            ->and($audit->subject_type)->toBe('user')
            ->and($audit->subject_id)->toBe((string) $user->id)
            ->and($audit->actor_type)->toBeNull()
            ->and($audit->actor_id)->toBeNull()
            ->and($audit->actor_name)->toBeNull()
            ->and($audit->actor_identifier)->toBeNull()
            ->and($audit->meta)->toEqual(['guard' => 'web', 'identifier_key' => 'email', 'identifier' => 'ada@example.test']);
    });

    it('has no subject when nobody matched', function () {
        makeUser();

        Auth::attempt(['email' => 'nobody@example.test', 'password' => 'whatever']);

        $audit = lastSignIn('login_failed');

        expect($audit->subject_type)->toBeNull()
            ->and($audit->subject_id)->toBeNull()
            ->and($audit->subject_label)->toBeNull()
            ->and($audit->meta['identifier'])->toBe('nobody@example.test');
    });

    it('is never attributed to the user who happens to be signed in', function () {
        $this->actingAs(makeUser());

        event(new Failed('web', null, ['email' => 'someone@example.test', 'password' => 'x']));

        expect(lastSignIn('login_failed')->actor_id)->toBeNull();
    });

    it('records no identifier when none of the listed keys is present', function () {
        event(new Failed('web', null, ['phone' => '0123', 'password' => 'x']));

        expect(lastSignIn('login_failed')->meta)->toEqual(['guard' => 'web', 'identifier_key' => null, 'identifier' => null]);
    });

    it('never stores the password, or anything else the credentials carry', function () {
        $user = makeUser();

        Auth::attempt(['email' => 'ada@example.test', 'password' => 'Hunter2-plaintext-password']);
        event(new Failed('web', $user, [
            'email' => 'ada@example.test',
            'password' => 'Hunter2-plaintext-password',
            'otp' => 'otp-123456-secret',
            'api_token' => 'tok-abcdef-secret',
            'nested' => ['password' => 'Hunter2-nested-password'],
        ]));
        event(new Failed('web', null, ['password' => 'Hunter2-only-password', 'otp' => 'otp-123456-secret']));
        event(new Lockout(formRequest(['email' => 'ada@example.test', 'password' => 'Hunter2-plaintext-password'])));

        $rows = everythingAudited();

        expect(Audit::query()->count())->toBe(4);

        foreach (['Hunter2', 'otp-123456', 'tok-abcdef', 'nested'] as $secret) {
            expect($rows)->not->toContain($secret);
        }
    });

    it('is not shown the password by the recorded row of a real wrong-password request', function () {
        makeUser();

        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'Hunter2-plaintext-password'])->assertStatus(401);

        expect(lastSignIn('login_failed')->meta['identifier'])->toBe('ada@example.test')
            ->and(everythingAudited())->not->toContain('Hunter2');
    });
});

describe('the identifier list', function () {
    it('records the key an email host signs in with', function () {
        event(new Failed('web', null, ['email' => 'ada@example.test', 'password' => 'x']));

        expect(lastSignIn('login_failed')->meta)->toMatchArray(['identifier_key' => 'email', 'identifier' => 'ada@example.test']);
    });

    it('records the key a username host signs in with', function () {
        $user = makeUser();

        Auth::attempt(['username' => 'ada', 'password' => 'wrong-password']);
        $this->post('/login', ['username' => 'ada', 'password' => 'wrong-password'])->assertStatus(401);

        $failed = Audit::query()->where('event', 'login_failed')->orderBy('id')->get();

        expect($failed)->toHaveCount(2)
            ->and($failed[0]->meta)->toMatchArray(['identifier_key' => 'username', 'identifier' => 'ada'])
            ->and($failed[0]->subject_id)->toBe((string) $user->id)
            ->and($failed[1]->meta)->toMatchArray(['identifier_key' => 'username', 'identifier' => 'ada']);
    });

    it('uses the first listed key that is present', function () {
        event(new Failed('web', null, ['username' => 'ada', 'email' => 'ada@example.test', 'password' => 'x']));

        expect(lastSignIn('login_failed')->meta)->toMatchArray(['identifier_key' => 'email', 'identifier' => 'ada@example.test']);

        config(['audit.auth.identifier' => ['username', 'email']]);
        event(new Failed('web', null, ['username' => 'ada', 'email' => 'ada@example.test', 'password' => 'x']));

        expect(lastSignIn('login_failed')->meta)->toMatchArray(['identifier_key' => 'username', 'identifier' => 'ada']);
    });

    it('reads only the keys that are listed', function () {
        config(['audit.auth.identifier' => ['phone']]);

        event(new Failed('web', null, ['email' => 'ada@example.test', 'password' => 'x']));

        expect(lastSignIn('login_failed')->meta)->toMatchArray(['identifier_key' => null, 'identifier' => null])
            ->and(everythingAudited())->not->toContain('ada@example.test');
    });

    it('skips a key whose value cannot be an identifier', function () {
        event(new Failed('web', null, ['email' => ['a@b.test'], 'username' => 'ada', 'password' => 'x']));

        expect(lastSignIn('login_failed')->meta)->toMatchArray(['identifier_key' => 'username', 'identifier' => 'ada']);
    });

    it('trims and truncates the value, and otherwise keeps it as typed', function () {
        event(new Failed('web', null, ['email' => '  Ada.O\'Neil+Test@Example.TEST  ']));
        event(new Failed('web', null, ['email' => str_repeat('é', 400)]));

        $failed = Audit::query()->where('event', 'login_failed')->orderBy('id')->get();

        expect($failed[0]->meta['identifier'])->toBe('Ada.O\'Neil+Test@Example.TEST')
            ->and($failed[1]->meta['identifier'])->toBe(str_repeat('é', 255));
    });

    it('reads a lockout from the request input', function () {
        event(new Lockout(formRequest(['email' => ' ada@example.test ', 'password' => 'x'])));
        event(new Lockout(formRequest(['username' => 'ada', 'password' => 'x'])));
        event(new Lockout(formRequest(['username' => 'ada', 'email' => 'ada@example.test'])));

        $lockouts = Audit::query()->where('event', 'lockout')->orderBy('id')->get();

        expect($lockouts[0]->meta)->toEqual(['identifier_key' => 'email', 'identifier' => 'ada@example.test'])
            ->and($lockouts[1]->meta)->toEqual(['identifier_key' => 'username', 'identifier' => 'ada'])
            ->and($lockouts[2]->meta)->toEqual(['identifier_key' => 'email', 'identifier' => 'ada@example.test']);
    });

    it('records null identifier fields for a lockout whose input has none of the keys', function () {
        event(new Lockout(formRequest(['password' => 'Hunter2-plaintext-password', 'q' => 'x'])));

        expect(lastSignIn('lockout')->meta)->toEqual(['identifier_key' => null, 'identifier' => null])
            ->and(everythingAudited())->not->toContain('Hunter2');
    });

    it('reads a lockout identifier merged into the input, as a Livewire form does', function () {
        $request = formRequest([]);
        $request->merge(['email' => 'ada@example.test']);

        event(new Lockout($request));

        expect(lastSignIn('lockout')->meta)->toEqual(['identifier_key' => 'email', 'identifier' => 'ada@example.test']);
    });
});

describe('lockout and password reset', function () {
    it('records a lockout with no subject and no actor', function () {
        $this->actingAs(makeUser());

        event(new Lockout(formRequest(['email' => 'ada@example.test'])));

        $audit = lastSignIn('lockout');

        expect($audit->category)->toBe('auth')
            ->and($audit->subject_type)->toBeNull()
            ->and($audit->subject_id)->toBeNull()
            ->and($audit->actor_id)->toBeNull();
    });

    it('records a password reset with the user as subject and actor, and no meta', function () {
        $user = makeUser();

        event(new PasswordReset($user));

        $audit = lastSignIn('password_reset');

        expect($audit->category)->toBe('auth')
            ->and($audit->subject_type)->toBe('user')
            ->and($audit->subject_id)->toBe((string) $user->id)
            ->and($audit->actor_id)->toBe((string) $user->id)
            ->and($audit->actor_name)->toBe('Ada Lovelace')
            ->and($audit->meta)->toBeNull();
    });
});

describe('sign-out', function () {
    it('records a logout', function () {
        $user = makeUser();
        Auth::login($user);

        Auth::logout();

        $audit = lastSignIn('logout');

        expect($audit->category)->toBe('auth')
            ->and($audit->subject_type)->toBe('user')
            ->and($audit->subject_id)->toBe((string) $user->id)
            ->and($audit->actor_id)->toBe((string) $user->id)
            ->and($audit->meta)->toEqual(['guard' => 'web']);
    });

    it('records a current device logout', function () {
        $user = makeUser();
        Auth::login($user);

        Auth::logoutCurrentDevice();

        $audit = lastSignIn('logout_current_device');

        expect($audit->subject_id)->toBe((string) $user->id)
            ->and($audit->actor_id)->toBe((string) $user->id)
            ->and($audit->meta)->toEqual(['guard' => 'web']);
    });

    it('records an other devices logout', function () {
        $user = makeUser();
        Auth::login($user);

        Auth::logoutOtherDevices('secret-password');

        $audit = lastSignIn('logout_other_devices');

        expect($audit->subject_id)->toBe((string) $user->id)
            ->and($audit->actor_id)->toBe((string) $user->id)
            ->and($audit->meta)->toEqual(['guard' => 'web']);
    });

    it('records a logout from a user loaded with a partial select, under strict mode', function () {
        $user = makeUser();
        $partial = User::query()->select('id')->findOrFail($user->id);

        event(new Logout('web', $partial));

        $audit = lastSignIn('logout');

        expect($audit->subject_id)->toBe((string) $user->id)
            ->and($audit->actor_id)->toBe((string) $user->id)
            ->and($audit->actor_name)->toBeNull();
    });

    it('records a logout with nobody to blame when the guard had no user', function () {
        event(new Logout('web', null));

        $audit = lastSignIn('logout');

        expect($audit->subject_id)->toBeNull()
            ->and($audit->actor_id)->toBeNull();
    });
});

describe('when the insert fails', function () {
    it('undoes a password login and rethrows the audit error', function () {
        makeUser();
        breakSignInAudits();

        $guard = Auth::guard();
        $key = $guard->getName();

        expect(fn () => Auth::attempt(['email' => 'ada@example.test', 'password' => 'secret-password'], true))
            ->toThrow(AuditWriteFailed::class);

        expect($guard->hasUser())->toBeFalse()
            ->and(Auth::check())->toBeFalse()
            ->and($guard->getSession()->has($key))->toBeFalse()
            ->and($guard->getSession()->has('password_hash_'.$key))->toBeFalse()
            ->and(Cookie::hasQueued($guard->getRecallerName()))->toBeFalse();
    });

    it('undoes a login made straight on the guard', function () {
        $user = makeUser();
        breakSignInAudits();

        $guard = Auth::guard();

        expect(fn () => $guard->login($user, true))->toThrow(AuditWriteFailed::class);
        expect($guard->hasUser())->toBeFalse()
            ->and(Cookie::hasQueued($guard->getRecallerName()))->toBeFalse();
    });

    it('does not fire sign-out events or call the guard user while undoing', function () {
        makeUser();
        breakSignInAudits();
        Exceptions::fake();

        expect(fn () => Auth::attempt(['email' => 'ada@example.test', 'password' => 'secret-password']))->toThrow(AuditWriteFailed::class);

        Exceptions::assertNothingReported();
    });

    it('undoes a remember-cookie login, so the cookie does not sign anyone in during this request', function () {
        $user = makeUser();

        $cookie = $this->post('/login', ['email' => 'ada@example.test', 'password' => 'secret-password', 'remember' => '1'])
            ->getCookie(recallerName());

        $this->startNewProcess();
        $this->flushSession();
        breakSignInAudits();

        $this->withoutExceptionHandling();

        expect(fn () => $this->withCookie(recallerName(), $cookie->getValue())->get('/whoami'))->toThrow(AuditWriteFailed::class);

        $guard = Auth::guard();

        expect($guard->hasUser())->toBeFalse()
            ->and($guard->getSession()->has($guard->getName()))->toBeFalse()
            ->and($guard->user())->toBeNull();
    });

    it('fails the sign-in attempt for a failed login', function () {
        makeUser();
        breakSignInAudits();

        expect(fn () => Auth::attempt(['email' => 'ada@example.test', 'password' => 'wrong-password']))->toThrow(AuditWriteFailed::class);
    });

    it('throws for a lockout and a password reset', function () {
        $user = makeUser();
        breakSignInAudits();

        expect(fn () => event(new Lockout(formRequest(['email' => 'ada@example.test']))))->toThrow(AuditWriteFailed::class);
        expect(fn () => event(new PasswordReset($user)))->toThrow(AuditWriteFailed::class);
    });

    it('never throws from a sign-out, and reports the failure', function () {
        $user = makeUser();
        breakSignInAudits();
        Exceptions::fake();

        // setUser() signs in without the Login event, which would fail first.
        Auth::setUser($user);
        Auth::logout();

        expect(Auth::guard()->hasUser())->toBeFalse();

        Auth::setUser($user);
        Auth::logoutCurrentDevice();

        expect(Auth::guard()->hasUser())->toBeFalse();

        Auth::setUser($user);
        Auth::logoutOtherDevices('secret-password');

        Exceptions::assertReported(AuditWriteFailed::class);
        expect(Exceptions::reported())->toHaveCount(3);
    });

    it('reports a failure that holds no values', function () {
        makeUser();
        $user = User::query()->firstOrFail();
        breakSignInAudits();
        Exceptions::fake();

        event(new Logout('web', $user));

        Exceptions::assertReported(fn (AuditWriteFailed $e) => ! str_contains($e->getMessage(), 'ada@example.test') && ! str_contains($e->getMessage(), 'insert into'));
    });
});

describe('a user class that is not in the morph map', function () {
    it('undoes the login and rethrows', function () {
        $user = UnmappedUser::create(['name' => 'Rogue', 'email' => 'rogue@example.test', 'password' => 'secret-password']);

        $guard = Auth::guard();

        expect(fn () => $guard->login($user, true))->toThrow(ClassMorphViolationException::class);

        expect($guard->hasUser())->toBeFalse()
            ->and($guard->getSession()->has($guard->getName()))->toBeFalse()
            ->and(Cookie::hasQueued($guard->getRecallerName()))->toBeFalse()
            ->and($this->audits())->toHaveCount(0);
    });

    it('reports instead of throwing for a sign-out', function () {
        $user = UnmappedUser::create(['name' => 'Rogue', 'email' => 'rogue@example.test', 'password' => 'secret-password']);
        Exceptions::fake();

        event(new Logout('web', $user));
        event(new CurrentDeviceLogout('web', $user));
        event(new OtherDeviceLogout('web', $user));

        expect(Exceptions::reported())->toHaveCount(3);
        Exceptions::assertReported(ClassMorphViolationException::class);
        expect($this->audits())->toHaveCount(0);
    });

    it('throws for a failed login with a matched user, and for a password reset', function () {
        $user = UnmappedUser::create(['name' => 'Rogue', 'email' => 'rogue@example.test', 'password' => 'secret-password']);

        expect(fn () => event(new Failed('web', $user, ['email' => 'rogue@example.test'])))->toThrow(ClassMorphViolationException::class);
        expect(fn () => event(new PasswordReset($user)))->toThrow(ClassMorphViolationException::class);
    });
});

describe('config', function () {
    it('registers only the events listed in auth.events', function () {
        $user = makeUser();

        $this->useSignInEvents(['login', 'lockout']);

        expect(Event::hasListeners(Login::class))->toBeTrue()
            ->and(Event::hasListeners(Lockout::class))->toBeTrue()
            ->and(Event::hasListeners(Failed::class))->toBeFalse()
            ->and(Event::hasListeners(Logout::class))->toBeFalse()
            ->and(Event::hasListeners(CurrentDeviceLogout::class))->toBeFalse()
            ->and(Event::hasListeners(OtherDeviceLogout::class))->toBeFalse()
            ->and(Event::hasListeners(PasswordReset::class))->toBeFalse();

        Auth::attempt(['email' => 'ada@example.test', 'password' => 'wrong-password']);
        event(new PasswordReset($user));
        event(new Lockout(formRequest(['email' => 'ada@example.test'])));
        Auth::login($user);
        Auth::logout();

        expect($this->audits()->pluck('event')->all())->toBe(['lockout', 'login']);
    });

    it('registers nothing for an empty list, and ignores a name it does not know', function () {
        $this->useSignInEvents([]);

        expect(Event::hasListeners(Login::class))->toBeFalse();

        $this->useSignInEvents(['nonsense', 'logout']);

        expect(Event::hasListeners(Logout::class))->toBeTrue()
            ->and(Event::hasListeners(Login::class))->toBeFalse();
    });

    it('writes nothing, and undoes nothing, when audit.enabled is false', function () {
        $user = makeUser();
        config(['audit.enabled' => false]);
        breakSignInAudits();

        Auth::attempt(['email' => 'ada@example.test', 'password' => 'wrong-password']);
        Auth::attempt(['email' => 'ada@example.test', 'password' => 'secret-password'], true);
        event(new Lockout(formRequest(['email' => 'ada@example.test'])));
        event(new PasswordReset($user));
        Auth::logout();

        expect(Auth::check())->toBeFalse();

        config(['audit.enabled' => true]);
        $this->createAuditsTable();

        expect($this->audits())->toHaveCount(0);

        config(['audit.enabled' => false]);
        Auth::attempt(['email' => 'ada@example.test', 'password' => 'secret-password']);

        expect(Auth::check())->toBeTrue();
    });
});

describe('a real flow', function () {
    it('records a login, a wrong password, a lockout and a logout in order', function () {
        $user = makeUser();

        $this->post('/throttled-login', ['email' => 'ada@example.test', 'password' => 'nope-1'])->assertStatus(401);
        $this->post('/throttled-login', ['email' => 'ada@example.test', 'password' => 'nope-2'])->assertStatus(401);
        $this->post('/throttled-login', ['email' => 'ada@example.test', 'password' => 'nope-3'])->assertStatus(429);

        $this->startNewProcess();
        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'secret-password'])->assertOk();
        $this->post('/logout')->assertOk();

        $audits = $this->audits();

        expect($audits->pluck('event')->all())->toBe(['login_failed', 'login_failed', 'lockout', 'login', 'logout'])
            ->and($audits->pluck('category')->unique()->all())->toBe(['auth'])
            ->and($audits->pluck('source')->unique()->all())->toBe(['web'])
            ->and($audits[2]->meta)->toEqual(['identifier_key' => 'email', 'identifier' => 'ada@example.test'])
            ->and($audits[2]->subject_type)->toBeNull()
            ->and($audits[3]->actor_id)->toBe((string) $user->id)
            ->and($audits[3]->ip_address)->toBe('127.0.0.1')
            ->and($audits[4]->actor_id)->toBe((string) $user->id)
            ->and($audits[4]->subject_id)->toBe((string) $user->id)
            ->and(everythingAudited())->not->toContain('nope-')->not->toContain('secret-password');
    });

    it('records a login with and without remember through a route', function () {
        makeUser();

        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'secret-password'])->assertOk();
        $this->startNewProcess();
        $this->flushSession();
        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'secret-password', 'remember' => '1'])->assertOk();

        $logins = Audit::query()->where('event', 'login')->orderBy('id')->get();

        expect($logins->pluck('meta')->all())->toEqual([
            ['guard' => 'web', 'via_remember' => false, 'remember' => false],
            ['guard' => 'web', 'via_remember' => false, 'remember' => true],
        ]);
    });

    it('records a username host through a route', function () {
        $user = makeUser();

        $this->post('/login', ['username' => 'ada', 'password' => 'secret-password'])->assertOk();
        $this->startNewProcess();
        $this->post('/login', ['username' => 'ada', 'password' => 'wrong-password'])->assertStatus(401);

        expect(lastSignIn('login')->subject_id)->toBe((string) $user->id)
            ->and(lastSignIn('login_failed')->meta)->toEqual(['guard' => 'web', 'identifier_key' => 'username', 'identifier' => 'ada']);
    });

    it('does not sign a user in from a remember cookie while recording a failed login', function () {
        $user = makeUser();

        $cookie = $this->post('/login', ['email' => 'ada@example.test', 'password' => 'secret-password', 'remember' => '1'])
            ->getCookie(recallerName());

        $this->startNewProcess();
        $this->flushSession();

        // A failed login on a request that carries a valid remember cookie must not sign that user in.
        $this->withCookie(recallerName(), $cookie->getValue())
            ->post('/login', ['email' => 'ada@example.test', 'password' => 'wrong-password'])
            ->assertStatus(401);

        $failed = lastSignIn('login_failed');

        expect($failed->actor_id)->toBeNull()
            ->and($failed->subject_id)->toBe((string) $user->id)
            ->and(Audit::query()->where('event', 'login')->count())->toBe(1)
            ->and(Auth::guard()->hasUser())->toBeFalse();
    });
});
