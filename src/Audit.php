<?php

namespace Jiannius\Audit;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Jiannius\Audit\Exceptions\ImmutableAuditException;

/**
 * The read side of the trail. Rows are inserted by the Recorder, never through
 * this model, and an instance cannot be updated or deleted.
 *
 * @property int $id
 * @property string $category
 * @property string $event
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property ?string $subject_label
 * @property ?string $parent_type
 * @property ?string $parent_id
 * @property ?string $actor_type
 * @property ?string $actor_id
 * @property ?string $actor_name
 * @property ?string $actor_identifier
 * @property ?string $source
 * @property ?string $request_id
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property ?array $old_values
 * @property ?array $new_values
 * @property ?array $meta
 * @property Carbon $created_at
 */
class Audit extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function () {
            throw ImmutableAuditException::forAction('updated');
        });

        static::deleting(function () {
            throw ImmutableAuditException::forAction('deleted');
        });

        foreach ((array) config('audit.scopes', []) as $column => $scope) {
            if (! is_array($scope) || empty($scope['read'])) {
                continue;
            }

            static::addGlobalScope("audit.scope.{$column}", function (Builder $query) use ($column, $scope) {
                $current = static::resolveReadScope($scope['read']);

                // No current value means no tenant: show nothing rather than everything.
                $current === null
                    ? $query->whereRaw('1 = 0')
                    : $query->where($query->qualifyColumn($column), $current);
            });
        }
    }

    public function getTable(): string
    {
        return config('audit.table', 'audits');
    }

    public function getConnectionName(): ?string
    {
        return config('audit.connection');
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** The record this row is about. Null once it is gone; never auto-loaded. */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject', 'subject_type', 'subject_id');
    }

    public function parent(): MorphTo
    {
        return $this->morphTo('parent', 'parent_type', 'parent_id');
    }

    public function actor(): MorphTo
    {
        return $this->morphTo('actor', 'actor_type', 'actor_id');
    }

    public function scopeForSubject(Builder $query, Model $model): void
    {
        $query
            ->where($query->qualifyColumn('subject_type'), $model->getMorphClass())
            ->where($query->qualifyColumn('subject_id'), (string) $model->getKey());
    }

    /** Rows about the model, and rows whose parent is the model. */
    public function scopeForSubjectAndChildren(Builder $query, Model $model): void
    {
        $query->where(function (Builder $query) use ($model) {
            $query->where(fn (Builder $q) => $q->forSubject($model))
                ->orWhere(function (Builder $q) use ($model) {
                    $q->where($q->qualifyColumn('parent_type'), $model->getMorphClass())
                        ->where($q->qualifyColumn('parent_id'), (string) $model->getKey());
                });
        });
    }

    public function scopeByActor(Builder $query, Model $actor): void
    {
        $query
            ->where($query->qualifyColumn('actor_type'), $actor->getMorphClass())
            ->where($query->qualifyColumn('actor_id'), (string) $actor->getKey());
    }

    public function scopeInCategory(Builder $query, string ...$categories): void
    {
        $query->whereIn($query->qualifyColumn('category'), $categories);
    }

    public function scopeBetween(Builder $query, DateTimeInterface|string $from, DateTimeInterface|string $to): void
    {
        $query->whereBetween($query->qualifyColumn('created_at'), [$from, $to]);
    }

    /**
     * The union of the old and new keys.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function fieldChanges(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $changes = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $changes[$field] = [
                'old' => $old[$field] ?? null,
                'new' => $new[$field] ?? null,
            ];
        }

        return $changes;
    }

    private static function resolveReadScope(mixed $read): string|int|null
    {
        $callable = is_callable($read) && ! is_string($read) ? $read : app($read);
        $value = $callable();

        return is_scalar($value) ? $value : null;
    }
}
