<?php

use Jiannius\Audit\Audit;
use Jiannius\Audit\Export\RawFormatter;

return [
    'enabled' => env('AUDIT_ENABLED', true),   // off only while seeding or importing, never in production
    'table' => 'audits',
    'connection' => null,                      // null: the subject's connection (shares its transaction)
    'transactional_saves' => true,             // the off switch is for hosts that cannot accept saved-hook rollbacks
    'model' => Audit::class,
    'max_value_bytes' => 65536,                // a string in old/new/meta over this is stored as "[truncated N bytes]"

    'attributes' => [
        'exclude' => ['created_at', 'updated_at', 'remember_token', '*_token', '*_secret', 'two_factor_recovery_codes'],
        'redact' => ['password'],
        'redact_hidden' => true,
        'redact_encrypted' => true,
        'label' => ['name', 'title', 'number', 'code', 'email'],
    ],

    'actor' => [
        'name' => 'name',            // attribute snapshotted as actor_name
        'identifier' => 'email',     // attribute snapshotted as actor_identifier, e.g. 'username' or 'phone'
        'context_key' => null,       // fallback: a host Context key holding the actor id inside jobs
    ],

    'request_id' => ['context_key' => null],   // reuse a request id the host already puts in Context

    'scopes' => [
        // Short form: a resolver only. The column is filled on write; reads are not restricted.
        // 'region_id' => App\Audit\RegionScope::class,   // invokable: fn (PendingAudit $audit): string|int|null

        // Long form: opt-in tenant isolation on read
        // 'team_id' => [
        //     'resolver' => App\Audit\TeamScope::class,  // fills the column on write
        //     'read'     => App\Audit\CurrentTeam::class, // invokable: fn (): string|int|null
        // ],
    ],

    'auth' => [
        'events' => ['login', 'login_failed', 'logout', 'logout_current_device', 'logout_other_devices', 'lockout', 'password_reset'],
        'identifier' => ['email', 'username'],  // the first key present is recorded; nothing else is ever read
    ],

    'retention' => [
        'record' => null,
        'action' => null,
        'auth' => null,
        'overrides' => [],
    ],

    'prune' => [
        'schedule' => 'daily',   // frequency method or cron expression; null: not scheduled
        'chunk' => 1000,
    ],

    'formatter' => RawFormatter::class,
];
