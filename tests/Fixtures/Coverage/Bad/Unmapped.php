<?php

namespace Jiannius\Audit\Tests\Fixtures\Coverage\Bad;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

class Unmapped extends Model
{
    use Auditable;
}
