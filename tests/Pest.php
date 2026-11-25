<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Jiannius\Audit\Tests\Fixtures\Document;
use Jiannius\Audit\Tests\Fixtures\DocumentStatus;
use Jiannius\Audit\Tests\Fixtures\User;
use Jiannius\Audit\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/** Every audit row, as one string, so a secret can be searched for anywhere in any column. */
function everythingAudited(): string
{
    return DB::table('audits')->get()->map(fn ($row) => json_encode($row))->implode("\n");
}

function makeDocument(array $attributes = []): Document
{
    return Document::create($attributes + [
        'title' => 'Quarterly report',
        'status' => DocumentStatus::Draft,
        'placed_at' => Carbon::parse('2026-01-02 03:04:05'),
        'options' => ['colour' => 'red'],
        'total' => 100,
    ]);
}

function makeUser(array $attributes = []): User
{
    return User::create($attributes + [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'username' => 'ada',
        'password' => 'secret-password',
    ]);
}
