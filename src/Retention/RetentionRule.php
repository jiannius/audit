<?php

namespace Jiannius\Audit\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * One retention rule: the rows it applies to, and the moment before which they may go.
 *
 * A rule applies to the rows that match its own criteria and match none of the rules listed
 * before it (the first matching override wins), so a category default never reaches rows an
 * override already claimed.
 *
 * @internal Built by RetentionPolicy.
 */
final class RetentionRule
{
    /** The columns an override can match on. */
    public const COLUMNS = ['subject_type', 'event', 'category'];

    /**
     * @param  array<string, string>  $criteria  column => value
     * @param  list<array<string, string>>  $shadows  the criteria of every rule that wins over this one
     * @param  array<string, mixed>  $source  the config entry this rule came from
     */
    public function __construct(
        public readonly string $type,
        public readonly array $criteria,
        public readonly ?CarbonImmutable $cutoff,
        public readonly array $shadows,
        public readonly array $source,
    ) {}

    public function isOverride(): bool
    {
        return $this->type === RetentionPolicy::OVERRIDE;
    }

    /** The rows this rule applies to, older than its cutoff when it has one. */
    public function constrain(Builder $query): Builder
    {
        foreach ($this->criteria as $column => $value) {
            $query->where($column, $value);
        }

        foreach ($this->shadows as $shadow) {
            // NOT (a AND b), written so a NULL column counts as "does not match" and the row stays ours.
            $query->where(function (Builder $outer) use ($shadow) {
                foreach ($shadow as $column => $value) {
                    $outer->orWhere(fn (Builder $not) => $not->whereNull($column)->orWhere($column, '<>', $value));
                }
            });
        }

        if ($this->cutoff !== null) {
            $query->where('created_at', '<', $this->cutoff->format('Y-m-d H:i:s'));
        }

        return $query;
    }

    /**
     * Whether any row inside the filter could be a row this rule applies to. Errs towards yes.
     *
     * Values are compared trimmed and without regard to case, because a case-insensitive collation (MySQL's
     * default) matches `VOIDED` to `voided`, and a filter that "could not" match a rule it does would cut below it.
     * Whether an earlier rule owns the filter's rows is the opposite risk, so that is compared exactly.
     *
     * @param  array<string, string>  $filter  column => value, the columns the filter pins
     */
    public function couldMatch(array $filter): bool
    {
        foreach ($this->criteria as $column => $value) {
            if (isset($filter[$column]) && self::fold($filter[$column]) !== self::fold($value)) {
                return false;
            }
        }

        // The rows both the filter and this rule pin down: an earlier rule that asks for no more than
        // that already owns every one of them.
        $pinned = array_map('trim', $filter) + $this->criteria;

        foreach ($this->shadows as $shadow) {
            if (array_diff_assoc($shadow, $pinned) === []) {
                return false;
            }
        }

        return true;
    }

    private static function fold(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /** What the rule looks like in a summary row. */
    public function describe(): array
    {
        return [
            $this->isOverride() ? 'override' : 'category' => $this->isOverride() ? $this->source : $this->criteria['category'],
            'cutoff' => $this->cutoff?->toIso8601String(),
        ];
    }
}
