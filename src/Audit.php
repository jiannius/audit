<?php

namespace Jiannius\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Audit extends Model
{
    use HasFactory;
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
    ];

    /**
     * Get the user that owns the audit.
     */
    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the auditable model that owns the audit.
     */
    public function auditable() : MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Convert the audit to a HTML string.
     */
    public function toHtml() : string
    {
        $action = (string) str($this->action)->title();
        $subject = class_basename(get_class($this->auditable()->getRelated()));
        $ref = $this->reference;

        return <<<EOL
        <span class="font-semibold">{$action}</span> {$subject} {$ref}
        EOL;
    }
}
