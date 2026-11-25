<?php

namespace Jiannius\Audit\Tests\Fixtures\Coverage\Good;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

class Aliased extends Model
{
    use Auditable {
        save as auditableSave;
        delete as auditableDelete;
    }

    public function save(array $options = [])
    {
        return $this->auditableSave($options);
    }

    public function delete()
    {
        return $this->auditableDelete();
    }
}
