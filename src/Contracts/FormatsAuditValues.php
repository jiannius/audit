<?php

namespace Jiannius\Audit\Contracts;

/**
 * Turns the raw values of an audit row into what a reader of the CSV export should see.
 *
 * The raw value can be a scalar, `null`, or an array (a pivot field: [1, 1, 2] or {"1": {"role": "admin"}}).
 * A JSON-cast column arrives as the JSON text the database holds, a string. Return numbers as numbers: a string cell that starts with
 * `=`, `+`, `-` or `@` is prefixed with a quote, and a negative amount returned as a string would be.
 */
interface FormatsAuditValues
{
    /** 'invoice-line' => 'Invoice line'. The subject type is the morph alias, or an empty string when the row has no subject. */
    public function recordType(string $subjectType): string;

    /** 'total' => 'Total'. */
    public function field(string $subjectType, string $field): string;

    /** 15050 => 150.5. A null result is a blank cell. */
    public function value(string $subjectType, string $field, mixed $raw): string|int|float|bool|null;
}
