<?php

use Illuminate\Support\Facades\DB;
use Jiannius\Audit\Audit;
use Jiannius\Audit\Exceptions\ImmutableAuditException;
use Jiannius\Audit\Facades\Auditor;

beforeEach(function () {
    Auditor::record('sent');
});

it('throws when a row is updated through the model', function () {
    $audit = Audit::query()->firstOrFail();
    $audit->event = 'changed';

    expect(fn () => $audit->save())->toThrow(ImmutableAuditException::class)
        ->and(fn () => $audit->update(['event' => 'other']))->toThrow(ImmutableAuditException::class);

    expect(DB::table('audits')->value('event'))->toBe('sent');
});

it('throws when a row is deleted through the model', function () {
    $audit = Audit::query()->firstOrFail();

    expect(fn () => $audit->delete())->toThrow(ImmutableAuditException::class);

    expect(DB::table('audits')->count())->toBe(1);
});

it('reads the table and connection from config', function () {
    config(['audit.table' => 'custom_audits', 'audit.connection' => 'other']);

    $audit = new Audit;

    expect($audit->getTable())->toBe('custom_audits')
        ->and($audit->getConnectionName())->toBe('other');
});
