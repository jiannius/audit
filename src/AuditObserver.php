<?php

namespace Jiannius\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use WeakMap;

/**
 * Turns an Auditable model's events into audit rows. It reads raw attributes
 * only (never a relation or a cast value), so it is safe under strict mode
 * and on models loaded with a partial select.
 */
class AuditObserver
{
    public const REDACTED = '[redacted]';

    /** Models between restoring and restored, so the save in between records one row, not two. */
    private static ?WeakMap $restoring = null;

    public function __construct(private Recorder $recorder) {}

    public function created(Model $model): void
    {
        if (! $this->wants($model, 'created')) {
            return;
        }

        $this->write($model, 'created', new: $this->values($model, $model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        if (! config('audit.enabled', true)) {
            return;
        }

        $changes = $model->getChanges();

        if (self::restoringModels()->offsetExists($model) && $this->changesDeletedAt($model, $changes)) {
            // Record the restore here, inside the save's transaction, instead of after it.
            self::restoringModels()->offsetUnset($model);

            if ($this->wants($model, 'restored')) {
                $this->writeChanges($model, 'restored', $changes);
            }

            return;
        }

        if (! $this->wants($model, 'updated')) {
            return;
        }

        $this->writeChanges($model, 'updated', $changes);
    }

    public function deleted(Model $model): void
    {
        $force = $this->isForceDeleting($model);

        // A force delete fires `deleted` too; it is recorded once, as force_deleted.
        if (! $this->wants($model, $force ? 'force_deleted' : 'deleted', $force ? 'deleted' : null)) {
            return;
        }

        $this->write(
            $model,
            $force ? 'force_deleted' : 'deleted',
            meta: $force ? [] : ['soft' => method_exists($model, 'isForceDeleting')],
            old: $this->values($model, $model->getAttributes()),
        );
    }

    public function restoring(Model $model): void
    {
        self::restoringModels()->offsetSet($model, true);
    }

    public function restored(Model $model): void
    {
        self::restoringModels()->offsetUnset($model);
    }

    /** Apply the exclude and redact rules to a set of raw attributes. */
    public function values(Model $model, array $attributes): array
    {
        $exclude = $this->excludePatterns($model);
        $redact = $this->redactPatterns($model);
        $values = [];

        foreach ($attributes as $key => $value) {
            $key = (string) $key;

            if (Str::is($exclude, $key)) {
                continue;
            }

            $values[$key] = $value !== null && Str::is($redact, $key) ? self::REDACTED : $value;
        }

        return $values;
    }

    /** @param  array<string, mixed>  $changes */
    private function writeChanges(Model $model, string $event, array $changes): void
    {
        $exclude = $this->excludePatterns($model);
        $keys = array_values(array_filter(array_keys($changes), fn ($key) => ! Str::is($exclude, (string) $key)));

        // Touch-only, token-only and excluded-only saves leave nothing to record.
        if ($keys === []) {
            return;
        }

        $previous = $model->getPrevious();
        $attributes = $model->getAttributes();

        $old = $new = [];

        foreach ($keys as $key) {
            $before = $previous[$key] ?? null;
            $after = $attributes[$key] ?? null;

            // null to null is not a change: an attribute that was never loaded or set, now set to null.
            if ($before === null && $after === null) {
                continue;
            }

            $old[$key] = $before;
            $new[$key] = $after;
        }

        if ($new === []) {
            return;
        }

        $this->write($model, $event, old: $this->values($model, $old), new: $this->values($model, $new));
    }

    private function write(Model $model, string $event, array $meta = [], array $old = [], array $new = []): void
    {
        $this->recorder->record(new PendingAudit($event, Category::RECORD, $model, $this->parentOf($model), $meta, $old, $new));
    }

    private function wants(Model $model, string $event, ?string $alias = null): bool
    {
        if (! config('audit.enabled', true)) {
            return false;
        }

        $events = $model->auditEvents();

        return in_array($event, $events, true) || ($alias !== null && in_array($alias, $events, true));
    }

    private function isForceDeleting(Model $model): bool
    {
        return method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
    }

    /** @param  array<string, mixed>  $changes */
    private function changesDeletedAt(Model $model, array $changes): bool
    {
        return method_exists($model, 'getDeletedAtColumn')
            && array_key_exists($model->getDeletedAtColumn(), $changes);
    }

    private static function restoringModels(): WeakMap
    {
        return self::$restoring ??= new WeakMap;
    }

    private function parentOf(Model $model): ?Model
    {
        $parent = $model->auditParent();

        if ($parent instanceof Model) {
            return $parent;
        }

        if (! is_array($parent)) {
            return null;
        }

        [$class, $id] = array_values($parent) + [null, null];

        if (! is_string($class) || $id === null || $id === '') {
            return null;
        }

        // A bare model holding only the key is enough to resolve the type and id.
        $shell = new $class;
        $shell->setAttribute($shell->getKeyName(), $id);

        return $shell;
    }

    /** @return list<string> */
    private function excludePatterns(Model $model): array
    {
        return array_values(array_merge((array) config('audit.attributes.exclude', []), $model->auditExclude()));
    }

    /** @return list<string> */
    private function redactPatterns(Model $model): array
    {
        $patterns = array_merge((array) config('audit.attributes.redact', []), $model->auditRedact());

        if (config('audit.attributes.redact_hidden', true)) {
            $patterns = array_merge($patterns, $model->getHidden());
        }

        if (config('audit.attributes.redact_encrypted', true)) {
            foreach ($model->getCasts() as $key => $cast) {
                if ($this->isSecretCast($cast)) {
                    $patterns[] = (string) $key;
                }
            }
        }

        return array_values($patterns);
    }

    /** encrypted, encrypted:array, hashed, AsEncryptedArrayObject and friends. */
    private function isSecretCast(mixed $cast): bool
    {
        $name = is_object($cast) ? $cast::class : (is_string($cast) ? $cast : '');
        $name = strtolower(class_basename(Str::before($name, ':')));

        return str_starts_with($name, 'encrypted') || str_starts_with($name, 'asencrypted') || $name === 'hashed';
    }
}
