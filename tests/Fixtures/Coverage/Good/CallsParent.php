<?php

namespace Jiannius\Audit\Tests\Fixtures\Coverage\Good;

/** Overrides save() on top of a parent that uses the trait plainly: parent::save() is the trait's, so it is transactional. */
class CallsParent extends BaseRecord
{
    public function save(array $options = [])
    {
        return parent::save($options);
    }
}
