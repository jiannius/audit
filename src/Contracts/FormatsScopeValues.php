<?php

namespace Jiannius\Audit\Contracts;

/**
 * Optional, for a `FormatsAuditValues` formatter: turns the raw values of the export's scope columns
 * (a tenant id, say) into what a reader should see (the tenant's name).
 *
 * The export reads rows a thousand at a time. Before it writes a batch, it calls `prepareScopeValues()`
 * once per scope column with the distinct values in that batch, so you can load their names in one query
 * and keep them for `scopeValue()`. Without this interface on the formatter, the raw values are written.
 */
interface FormatsScopeValues
{
    /**
     * Called before each batch of rows, once per scope column, with the batch's distinct non-null values,
     * which may be none.
     * It may do nothing, if `scopeValue()` needs no lookup.
     *
     * @param  list<mixed>  $raw
     */
    public function prepareScopeValues(string $column, array $raw): void;

    /** 5 => 'Acme Ltd'. Called once per audit entry, null included. A null result is a blank cell. */
    public function scopeValue(string $column, mixed $raw): string|int|float|bool|null;
}
