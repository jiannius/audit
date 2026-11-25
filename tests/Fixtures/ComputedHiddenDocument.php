<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\Auditable;

/** Hides `pin` by overriding getHidden(), so no instance state can show it. */
class ComputedHiddenDocument extends BaseDocument
{
    use Auditable;

    public function getHidden()
    {
        return ['pin'];
    }
}
