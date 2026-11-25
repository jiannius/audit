<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Jiannius\Audit\Auditable;

/** Hides `pin` through the #[Hidden] attribute, which Laravel merges into the instance in the constructor. */
#[Hidden(['pin'])]
class AttributeHiddenDocument extends BaseDocument
{
    use Auditable;
}
