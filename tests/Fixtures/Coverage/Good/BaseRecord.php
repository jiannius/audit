<?php

namespace Jiannius\Audit\Tests\Fixtures\Coverage\Good;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

abstract class BaseRecord extends Model
{
    use Auditable;
}
