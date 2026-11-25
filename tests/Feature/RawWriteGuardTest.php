<?php

use Jiannius\Audit\Testing\PhpStatements;
use Jiannius\Audit\Testing\RawWriteGuard;
use PHPUnit\Framework\AssertionFailedError;

/** @var list<string> */
$GLOBALS['guard_sandboxes'] = [];

/** A directory holding these files, as a host app would: path => contents. Returns its path. */
function guardSandbox(array $files): string
{
    $root = sys_get_temp_dir().'/audit-guard-'.bin2hex(random_bytes(6));
    $GLOBALS['guard_sandboxes'][] = $root;

    foreach ($files as $path => $contents) {
        @mkdir(dirname($root.'/'.$path), 0777, true);
        file_put_contents($root.'/'.$path, $contents);
    }

    return $root;
}

/** The guard over one file of PHP statements, as a class method would hold them. */
function guardOver(string $statements, string $file = 'app/Thing.php'): RawWriteGuard
{
    $root = guardSandbox([$file => "<?php\n\nnamespace App;\n\nclass Thing\n{\n    public function run()\n    {\n{$statements}\n    }\n}\n"]);

    return RawWriteGuard::for($root)->paths([dirname($file)]);
}

function flaggedRules(string $statements): array
{
    return array_merge(...array_column(guardOver($statements)->writes(), 'rules') ?: [[]]);
}

afterEach(function () {
    foreach ($GLOBALS['guard_sandboxes'] as $root) {
        if (is_dir($root)) {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    $GLOBALS['guard_sandboxes'] = [];
});

describe('what it flags', function () {
    it('flags every pattern', function (string $statement, string $rule) {
        $writes = guardOver("        {$statement}")->writes();

        expect($writes)->toHaveCount(1)
            ->and($writes[0]['rules'])->toContain($rule);
    })->with([
        'saveQuietly' => ['$invoice->saveQuietly();', 'a *Quietly() call or withoutEvents()'],
        'updateQuietly' => ["\$invoice->updateQuietly(['a' => 1]);", 'a *Quietly() call or withoutEvents()'],
        'deleteQuietly' => ['$invoice->deleteQuietly();', 'a *Quietly() call or withoutEvents()'],
        'incrementQuietly' => ["\$invoice->incrementQuietly('n');", 'a *Quietly() call or withoutEvents()'],
        'withoutEvents' => ['Invoice::withoutEvents(fn () => $invoice->save());', 'a *Quietly() call or withoutEvents()'],
        'withoutEvents with a closure body' => ["Model::withoutEvents(function () {\n    \$invoice->touch();\n});", 'a *Quietly() call or withoutEvents()'],
        'DB::insert' => ["DB::insert('insert into t values (1)');", 'a DB:: write'],
        'DB::update' => ["DB::update('update t set a = 1');", 'a DB:: write'],
        'DB::delete' => ["DB::delete('delete from t');", 'a DB:: write'],
        'DB::statement' => ["DB::statement('truncate t');", 'a DB:: write'],
        'DB::unprepared' => ["DB::unprepared('delete from t');", 'a DB:: write'],
        'DB::affectingStatement' => ["DB::affectingStatement('delete from t');", 'a DB:: write'],
        'DB::connection write' => ["DB::connection('tenant')->statement('delete from t');", 'a DB:: write'],
        'DB::table insert' => ["DB::table('invoices')->insert(['a' => 1]);", 'DB::table() followed by a write'],
        'DB::table insertGetId' => ["\$id = DB::table('invoices')->insertGetId(['a' => 1]);", 'DB::table() followed by a write'],
        'DB::table update' => ["DB::table('invoices')->where('id', 1)->update(['a' => 1]);", 'DB::table() followed by a write'],
        'DB::table upsert' => ["DB::table('invoices')->upsert([['a' => 1]], ['a']);", 'DB::table() followed by a write'],
        'DB::table updateOrInsert' => ["DB::table('invoices')->updateOrInsert(['a' => 1], ['b' => 2]);", 'DB::table() followed by a write'],
        'DB::table delete' => ["DB::table('invoices')->where('id', 1)->delete();", 'DB::table() followed by a write'],
        'DB::table truncate' => ["DB::table('invoices')->truncate();", 'DB::table() followed by a write'],
        'DB::table increment' => ["DB::table('invoices')->where('id', 1)->increment('n');", 'DB::table() followed by a write'],
        'DB::table decrement' => ["DB::table('invoices')->decrement('n', 2);", 'DB::table() followed by a write'],
        'DB::table with a closure in the where' => ["DB::table('invoices')\n    ->where(function (\$q) {\n        \$q->where('a', 1);\n    })\n    ->delete();", 'a query chain ending in a write'],
        'toBase update' => ["Invoice::query()->toBase()->update(['a' => 1]);", 'toBase() followed by a write'],
        'sync' => ['$user->teams()->sync([1, 2]);', 'a pivot write'],
        'syncWithoutDetaching' => ['$user->teams()->syncWithoutDetaching([1]);', 'a pivot write'],
        'syncWithPivotValues' => ["\$user->teams()->syncWithPivotValues([1], ['role' => 'a']);", 'a pivot write'],
        'attach' => ['$user->teams()->attach(1);', 'a pivot write'],
        'detach' => ['$user->teams()->detach();', 'a pivot write'],
        'toggle' => ['$user->teams()->toggle([1]);', 'a pivot write'],
        'updateExistingPivot' => ["\$user->teams()->updateExistingPivot(1, ['role' => 'a']);", 'a pivot write'],
        'a chain ending in update' => ["Order::where('status', 'open')->update(['status' => 'cancelled']);", 'a query chain ending in a write'],
        'a chain ending in delete' => ["Order::where('status', 'draft')->delete();", 'a query chain ending in a write'],
        'a relation delete' => ['$invoice->lines()->delete();', 'a query chain ending in a write'],
        'a chain ending in forceDelete' => ['Order::onlyTrashed()->forceDelete();', 'a query chain ending in a write'],
        'a chain ending in insertOrIgnore' => ["Order::query()->insertOrIgnore([['a' => 1]]);", 'a query chain ending in a write'],
        'a chain ending in increment' => ["Order::where('id', 1)->increment('n');", 'a query chain ending in a write'],
        'a chain with a closure that has a body' => ["Order::where(function (\$q) {\n    \$q->where('a', 1);\n})->update(['b' => 2]);", 'a query chain ending in a write'],
        'a static mass insert' => ["Order::insert([['a' => 1]]);", 'a static mass write'],
        'a static upsert' => ["Order::upsert([['a' => 1]], ['a']);", 'a static mass write'],
        'a static truncate' => ['Order::truncate();', 'a static mass write'],
        'Audit::create' => ["Audit::create(['event' => 'x']);", 'a write through the Audit model'],
        'Audit query update' => ["Audit::query()->where('id', 1)->update(['event' => 'x']);", 'a write through the Audit model'],
        'Audit delete' => ["Audit::where('id', 1)->delete();", 'a write through the Audit model'],
        'a fully qualified Audit write' => ["\\Jiannius\\Audit\\Audit::insert([['event' => 'x']]);", 'a write through the Audit model'],
        'an Audit instance saved' => ["(new Audit(['event' => 'x']))->save();", 'a write through the Audit model'],
        'a write through the model inside a withSource closure body' => ["Auditor::withSource('backfill:x', function () {\n    Order::where('a', 1)->update(['b' => 2]);\n});", 'a query chain ending in a write'],
        'a quiet save inside actingAs' => ['Auditor::actingAs($user, fn () => $invoice->saveQuietly());', 'a *Quietly() call or withoutEvents()'],
    ]);

    it('reports the line of the statement, even when it spans several lines', function () {
        $guard = guardOver("        \$a = 1;\n        Order::query()\n            ->where('a', 1)\n            ->update(['b' => 2]);\n        \$b = 2;");

        expect($guard->writes())->toHaveCount(1)
            ->and($guard->writes()[0]['line'])->toBe(10);
    });

    it('flags each statement of a file on its own', function () {
        $writes = guardOver("        \$invoice->saveQuietly();\n        Order::where('a', 1)->delete();\n        \$ok = 1;\n        \$user->teams()->sync([1]);")->writes();

        expect(array_column($writes, 'line'))->toBe([9, 10, 12]);
    });

    it('flags a write inside a closure body or a nested block', function () {
        $writes = guardOver("        collect([1])->each(function (\$id) {\n            if (\$id) {\n                Order::where('id', \$id)->delete();\n            }\n        });")->writes();

        expect($writes)->toHaveCount(1)
            ->and($writes[0]['line'])->toBe(11);
    });
});

describe('what it leaves alone', function () {
    it('does not flag audited writes', function (string $statement) {
        expect(guardOver("        {$statement}")->writes())->toBe([]);
    })->with([
        'Auditor::update' => ["Auditor::update(Order::where('x', 1), ['a' => 2]);"],
        'Auditor::update on a base query' => ["Auditor::update(DB::table('invoices')->where('id', 1), ['a' => 2], subjectType: 'invoice');"],
        'Auditor::update with a closure in the query' => ["Auditor::update(\n    DB::table('invoices')->where(function (\$q) {\n        \$q->where('a', 1);\n    }),\n    ['a' => 2],\n    subjectType: 'invoice',\n);"],
        'Auditor::delete' => ["Auditor::delete(Order::where('status', 'draft'));"],
        'a fully qualified Auditor' => ["\\Jiannius\\Audit\\Facades\\Auditor::delete(DB::table('orders')->where('a', 1), subjectType: 'order');"],
        'Auditor::pivot with an arrow function' => ['Auditor::pivot($user->teams(), fn ($teams) => $teams->sync($ids));'],
        'Auditor::pivot with a closure body' => ["Auditor::pivot(\$user->teams(), function (\$teams) use (\$ids) {\n    \$teams->sync(\$ids);\n    \$teams->attach(3);\n    foreach (\$ids as \$id) {\n        \$teams->detach(\$id);\n    }\n});"],
        'updateQuietlyWithAudit' => ["\$invoice->updateQuietlyWithAudit(['a' => 1], event: 'synced');"],
        'a model audit call' => ["\$invoice->audit('sent', meta: ['to' => \$a]);"],
        'a model save and delete, which fire events' => ["\$invoice->save();\n\$invoice->delete();\n\$invoice->update(['a' => 1]);"],
        'reading' => ["\$orders = Order::where('a', 1)->get();\n\$n = DB::table('orders')->count();\n\$a = Audit::query()->where('id', 1)->first();"],
        'a name that only contains the words' => ['$x = Auditor::class; $y = Audit::class; $z = $this->updateQuietlyWithAuditing();'],
    ]);

    it('does not flag comments or strings', function (string $statement) {
        expect(guardOver("        {$statement}\n        \$x = 1;")->writes())->toBe([]);
    })->with([
        'a line comment' => ["// Order::where('a', 1)->update(['b' => 2]);"],
        'a hash comment' => ['# $invoice->saveQuietly();'],
        'a block comment' => ["/* DB::table('x')->delete(); */"],
        'a doc comment' => ["/**\n * Order::where('a', 1)->delete();\n */"],
        'a single-quoted string' => ["\$sql = 'DB::table(\"x\")->delete()';"],
        'a double-quoted string' => ['$sql = "Order::where(a)->update(b) \$x->saveQuietly()";'],
        'a heredoc' => ["\$doc = <<<TEXT\n\$invoice->saveQuietly();\nOrder::where('a', 1)->delete();\nTEXT;"],
        'a nowdoc' => ["\$doc = <<<'TEXT'\nOrder::where('a', 1)->delete();\nTEXT;"],
    ]);

    it('scopes a flagged write to its own statement, not to the file', function () {
        $guard = guardOver("        Auditor::update(Order::where('a', 1), ['b' => 2]);\n        Order::where('c', 3)->delete();");

        expect($guard->writes())->toHaveCount(1)
            ->and($guard->writes()[0]['line'])->toBe(10);
    });
});

describe('Blade files', function () {
    $component = <<<'BLADE'
<?php

use App\Models\Order;
use Livewire\Component;

new class extends Component
{
    public function archive()
    {
        Order::where('open', 1)->update(['open' => 0]);
    }

    public function audited()
    {
        Auditor::update(Order::where('open', 1), ['open' => 0]);
    }
};
?>

<div>
    {{-- Order::where('hidden', 1)->delete() is a comment --}}
    <p>{{ $order->number }}</p>
    <p>{{ Order::where('a', 1)->delete() }}</p>
    {!! Order::where('b', 1)->forceDelete() !!}
    @php
        Invoice::where('c', 1)->update(['d' => 1]);
    @endphp
    @php($x = DB::table('orders')->truncate())
    @if ($order->exists)
        <span>@lang('Hello')</span>
    @endif
    @verbatim
        {{ Order::where('e', 1)->delete() }}
    @endverbatim
</div>
BLADE;

    it('reads a single-file component and the code in Blade directives and echoes', function () use ($component) {
        $root = guardSandbox(['resources/views/livewire/orders.blade.php' => $component]);

        $writes = RawWriteGuard::for($root)->paths(['resources/views'])->writes();

        expect(array_column($writes, 'line'))->toBe([10, 23, 24, 26, 28])
            ->and($writes[0]['file'])->toBe('resources/views/livewire/orders.blade.php');
    });

    it('reads plain PHP statements split across HTML', function () {
        $root = guardSandbox(['resources/views/page.blade.php' => "<div>\n<?php \$invoice->saveQuietly(); ?>\n<p>text</p>\n<?php Order::where('a', 1)->delete() ?>\n</div>\n"]);

        $writes = RawWriteGuard::for($root)->paths(['resources/views'])->writes();

        expect(array_column($writes, 'line'))->toBe([2, 4]);
    });

    it('finds nothing in a view that writes nothing', function () {
        $root = guardSandbox(['resources/views/page.blade.php' => "<div>\n@foreach (\$orders as \$order)\n  <p>{{ \$order->number }}</p>\n@endforeach\n</div>\n"]);

        expect(RawWriteGuard::for($root)->paths(['resources/views'])->writes())->toBe([]);
    });
});

describe('the allowlist', function () {
    it('lets an allowlisted statement through', function () {
        $root = guardSandbox(['app/Cleanup.php' => "<?php\n\nclass Cleanup\n{\n    public function run()\n    {\n        Order::where('status', 'draft')->delete();\n    }\n}\n"]);

        RawWriteGuard::for($root)
            ->paths(['app'])
            ->allow([['app/Cleanup.php', "Order::where('status', 'draft')->delete", 'Drafts are scratch space and never audited.']])
            ->assertClean();
    });

    it('takes entries by key too, and matches on the statement as written, string contents included', function () {
        $root = guardSandbox(['app/Cleanup.php' => "<?php\n\nOrder::query()\n    ->where('status', 'draft')\n    ->delete();\n"]);

        RawWriteGuard::for($root)
            ->paths(['app'])
            ->allow([['file' => './app/Cleanup.php', 'contains' => "->where('status', 'draft') ->delete()", 'reason' => 'Scratch rows.']])
            ->assertClean();
    });

    it('fails on a write that is not in the allowlist, and says where it is, what it is and how to allow it', function () {
        $root = guardSandbox(['app/Jobs/Cleanup.php' => "<?php\n\nclass Cleanup\n{\n    public function run()\n    {\n        Order::where('status', 'draft')->delete();\n    }\n}\n"]);

        try {
            RawWriteGuard::for($root)->paths(['app'])->assertClean();
            $this->fail('The guard should have failed.');
        } catch (AssertionFailedError $e) {
            expect($e->getMessage())
                ->toContain('1 write skips the audit trail')
                ->toContain('app/Jobs/Cleanup.php:7  (a query chain ending in a write)')
                ->toContain("Order::where('status', 'draft')->delete()")
                ->toContain('Auditor::update()')
                ->toContain("['app/Jobs/Cleanup.php', \"Order::where('status', 'draft')->delete()\"")
                ->not->toContain($root);
        }
    });

    it('fails on a stale entry, a file that no longer has the write, and says which', function () {
        $root = guardSandbox(['app/Cleanup.php' => "<?php\n\n\$order->save();\n"]);

        try {
            RawWriteGuard::for($root)
                ->paths(['app'])
                ->allow([['app/Cleanup.php', 'Order::where', 'Used to delete drafts.']])
                ->assertClean();
            $this->fail('The guard should have failed.');
        } catch (AssertionFailedError $e) {
            expect($e->getMessage())
                ->toContain('1 allowlist entry no longer matches any write')
                ->toContain('app/Cleanup.php  contains: Order::where  (reason: Used to delete drafts.)');
        }
    });

    it('reports a violation and a stale entry together when the entry names the wrong file', function () {
        $root = guardSandbox(['app/A.php' => "<?php\n\nOrder::where('a', 1)->delete();\n", 'app/B.php' => "<?php\n\n\$x = 1;\n"]);

        $result = RawWriteGuard::for($root)->paths(['app'])->allow([['app/B.php', 'Order::where', 'Wrong file.']])->check();

        expect($result['violations'])->toHaveCount(1)
            ->and($result['stale'])->toHaveCount(1);
    });

    it('treats an entry that matches one of several writes as used', function () {
        $root = guardSandbox(['app/A.php' => "<?php\n\nOrder::where('a', 1)->delete();\nOrder::where('b', 1)->delete();\n"]);

        $result = RawWriteGuard::for($root)->paths(['app'])->allow([['app/A.php', "where('a', 1)", 'Only this one.']])->check();

        expect($result['violations'])->toHaveCount(1)
            ->and($result['violations'][0]['line'])->toBe(4)
            ->and($result['stale'])->toBe([]);
    });

    it('refuses an entry without a reason, a file or a contains', function (array $entry) {
        expect(fn () => RawWriteGuard::for('/tmp')->allow([$entry]))->toThrow(InvalidArgumentException::class);
    })->with([
        'no reason' => [['app/A.php', 'Order::where']],
        'a blank reason' => [['app/A.php', 'Order::where', '  ']],
        'no contains' => [['app/A.php', '', 'Because.']],
        'no file' => [['', 'Order::where', 'Because.']],
    ]);
});

describe('paths', function () {
    it('refuses a path that does not exist, since reading nothing would pass', function () {
        $root = guardSandbox(['app/A.php' => "<?php\n"]);

        expect(fn () => RawWriteGuard::for($root)->paths(['app', 'resources/views'])->writes())
            ->toThrow(InvalidArgumentException::class, 'resources/views');
    });

    it('reads a single file, a directory recursively, and skips vendor', function () {
        $root = guardSandbox([
            'app/A.php' => "<?php\n\$a->saveQuietly();\n",
            'app/Deep/Er/B.php' => "<?php\n\$b->saveQuietly();\n",
            'app/vendor/C.php' => "<?php\n\$c->saveQuietly();\n",
            'app/notes.txt' => '$d->saveQuietly();',
            'routes/web.php' => "<?php\n\$e->saveQuietly();\n",
        ]);

        $files = array_column(RawWriteGuard::for($root)->paths(['app', 'routes/web.php'])->writes(), 'file');

        expect($files)->toBe(['app/A.php', 'app/Deep/Er/B.php', 'routes/web.php']);
    });

    it('passes a clean tree and counts as an assertion', function () {
        $root = guardSandbox(['app/A.php' => "<?php\n\$a->save();\n"]);

        RawWriteGuard::for($root)->paths(['app'])->assertClean();

        expect(true)->toBeTrue();
    });
});

describe('statements', function () {
    it('keeps a closure argument inside its statement and the body as statements of its own', function () {
        $statements = PhpStatements::of("<?php\nOrder::where(function (\$q) {\n    \$q->where('a', 1);\n})->update(['b' => 2]);\n\$next = 1;\n");

        expect(array_column($statements, 'text'))->toBe([
            "Order::where(function(\$q){…})->update([''=>2])",
            "\$q->where('',1)",
            '$next=1',
        ]);
    });

    it('does not run a block statement into the one after it', function () {
        $statements = PhpStatements::of("<?php\nif (\$a) {\n    \$a->saveQuietly();\n}\nOrder::where('x', 1)->delete();\n");

        expect(array_column($statements, 'line'))->toContain(2, 3, 5)
            ->and(array_column($statements, 'text'))->toContain("Order::where('',1)->delete()");
    });
});
