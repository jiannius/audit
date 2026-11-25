<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Jiannius\Audit\Audit;
use Jiannius\Audit\Contracts\FormatsAuditValues;
use Jiannius\Audit\Export\CsvExport;
use Jiannius\Audit\Export\RawFormatter;
use Jiannius\Audit\Facades\Auditor;
use Jiannius\Audit\Tests\Fixtures\AuditedUser;
use Jiannius\Audit\Tests\Fixtures\CentsFormatter;
use Jiannius\Audit\Tests\Fixtures\Document;
use Jiannius\Audit\Tests\Fixtures\Team;
use Symfony\Component\HttpFoundation\StreamedResponse;

const CSV_HEADINGS = ['Audit ID', 'Time', 'Category', 'Event', 'Record type', 'Record ID', 'Record', 'Parent type', 'Parent ID', 'Field', 'Old value', 'New value', 'User ID', 'User name', 'User identifier', 'Source', 'IP address', 'User agent', 'Request ID', 'Details'];

/** What the export writes, as one string. */
function csvText(CsvExport $export, ?int &$count = null): string
{
    $handle = fopen('php://temp', 'w+');
    $count = $export->write($handle);
    rewind($handle);

    return stream_get_contents($handle);
}

/** The export as rows of cells, heading row first, with the BOM stripped. */
function csvRows(CsvExport $export): array
{
    $handle = fopen('php://temp', 'w+');
    fwrite($handle, substr(csvText($export), 3));
    rewind($handle);

    $rows = [];

    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    return $rows;
}

/** The data rows, each keyed by the heading, so a test reads the cell it means. */
function csvRecords(CsvExport $export): array
{
    $rows = csvRows($export);
    $headings = array_shift($rows);

    return array_map(fn (array $row) => array_combine($headings, $row), $rows);
}

function insertedAudit(array $attributes = []): int
{
    foreach (['old_values', 'new_values', 'meta'] as $column) {
        if (array_key_exists($column, $attributes) && is_array($attributes[$column])) {
            $attributes[$column] = json_encode($attributes[$column]);
        }
    }

    return DB::table('audits')->insertGetId($attributes + [
        'category' => 'record',
        'event' => 'updated',
        'created_at' => '2026-11-25 12:00:00',
    ]);
}

describe('rows', function () {
    it('starts with the BOM and the headings, then one row per changed field', function () {
        $id = insertedAudit([
            'subject_type' => 'invoice-line', 'subject_id' => '7', 'subject_label' => 'Widget',
            'parent_type' => 'invoice', 'parent_id' => 'INV-1',
            'actor_type' => 'user', 'actor_id' => '3', 'actor_name' => 'Ada', 'actor_identifier' => 'ada@example.test',
            'source' => 'web', 'request_id' => 'req-1', 'ip_address' => '203.0.113.9', 'user_agent' => 'Browser/1.0',
            'old_values' => ['status' => 'draft', 'quantity' => 1],
            'new_values' => ['status' => 'sent', 'quantity' => 2],
            'meta' => ['reason' => 'season'],
        ]);

        $text = csvText(CsvExport::make(Audit::query()), $count);
        $rows = csvRows(CsvExport::make(Audit::query()));

        expect(substr($text, 0, 3))->toBe("\xEF\xBB\xBF")
            ->and($count)->toBe(2)
            ->and($rows[0])->toBe(CSV_HEADINGS)
            ->and($rows)->toHaveCount(3)
            ->and($rows[1])->toBe([(string) $id, '2026-11-25 12:00:00', 'record', 'updated', 'Invoice Line', '7', 'Widget', 'Invoice', 'INV-1', 'Status', 'draft', 'sent', '3', 'Ada', 'ada@example.test', 'web', '203.0.113.9', 'Browser/1.0', 'req-1', '{"reason":"season"}'])
            ->and($rows[2][0])->toBe((string) $id)
            ->and(array_slice($rows[2], 9, 3))->toBe(['Quantity', '1', '2']);
    });

    it('gives a create one row per stored field, and a delete one per field of its snapshot', function () {
        insertedAudit(['event' => 'created', 'subject_type' => 'invoice', 'subject_id' => '1', 'new_values' => ['number' => 'INV-1', 'total' => 500]]);
        insertedAudit(['event' => 'deleted', 'subject_type' => 'invoice', 'subject_id' => '1', 'old_values' => ['number' => 'INV-1', 'total' => 500], 'meta' => ['soft' => false]]);

        $rows = csvRecords(CsvExport::make(Audit::query()));

        // MySQL's json type reorders keys, so the fields of a row come back in either order.
        usort($rows, fn ($a, $b) => [$a['Event'], $a['Field']] <=> [$b['Event'], $b['Field']]);

        expect($rows)->toHaveCount(4)
            ->and(array_map(fn ($r) => [$r['Event'], $r['Field'], $r['Old value'], $r['New value']], $rows))->toBe([
                ['created', 'Number', '', 'INV-1'],
                ['created', 'Total', '', '500'],
                ['deleted', 'Number', 'INV-1', ''],
                ['deleted', 'Total', '500', ''],
            ]);
    });

    it('gives an event with no values one row with blank field columns', function () {
        insertedAudit(['category' => 'action', 'event' => 'sent', 'subject_type' => 'invoice', 'subject_id' => '1', 'meta' => ['to' => ['ada@example.test']]]);

        $rows = csvRecords(CsvExport::make(Audit::query()));

        expect($rows)->toHaveCount(1)
            ->and([$rows[0]['Field'], $rows[0]['Old value'], $rows[0]['New value']])->toBe(['', '', ''])
            ->and($rows[0]['Details'])->toBe('{"to":["ada@example.test"]}');
    });

    it('exports the sign-in rows, which have only meta', function () {
        makeUser();
        Auth::attempt(['email' => 'ada@example.test', 'password' => 'wrong']);

        $rows = csvRecords(CsvExport::make(Audit::query()->where('event', 'login_failed')));

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['Category'])->toBe('auth')
            ->and($rows[0]['Event'])->toBe('login_failed')
            ->and($rows[0]['Field'])->toBe('')
            ->and(json_decode($rows[0]['Details'], true))->toEqual(['guard' => 'web', 'identifier_key' => 'email', 'identifier' => 'ada@example.test']);
    });

    it('exports the rows of real model changes', function () {
        $document = makeDocument(['title' => 'Report']);
        $document->update(['title' => 'Report v2', 'total' => 250]);
        $document->delete();

        $rows = csvRecords(CsvExport::make(Audit::query()));
        $created = array_values(array_filter($rows, fn ($r) => $r['Event'] === 'created'));
        $updated = array_values(array_filter($rows, fn ($r) => $r['Event'] === 'updated'));
        $deleted = array_values(array_filter($rows, fn ($r) => $r['Event'] === 'deleted'));

        $stored = Audit::query()->where('event', 'created')->first()->new_values;

        expect($created)->toHaveCount(count($stored))
            ->and(array_column($updated, 'Field'))->toEqualCanonicalizing(['Title', 'Total'])
            ->and(array_column($updated, 'New value'))->toEqualCanonicalizing(['Report v2', '250'])
            ->and($deleted)->not->toBeEmpty()
            ->and(array_unique(array_column($created, 'Audit ID')))->toHaveCount(1);
    });

    it('lists what it wrote, a row per field, and returns that count', function () {
        insertedAudit(['new_values' => ['a' => 1, 'b' => 2, 'c' => 3]]);
        insertedAudit(['event' => 'sent']);

        csvText(CsvExport::make(Audit::query()), $count);

        expect($count)->toBe(4);
    });

    it('writes the headings alone for an empty result', function () {
        $rows = csvRows(CsvExport::make(Audit::query()));

        expect($rows)->toBe([CSV_HEADINGS]);
    });

    it('applies the filters of the query', function () {
        $first = insertedAudit(['event' => 'one']);
        $second = insertedAudit(['event' => 'two']);
        insertedAudit(['event' => 'other', 'category' => 'auth']);

        $rows = csvRecords(CsvExport::make(Audit::query()->where('category', 'record')));

        expect(array_column($rows, 'Audit ID'))->toBe([(string) $first, (string) $second]);
    });

    it('applies the model read scope, since the query is yours', function () {
        $this->useScopes(['team_id' => ['resolver' => fn () => null, 'read' => fn () => 1]]);

        insertedAudit(['event' => 'mine', 'team_id' => 1]);
        insertedAudit(['event' => 'theirs', 'team_id' => 2]);

        $rows = csvRecords(CsvExport::make(Audit::query()));

        expect(array_column($rows, 'Event'))->toBe(['mine']);
    });
});

describe('a query with its own order', function () {
    /** More rows than one chunk, with events that sort differently from their ids. */
    function manyAudits(int $count, ?int $subject = null): void
    {
        $rows = [];

        foreach (range(1, $count) as $i) {
            $rows[] = ['category' => 'record', 'event' => 'e'.str_pad((string) (($i * 7919) % 997), 4, '0', STR_PAD_LEFT), 'subject_type' => 'document', 'subject_id' => (string) ($subject ?? 1), 'created_at' => '2026-11-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT).' 12:00:00'];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('audits')->insert($chunk);
        }
    }

    it('exports every row exactly once, whatever the order', function (Closure $order) {
        manyAudits(2500);

        $ids = array_column(csvRecords(CsvExport::make($order(Audit::query()))), 'Audit ID');

        expect($ids)->toHaveCount(2500)
            ->and(count(array_unique($ids)))->toBe(2500)
            ->and($ids)->toBe(array_map('strval', range(1, 2500)));
    })->with([
        'orderBy event' => [fn ($query) => $query->orderBy('event')],
        'latest' => [fn ($query) => $query->latest()],
        'orderByDesc id' => [fn ($query) => $query->orderByDesc('id')],
        'several orders' => [fn ($query) => $query->orderBy('event')->orderByDesc('created_at')],
        'an order by a raw expression' => [fn ($query) => $query->orderByRaw('event desc')],
    ]);

    it('does so through a relation too', function () {
        manyAudits(2200, subject: 7);
        manyAudits(300, subject: 8);

        $document = new Document;
        $document->setRawAttributes(['id' => 7]);
        $document->exists = true;

        $ids = array_column(csvRecords(CsvExport::make($document->audits()->orderBy('event'))), 'Audit ID');

        expect($ids)->toHaveCount(2200)
            ->and(count(array_unique($ids)))->toBe(2200);
    });

    it('refuses a limit or an offset, which paging by id could not honour', function (Closure $query) {
        insertedAudit();

        expect(fn () => csvText(CsvExport::make($query(Audit::query()))))->toThrow(InvalidArgumentException::class, 'Filter by a date or id range')
            ->and(fn () => CsvExport::make($query(Audit::query()))->download('x.csv'))->toThrow(InvalidArgumentException::class);
    })->with([
        'latest and limit' => [fn ($query) => $query->latest()->limit(100)],
        'take' => [fn ($query) => $query->take(5)],
        'an offset' => [fn ($query) => $query->offset(3)],
        'a skip' => [fn ($query) => $query->skip(1)->take(2)],
    ]);

    it('refuses a limit on a relation too', function () {
        $document = new Document;
        $document->setRawAttributes(['id' => 7]);
        $document->exists = true;

        expect(fn () => csvText(CsvExport::make($document->audits()->limit(10))))->toThrow(InvalidArgumentException::class);
    });

    it('leaves the query it was given alone', function () {
        manyAudits(5);

        $query = Audit::query()->orderBy('event');
        csvText(CsvExport::make($query));

        expect($query->toBase()->orders)->not->toBeEmpty();
    });
});

describe('the Time column', function () {
    it('is in the application timezone by default', function () {
        insertedAudit(['created_at' => '2026-11-25 12:00:00']);

        expect(csvRecords(CsvExport::make(Audit::query()))[0]['Time'])->toBe('2026-11-25 12:00:00');
    });

    it('is in the given timezone', function () {
        insertedAudit(['created_at' => '2026-11-25 12:00:00']);

        expect(csvRecords(CsvExport::make(Audit::query())->timezone('Asia/Kuala_Lumpur'))[0]['Time'])->toBe('2026-11-25 20:00:00')
            ->and(csvRecords(CsvExport::make(Audit::query())->timezone(new DateTimeZone('America/New_York')))[0]['Time'])->toBe('2026-11-25 07:00:00')
            ->and(csvRecords(CsvExport::make(Audit::query())->timezone(null))[0]['Time'])->toBe('2026-11-25 12:00:00');
    });
});

describe('headings and scope columns', function () {
    it('replaces a heading by its column key', function () {
        insertedAudit();

        $rows = csvRows(CsvExport::make(Audit::query())->headings(['actor_identifier' => 'User email', 'time' => 'When']));

        expect($rows[0][1])->toBe('When')
            ->and($rows[0][14])->toBe('User email')
            ->and($rows[0][13])->toBe('User name');
    });

    it('refuses a heading for a column it does not have, and lists the ones it has', function () {
        expect(fn () => csvText(CsvExport::make(Audit::query())->headings(['actor_email' => 'Email'])))
            ->toThrow(InvalidArgumentException::class, 'Unknown export column [actor_email]');
    });

    it('writes the scope columns after the request id, before the details', function () {
        insertedAudit(['team_id' => 5, 'region_id' => 9, 'meta' => ['a' => 1]]);

        $rows = csvRows(CsvExport::make(Audit::query())->scopeColumns(['team_id' => 'Team', 'region_id']));

        expect(array_slice($rows[0], 18))->toBe(['Request ID', 'Team', 'Region Id', 'Details'])
            ->and(array_slice($rows[1], 18))->toBe(['', '5', '9', '{"a":1}']);
    });

    it('refuses a scope column named like an export column, which would be dropped', function (string $column) {
        expect(fn () => CsvExport::make(Audit::query())->scopeColumns([$column => 'Heading']))->toThrow(InvalidArgumentException::class, $column)
            ->and(fn () => CsvExport::make(Audit::query())->scopeColumns([$column]))->toThrow(InvalidArgumentException::class);
    })->with(['id', 'source', 'details', 'category', 'event', 'time']);

    it('lets a heading override a scope column heading', function () {
        $rows = csvRows(CsvExport::make(Audit::query())->scopeColumns(['team_id' => 'Team'])->headings(['team_id' => 'Workspace']));

        expect($rows[0])->toContain('Workspace')->not->toContain('Team');
    });
});

describe('formatting', function () {
    it('uses the audit.formatter binding by default', function () {
        expect(app(FormatsAuditValues::class))->toBeInstanceOf(RawFormatter::class);

        config(['audit.formatter' => CentsFormatter::class]);

        insertedAudit(['subject_type' => 'invoice-line', 'old_values' => ['total' => 15050], 'new_values' => ['total' => 20000]]);

        $row = csvRecords(CsvExport::make(Audit::query()))[0];

        expect($row['Record type'])->toBe('Invoice line')
            ->and([$row['Field'], $row['Old value'], $row['New value']])->toBe(['Total', '150.5', '200']);
    });

    it('uses the formatter it is given', function () {
        insertedAudit(['subject_type' => 'invoice', 'old_values' => ['total' => 15050], 'new_values' => ['total' => 20000]]);

        $row = csvRecords(CsvExport::make(Audit::query())->formatter(new CentsFormatter))[0];

        expect([$row['Field'], $row['Old value'], $row['New value']])->toBe(['Total', '150.5', '200']);
    });

    it('passes the raw value, the record type and the field to the formatter', function () {
        insertedAudit(['subject_type' => 'invoice', 'old_values' => ['options' => ['a' => 1]], 'new_values' => ['total' => null]]);

        $seen = [];
        $formatter = new class($seen) extends RawFormatter
        {
            public function __construct(public array &$seen) {}

            public function value(string $subjectType, string $field, mixed $raw): string|int|float|bool|null
            {
                $this->seen[] = [$subjectType, $field, $raw];

                return parent::value($subjectType, $field, $raw);
            }
        };

        csvText(CsvExport::make(Audit::query())->formatter($formatter));

        expect($seen)->toBe([
            ['invoice', 'options', ['a' => 1]], ['invoice', 'options', null],
            ['invoice', 'total', null], ['invoice', 'total', null],
        ]);
    });

    it('renders scalars as they are, arrays as JSON, null as blank and booleans as words', function () {
        insertedAudit(['new_values' => ['n' => 5, 'f' => 1.5, 's' => 'text', 'a' => ['x' => ['y' => 1]], 'z' => null, 'u' => 'ünï/çode']]);

        $rows = csvRecords(CsvExport::make(Audit::query()));

        expect(array_column($rows, 'New value', 'Field'))->toEqual(['N' => '5', 'F' => '1.5', 'S' => 'text', 'A' => '{"x":{"y":1}}', 'Z' => '', 'U' => 'ünï/çode']);

        $formatter = new class extends RawFormatter
        {
            public function value(string $subjectType, string $field, mixed $raw): string|int|float|bool|null
            {
                return $raw === 5;
            }
        };

        expect(array_column(csvRecords(CsvExport::make(Audit::query())->formatter($formatter)), 'New value', 'Field')['N'])->toBe('true')
            ->and(array_column(csvRecords(CsvExport::make(Audit::query())->formatter($formatter)), 'New value', 'Field')['S'])->toBe('false');
    });

    it('renders both shapes of a pivot field', function () {
        insertedAudit([
            'subject_type' => 'user',
            'old_values' => ['teams' => [1, 1, 2]],
            'new_values' => ['teams' => [1, 2, 3]],
        ]);
        insertedAudit([
            'subject_type' => 'user',
            'old_values' => ['members' => ['1' => ['role' => 'member'], '2' => [['role' => 'a'], ['role' => 'b']]]],
            'new_values' => ['members' => ['1' => ['role' => 'admin'], '2' => [['role' => 'a'], ['role' => 'b']]]],
        ]);

        $rows = csvRecords(CsvExport::make(Audit::query()));

        expect($rows)->toHaveCount(2)
            ->and([$rows[0]['Field'], $rows[0]['Old value'], $rows[0]['New value']])->toBe(['Teams', '[1,1,2]', '[1,2,3]'])
            ->and([$rows[1]['Field'], $rows[1]['Old value'], $rows[1]['New value']])->toBe([
                'Members',
                '{"1":{"role":"member"},"2":[{"role":"a"},{"role":"b"}]}',
                '{"1":{"role":"admin"},"2":[{"role":"a"},{"role":"b"}]}',
            ]);
    });

    it('exports a pivot change the package wrote', function () {
        $user = AuditedUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
        $team = Team::create(['name' => 'Alpha']);

        Auditor::pivot($user->teams(), fn ($teams) => $teams->attach([$team->getKey()]));

        $row = csvRecords(CsvExport::make(Audit::query()->where('subject_type', 'audited-user')->where('event', 'updated')))[0];

        expect([$row['Field'], $row['Old value'], $row['New value']])->toBe(['Teams', '[]', '['.$team->getKey().']']);
    });

    it('shows a truncated value as its marker, and lists the field in the details', function () {
        config(['audit.max_value_bytes' => 20]);

        makeDocument(['title' => str_repeat('x', 50)]);

        $row = array_values(array_filter(csvRecords(CsvExport::make(Audit::query())), fn ($r) => $r['Field'] === 'Title'))[0];

        expect($row['New value'])->toBe('[truncated 50 bytes]')
            ->and(json_decode($row['Details'], true))->toEqual(['_truncated' => ['title']]);
    });
});

describe('CSV injection', function () {
    it('prefixes a string cell that starts with a formula character', function (string $value) {
        insertedAudit(['subject_label' => $value, 'actor_name' => $value, 'new_values' => ['note' => $value]]);

        $row = csvRecords(CsvExport::make(Audit::query()))[0];

        expect($row['Record'])->toBe("'".$value)
            ->and($row['User name'])->toBe("'".$value)
            ->and($row['New value'])->toBe("'".$value);
    })->with([
        'equals' => '=SUM(A1:A9)',
        'plus' => '+60123456789',
        'minus' => '-2+3',
        'at' => '@cmd',
        'tab' => "\tcmd",
        'carriage return' => "\rcmd",
    ]);

    it('leaves other strings alone, however they look', function (string $value) {
        insertedAudit(['subject_label' => $value]);

        expect(csvRecords(CsvExport::make(Audit::query()))[0]['Record'])->toBe($value);
    })->with(['a hyphen inside' => 'well-known', 'a number' => '12', 'a quote' => "'=safe", 'empty-ish' => ' =space first']);

    it('does not prefix a negative number', function () {
        insertedAudit(['new_values' => ['balance' => -500, 'ratio' => -0.5]]);

        $new = array_column(csvRecords(CsvExport::make(Audit::query())), 'New value', 'Field');

        expect($new)->toEqual(['Balance' => '-500', 'Ratio' => '-0.5']);
    });

    it('does not prefix a numeric string either, such as a decimal column', function (string $value) {
        insertedAudit(['new_values' => ['total' => $value]]);

        expect(csvRecords(CsvExport::make(Audit::query()))[0]['New value'])->toBe($value);
    })->with(['a negative decimal' => '-150.00', 'a negative integer' => '-500', 'a decimal' => '150.5', 'zero' => '-0']);

    it('still prefixes a string that only starts like a number', function (string $value) {
        insertedAudit(['new_values' => ['total' => $value]]);

        expect(csvRecords(CsvExport::make(Audit::query()))[0]['New value'])->toBe("'".$value);
    })->with([
        'arithmetic' => '-2+3',
        'an exponent' => '-1e5',
        'a formula' => '=1',
        'a plus sign' => '+60123456789',
        'a trailing newline' => "-150\n",
        'a trailing dot' => '-150.',
        'a leading dot' => '-.5',
        'two signs' => '--5',
    ]);

    it('lets a formatter keep a negative amount a number', function () {
        insertedAudit(['subject_type' => 'invoice', 'new_values' => ['total' => -15050]]);

        expect(csvRecords(CsvExport::make(Audit::query())->formatter(new CentsFormatter))[0]['New value'])->toBe('-150.5');
    });

    it('is not fooled by a value that CSV quoting would hide', function () {
        insertedAudit(['new_values' => ['note' => "=1+1\", 2\nline"]]);

        $row = csvRecords(CsvExport::make(Audit::query()))[0];

        expect($row['New value'])->toBe("'=1+1\", 2\nline");
    });
});

describe('writing', function () {
    it('streams: the memory it uses does not grow with the number of rows', function () {
        $payload = str_repeat('a', 3000);
        $rows = [];

        foreach (range(1, 8000) as $i) {
            $rows[] = ['category' => 'record', 'event' => 'updated', 'subject_type' => 'invoice', 'subject_id' => (string) $i, 'new_values' => json_encode(['body' => $payload]), 'created_at' => '2026-11-25 12:00:00'];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('audits')->insert($chunk);
        }

        unset($rows, $chunk);
        gc_collect_cycles();

        $handle = tmpfile();
        $export = CsvExport::make(Audit::query());

        memory_reset_peak_usage();
        $before = memory_get_usage();

        $written = $export->write($handle);

        $grown = memory_get_peak_usage() - $before;

        // 8,000 rows of 3 KB are 24 MB of values before PHP's own overhead. A thousand-row chunk is a few MB.
        expect($written)->toBe(8000)
            ->and(filesize(stream_get_meta_data($handle)['uri']))->toBeGreaterThan(24_000_000)
            ->and($grown)->toBeLessThan(20 * 1024 * 1024);
    });

    it('is a download: a streamed CSV response with the file name', function () {
        insertedAudit(['new_values' => ['a' => 1]]);

        $response = CsvExport::make(Audit::query())->download('audit-trail-2026-11.csv');

        ob_start();
        $response->sendContent();
        $body = ob_get_clean();

        expect($response)->toBeInstanceOf(StreamedResponse::class)
            ->and($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename=audit-trail-2026-11.csv')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(substr($body, 0, 3))->toBe("\xEF\xBB\xBF")
            ->and($body)->toContain('"Audit ID",Time')
            ->and($body)->toContain(',A,,1,');
    });

    it('survives a file name with characters a header cannot hold', function () {
        $response = CsvExport::make(Audit::query())->download("rapport \"é\"/50%\r\n.csv");

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename=')
            ->and($response->headers->get('Content-Disposition'))->not->toContain("\n");
    });

    it('rejects a heading it cannot place before it sends anything', function () {
        $export = CsvExport::make(Audit::query())->headings(['nope' => 'x']);

        expect(fn () => $export->download('x.csv'))->toThrow(InvalidArgumentException::class);
    });
});
