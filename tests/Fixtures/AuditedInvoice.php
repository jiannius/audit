<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Jiannius\Audit\Auditable;

class AuditedInvoice extends Invoice
{
    use Auditable;

    protected $table = 'invoices';

    public function lines(): HasMany
    {
        return $this->hasMany(AuditedLine::class, 'invoice_id');
    }
}
