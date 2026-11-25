<?php

namespace Jiannius\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use WeakMap;

/**
 * Turns an Auditable model's events into audit rows. It reads raw attributes
 * only (never a relation or a cast value), so it is safe under strict mode
 * and on models loaded with a partial select.
 */
class AuditObserver
{
    public const REDACTED = AttributeRules::REDACTED;

    /** Models between restoring and restored, so the save in between records one row, not two. */
    private static ?WeakMap $restoring = null;

    public function __construct(private Recorder $recorder, private AttributeRules $rules) {}

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

        $restore = self::restoringModels()->offsetExists($model) ? self::restoringModels()->offsetGet($model) : null;

        if ($restore !== null && ! $this->isRestoreSave($restore, $changes)) {
            // A restore that another listener cancelled never completed, so its flag is stale.
            self::restoringModels()->offsetUnset($model);
            $restore = null;
        }

        if ($restore !== null) {
            // Record the restore here, inside the save's transaction, instead of after it. The flag
            // stays (marked recorded, so there is no second row) until `restored` or abandonRestore(),
            // so a later failure in the same save can still put deleted_at back.
            if (! $restore['recorded'] && $this->wants($model, 'restored')) {
                $this->writeChanges($model, 'restored', $changes, $restore);
            }

            $restore['recorded'] = true;
            self::restoringModels()->offsetSet($model, $restore);

            return;
        }

        if (! $this->wants($model, 'updated')) {
            return;
        }

        $this->writeChanges($model, 'updated', $changes);
    }

    public function deleted(Model $model): void
    {
        self::restoringModels()->offsetUnset($model);

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
        $column = method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : null;
        $attributes = $model->getAttributes();

        // Remember deleted_at as it is now: restore() clears it before saving.
        self::restoringModels()->offsetSet($model, [
            'column' => $column,
            'had' => $column !== null && array_key_exists($column, $attributes),
            'value' => $column !== null ? ($attributes[$column] ?? null) : null,
            // Never loaded from the database: built in memory, or just created.
            'fresh' => $model->wasRecentlyCreated || ! $model->exists,
            'recorded' => false,
        ]);
    }

    /**
     * A restore whose save failed: put deleted_at back, so the model is still trashed.
     * Does nothing when no restore is in progress.
     */
    public static function abandonRestore(Model $model): void
    {
        if (! self::restoringModels()->offsetExists($model)) {
            return;
        }

        $state = self::restoringModels()->offsetGet($model);
        self::restoringModels()->offsetUnset($model);

        if (! is_array($state) || $state['column'] === null) {
            return;
        }

        $attributes = $model->getAttributes();

        if ($state['had']) {
            $attributes[$state['column']] = $state['value'];
        } else {
            unset($attributes[$state['column']]);
        }

        $model->setRawAttributes($attributes);
    }

    public function restored(Model $model): void
    {
        self::restoringModels()->offsetUnset($model);
    }

    /** Apply the exclude and redact rules to a set of raw attributes. */
    public function values(Model $model, array $attributes): array
    {
        return $this->rules->values($model, $attributes);
    }

    /** @param  array<string, mixed>  $changes */
    private function writeChanges(Model $model, string $event, array $changes, ?array $restore = null): void
    {
        $exclude = $this->rules->excludePatterns($model);
        $keys = array_values(array_filter(array_keys($changes), fn ($key) => ! Str::is($exclude, (string) $key)));

        // Touch-only, token-only and excluded-only saves leave nothing to record.
        if ($keys === []) {
            return;
        }

        $previous = $model->getPrevious();
        $attributes = $model->getAttributes();

        // A restore records the deleted_at change even when the column was not selected and so reads null before,
        // unless the model was never loaded from the database (nothing was trashed). When the old value is
        // unknown, the old key is left out rather than claiming "was not deleted".
        $always = $unknownOld = [];

        if ($restore !== null && $restore['column'] !== null && ($restore['had'] || ! $restore['fresh'])) {
            $always = [$restore['column']];
            $unknownOld = $restore['had'] ? [] : [$restore['column']];
        }

        $old = $new = [];

        foreach ($keys as $key) {
            $before = $previous[$key] ?? null;
            $after = $attributes[$key] ?? null;

            // null to null is not a change: an attribute that was never loaded or set, now set to null.
            if ($before === null && $after === null && ! in_array($key, $always, true)) {
                continue;
            }

            if (! in_array($key, $unknownOld, true)) {
                $old[$key] = $before;
            }

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

    /**
     * Whether this save is the restore: deleted_at is among the changes, and it is cleared.
     *
     * @param  array<string, mixed>  $restore
     * @param  array<string, mixed>  $changes
     */
    private function isRestoreSave(array $restore, array $changes): bool
    {
        return $restore['column'] !== null
            && array_key_exists($restore['column'], $changes)
            && $changes[$restore['column']] === null;
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

        if ($id === null || $id === '') {
            return null;
        }

        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException(sprintf('%s::auditParent() must return a model or [ModelClass::class, $id]; [%s] is not an Eloquent model.', $model::class, is_string($class) ? $class : get_debug_type($class)));
        }

        // A bare model holding only the key is enough to resolve the type and id.
        $shell = new $class;
        $shell->setAttribute($shell->getKeyName(), $id);

        return $shell;
    }
}
