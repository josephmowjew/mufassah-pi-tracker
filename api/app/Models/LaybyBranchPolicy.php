<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaybyBranchPolicy extends Model
{
    protected $primaryKey = 'branch_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean', 'merchant_channels' => 'array'];
}
