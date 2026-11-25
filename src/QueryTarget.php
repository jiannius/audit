<?php

namespace Jiannius\Audit;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * What a bulk helper works on: the query as a base builder, with the global scopes of an
 * Eloquent builder or relation already applied, and the model its rows belong to.
 *
 * @internal
 */
final class QueryTarget
{
    private const CHUNK = 1000;

    private function __construct(
        public readonly QueryBuilder $base,
        public readonly Model $model,
        public readonly bool $eloquent,
    ) {}

    /**
     * @param  bool  $withTrashed  drop the soft-delete scope (and only that one: other global scopes still apply)
     */
    public static function of(EloquentBuilder|Relation|QueryBuilder $query, ?string $subjectType = null, bool $withTrashed = false): self
    {
        if ($query instanceof Relation) {
            $query = $query->getQuery();
        }

        if ($query instanceof EloquentBuilder) {
            if ($withTrashed) {
                $query = (clone $query)->withoutGlobalScope(SoftDeletingScope::class);
            }

            return new self($query->toBase(), $query->getModel(), true);
        }

        return new self($query, self::modelFor($query, $subjectType), false);
    }

    public function connection(): ConnectionInterface
    {
        return $this->base->getConnection();
    }

    public function keyName(): string
    {
        return $this->model->getKeyName();
    }

    /** The table the query reads from, without any alias. */
    public function table(): string
    {
        return self::fromParts($this->base)[0];
    }

    /** The name the query refers to its table by: the alias when it has one, else the table. */
    public function alias(): string
    {
        return self::fromParts($this->base)[1];
    }

    /**
     * Refuse a query whose audited set could differ from its written set. Laravel's MySQL grammar drops
     * ORDER BY and LIMIT from an UPDATE or DELETE that has joins, but the locked select would keep them.
     */
    public function assertWritable(): void
    {
        if ($this->base->joins && ($this->base->limit !== null || $this->base->offset !== null)) {
            throw new InvalidArgumentException('Auditor cannot audit an update or delete that combines joins (a many-to-many or has-many-through relation counts) with limit() or offset(): the database ignores the limit on a joined write, so the audited rows could differ from the written ones. Select the keys first and use whereKey().');
        }
    }

    /** Whether the model soft deletes, which makes an Eloquent builder's delete() a soft delete. */
    public function softDeletes(): bool
    {
        return $this->eloquent && method_exists($this->model, 'isForceDeleting');
    }

    /** A model for one raw row. Every column is present, so overrides that read raw attributes are strict-safe. */
    public function hydrate(array $row): Model
    {
        return $this->model->newFromBuilder($row, $this->connection()->getName());
    }

    /**
     * The rows the query matches, locked, as raw arrays keyed by primary key (as a string).
     *
     * @return array<string, array<string, mixed>>
     */
    public function lockedRows(): array
    {
        $query = $this->base->cloneWithout(['columns'])->cloneWithoutBindings(['select']);

        // A join would make a bare * pull in the other tables' columns.
        // Ordered by key, so two audited writes lock overlapping rows in the same order.
        $query->select($this->base->joins ? $this->alias().'.*' : '*')
            ->orderBy($this->alias().'.'.$this->keyName())
            ->lockForUpdate();

        return $this->keyed($query->get());
    }

    /**
     * The rows with these keys as they are now, in chunks, whatever the original WHERE says.
     *
     * @param  list<int|string>  $keys
     * @return array<string, array<string, mixed>>
     */
    public function rowsByKey(array $keys): array
    {
        $rows = [];

        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $rows += $this->keyed($this->base->newQuery()->from($this->base->from)->whereIn($this->keyName(), $chunk)->get());
        }

        return $rows;
    }

    /**
     * @param  iterable<object>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function keyed(iterable $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $keyed[(string) $row[$this->keyName()]] = $row;
        }

        return $keyed;
    }

    /** @return array{0: string, 1: string} the table, and the alias the query refers to it by */
    private static function fromParts(QueryBuilder $query): array
    {
        $from = $query->from;

        if (! is_string($from) || $from === '') {
            throw new InvalidArgumentException('The query must select from a plain table to be audited.');
        }

        $parts = preg_split('/\s+as\s+/i', $from) ?: [$from];

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    private static function modelFor(QueryBuilder $query, ?string $subjectType): Model
    {
        $model = $subjectType !== null ? self::modelNamed($subjectType) : self::modelFromTable($query);

        return $model->setConnection($query->getConnection()->getName());
    }

    private static function modelNamed(string $type): Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException(sprintf('Unknown subject type [%s]. Use a morph-map alias or an Eloquent model class.', $type));
        }

        return new $class;
    }

    private static function modelFromTable(QueryBuilder $query): Model
    {
        $table = self::fromParts($query)[0];
        $matches = [];

        foreach (Relation::morphMap() ?: [] as $alias => $class) {
            if (is_string($class) && is_subclass_of($class, Model::class) && (new $class)->getTable() === $table) {
                $matches[$alias] = $class;
            }
        }

        if (count($matches) === 1) {
            return new (reset($matches));
        }

        throw new InvalidArgumentException($matches === []
            ? sprintf('Cannot tell which model the table [%s] belongs to: no morph-map entry uses it. Pass the subjectType, for example subjectType: \'invoice\'.', $table)
            : sprintf('Cannot tell which model the table [%s] belongs to: %s all use it. Pass the subjectType.', $table, implode(', ', array_keys($matches))));
    }
}
