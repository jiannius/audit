<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\Auditable;

class Document extends BaseDocument
{
    use Auditable;

    public function auditExclude(): array
    {
        return ['internal_*'];
    }

    public function auditRedact(): array
    {
        return ['pin'];
    }
}
