<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaybyEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['reference_key'];

    protected $casts = ['details' => 'array', 'amount_minor' => 'integer', 'occurred_at' => 'immutable_datetime'];
}
