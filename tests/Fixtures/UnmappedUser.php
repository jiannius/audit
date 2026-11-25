<?php

namespace Jiannius\Audit\Tests\Fixtures;

/** A user class that is not in the morph map. */
class UnmappedUser extends User
{
    protected $table = 'users';
}
