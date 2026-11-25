<?php

namespace Jiannius\Audit;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Jiannius\Audit\Relations\SubjectAudits;
use Throwable;

/**
 * Records created, updated, deleted, force_deleted and restored events for a
 * model, as raw database values.
 *
 * Overrides of the audit*() methods must stay strict-mode safe: read from
 * $this->getAttributes() with array_key_exists, never from a relation or a
 * magic attribute.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        // Not observe(): it instantiates the model, which cannot happen while the model is booting.
        foreach (['created', 'updated', 'deleted', 'restoring', 'restored'] as $event) {
            static::registerModelEvent($event, AuditObserver::class.'@'.$event);
        }
    }

    /** The audit rows where this model is the subject. */
    public function audits(): MorphMany
    {
        $related = $this->newRelatedInstance(config('audit.model', Audit::class));

        return new SubjectAudits(
            $related->newQuery(),
            $this,
            $related->qualifyColumn('subject_type'),
            $related->qualifyColumn('subject_id'),
            $this->getKeyName(),
        );
    }

    /** This model's rows, plus the rows whose parent is this model: a timeline. Unordered. */
    public function auditTrail(): Builder
    {
        return $this->newRelatedInstance(config('audit.model', Audit::class))
            ->newQuery()
            ->forSubjectAndChildren($this);
    }

    /** The record label stored with each row: the first filled attribute in config('audit.attributes.label'). */
    public function auditLabel(): ?string
    {
        return app(Recorder::class)->defaultLabel($this);
    }

    /**
     * The record this one rolls up to, for the timeline: a model, or [Class::class, $id]. Null for none.
     *
     * @return Model|array{0: class-string<Model>, 1: string|int|null}|null
     */
    public function auditParent(): Model|array|null
    {
        return null;
    }

    /**
     * Attributes that are never stored and never count as a change. Glob patterns, added to config('audit.attributes.exclude').
     *
     * @return list<string>
     */
    public function auditExclude(): array
    {
        return [];
    }

    /**
     * Attributes stored as "[redacted]". Glob patterns, added to config('audit.attributes.redact').
     *
     * @return list<string>
     */
    public function auditRedact(): array
    {
        return [];
    }

    /**
     * The events this model records. 'deleted' also covers 'force_deleted'.
     *
     * @return list<string>
     */
    public function auditEvents(): array
    {
        return ['created', 'updated', 'deleted', 'restored'];
    }

    /**
     * With config('audit.transactional_saves') on, the save and its audit row commit or roll back together.
     * If the audit insert fails, the model is put back as it was before the call, so a retry starts clean.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        if (! $this->auditsInTransaction($this->exists ? ['updated', 'restored'] : ['created'])) {
            return parent::save($options);
        }

        return $this->auditTransaction(fn () => parent::save($options));
    }

    public function delete()
    {
        if (! $this->auditsInTransaction(['deleted', 'force_deleted'])) {
            return parent::delete();
        }

        return $this->auditTransaction(fn () => parent::delete());
    }

    /** increment() and decrement() fire `updated`, so they get the same atomic wrapper. */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        if (! $this->exists || ! $this->auditsInTransaction(['updated'])) {
            return parent::incrementOrDecrement($column, $amount, $extra, $method);
        }

        return $this->auditTransaction(fn () => parent::incrementOrDecrement($column, $amount, $extra, $method));
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method)
    {
        if (! $this->exists || ! $this->auditsInTransaction(['updated'])) {
            return parent::incrementOrDecrementEach($columns, $extra, $method);
        }

        return $this->auditTransaction(fn () => parent::incrementOrDecrementEach($columns, $extra, $method));
    }

    /**
     * Whether the operation needs the wrapper: auditing and the switch are on, and the model
     * records at least one of the events the operation can fire.
     *
     * @param  list<string>  $events
     */
    private function auditsInTransaction(array $events): bool
    {
        return (bool) config('audit.enabled', true)
            && (bool) config('audit.transactional_saves', true)
            && array_intersect($events, $this->auditEvents()) !== [];
    }

    /** Run the callback in a transaction on the model's connection, and undo its effect on the model if it throws. */
    private function auditTransaction(Closure $callback): mixed
    {
        $state = [
            $this->attributes, $this->original, $this->changes, $this->previous,
            $this->exists, $this->wasRecentlyCreated,
        ];

        try {
            return $this->getConnection()->transaction($callback);
        } catch (Throwable $e) {
            // The database rolled back, but the model still looks persisted (or deleted, or force deleting).
            [$this->attributes, $this->original, $this->changes, $this->previous, $this->exists, $this->wasRecentlyCreated] = $state;

            // forceDelete() sets this before calling delete(), and never clears it when delete() throws.
            if (property_exists($this, 'forceDeleting')) {
                $this->forceDeleting = false;
            }

            // Object casts (AsArrayObject and friends) mutate in place; drop what they cached.
            $this->classCastCache = [];
            $this->attributeCastCache = [];

            AuditObserver::abandonRestore($this);

            throw $e;
        }
    }
}
