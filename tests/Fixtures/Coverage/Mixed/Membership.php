<?php

namespace Jiannius\Audit\Tests\Fixtures\Coverage\Mixed;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** A custom pivot model: audited through Auditor::pivot(), so coverage does not ask for the trait. */
class Membership extends Pivot {}
