<?php

namespace Jiannius\Audit\Tests\Fixtures\Coverage\Mixed;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

class Kept extends Model
{
    use Auditable;
}
