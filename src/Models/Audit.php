<?php

namespace Jiannius\Audit\Models;

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

    public function user() : BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable() : MorphTo
    {
        return $this->morphTo();
    }

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
