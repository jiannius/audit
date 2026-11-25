<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Jiannius\Audit\Auditable;

class AuditedInvoice extends Invoice
{
    use Auditable;

    protected $table = 'invoices';
}
