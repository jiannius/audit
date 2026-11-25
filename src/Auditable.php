<?php

namespace Jiannius\Audit;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Jiannius\Audit\Relations\SubjectAudits;

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
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        if (! $this->auditsInTransaction()) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    public function delete()
    {
        if (! $this->auditsInTransaction()) {
            return parent::delete();
        }

        return $this->getConnection()->transaction(fn () => parent::delete());
    }

    private function auditsInTransaction(): bool
    {
        return (bool) config('audit.enabled', true) && (bool) config('audit.transactional_saves', true);
    }
}
