<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

/** Custom timestamp columns and a boolean cast. */
class StampedRecord extends Model
{
    use Auditable;

    public const CREATED_AT = 'added_at';

    public const UPDATED_AT = 'modified_at';

    protected $table = 'stamped_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
