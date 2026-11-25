<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

class AuditedLine extends Model
{
    use Auditable;

    protected $table = 'invoice_lines';

    protected $guarded = [];

    public function auditParent(): array
    {
        return [AuditedInvoice::class, $this->getAttributes()['invoice_id'] ?? null];
    }

    public function auditLabel(): ?string
    {
        return $this->getAttributes()['description'] ?? null;
    }
}
