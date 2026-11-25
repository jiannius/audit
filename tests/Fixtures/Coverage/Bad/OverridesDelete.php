<?php

namespace Jiannius\Audit\Tests\Fixtures\Coverage\Bad;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

class OverridesDelete extends Model
{
    use Auditable { save as auditableSave; }

    public function delete()
    {
        return parent::delete();
    }
}
