<?php

namespace Jiannius\Audit\Tests\Fixtures\Coverage\Good;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

class Invoice extends Model
{
    use Auditable;
}
