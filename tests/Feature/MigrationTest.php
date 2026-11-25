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

describe('which migration publishing reuses', function () {
    function migrationsDirectory(array $files): string
    {
        $directory = sys_get_temp_dir().'/audit-migrations-'.bin2hex(random_bytes(6));
        mkdir($directory);

        foreach ($files as $name => $contents) {
            file_put_contents($directory.'/'.$name, $contents);
        }

        return $directory;
    }

    afterEach(function () {
        foreach (glob(sys_get_temp_dir().'/audit-migrations-*') ?: [] as $directory) {
            array_map('unlink', glob($directory.'/*'));
            rmdir($directory);
        }
    });

    it('carries the marker in the stub, so a published copy can be recognised', function () {
        expect(file_get_contents(__DIR__.'/../../database/migrations/create_audits_table.php.stub'))->toContain(AuditServiceProvider::MIGRATION_MARKER);
    });

    it('recognises the stub itself as the v1 migration once it is published', function () {
        $directory = migrationsDirectory(['2026_02_02_000000_create_audits_table.php' => file_get_contents(__DIR__.'/../../database/migrations/create_audits_table.php.stub')]);

        expect(AuditServiceProvider::publishedMigrationPath($directory))->toBe($directory.'/2026_02_02_000000_create_audits_table.php');
    });

    it('proposes a new file when there is no migration', function () {
        $path = AuditServiceProvider::publishedMigrationPath(migrationsDirectory([]));

        expect($path)->toMatch('/\/\d{4}_\d{2}_\d{2}_\d{6}_create_audits_table\.php$/')->and(file_exists($path))->toBeFalse();
    });

    it('reuses the v1 migration the host already published, so publishing twice changes nothing', function () {
        $directory = migrationsDirectory(['2026_01_01_000000_create_audits_table.php' => "<?php\n\n".AuditServiceProvider::MIGRATION_MARKER."\n"]);

        expect(AuditServiceProvider::publishedMigrationPath($directory))->toBe($directory.'/2026_01_01_000000_create_audits_table.php');
    });

    it('does not reuse the migration of the pre-1.0 package, whose table has the same name', function () {
        $directory = migrationsDirectory(['2020_01_01_000000_create_audits_table.php' => "<?php\n\nreturn new class extends Illuminate\\Database\\Migrations\\Migration {};\n"]);

        $path = AuditServiceProvider::publishedMigrationPath($directory);

        expect($path)->not->toBe($directory.'/2020_01_01_000000_create_audits_table.php')
            ->and($path)->toEndWith('_create_audits_table.php')
            ->and(dirname($path))->toBe($directory);
    });

    it('is not fooled by a comment that merely mentions the marker', function (string $decoy) {
        $directory = migrationsDirectory(['2020_01_01_000000_create_audits_table.php' => "<?php\n\n".$decoy."\n"]);

        expect(AuditServiceProvider::publishedMigrationPath($directory))->not->toBe($directory.'/2020_01_01_000000_create_audits_table.php');
    })->with([
        'a note quoting it' => ['// the v1 migration has // jiannius/audit v1 at the top'],
        'text after it' => ['// jiannius/audit v1 is what the old package used to say'],
        'indented' => ['    // jiannius/audit v1'],
        'a block comment' => ['/* // jiannius/audit v1 */'],
        'a docblock' => ["/**\n * Not the // jiannius/audit v1 file.\n */"],
    ]);

    it('recognises the marker with trailing spaces or Windows line endings', function (string $line) {
        $directory = migrationsDirectory(['2026_01_01_000000_create_audits_table.php' => "<?php\n".$line."\nreturn 1;\n"]);

        expect(AuditServiceProvider::publishedMigrationPath($directory))->toBe($directory.'/2026_01_01_000000_create_audits_table.php');
    })->with(['trailing spaces' => ['// jiannius/audit v1   '], 'CRLF' => ["// jiannius/audit v1\r"]]);

    it('finds the v1 migration among others', function () {
        $directory = migrationsDirectory([
            '2020_01_01_000000_create_audits_table.php' => "<?php\n",
            '2026_01_01_000000_create_audits_table.php' => "<?php\n".AuditServiceProvider::MIGRATION_MARKER."\n",
        ]);

        expect(AuditServiceProvider::publishedMigrationPath($directory))->toBe($directory.'/2026_01_01_000000_create_audits_table.php');
    });
});
