<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Jiannius\Audit\AuditServiceProvider;

it('publishes the migration under the audit-migrations tag and runs it', function () {
    $before = glob(database_path('migrations/*_create_audits_table.php'));

    Artisan::call('vendor:publish', ['--tag' => 'audit-migrations']);

    $published = array_values(array_diff(glob(database_path('migrations/*_create_audits_table.php')), $before));

    try {
        expect($published)->toHaveCount(1);

        // The host owns the table name too: run the published file under another one.
        config(['audit.table' => 'audit_log']);
        Artisan::call('migrate', ['--path' => $published[0], '--realpath' => true]);

        expect(Schema::hasTable('audit_log'))->toBeTrue()
            ->and(Schema::hasColumns('audit_log', [
                'id', 'category', 'event', 'subject_type', 'subject_id', 'subject_label',
                'parent_type', 'parent_id', 'actor_type', 'actor_id', 'actor_name', 'actor_identifier',
                'source', 'request_id', 'ip_address', 'user_agent', 'old_values', 'new_values', 'meta', 'created_at',
            ]))->toBeTrue()
            ->and(Schema::hasColumn('audit_log', 'updated_at'))->toBeFalse();
    } finally {
        Schema::dropIfExists('audit_log');
        array_map('unlink', $published);
    }
});

it('does not publish a second copy of the migration', function () {
    $before = glob(database_path('migrations/*_create_audits_table.php'));

    Artisan::call('vendor:publish', ['--tag' => 'audit-migrations']);
    // A later artisan process boots the provider again and finds the published file.
    (new AuditServiceProvider($this->app))->boot();
    Artisan::call('vendor:publish', ['--tag' => 'audit-migrations']);

    $published = array_values(array_diff(glob(database_path('migrations/*_create_audits_table.php')), $before));

    try {
        expect($published)->toHaveCount(1);
    } finally {
        array_map('unlink', $published);
    }
});

it('names its indexes explicitly, so a long table name stays under 64 characters', function () {
    $table = str_repeat('a', 56);
    config(['audit.table' => $table]);

    (include __DIR__.'/../../database/migrations/create_audits_table.php.stub')->up();

    try {
        $indexes = collect(Schema::getIndexes($table))->pluck('name');

        expect($indexes)->toHaveCount(8) // primary plus the seven
            ->and($indexes->every(fn ($name) => strlen($name) <= 64))->toBeTrue();
    } finally {
        Schema::dropIfExists($table);
    }
});

it('does not load the migration on its own', function () {
    $paths = app('migrator')->paths();

    expect(collect($paths)->filter(fn ($path) => str_contains($path, 'audit')))->toBeEmpty();
});

it('publishes the config under the audit-config tag', function () {
    $target = config_path('audit.php');

    expect(file_exists($target))->toBeFalse();

    Artisan::call('vendor:publish', ['--tag' => 'audit-config']);

    try {
        expect(file_exists($target))->toBeTrue();
    } finally {
        @unlink($target);
    }
});

it('ships config that keeps everything by default', function () {
    expect(config('audit.retention'))->toBe(['record' => null, 'action' => null, 'auth' => null, 'overrides' => []])
        ->and(config('audit.prune.schedule'))->toBe('daily')
        ->and(config('audit.transactional_saves'))->toBeTrue();
});
