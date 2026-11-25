<?php

namespace Jiannius\Audit\Export;

use Illuminate\Support\Str;
use Jiannius\Audit\Contracts\FormatsAuditValues;

/**
 * The default formatter: readable names, scalars as they are, arrays as JSON, null as blank.
 */
class RawFormatter implements FormatsAuditValues
{
    public function recordType(string $subjectType): string
    {
        return Str::headline($subjectType);
    }

    public function field(string $subjectType, string $field): string
    {
        return Str::headline($field);
    }

    public function value(string $subjectType, string $field, mixed $raw): string|int|float|bool|null
    {
        if ($raw === null || is_scalar($raw)) {
            return $raw;
        }

        // A JSON column or a pivot field: the list [1, 1, 2] or the map {"1": {"role": "admin"}}.
        $json = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }
}
