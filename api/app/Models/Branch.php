<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'address',
        'city',
        'phone',
        'email',
        'manager_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function inventoryStats()
    {
        return $this->devices()
            ->selectRaw('branch_id,
                COUNT(*) as total,
                SUM(CASE WHEN inventory_status = ? THEN 1 ELSE 0 END) as in_stock,
                SUM(CASE WHEN inventory_status = ? THEN 1 ELSE 0 END) as sold,
                SUM(CASE WHEN inventory_status = ? THEN 1 ELSE 0 END) as reserved',
                ['in_stock', 'sold', 'reserved'])
            ->groupBy('branch_id');
    }
}
