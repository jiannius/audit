<?php

namespace Jiannius\Audit\Testing;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

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

    /** Directories nobody means to scan. */
    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'storage'];

    private const WRITES = 'insert\w*|update\w*|upsert|delete|forceDelete|truncate|increment\w*|decrement\w*';

    /** @var list<string> */
    private array $paths = [];

    /** @var list<array{file: string, contains: string, reason: string}> */
    private array $allowed = [];

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

        $models = implode('|', array_map('preg_quote', array_unique(['Audit', class_basename((string) config('audit.model', 'Audit'))])));
        $writes = self::WRITES;

        $rules = [
            'a *Quietly() call or withoutEvents()' => '/\b\w*Quietly\(|\bwithoutEvents\(/i',
            'a DB:: write' => '/\bDB::(?:connection\([^)]*\)->)?(?:insert|update|delete|statement|unprepared|affectingStatement)\(/i',
            'DB::table() followed by a write' => "/\\bDB::table\\(.*->(?:{$writes})\\(/is",
            'toBase() followed by a write' => '/->toBase\(\)->(?:update|delete|insert)\w*\(/i',
            'a pivot write' => '/->(?:sync|syncWithoutDetaching|syncWithPivotValues|attach|detach|toggle|updateExistingPivot)\(/i',
            'a static mass write' => '/::(?:insert|insertOrIgnore|insertGetId|insertUsing|upsert|truncate)\(/i',
            'a query chain ending in a write' => "/\\)\\s*(?:\\?->|->)(?:{$writes})\\(/i",
            'a write through the Audit model' => "/\\b(?:{$models})::.*?\\b(?:create|forceCreate|firstOrCreate|updateOrCreate|{$writes}|destroy|save\\w*)\\(|\\bnew\\s+(?:{$models})\\b.*->save\\w*\\(/s",
        ];

        return array_keys(array_filter($rules, fn (string $pattern) => preg_match($pattern, $text) === 1));
    }

    /** @return list<string> files relative to the base path, in a stable order */
    private function files(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            $absolute = str_starts_with($path, '/') ? $path : $this->basePath.'/'.$path;

            if (is_file($absolute)) {
                $files[] = $absolute;

                continue;
            }

            if (! is_dir($absolute)) {
                throw new InvalidArgumentException("RawWriteGuard path [{$path}] does not exist under [{$this->basePath}]. A path that is not there would let the guard pass without reading anything.");
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $skipped = array_intersect(explode('/', str_replace('\\', '/', substr($file->getPath(), strlen($absolute)))), self::SKIPPED_DIRECTORIES);

                if ($file->isFile() && $file->getExtension() === 'php' && $skipped === []) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $files = array_unique(array_map(fn (string $file) => $this->relative($file), $files));
        sort($files);

        return array_values($files);
    }

    private function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $base = str_replace('\\', '/', $this->basePath).'/';

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
