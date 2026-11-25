<?php

namespace Jiannius\Audit\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Jiannius\Audit\Audit;
use PHPUnit\Framework\Assert;

/**
 * Assertions on audit rows, for a feature test:
 *
 *     $this->assertAudited($invoice, 'updated', old: ['status' => 'draft'], new: ['status' => 'sent']);
 *     $this->assertNotAudited($invoice, 'updated');
 *
 * They look at the rows whose subject is the model, whichever tenant they belong to.
 */
trait InteractsWithAudits
{
    /**
     * Assert that the model has an audit row for the event, whose old and new values include these.
     * Only the keys you give are compared, and by meaning: an integer and its numeric string are the
     * same value, a boolean is the 1 or 0 the database holds, and the order of keys does not matter.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function assertAudited(Model $model, string $event, array $old = [], array $new = []): void
    {
        $rows = $this->auditRowsOf($model);
        $named = $rows->where('event', $event);

        $matching = $named->first(fn (Audit $row) => self::auditIncludes($row->old_values ?? [], $old) && self::auditIncludes($row->new_values ?? [], $new));

        if ($matching !== null) {
            Assert::assertTrue(true);

            return;
        }

        $description = sprintf('%s [%s]', $model->getMorphClass(), $model->getKey());

        if ($rows->isEmpty()) {
            Assert::fail("{$description} has no audit rows at all. Was the change made inside Event::fake(), or through a write that skips model events?");
        }

        if ($named->isEmpty()) {
            Assert::fail("{$description} has no [{$event}] audit row. Its rows are: ".$rows->pluck('event')->implode(', ').'.');
        }

        Assert::fail("{$description} has [{$event}] rows, but none with the old and new values you gave.\n  expected old: ".self::auditJson($old).'  new: '.self::auditJson($new)."\n".$named->map(fn (Audit $row) => '  found    old: '.self::auditJson($row->old_values ?? []).'  new: '.self::auditJson($row->new_values ?? []))->implode("\n"));
    }

    /** Assert that the model has no audit row for the event. */
    public function assertNotAudited(Model $model, string $event): void
    {
        $rows = $this->auditRowsOf($model)->where('event', $event);

        Assert::assertTrue($rows->isEmpty(), sprintf('%s [%s] has %d [%s] audit row%s, and should have none.', $model->getMorphClass(), $model->getKey(), $rows->count(), $event, $rows->count() === 1 ? '' : 's'));
    }

    /** @return Collection<int, Audit> */
    private function auditRowsOf(Model $model)
    {
        /** @var class-string<Audit> $class */
        $class = config('audit.model', Audit::class);

        return $class::query()->withoutGlobalScopes()->forSubject($model)->orderBy('id')->get();
    }

    /**
     * Whether every key you expect is in the row's values with the same value.
     *
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $expected
     */
    private static function auditIncludes(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || ! self::auditSame($actual[$key], $value)) {
                return false;
            }
        }

        return true;
    }

    private static function auditSame(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual) || is_array($expected)) {
            if (! is_array($actual) || ! is_array($expected) || count($actual) !== count($expected)) {
                return false;
            }

            // Lists compare in order, maps by key: a pivot's id list is sorted, a map's keys are not ordered.
            foreach ($expected as $key => $value) {
                if (! array_key_exists($key, $actual) || ! self::auditSame($actual[$key], $value)) {
                    return false;
                }
            }

            return true;
        }

        $expected = is_bool($expected) ? (int) $expected : $expected;
        $actual = is_bool($actual) ? (int) $actual : $actual;

        // An integer and its numeric string are one value. Two strings are compared as they are: '007' is not '7'.
        if (is_numeric($actual) && is_numeric($expected) && ! (is_string($actual) && is_string($expected))) {
            return (float) $actual === (float) $expected;
        }

        return $actual === $expected;
    }

    private static function auditJson(array $values): string
    {
        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
