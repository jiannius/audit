<?php

namespace Jiannius\Audit\Tests\Fixtures\Coverage\Bad;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

class OverridesSave extends Model
{
    use Auditable;

    public function save(array $options = [])
    {
        return parent::save($options);
    }
}
