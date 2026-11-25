<?php

namespace Jiannius\Audit\Tests\Fixtures;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The columns shared by every Auditable document fixture: an enum, a date, JSON and an encrypted cast. */
abstract class BaseDocument extends Model
{
    use SoftDeletes;

    protected $table = 'documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'placed_at' => 'datetime',
            'options' => 'array',
            'total' => 'integer',
            'notes' => 'encrypted',
            'settings' => AsArrayObject::class,
        ];
    }
}
