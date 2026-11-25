<?php

namespace Jiannius\Audit\Export;

use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jiannius\Audit\Contracts\FormatsAuditValues;
use Jiannius\Audit\Contracts\FormatsScopeValues;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A streamed, CSV-injection-safe export of the rows a query selects.
 *
 * You supply the query, so your own filters and read scopes apply. The rows are read in id order,
 * a thousand at a time, and each batch is written before the next is read, so the whole result is never in memory.
 * Each field of each audit row becomes one CSV row; the Audit ID column regroups them.
 */
final class CsvExport
{
    /** Rows per read. */
    private const CHUNK = 1000;

    /** A string cell starting with one of these is read as a formula by spreadsheet software. */
    private const INJECTION_CHARACTERS = "=+-@\t\r";

    /** The columns before the host's scope columns, by the key `headings()` takes. */
    public const COLUMNS = [
        'id' => 'Audit ID',
        'time' => 'Time',
        'category' => 'Category',
        'event' => 'Event',
        'record_type' => 'Record type',
        'record_id' => 'Record ID',
        'record' => 'Record',
        'parent_type' => 'Parent type',
        'parent_id' => 'Parent ID',
        'field' => 'Field',
        'old_value' => 'Old value',
        'new_value' => 'New value',
        'actor_id' => 'User ID',
        'actor_name' => 'User name',
        'actor_identifier' => 'User identifier',
        'source' => 'Source',
        'ip_address' => 'IP address',
        'user_agent' => 'User agent',
        'request_id' => 'Request ID',
    ];

    /** The last column, after the scope columns: the row's meta as JSON. */
    public const DETAILS = ['details' => 'Details'];

    private ?FormatsAuditValues $formatter = null;

    private ?DateTimeZone $timezone = null;

    /** @var array<string, string> column => heading */
    private array $scopeColumns = [];

    /** @var array<string, string> column key => heading */
    private array $headings = [];

    private function __construct(private readonly Builder|Relation $query) {}

    /** Any Eloquent builder over the audits (usually `Audit::query()`), with your own filters applied. */
    public static function make(Builder|Relation $query): self
    {
        return new self($query);
    }

    /** Turns raw values into display values. Default: the `FormatsAuditValues` binding, which is `audit.formatter`. */
    public function formatter(FormatsAuditValues $formatter): self
    {
        $this->formatter = $formatter;

        return $this;
    }

    /** The timezone of the Time column. Default: the application's, which is what rows are stored in. */
    public function timezone(DateTimeZone|string|null $timezone): self
    {
        $this->timezone = $timezone === null || $timezone === '' ? null : ($timezone instanceof DateTimeZone ? $timezone : new DateTimeZone($timezone));

        return $this;
    }

    /**
     * Your scope columns, written after Request ID: `['team_id' => 'Team']`, or `['team_id']` for a
     * heading made from the column name.
     *
     * @param  array<int|string, string>  $columns
     */
    public function scopeColumns(array $columns): self
    {
        $this->scopeColumns = [];

        foreach ($columns as $column => $heading) {
            $name = is_int($column) ? $heading : $column;

            // A scope column named like one of ours would be written under the other's key and silently lost.
            if (isset((self::COLUMNS + self::DETAILS)[$name])) {
                throw new InvalidArgumentException("The scope column [{$name}] has the same name as an export column, so one of them would be dropped. Rename the scope column, or read it under another name.");
            }

            $this->scopeColumns[$name] = is_int($column) ? Str::headline($heading) : $heading;
        }

        return $this;
    }

    /**
     * Replace the heading of a column, by its key: `['actor_identifier' => 'User email']`.
     * The keys are those of `CsvExport::COLUMNS`, `details`, and your scope columns.
     *
     * @param  array<string, string>  $headings
     */
    public function headings(array $headings): self
    {
        $this->headings = $headings;

        return $this;
    }

    /** The response for a controller: the CSV streamed as a download. */
    public function download(string $filename, array $headers = []): StreamedResponse
    {
        $this->headingRow();
        $this->assertNotLimited();

        $response = new StreamedResponse(function () {
            $handle = fopen('php://output', 'w');

            try {
                $this->write($handle);
            } finally {
                fclose($handle);
            }
        }, 200, $headers + [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // A header cannot hold control characters or path separators, and the ASCII fallback no percent sign.
        $filename = str_replace(['/', '\\'], '-', preg_replace('/[\x00-\x1f\x7f]/', '', $filename) ?? '');
        $fallback = str_replace('%', '-', Str::ascii($filename));

        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename === '' ? 'audit.csv' : $filename, $fallback === '' ? 'audit.csv' : $fallback));

        return $response;
    }

    /**
     * Write the BOM, the headings and every row to an open stream, for a command or a test.
     *
     * @param  resource  $handle
     * @return int the number of data rows written, not counting the heading row
     */
    public function write($handle): int
    {
        $headings = $this->headingRow();
        $this->assertNotLimited();
        $formatter = $this->formatter ?? app(FormatsAuditValues::class);
        $timezone = $this->timezone;
        $count = 0;

        if (fwrite($handle, "\xEF\xBB\xBF") === false) {
            throw new RuntimeException('Could not write the CSV export.');
        }

        $this->put($handle, $headings);

        // Paging by id appends its own order to the query's. Left in place, the query's order comes first and
        // the pages overlap or skip rows, so it is dropped: the export is in id order, whatever the query asked for.
        $query = (clone $this->query)->reorder();

        $query->chunkById(self::CHUNK, function ($audits) use ($handle, $formatter, $timezone, &$count) {
            $rows = $audits->map(fn ($audit) => $audit->getAttributes())->all();

            $this->prepareScopeValues($rows, $formatter);

            foreach ($rows as $audit) {
                foreach ($this->rowsOf($audit, $formatter, $timezone) as $row) {
                    $this->put($handle, $row);
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * Give a formatter that formats scope values each column's distinct values in the batch, so it can look
     * them up in one query instead of one per row.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function prepareScopeValues(array $rows, FormatsAuditValues $formatter): void
    {
        if (! $formatter instanceof FormatsScopeValues) {
            return;
        }

        foreach (array_keys($this->scopeColumns) as $column) {
            $values = [];

            foreach ($rows as $row) {
                $value = $row[$column] ?? null;

                if ($value !== null) {
                    $values[get_debug_type($value).':'.$value] = $value;
                }
            }

            $formatter->prepareScopeValues($column, array_values($values));
        }
    }

    /**
     * Paging by id cannot honour a limit or an offset: `latest()->limit(100)` would export the hundred lowest
     * ids, not the hundred newest. Refuse, instead of writing the wrong rows.
     */
    private function assertNotLimited(): void
    {
        $query = $this->query instanceof Relation ? $this->query->getQuery()->getQuery() : $this->query->getQuery();

        if ($query->limit !== null || $query->offset !== null) {
            throw new InvalidArgumentException('CsvExport reads every row of the query in id order, so it cannot honour limit() or offset(): it would export the lowest ids, not the ones you meant. Filter by a date or id range instead.');
        }
    }

    /** @return list<string> */
    private function headingRow(): array
    {
        $columns = self::COLUMNS + $this->scopeColumns + self::DETAILS;
        $unknown = array_diff(array_keys($this->headings), array_keys($columns));

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown export column ['.implode(', ', $unknown).']. The columns are: '.implode(', ', array_keys($columns)).'.');
        }

        return array_values(array_map(fn (string $key) => (string) ($this->headings[$key] ?? $columns[$key]), array_keys($columns)));
    }

    /**
     * One CSV row per field of the audit row, or a single row with blank field columns when it has none.
     *
     * @param  array<string, mixed>  $audit  the row's raw attributes
     * @return iterable<list<string|int|float>>
     */
    private function rowsOf(array $audit, FormatsAuditValues $formatter, ?DateTimeZone $timezone): iterable
    {
        $type = (string) ($audit['subject_type'] ?? '');
        $parentType = (string) ($audit['parent_type'] ?? '');
        $old = $this->decode($audit['old_values'] ?? null);
        $new = $this->decode($audit['new_values'] ?? null);

        $time = isset($audit['created_at']) ? Carbon::parse($audit['created_at']) : null;
        $time = $time !== null && $timezone !== null ? $time->setTimezone($timezone) : $time;

        $before = [
            $audit['id'] ?? null,
            $time?->format('Y-m-d H:i:s'),
            $audit['category'] ?? null,
            $audit['event'] ?? null,
            $type === '' ? null : $formatter->recordType($type),
            $audit['subject_id'] ?? null,
            $audit['subject_label'] ?? null,
            $parentType === '' ? null : $formatter->recordType($parentType),
            $audit['parent_id'] ?? null,
        ];

        $after = [
            $audit['actor_id'] ?? null,
            $audit['actor_name'] ?? null,
            $audit['actor_identifier'] ?? null,
            $audit['source'] ?? null,
            $audit['ip_address'] ?? null,
            $audit['user_agent'] ?? null,
            $audit['request_id'] ?? null,
            ...array_map(fn (string $column) => $formatter instanceof FormatsScopeValues
                ? $formatter->scopeValue($column, $audit[$column] ?? null)
                : $audit[$column] ?? null, array_keys($this->scopeColumns)),
            $this->details($audit['meta'] ?? null),
        ];

        $fields = array_values(array_unique([...array_map('strval', array_keys($old)), ...array_map('strval', array_keys($new))]));

        if ($fields === []) {
            yield array_map($this->cell(...), [...$before, null, null, null, ...$after]);

            return;
        }

        foreach ($fields as $field) {
            yield array_map($this->cell(...), [
                ...$before,
                $formatter->field($type, $field),
                $formatter->value($type, $field, $old[$field] ?? null),
                $formatter->value($type, $field, $new[$field] ?? null),
                ...$after,
            ]);
        }
    }

    /** @return array<int|string, mixed> */
    private function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }

        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function details(mixed $meta): ?string
    {
        $meta = $this->decode($meta);

        return $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Numbers stay numbers. A string that a spreadsheet would read as a formula gets a leading quote.
     */
    private function cell(mixed $value): string|int|float
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => $value,
            default => $this->safe((string) $value),
        };
    }

    private function safe(string $text): string
    {
        // A plain number, such as a decimal column's '-150.00', is not a formula. Anchored, so '-2+3' and '-1e5' are.
        if ($text === '' || ! str_contains(self::INJECTION_CHARACTERS, $text[0]) || preg_match('/\A-?\d+(\.\d+)?\z/D', $text) === 1) {
            return $text;
        }

        return "'".$text;
    }

    /**
     * @param  resource  $handle
     * @param  list<string|int|float>  $row
     */
    private function put($handle, array $row): void
    {
        // The explicit empty escape: PHP 8.4 deprecates relying on the default.
        if (fputcsv($handle, $row, ',', '"', '') === false) {
            throw new RuntimeException('Could not write the CSV export.');
        }
    }
}
