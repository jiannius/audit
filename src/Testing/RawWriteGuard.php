<?php

namespace Jiannius\Audit\Testing;

use InvalidArgumentException;
use Jiannius\Audit\Audit;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * A test that fails on every write that skips model events and is not audited, and on allowlist
 * entries that no longer match anything.
 *
 *     RawWriteGuard::for(base_path())
 *         ->paths(['app', 'resources/views', 'routes'])
 *         ->allow(require base_path('tests/Fixtures/audit/raw-writes.php'))   // [['file', 'contains', 'reason'], ...]
 *         ->assertClean();
 *
 * It reads your source with token_get_all, splits it into statements and matches each against a list
 * of patterns. It is biased towards false positives: one allowlist line with a reason costs little. It
 * cannot see dynamic calls such as `$query->{$method}()`, or a helper that hides a write.
 */
final class RawWriteGuard
{
    /** A statement through one of these is an audited write. */
    private const SANCTIONED = '/\bAuditor::(?:update|delete|pivot)\(|\$auditor->(?:update|delete|pivot)\(|\bupdateQuietlyWithAudit\(|->audit\(/i';

    /** Directories nobody means to scan, when they sit at the top level of the base path. */
    private const DEFAULT_SKIPPED = ['vendor', 'node_modules', 'storage'];

    private const WRITES = 'insert\w*|update\w*|upsert|delete|forceDelete|truncate|increment\w*|decrement\w*|restore|touch';

    /**
     * A chain `X->update()` is not a query write when X is a model: these calls return one (or the signed-in
     * user), and `$model->update()` fires the model's events.
     */
    private const MODEL_RETURNING = ['find', 'findorfail', 'first', 'firstorfail', 'sole', 'fresh', 'refresh', 'user', 'create', 'firstorcreate', 'updateorcreate', 'firstwhere'];

    /** A chain that starts at one of these facades is not an Eloquent query: a file, an HTTP call, a cache key. */
    private const NOT_QUERY_ROOTS = ['Storage', 'Http', 'Cache', 'Session', 'Redis', 'Mail'];

    /** @var list<string> */
    private array $paths = [];

    /** @var list<array{file: string, contains: string, reason: string}> */
    private array $allowed = [];

    /** @var list<string> */
    private array $skip = self::DEFAULT_SKIPPED;

    /** @var array<string, true> the skipped directories that were met while reading */
    private array $skipped = [];

    private ?string $models = null;

    private function __construct(private readonly string $basePath) {}

    public static function for(string $basePath): self
    {
        return new self(rtrim($basePath, '/\\'));
    }

    /**
     * The files and directories to read, relative to the base path (or absolute). A directory is read
     * for every `.php` file in it, Blade views included.
     *
     * @param  list<string>  $paths
     */
    public function paths(array $paths): self
    {
        $this->paths = array_values($paths);

        return $this;
    }

    /**
     * Directories not to read, by their path relative to the base path ('vendor', 'storage/framework'): only
     * the ones that start at the top of the base path count. Default: vendor, node_modules and storage. The
     * ones met while reading are named in a failure message, and by skipped().
     *
     * @param  list<string>  $directories
     */
    public function skipDirectories(array $directories): self
    {
        $this->skip = array_values(array_map(fn (string $directory) => trim(str_replace('\\', '/', $directory), '/'), $directories));

        return $this;
    }

    /**
     * The directories left unread because they are skipped, as relative paths.
     *
     * @return list<string>
     */
    public function skipped(): array
    {
        $skipped = array_keys($this->skipped);
        sort($skipped);

        return $skipped;
    }

    /**
     * Writes that are deliberately not audited. Each is `[file, contains, reason]` (or the same under
     * those keys): the file relative to the base path, text that appears in the statement as written, and why.
     *
     * @param  list<array<int|string, string>>  $entries
     */
    public function allow(array $entries): self
    {
        $this->allowed = [];

        foreach (array_values($entries) as $index => $entry) {
            $file = $entry['file'] ?? $entry[0] ?? null;
            $contains = $entry['contains'] ?? $entry[1] ?? null;
            $reason = $entry['reason'] ?? $entry[2] ?? null;

            foreach (['file' => $file, 'contains' => $contains, 'reason' => $reason] as $name => $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException("Allowlist entry #{$index} has no {$name}. Each entry is ['file', 'contains', 'reason'], and an entry without a reason is not allowed.");
                }
            }

            $this->allowed[] = ['file' => self::normalise($file), 'contains' => $contains, 'reason' => trim($reason)];
        }

        return $this;
    }

    /**
     * Every flagged statement, allowlisted or not.
     *
     * @return list<array{file: string, line: int, statement: string, rules: list<string>}>
     */
    public function writes(): array
    {
        $writes = [];
        $this->skipped = [];
        $this->models = null;

        foreach ($this->files() as $file) {
            $source = (string) file_get_contents($this->basePath.'/'.$file);

            foreach (PhpStatements::of($source, str_ends_with($file, '.blade.php')) as $statement) {
                $rules = $this->matches($statement);

                if ($rules !== []) {
                    $writes[] = ['file' => $file, 'line' => $statement['line'], 'statement' => $statement['raw'], 'rules' => $rules];
                }
            }
        }

        return $writes;
    }

    /**
     * Flagged statements that no allowlist entry covers, and allowlist entries that cover nothing.
     *
     * @return array{violations: list<array{file: string, line: int, statement: string, rules: list<string>}>, stale: list<array{file: string, contains: string, reason: string}>}
     */
    public function check(): array
    {
        $used = [];
        $violations = [];

        foreach ($this->writes() as $write) {
            $covered = false;

            foreach ($this->allowed as $index => $entry) {
                if ($entry['file'] === $write['file'] && str_contains($write['statement'], $entry['contains'])) {
                    $used[$index] = true;
                    $covered = true;
                }
            }

            if (! $covered) {
                $violations[] = $write;
            }
        }

        $stale = array_values(array_filter($this->allowed, fn (array $entry, int $index) => ! isset($used[$index]), ARRAY_FILTER_USE_BOTH));

        return ['violations' => $violations, 'stale' => $stale];
    }

    /** Fail the test when a write skips the audit trail without being allowlisted, or an allowlist entry is stale. */
    public function assertClean(): void
    {
        ['violations' => $violations, 'stale' => $stale] = $this->check();

        if ($violations === [] && $stale === []) {
            Assert::assertTrue(true);

            return;
        }

        Assert::fail($this->message($violations, $stale));
    }

    /** @return list<string> the rules the statement matches, by name */
    private function matches(array $statement): array
    {
        $text = $statement['text'];

        if ($statement['sanctioned'] || preg_match(self::SANCTIONED, $text) === 1) {
            return [];
        }

        $models = $this->modelNames();
        $writes = self::WRITES;

        $rules = [
            'a *Quietly() call or withoutEvents()' => '/\b\w*Quietly\(|\bwithoutEvents\(/i',
            'a DB:: write' => '/\bDB::(?:connection\([^)]*\)->)?(?:insert|update|delete|statement|unprepared|affectingStatement)\(/i',
            'DB::table() followed by a write' => "/\\bDB::table\\(.*->(?:{$writes})\\(/is",
            'toBase() followed by a write' => '/->toBase\(\)->(?:update|delete|insert)\w*\(/i',
            'a pivot write' => '/->(?:sync|syncWithoutDetaching|syncWithPivotValues|attach|detach|toggle|updateExistingPivot)\(/i',
            'a static mass write' => '/::(?:insert|insertOrIgnore|insertGetId|insertUsing|upsert|truncate)\(/i',
            'raw PDO access' => '/(?:->|::)(?:getPdo|getRawPdo)\\(\\)->(?:exec|prepare)\\(/i',
            'a write through the Audit model' => "/\\b(?:{$models})::.*?\\b(?:create|forceCreate|firstOrCreate|updateOrCreate|{$writes}|destroy|save\\w*)\\(|\\bnew\\s+(?:{$models})\\b.*->save\\w*\\(/s",
        ];

        $matched = array_keys(array_filter($rules, fn (string $pattern) => preg_match($pattern, $text) === 1));

        if ($this->endsInQueryWrite($text)) {
            $matched[] = 'a query chain ending in a write';
        }

        return $matched;
    }

    /** The class names a write through the Audit model can be spelled with: the package's, and the host's subclass. */
    private function modelNames(): string
    {
        if ($this->models !== null) {
            return $this->models;
        }

        // Without a container (a bare PHPUnit test) there is no config: the package's own model it is.
        try {
            $configured = config('audit.model');
        } catch (Throwable) {
            $configured = null;
        }

        $configured = is_string($configured) && $configured !== '' ? $configured : Audit::class;

        return $this->models = implode('|', array_map('preg_quote', array_unique(['Audit', class_basename($configured)])));
    }

    /**
     * Whether the statement has a `)->update(`-style call that is a query write: the call before it is not
     * one that returns a model, and the chain does not start at a facade that is not a query.
     */
    private function endsInQueryWrite(string $text): bool
    {
        $writes = self::WRITES;

        if (preg_match_all("/\\)\\s*(?:\\?->|->)(?:{$writes})\\(/i", $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return false;
        }

        foreach ($matches[0] as [, $offset]) {
            $chain = self::chainBefore($text, $offset);

            if ($chain !== [] && in_array(strtolower((string) end($chain)), self::MODEL_RETURNING, true)) {
                continue;
            }

            if ($chain !== [] && in_array($chain[0], self::NOT_QUERY_ROOTS, true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * The names in the chain that ends at the `)` at $position, root first: `Order::where(...)->limit(3)` is
     * [Order, where, limit], `$request->user()` is [$request, user], `auth()->user()` is [auth, user].
     *
     * @return list<string>
     */
    private static function chainBefore(string $text, int $position): array
    {
        $names = [];
        $word = '/[\w$\\\\]/';

        while (($text[$position] ?? null) === ')') {
            $depth = 0;

            for ($open = $position; $open >= 0; $open--) {
                $depth += $text[$open] === ')' ? 1 : ($text[$open] === '(' ? -1 : 0);

                if ($depth === 0) {
                    break;
                }
            }

            if ($open < 0) {
                break;
            }

            $start = $open;

            while ($start > 0 && preg_match($word, $text[$start - 1]) === 1) {
                $start--;
            }

            $names[] = self::lastSegment(substr($text, $start, $open - $start));

            if (substr($text, max(0, $start - 3), 3) === '?->') {
                $position = $start - 4;
            } elseif (substr($text, max(0, $start - 2), 2) === '->') {
                $position = $start - 3;
            } elseif (substr($text, max(0, $start - 2), 2) === '::') {
                $end = $start - 2;
                $from = $end;

                while ($from > 0 && preg_match($word, $text[$from - 1]) === 1) {
                    $from--;
                }

                $names[] = self::lastSegment(substr($text, $from, $end - $from));

                break;
            } else {
                break;
            }

            if (($text[$position] ?? null) !== ')') {
                $from = $position + 1;

                while ($from > 0 && preg_match($word, $text[$from - 1]) === 1) {
                    $from--;
                }

                $names[] = self::lastSegment(substr($text, $from, $position + 1 - $from));

                break;
            }
        }

        return array_reverse($names);
    }

    /** `\Illuminate\Support\Facades\Storage` is `Storage`. */
    private static function lastSegment(string $name): string
    {
        $position = strrpos($name, '\\');

        return $position === false ? $name : substr($name, $position + 1);
    }

    /** @return list<string> files relative to the base path, in a stable order */
    private function files(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            $absolute = str_starts_with($path, '/') ? $path : $this->basePath.'/'.$path;

            if (is_file($absolute)) {
                $files[] = (string) realpath($absolute);

                continue;
            }

            if (! is_dir($absolute)) {
                throw new InvalidArgumentException("RawWriteGuard path [{$path}] does not exist under [{$this->basePath}]. A path that is not there would let the guard pass without reading anything.");
            }

            $requested = trim(str_replace('\\', '/', $this->relative((string) realpath($absolute))), '/').'/';
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = $this->relative((string) $file->getRealPath());

                // Only the top of the base path is skipped, and not when the path asked for is itself inside the skipped directory.
                foreach ($this->skip as $directory) {
                    if (str_starts_with($relative, $directory.'/') && ! str_starts_with($requested, $directory.'/')) {
                        $this->skipped[$directory] = true;

                        continue 2;
                    }
                }

                $files[] = (string) $file->getRealPath();
            }
        }

        $files = array_unique(array_map(fn (string $file) => $this->relative($file), $files));
        sort($files);

        return array_values($files);
    }

    private function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $base = str_replace('\\', '/', (string) (realpath($this->basePath) ?: $this->basePath)).'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    private static function normalise(string $file): string
    {
        return ltrim(preg_replace('#^\./#', '', str_replace('\\', '/', $file)) ?? $file, '/');
    }

    /**
     * @param  list<array{file: string, line: int, statement: string, rules: list<string>}>  $violations
     * @param  list<array{file: string, contains: string, reason: string}>  $stale
     */
    private function message(array $violations, array $stale): string
    {
        $lines = [];

        if ($violations !== []) {
            $lines[] = sprintf('%d write%s skip%s the audit trail and %s not in the allowlist:', count($violations), count($violations) === 1 ? '' : 's', count($violations) === 1 ? 's' : '', count($violations) === 1 ? 'is' : 'are');
            $lines[] = '';

            foreach ($violations as $write) {
                $lines[] = "  {$write['file']}:{$write['line']}  ({$write['rules'][0]})";
                $lines[] = '    '.self::shorten($write['statement'], 200);
                $lines[] = '';
            }

            $lines[] = 'Audit the write instead: Auditor::update(), Auditor::delete() or Auditor::pivot() for bulk and pivot writes,';
            $lines[] = '$model->updateQuietlyWithAudit() for a quiet update, or $model->audit(...) to record an event next to it.';
            $lines[] = '';
            $lines[] = 'If the write is deliberately not audited, add it to the allowlist with the reason:';
            $lines[] = '';

            foreach ($violations as $write) {
                $lines[] = '  '.sprintf("[%s, %s, 'why this write needs no audit row'],", self::quote($write['file']), self::quote(mb_substr($write['statement'], 0, 60)));
            }
        }

        if ($stale !== []) {
            $lines[] = '';
            $lines[] = sprintf('%d allowlist entr%s no longer match%s any write. Remove %s:', count($stale), count($stale) === 1 ? 'y' : 'ies', count($stale) === 1 ? 'es' : '', count($stale) === 1 ? 'it' : 'them');
            $lines[] = '';

            foreach ($stale as $entry) {
                $lines[] = "  {$entry['file']}  contains: {$entry['contains']}  (reason: {$entry['reason']})";
            }
        }

        if ($this->skipped !== []) {
            $lines[] = '';
            $lines[] = 'Not read: '.implode(', ', array_map(fn (string $directory) => $directory.'/', $this->skipped())).' (skipped at the top of the base path; change it with skipDirectories()).';
        }

        return "RawWriteGuard failed.\n\n".implode("\n", $lines);
    }

    /** A PHP string literal to paste into the allowlist: single quotes unless the text has one. */
    private static function quote(string $text): string
    {
        return str_contains($text, "'") && ! str_contains($text, '"') && ! str_contains($text, '$') && ! str_contains($text, '\\')
            ? '"'.$text.'"'
            : var_export($text, true);
    }

    private static function shorten(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
