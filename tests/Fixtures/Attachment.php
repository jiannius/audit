<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Auditable;

/** A noisy child record: only its deletion is worth a row. */
class Attachment extends Model
{
    use Auditable;

    protected $table = 'invoice_lines';

    protected $guarded = [];

    public function auditEvents(): array
    {
        return ['deleted'];
    }
}
