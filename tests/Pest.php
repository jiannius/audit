<?php

use Jiannius\Audit\Tests\Fixtures\User;
use Jiannius\Audit\Tests\TestCase;

uses(TestCase::class)->in('Feature');

function makeUser(array $attributes = []): User
{
    return User::create($attributes + [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'username' => 'ada',
        'password' => 'secret-password',
    ]);
}
