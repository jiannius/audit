<?php

namespace Jiannius\Audit\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Schema;
use Jiannius\Audit\Audit;
use Jiannius\Audit\AuditServiceProvider;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Tests\Fixtures\Attachment;
use Jiannius\Audit\Tests\Fixtures\AuditedInvoice;
use Jiannius\Audit\Tests\Fixtures\AuditedLine;
use Jiannius\Audit\Tests\Fixtures\AuditedUser;
use Jiannius\Audit\Tests\Fixtures\CurrentTeam;
use Jiannius\Audit\Tests\Fixtures\Document;
use Jiannius\Audit\Tests\Fixtures\HiddenPinDocument;
use Jiannius\Audit\Tests\Fixtures\Invoice;
use Jiannius\Audit\Tests\Fixtures\InvoiceLine;
use Jiannius\Audit\Tests\Fixtures\LoggedDocument;
use Jiannius\Audit\Tests\Fixtures\Order;
use Jiannius\Audit\Tests\Fixtures\RecordActorJob;
use Jiannius\Audit\Tests\Fixtures\RecordCommand;
use Jiannius\Audit\Tests\Fixtures\StampedRecord;
use Jiannius\Audit\Tests\Fixtures\Team;
use Jiannius\Audit\Tests\Fixtures\UnwrappedDocument;
use Jiannius\Audit\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        // The Audit model registers its read scopes when it boots, from config.
        Model::clearBootedModels();

        parent::setUp();

        Model::shouldBeStrict();
        Relation::enforceMorphMap([
            'user' => User::class,
            'team' => Team::class,
            'invoice' => Invoice::class,
            'invoice-line' => InvoiceLine::class,
            'order' => Order::class,
            'audited-invoice' => AuditedInvoice::class,
            'audited-line' => AuditedLine::class,
            'audited-user' => AuditedUser::class,
            'attachment' => Attachment::class,
            'document' => Document::class,
            'hidden-pin-document' => HiddenPinDocument::class,
            'stamped' => StampedRecord::class,
            'logged-document' => LoggedDocument::class,
            'unwrapped-document' => UnwrappedDocument::class,
        ]);

        CurrentTeam::$id = null;
        Artisan::registerCommand(new RecordCommand);
    }

    protected function getPackageProviders($app): array
    {
        return [AuditServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        if (env('DB_CONNECTION') === 'mysql') {
            $app['config']->set('database.default', 'mysql');
            $app['config']->set('database.connections.mysql', array_merge($app['config']->get('database.connections.mysql', []), [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'audit_testing'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]));
        } else {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ]);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/touch', function () {
            Auditor::record('touched');

            return 'ok';
        });

        // Web-group routes that never touch the user, as plenty of real routes do not.
        $router->middleware('web')->get('/touch-web', function () {
            Auditor::record('touched');

            return 'ok';
        });

        $router->middleware('web')->get('/resolve', function () {
            auth()->user();
            Auditor::record('resolved');

            return 'ok';
        });

        $router->get('/dispatch', function () {
            Auditor::record('dispatching');
            RecordActorJob::dispatch('single');

            return 'ok';
        });

        $router->get('/chain', function () {
            Auditor::record('dispatching');
            Bus::chain([new RecordActorJob('first'), new RecordActorJob('second')])->dispatch();

            return 'ok';
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->text('name')->nullable();
            $table->text('email')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->text('number')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_id', 26);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->createDocumentsTable();

        Schema::create('stamped_records', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->boolean('active')->default(false);
            $table->dateTime('added_at')->nullable();
            $table->dateTime('modified_at')->nullable();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $this->createAuditsTable();
    }

    protected function createDocumentsTable(?string $connection = null): void
    {
        Schema::connection($connection)->create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->dateTime('placed_at')->nullable();
            $table->text('options')->nullable();
            $table->integer('total')->nullable();
            $table->text('notes')->nullable();
            $table->string('pin')->nullable();
            $table->string('api_token')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->string('internal_ref')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /** Run the package's own migration stub, then add the scope columns a host would add. */
    protected function createAuditsTable(): void
    {
        (include __DIR__.'/../database/migrations/create_audits_table.php.stub')->up();

        Schema::table(config('audit.table'), function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->index(['team_id', 'id']);
        });
    }

    /** Laravel skips this while running unit tests; a real artisan process always has it, and it is what fires CommandStarting. */
    protected function rerouteConsoleEvents(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();

        $dispatcher = (new \ReflectionProperty($kernel, 'symfonyDispatcher'))->getValue($kernel);
        $getArtisan = new \ReflectionMethod($kernel, 'getArtisan');
        $getArtisan->invoke($kernel)->setDispatcher($dispatcher);
    }

    /** A second, in-memory connection with its own audits table, standing in for a tenant database. */
    protected function addSecondConnection(): void
    {
        config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        config(['audit.connection' => 'other']);
        (include __DIR__.'/../database/migrations/create_audits_table.php.stub')->up();
        config(['audit.connection' => null]);
    }

    /** Register scope columns, then reboot the Audit model so its read scopes pick them up. */
    protected function useScopes(array $scopes): void
    {
        config(['audit.scopes' => $scopes]);
        Audit::clearBootedModels();
    }

    /** Forget everything a long-lived worker process would not share with the request: the user, the scoped context. */
    protected function startNewProcess(): void
    {
        Auth::forgetGuards();
        Context::flush();
        $this->app->forgetScopedInstances();
    }

    protected function audits(): Collection
    {
        return Audit::query()->orderBy('id')->get();
    }
}
