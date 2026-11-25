<?php

use Jiannius\Audit\AuditServiceProvider;

it('keeps the other defaults when a published config is partial', function () {
    config(['audit' => [
        'actor' => ['identifier' => 'username'],
        'attributes' => ['exclude' => ['only_this']],
        'prune' => ['schedule' => null],
        'table' => 'my_audits',
    ]]);

    (new AuditServiceProvider($this->app))->register();

    expect(config('audit.actor'))->toBe(['name' => 'name', 'identifier' => 'username', 'context_key' => null])
        ->and(config('audit.attributes.exclude'))->toBe(['only_this'])
        ->and(config('audit.attributes.redact'))->toBe(['password', 'api_key', '*_api_key', 'secret_key', 'private_key', 'token'])
        ->and(config('audit.attributes.redact_hidden'))->toBeTrue()
        ->and(config('audit.prune'))->toBe(['schedule' => null, 'chunk' => 1000, 'on_one_server' => false])
        ->and(config('audit.table'))->toBe('my_audits')
        ->and(config('audit.auth.identifier'))->toBe(['email', 'username'])
        ->and(config('audit.max_value_bytes'))->toBe(65536);
});

it('replaces lists rather than merging them by index', function () {
    $merged = AuditServiceProvider::mergeDefaults(
        ['list' => ['a', 'b', 'c'], 'map' => ['x' => 1, 'y' => 2], 'scopes' => []],
        ['list' => ['z'], 'map' => ['y' => 3], 'scopes' => ['team_id' => 'Resolver']],
    );

    expect($merged)->toBe(['list' => ['z'], 'map' => ['x' => 1, 'y' => 3], 'scopes' => ['team_id' => 'Resolver']]);
});

it('lets a host empty a list, and an empty map keeps its defaults', function () {
    $merged = AuditServiceProvider::mergeDefaults(
        ['list' => ['a'], 'map' => ['x' => 1]],
        ['list' => [], 'map' => []],
    );

    expect($merged)->toBe(['list' => [], 'map' => ['x' => 1]]);
});
