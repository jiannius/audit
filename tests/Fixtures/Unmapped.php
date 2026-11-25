<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

/** Auditable, but deliberately missing from the morph map. */
class Unmapped extends Model
{
    use Auditable;

    protected $table = 'teams';

    protected $guarded = [];
}
