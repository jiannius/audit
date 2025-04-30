<?php

namespace Jiannius\Logs\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Log extends Model
{
    use HasFactory;
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
    ];

    public function user() : BelongsTo
    {
        return $this->belongsTo(App\Models\User::class);
    }

    public function loggable() : MorphTo
    {
        return $this->morphTo();
    }

    public function toHtml() : string
    {
        $action = (string) str($this->action)->title();
        $subject = class_basename(get_class($this->loggable()->getRelated()));
        $ref = $this->reference;

        return <<<EOL
        <span class="font-semibold">{$action}</span> {$subject} {$ref}
        EOL;
    }
}
