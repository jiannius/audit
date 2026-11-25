<?php

namespace Jiannius\Audit\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Jiannius\Audit\Auditable;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * An optional check that your models are covered:
 *
 *     AuditCoverage::models(app_path('Models'), 'App\\Models')
 *         ->except(require base_path('tests/Fixtures/audit/unaudited-models.php'))   // [Model::class => 'reason']
 *         ->assertAllAuditable()
 *         ->assertMorphMapped()
 *         ->assertTransactional();
 *
 * Classes are found from the file paths under the directory, by PSR-4: `app/Models/Billing/Invoice.php`
 * is `App\Models\Billing\Invoice`. Each assertion reports every problem at once.
 */
final class AuditCoverage
{
    /** @var array<class-string, string> */
    private array $except = [];

    /** @var list<class-string<Model>>|null */
    private ?array $concrete = null;

    /**
     * Classes the autoloader already failed to find. Autoloading a file that declares another class runs it again
     * each time, and a second run of such a file is a fatal error, so each class is asked for once per process.
     *
     * @var array<class-string, true>
     */
    private static array $missing = [];

    /** @var array<string, string> file (relative to the path) => the class its path and the namespace say it declares */
    private array $unresolved = [];

    private function __construct(private readonly string $path, private readonly string $namespace) {}

    public static function models(string $path, string $namespace): self
    {
        return new self(rtrim($path, '/\\'), trim($namespace, '\\'));
    }

    /**
     * Models that are deliberately not audited, each with the reason.
     *
     * @param  array<class-string, string>  $models
     */
    public function except(array $models): self
    {
        foreach ($models as $class => $reason) {
            if (! is_string($class) || ! is_string($reason) || trim($reason) === '') {
                throw new InvalidArgumentException('Every excepted model needs a reason: [Model::class => \'why it is not audited\'].');
            }
        }

        $this->except = array_map('trim', $models);

        return $this;
    }

    /**
     * Every concrete model uses `Auditable`, or is excepted with a reason. An exception that names a
     * model that is audited after all, or is not in this directory, is flagged too.
     */
    public function assertAllAuditable(): self
    {
        $problems = [];

        foreach ($this->concreteModels() as $class) {
            if (! self::isAuditable($class) && ! isset($this->except[$class])) {
                $problems[] = "  {$class} does not use Auditable. Add the trait, or list it in except() with the reason it is not audited.";
            }
        }

        foreach ($this->unresolved as $file => $class) {
            $problems[] = "  {$file} does not declare {$class}, which its path and the namespace [{$this->namespace}] say it should, so it was not checked. Fix the namespace or the file name.";
        }

        foreach ($this->except as $class => $reason) {
            if (! in_array($class, $this->concreteModels(), true)) {
                $problems[] = "  {$class} is excepted but is not a concrete model under [{$this->path}]. Remove it from except().";
            } elseif (self::isAuditable($class)) {
                $problems[] = "  {$class} is excepted ({$reason}) but uses Auditable. Remove it from except().";
            }
        }

        return $this->conclude('Models that are not covered by the audit trail', $problems);
    }

    /** Every audited model has a morph-map alias, so the first save cannot fail on a class name. */
    public function assertMorphMapped(): self
    {
        $mapped = array_values(array_filter(Relation::morphMap(), 'is_string'));
        $problems = [];

        foreach ($this->audited() as $class) {
            if (! in_array($class, $mapped, true)) {
                $problems[] = "  {$class} has no morph-map alias. Add it to Relation::enforceMorphMap([...]).";
            }
        }

        return $this->conclude('Audited models without a morph-map alias', $problems);
    }

    /**
     * No audited model overrides `save()` or `delete()` without aliasing the trait's method, which would
     * silently skip the transaction that makes the change and its row atomic.
     */
    public function assertTransactional(): self
    {
        $trait = (new ReflectionClass(Auditable::class))->getFileName();
        $problems = [];
        $seen = [];

        foreach ($this->audited() as $class) {
            $user = self::classUsingAuditable($class);

            if (isset($seen[$user])) {
                continue;
            }

            $seen[$user] = true;

            foreach (['save' => 'auditableSave', 'delete' => 'auditableDelete'] as $method => $alias) {
                $own = self::declaration($user, $method);

                // The trait's own method is the one that runs: nothing overrides it.
                if ($own === null || $own->getFileName() === $trait || method_exists($user, $alias)) {
                    continue;
                }

                $problems[] = sprintf("  %s overrides %s() (%s:%d) without aliasing the trait's: use Auditable { %s as %s; } and call \$this->%s() where you would call parent::%s().", $user, $method, basename((string) $own->getFileName()), $own->getStartLine(), $method, $alias, $alias, $method);
            }
        }

        return $this->conclude('Audited models that skip the transactional save', $problems);
    }

    /** @return list<class-string<Model>> the concrete models under the directory */
    private function concreteModels(): array
    {
        if ($this->concrete !== null) {
            return $this->concrete;
        }

        if (! is_dir($this->path)) {
            throw new InvalidArgumentException("AuditCoverage path [{$this->path}] is not a directory.");
        }

        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->path, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($this->path) + 1, -4);
            $class = $this->namespace.'\\'.str_replace(['/', '\\'], '\\', $relative);

            if (isset(self::$missing[$class]) || ! class_exists($class)) {
                self::$missing[$class] = true;

                // A trait, an interface or an enum is not a model. Anything else is a file nobody can check.
                if (! interface_exists($class, false) && ! trait_exists($class, false) && ! enum_exists($class, false)) {
                    $this->unresolved[ltrim(substr($file->getPathname(), strlen($this->path)), '/\\')] = $class;
                }

                continue;
            }

            $reflection = new ReflectionClass($class);

            // A custom pivot model is audited through Auditor::pivot() on the relation's parent, not by the trait.
            if ($reflection->isSubclassOf(Model::class) && $reflection->isInstantiable() && ! $reflection->isSubclassOf(Pivot::class)) {
                $found[] = $class;
            }
        }

        sort($found);
        ksort($this->unresolved);

        if ($found === []) {
            throw new InvalidArgumentException(sprintf(
                'AuditCoverage found no Eloquent model under [%s] for the namespace [%s], so it would pass without checking anything. Check the path and the namespace (classes are found by PSR-4: the path below the directory is the class name).%s',
                $this->path,
                $this->namespace,
                $this->unresolved === [] ? '' : ' These files did not declare the class their path implies: '.implode(', ', array_keys($this->unresolved)).'.',
            ));
        }

        return $this->concrete = $found;
    }

    /** @return list<class-string<Model>> */
    private function audited(): array
    {
        return array_values(array_filter($this->concreteModels(), fn (string $class) => self::isAuditable($class)));
    }

    private static function isAuditable(string $class): bool
    {
        return in_array(Auditable::class, class_uses_recursive($class), true);
    }

    /** The class in the hierarchy that uses the trait itself, which is where an override would shadow it. */
    private static function classUsingAuditable(string $class): string
    {
        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            if (in_array(Auditable::class, class_uses($current) ?: [], true)) {
                return $current;
            }
        }

        return $class;
    }

    private static function declaration(string $class, string $method): ?ReflectionMethod
    {
        try {
            return new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            return null;
        }
    }

    /** @param  list<string>  $problems */
    private function conclude(string $title, array $problems): self
    {
        if ($problems === []) {
            Assert::assertTrue(true);

            return $this;
        }

        Assert::fail(sprintf("%s (%d):\n\n%s", $title, count($problems), implode("\n", $problems)));
    }
}
