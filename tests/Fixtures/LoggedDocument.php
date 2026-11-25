<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\Auditable;

/** Overrides save() the sanctioned way: through the alias. */
class LoggedDocument extends BaseDocument
{
    use Auditable { save as auditableSave; }

    public static int $saves = 0;

    public function save(array $options = [])
    {
        static::$saves++;

        return $this->auditableSave($options);
    }
}
