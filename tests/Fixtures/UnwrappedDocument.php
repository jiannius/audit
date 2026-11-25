<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\Auditable;

/** Overrides save() without the alias, so the transactional wrapper is skipped. */
class UnwrappedDocument extends BaseDocument
{
    use Auditable;

    public function save(array $options = [])
    {
        return parent::save($options);
    }
}
