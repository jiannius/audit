<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\Auditable;

class AuditedUser extends User
{
    use Auditable;

    protected $table = 'users';
}
