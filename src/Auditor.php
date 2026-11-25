<?php

namespace Jiannius\Audit;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jiannius\Audit\Context\AuditContext;
use RuntimeException;

/**
 * The logger and the write helpers. Use it through the Auditor facade.
 *
 * Every helper that changes data runs the change and its audit rows in one
 * transaction on the query's connection, so a failed audit insert rolls the
 * change back.
 */
class Auditor
{
    /** Keys per re-read, and per batch of recorded rows. */
    private const CHUNK = 1000;

    public function __construct(
        protected Recorder $recorder,
        protected AuditObserver $observer,
        protected AttributeRules $rules,
    ) {}

    /**
     * Record a custom event.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $meta = [],
        array $old = [],
        array $new = [],
        string $category = Category::ACTION,
        ?Model $parent = null,
    ): void {
        $this->recorder->record(new PendingAudit($event, $category, $subject, $parent, $meta, $old, $new));
    }

    /**
     * A bulk or raw update that is audited: one row per record that actually changed.
     *
     * In one transaction on the query's connection it selects the matching rows with a lock, runs the
     * update, reads the same rows again by key, and compares the written columns. Expressions such as
     * DB::raw('total + 1') work, because the new value is read back rather than computed.
     *
     * A base query builder (DB::table()) needs $subjectType, a morph-map alias or a model class, unless
     * exactly one morph-map model uses its table. For an Eloquent builder or a relation the type comes
     * from the model, and $subjectType is ignored.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $meta
     * @return int the number of rows the update affected
     */
    public function update(EloquentBuilder|Relation|QueryBuilder $query, array $values, string $event = 'updated', array $meta = [], ?string $subjectType = null): int
    {
        $target = QueryTarget::of($query, $subjectType);

        if (! $this->observer->allows($target->model, $event)) {
            return $query->update($values);
        }

        $target->assertWritable();
        $columns = $this->writtenColumns($target, $values);

        return $target->connection()->transaction(function () use ($query, $target, $values, $columns, $event, $meta) {
            $before = $target->lockedRows();
            $affected = $query->update($values);

            $this->assertSameSet($before, $affected);

            // The new state is read back one chunk at a time, so only the before-snapshot is held in full.
            foreach (array_chunk(array_column($before, $target->keyName()), self::CHUNK) as $keys) {
                $after = $target->rowsByKey($keys);

                foreach ($after as $key => $row) {
                    $old = $new = [];

                    foreach ($columns as $column) {
                        $was = $before[$key][$column] ?? null;
                        $now = $row[$column] ?? null;

                        if (! $this->same($was, $now)) {
                            $old[$column] = $was;
                            $new[$column] = $now;
                        }
                    }

                    // Rows the update left as they were, and changes to excluded columns only, leave nothing to record.
                    if ($new === []) {
                        continue;
                    }

                    $subject = $target->hydrate($row);
                    $new = $this->rules->values($subject, $new);

                    if ($new === []) {
                        continue;
                    }

                    $this->record($event, $subject, $meta, $this->rules->values($subject, $old), $new, Category::RECORD, $this->observer->parentOf($subject));
                }
            }

            return $affected;
        });
    }

    /**
     * A bulk or raw delete that is audited: every row is snapshotted before it goes.
     *
     * On an Eloquent builder or relation of a soft-deleting model this soft deletes, as Laravel's builder
     * does, and each row has meta.soft = true. Everything else is a hard delete with meta.soft = false.
     *
     * A soft delete records the row as the model's own delete() does: the attributes after the soft delete,
     * so `deleted_at` holds the new timestamp, and meta.soft. A hard delete records the row as it was.
     *
     * With $event = 'force_deleted' a soft-deleting model's rows are deleted for good, trashed ones too:
     * the soft-delete scope is dropped, and only that one. The model's other global scopes still apply
     * (Laravel's own forceDelete() drops them all). meta.soft is left out, as the observer does.
     *
     * @param  array<string, mixed>  $meta
     * @return int the number of rows the delete affected
     */
    public function delete(EloquentBuilder|Relation|QueryBuilder $query, string $event = 'deleted', array $meta = [], ?string $subjectType = null): int
    {
        $force = $event === 'force_deleted';
        $target = QueryTarget::of($query, $subjectType, withTrashed: $force);
        $soft = ! $force && $target->softDeletes();
        $run = fn (): int => $soft ? $query->delete() : $target->base->delete();

        if (! $this->observer->allows($target->model, $event)) {
            return $run();
        }

        $target->assertWritable();

        return $target->connection()->transaction(function () use ($run, $target, $event, $meta, $force, $soft) {
            $before = $target->lockedRows();
            $affected = $run();

            $this->assertSameSet($before, $affected);

            $meta = $force ? $meta : [...$meta, 'soft' => $soft];

            foreach (array_chunk(array_column($before, $target->keyName()), self::CHUNK) as $keys) {
                // A soft delete is recorded as the model records it, with the new deleted_at: read the rows back.
                $rows = $soft ? $target->rowsByKey($keys) : array_intersect_key($before, array_flip(array_map('strval', $keys)));

                foreach ($rows as $key => $row) {
                    $subject = $target->hydrate($row);

                    $this->record($event, $subject, $meta, $this->rules->values($subject, $row), [], Category::RECORD, $this->observer->parentOf($subject));
                }
            }

            return $affected;
        });
    }

    /**
     * An audited change to a many-to-many relation: sync, attach, detach, updateExistingPivot or anything else.
     *
     * The pivot rows are read before and after $write, in one transaction, and one row is recorded on
     * the relation's parent when they differ. Nothing is written when they do not.
     *
     * The field is the relation's name, and its old and new value is the whole set of links:
     *   - a relation with no pivot columns: a sorted list of the related ids, [1, 2, 5];
     *   - a relation with pivot columns (withPivot): a map of related id to its columns,
     *     {"1": {"role": "admin"}, "2": {"role": "member"}}, sorted by id.
     * An id linked more than once (a pivot table with no unique key) is repeated in the list; in the map,
     * its value is a list of the column sets, ordered by their JSON. Timestamp columns are left out, and
     * so are excluded attributes; redacted ones read "[redacted]".
     *
     * The field is the relation's method name when it can be told, else the related table. Pass $field to set it.
     *
     * @param  Closure(BelongsToMany): mixed  $write  receives the relation
     * @param  array<string, mixed>  $meta
     * @return mixed what $write returned
     */
    public function pivot(BelongsToMany $relation, Closure $write, string $event = 'updated', array $meta = [], ?string $field = null): mixed
    {
        $parent = $relation->getParent();

        if (! $this->observer->allows($parent, $event)) {
            return $write($relation);
        }

        if (! $parent->exists) {
            throw new InvalidArgumentException('The relation\'s parent ['.$parent::class.'] must be saved before its pivot can be audited.');
        }

        return $parent->getConnection()->transaction(function () use ($relation, $write, $parent, $event, $meta, $field) {
            $pivot = $relation->newPivot();
            $withColumns = $this->pivotColumns($relation, $pivot) !== [];
            $before = $this->pivotSnapshot($relation, $pivot, lock: true);
            $result = $write($relation);
            $after = $this->pivotSnapshot($relation, $pivot);

            if ($before !== $after) {
                $field ??= $this->pivotField($relation);

                $this->record(
                    $event,
                    $parent,
                    $meta,
                    [$field => $withColumns ? $this->redactPivot($pivot, $before) : $before],
                    [$field => $withColumns ? $this->redactPivot($pivot, $after) : $after],
                    Category::RECORD,
                    $this->observer->parentOf($parent),
                );
            }

            return $result;
        });
    }

    /** Run the callback with the given actor, or with nobody ("System") when null. */
    public function actingAs(?Authenticatable $actor, Closure $callback): mixed
    {
        return $this->context()->actingAs($actor, $callback);
    }

    /** Run the callback with a fixed source, for example "backfill:fix-totals". */
    public function withSource(string $source, Closure $callback): mixed
    {
        return $this->context()->withSource($source, $callback);
    }

    private function context(): AuditContext
    {
        return app(AuditContext::class);
    }

    /**
     * The columns the update writes, as plain column names: a qualifier (the table or its alias) and a JSON path are removed.
     * A column qualified with anything else throws.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function writtenColumns(QueryTarget $target, array $values): array
    {
        $columns = [];

        foreach (array_keys($values) as $key) {
            $column = Str::before((string) $key, '->');

            if (str_contains($column, '.')) {
                [$qualifier, $column] = explode('.', $column, 2);

                // Skipping a column we cannot place would write a change with no row, so refuse it.
                if ($qualifier !== $target->alias() && $qualifier !== $target->table()) {
                    throw new InvalidArgumentException(sprintf('Auditor cannot audit the column [%s]: its qualifier [%s] is neither the audited table [%s] nor its alias [%s].', $key, $qualifier, $target->table(), $target->alias()));
                }
            }

            if ($column === $target->keyName()) {
                throw new InvalidArgumentException('Auditor cannot audit an update of the primary key ['.$column.']: the rows could not be matched before and after.');
            }

            $columns[$column] = $column;
        }

        return array_values($columns);
    }

    /** Equal as stored: the same type and value, or two numbers that are equal. */
    private function same(mixed $a, mixed $b): bool
    {
        return $a === $b || ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b)) && $a == $b);
    }

    /**
     * The pivot rows now, as the old or new value of the relation's field: a sorted list of related ids,
     * or, with pivot columns, a map of id to columns. An id linked more than once is repeated in the list;
     * in the map its value is a list of its column sets, in JSON order. Raw values; excluded columns are
     * already gone.
     *
     * @return array<int|string, mixed>
     */
    private function pivotSnapshot(BelongsToMany $relation, Model $pivot, bool $lock = false): array
    {
        $idColumn = $relation->getRelatedPivotKeyName();
        $columns = $this->pivotColumns($relation, $pivot);

        $query = $relation->newPivotQuery()->select([$idColumn, ...$columns]);

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = array_map(fn ($row) => (array) $row, $query->get()->all());

        usort($rows, fn (array $a, array $b) => strnatcmp((string) $a[$idColumn], (string) $b[$idColumn]));

        if ($columns === []) {
            return array_map(fn (array $row) => $row[$idColumn], $rows);
        }

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row[$idColumn]][] = array_intersect_key($row, array_flip($columns));
        }

        return array_map(function (array $sets) {
            if (count($sets) === 1) {
                return $sets[0];
            }

            usort($sets, fn (array $a, array $b) => strcmp(json_encode($a), json_encode($b)));

            return $sets;
        }, $grouped);
    }

    /**
     * The pivot columns that are recorded: those declared with withPivot(), less the timestamps and the excluded.
     *
     * @return list<string>
     */
    private function pivotColumns(BelongsToMany $relation, Model $pivot): array
    {
        $declared = array_filter($relation->getPivotColumns(), is_string(...));
        $timestamps = [$relation->createdAt(), $relation->updatedAt()];

        $columns = array_values(array_diff($declared, $timestamps));

        return array_values(array_filter($columns, fn (string $column) => ! $this->rules->isExcluded($pivot, $column)));
    }

    /**
     * Redact the pivot columns that are secret, in a map of related id to columns (or to a list of column sets).
     *
     * @param  array<int|string, array<int|string, mixed>>  $snapshot
     * @return array<int|string, array<int|string, mixed>>
     */
    private function redactPivot(Model $pivot, array $snapshot): array
    {
        return array_map(
            fn (array $columns) => array_is_list($columns) ? array_map(fn (array $set) => $this->rules->values($pivot, $set), $columns) : $this->rules->values($pivot, $columns),
            $snapshot,
        );
    }

    /** The relation's method name when it is one, else the related table: a backtrace guess can name a closure and a file path. */
    private function pivotField(BelongsToMany $relation): string
    {
        $name = $relation->getRelationName();

        if (is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) && method_exists($relation->getParent(), $name)) {
            return $name;
        }

        return $relation->getRelated()->getTable();
    }

    /**
     * The write must have touched exactly the rows that were locked and snapshotted. More means rows were
     * written that have no audit row (a phantom insert, or a limit the database ignores): roll back.
     *
     * @param  array<string, array<string, mixed>>  $locked
     */
    private function assertSameSet(array $locked, int $affected): void
    {
        if ($affected > count($locked)) {
            throw new RuntimeException(sprintf('The write affected %d rows but only %d were audited (rows changed between the select and the write). Nothing was written; retry.', $affected, count($locked)));
        }
    }
}
