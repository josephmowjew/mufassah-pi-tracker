<?php

namespace App\Models;

use App\Enums\GeofenceEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeofenceEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'geofence_id',
        'device_id',
        'location_id',
        'event_type',
        'distance_from_center',
        'alert_sent',
        'event_time',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => GeofenceEventType::class,
            'distance_from_center' => 'decimal:2',
            'alert_sent' => 'boolean',
            'event_time' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(DeviceLocation::class, 'location_id');
    }
}
