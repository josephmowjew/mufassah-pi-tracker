<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceLocation extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceLocationFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     * Only created_at is used for this high-volume table.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'device_id',
        'iot_module_id',
        'latitude',
        'longitude',
        'altitude',
        'accuracy',
        'speed',
        'heading',
        'address',
        'is_manual',
        'recorded_at',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'altitude' => 'decimal:2',
            'accuracy' => 'decimal:2',
            'speed' => 'decimal:2',
            'heading' => 'decimal:2',
            'is_manual' => 'boolean',
            'recorded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (DeviceLocation $location) {
            $location->created_at = $location->created_at ?? now();
        });
    }

    /**
     * Get the device that owns the location.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Get the IoT module that recorded the location.
     */
    public function iotModule(): BelongsTo
    {
        return $this->belongsTo(IotModule::class);
    }

    /**
     * Scope a query to filter by device.
     */
    public function scopeForDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope a query to get locations within a time range.
     */
    public function scopeRecordedBetween($query, $start, $end)
    {
        return $query->whereBetween('recorded_at', [$start, $end]);
    }

    /**
     * Scope a query to get only manual locations.
     */
    public function scopeManual($query)
    {
        return $query->where('is_manual', true);
    }

    /**
     * Scope a query to get only automatic (IoT) locations.
     */
    public function scopeAutomatic($query)
    {
        return $query->where('is_manual', false);
    }

    /**
     * Get the distance to another location in meters.
     */
    public function distanceTo(float $latitude, float $longitude): float
    {
        $earthRadius = 6371000; // meters

        $latFrom = deg2rad($this->latitude);
        $lonFrom = deg2rad($this->longitude);
        $latTo = deg2rad($latitude);
        $lonTo = deg2rad($longitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos($latFrom) * cos($latTo) *
            sin($lonDelta / 2) * sin($lonDelta / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
