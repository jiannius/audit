<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\Contracts\FormatsScopeValues;
use Jiannius\Audit\Export\RawFormatter;

/** A host formatter that writes team names for the team_id scope column, loaded once per batch. */
class TeamNameFormatter extends RawFormatter implements FormatsScopeValues
{
    /** @var list<array{string, list<mixed>}> each prepareScopeValues() call, in order */
    public array $prepared = [];

    /** @var array<string, array<int|string, string>> column => raw value => name */
    private array $names = [];

    public function prepareScopeValues(string $column, array $raw): void
    {
        $this->prepared[] = [$column, $raw];

        if ($column === 'team_id' && $raw !== []) {
            $this->names[$column] = Team::query()->whereIn('id', $raw)->pluck('name', 'id')->all();
        }
    }

    public function scopeValue(string $column, mixed $raw): string|int|float|bool|null
    {
        return match (true) {
            $raw === null => null,
            $column === 'team_id' => $this->names[$column][$raw] ?? '#'.$raw,
            default => $raw,
        };
    }
}
