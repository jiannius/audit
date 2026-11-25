<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\Contracts\FormatsAuditValues;

/** A host formatter: money stored in cents is shown as an amount, and names are written out in full. */
class CentsFormatter implements FormatsAuditValues
{
    public function recordType(string $subjectType): string
    {
        return ucfirst(str_replace('-', ' ', $subjectType));
    }

    public function field(string $subjectType, string $field): string
    {
        return ucfirst($field);
    }

    public function value(string $subjectType, string $field, mixed $raw): string|int|float|bool|null
    {
        return match (true) {
            $field === 'total' && is_int($raw) => $raw / 100,
            is_array($raw) => implode('|', array_map('json_encode', $raw)),
            default => $raw,
        };
    }
}
