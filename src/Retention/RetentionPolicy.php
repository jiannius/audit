<?php

namespace Jiannius\Audit\Retention;

use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Jiannius\Audit\Category;
use Throwable;

/**
 * Turns `audit.retention` into rules, each with a cutoff. Overrides come first, in list order, and a
 * category default applies only to the rows of its category that no override matches.
 */
final class RetentionPolicy
{
    public const CATEGORY = 'category';

    public const OVERRIDE = 'override';

    private const UNITS = 'second|minute|hour|day|week|month|year';

    /** @param  list<RetentionRule>  $rules  overrides in config order, then the category defaults */
    private function __construct(private readonly array $rules) {}

    /** Build the policy from `audit.retention`, with every cutoff counted back from $now. */
    public static function fromConfig(?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $config = (array) config('audit.retention', []);

        // A misspelt key (`override`, `recrod`) would quietly stop protecting or pruning the rows it meant.
        $unknown = array_diff(array_keys($config), [...Category::all(), 'overrides']);

        if ($unknown !== []) {
            throw new InvalidArgumentException('audit.retention has unknown keys ['.implode(', ', $unknown).']. Use '.implode(', ', Category::all()).' and overrides.');
        }

        $rules = [];
        $shadows = [];

        foreach ((array) ($config['overrides'] ?? []) as $index => $override) {
            [$criteria, $keep] = self::parseOverride($index, $override);

            $rules[] = new RetentionRule(self::OVERRIDE, $criteria, self::cutoff($keep, $now, "overrides.{$index}.keep"), $shadows, $override);
            $shadows[] = $criteria;
        }

        foreach (Category::all() as $category) {
            $rules[] = new RetentionRule(self::CATEGORY, ['category' => $category], self::cutoff($config[$category] ?? null, $now, $category), $shadows, ['category' => $category]);
        }

        return new self($rules);
    }

    /** @return list<RetentionRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * How long the rows inside a filter are kept: forever when any rule that could match them keeps
     * them forever, else the cutoff of the rule that keeps them longest.
     *
     * @param  array<string, string>  $filter  column => value
     * @return array{forever: bool, cutoff: ?CarbonImmutable}
     */
    public function retentionFor(array $filter): array
    {
        $cutoff = null;

        foreach ($this->rules as $rule) {
            if (! $rule->couldMatch($filter)) {
                continue;
            }

            if ($rule->cutoff === null) {
                return ['forever' => true, 'cutoff' => null];
            }

            // The earliest cutoff is the longest retention.
            if ($cutoff === null || $rule->cutoff->lessThan($cutoff)) {
                $cutoff = $rule->cutoff;
            }
        }

        return ['forever' => false, 'cutoff' => $cutoff];
    }

    /** @return array{0: array<string, string>, 1: mixed} */
    private static function parseOverride(int|string $index, mixed $override): array
    {
        if (! is_array($override)) {
            throw new InvalidArgumentException("audit.retention.overrides.{$index} must be an array.");
        }

        $unknown = array_diff(array_keys($override), [...RetentionRule::COLUMNS, 'keep']);

        if ($unknown !== []) {
            throw new InvalidArgumentException("audit.retention.overrides.{$index} has unknown keys [".implode(', ', $unknown).'], so it would match more rows than intended. Use '.implode(', ', RetentionRule::COLUMNS).' and keep.');
        }

        if (! array_key_exists('keep', $override)) {
            throw new InvalidArgumentException("audit.retention.overrides.{$index} needs a keep value, for example '90 days', or null to keep forever.");
        }

        $criteria = [];

        foreach (RetentionRule::COLUMNS as $column) {
            if (! array_key_exists($column, $override)) {
                continue;
            }

            if (! is_string($override[$column]) || trim($override[$column]) === '') {
                throw new InvalidArgumentException("audit.retention.overrides.{$index}.{$column} must be a non-empty string.");
            }

            $criteria[$column] = trim($override[$column]);
        }

        if ($criteria === []) {
            throw new InvalidArgumentException("audit.retention.overrides.{$index} needs at least one of ".implode(', ', RetentionRule::COLUMNS).'.');
        }

        if (isset($criteria['category']) && ! in_array($criteria['category'], Category::all(), true)) {
            throw new InvalidArgumentException("audit.retention.overrides.{$index}.category must be one of: ".implode(', ', Category::all()).'.');
        }

        // Rows store the morph alias, so a class name means its alias, as it does for `audit:purge --type`.
        if (isset($criteria['subject_type'])) {
            $criteria['subject_type'] = Relation::getMorphAlias($criteria['subject_type']);
        }

        return [$criteria, $override['keep']];
    }

    /**
     * The moment before which rows may go: `$keep` counted back from now, which must lie in the past.
     *
     * $keep is `null` (keep forever), a sequence of `<positive number> <unit>` pairs such as `'7 years'` or
     * `'1 year 6 months'` (second, minute, hour, day, week, month, year, singular or plural), or an ISO 8601
     * duration such as `'P90D'`. Anything else, zero included, is refused: a period that reads as "now" or as
     * a future date would delete a whole category.
     */
    private static function cutoff(mixed $keep, CarbonImmutable $now, string $where): ?CarbonImmutable
    {
        if ($keep === null) {
            return null;
        }

        $cutoff = null;

        try {
            if ($keep instanceof DateInterval) {
                $cutoff = $keep->invert === 1 ? null : $now->sub($keep);
            } elseif (is_string($keep) && preg_match('/\A\s*P(?=\d|T\d)/', $keep) === 1) {
                $cutoff = $now->sub(new DateInterval(trim($keep)));
            } elseif (is_string($keep) && preg_match('/\A\s*(?:[1-9]\d{0,5}\s*(?:'.self::UNITS.')s?\s*)+\z/i', $keep) === 1) {
                $cutoff = $now;

                preg_match_all('/([1-9]\d*)\s*('.self::UNITS.')/i', $keep, $pairs, PREG_SET_ORDER);

                foreach ($pairs as [, $amount, $unit]) {
                    $cutoff = $cutoff->sub(strtolower($unit), (int) $amount);
                }
            }
        } catch (Throwable) {
            $cutoff = null;
        }

        if ($cutoff === null || ! $cutoff->lessThan($now)) {
            throw new InvalidArgumentException("audit.retention.{$where} is not a period that reaches back from now. Use a positive period such as '7 years', '90 days' or '1 year 6 months', an ISO 8601 duration such as 'P90D', or null to keep forever.");
        }

        return $cutoff;
    }
}
