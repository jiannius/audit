<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\Auditable;

/** Hides `pin` at class level. */
class HiddenPinDocument extends BaseDocument
{
    use Auditable;

    protected $hidden = ['pin'];
}
