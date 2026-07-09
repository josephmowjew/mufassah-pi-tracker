<?php

namespace App\Models;

use App\Enums\GeofenceShapeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Geofence extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'device_id',
        'name',
        'description',
        'shape_type',
        'center_latitude',
        'center_longitude',
        'radius_meters',
        'polygon_coordinates',
        'is_active',
        'notify_on_entry',
        'notify_on_exit',
        'active_days',
        'active_time_start',
        'active_time_end',
    ];

    protected function casts(): array
    {
        return [
            'shape_type' => GeofenceShapeType::class,
            'center_latitude' => 'decimal:8',
            'center_longitude' => 'decimal:8',
            'polygon_coordinates' => 'array',
            'is_active' => 'boolean',
            'notify_on_entry' => 'boolean',
            'notify_on_exit' => 'boolean',
            'active_days' => 'array',
            'active_time_start' => 'datetime:H:i',
            'active_time_end' => 'datetime:H:i',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GeofenceEvent::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
