<?php

namespace App\Models;

use App\Enums\IotModuleStatus;
use App\Enums\ModemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class IotModule extends Model
{
    /** @use HasFactory<\Database\Factories\IotModuleFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'module_id',
        'api_token',
        'sim_number',
        'imei',
        'modem_type',
        'firmware_version',
        'hardware_version',
        'status',
        'battery_level',
        'signal_strength',
        'configuration',
        'last_ping',
        'last_location_update',
        'activated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'api_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modem_type' => ModemType::class,
            'status' => IotModuleStatus::class,
            'battery_level' => 'integer',
            'signal_strength' => 'integer',
            'configuration' => 'array',
            'last_ping' => 'datetime',
            'last_location_update' => 'datetime',
            'activated_at' => 'datetime',
            'api_token' => 'hashed',
        ];
    }

    /**
     * Get the device that owns the IoT module.
     */
    public function device(): HasOne
    {
        return $this->hasOne(Device::class);
    }

    /**
     * Get the locations recorded by this module.
     */
    public function locations(): HasMany
    {
        return $this->hasMany(DeviceLocation::class);
    }

    /**
     * Scope a query to only include active modules.
     */
    public function scopeActive($query)
    {
        return $query->where('status', IotModuleStatus::Active);
    }

    /**
     * Scope a query to only include online modules (pinged recently).
     */
    public function scopeOnline($query, int $minutes = 5)
    {
        return $query->where('last_ping', '>=', now()->subMinutes($minutes));
    }

    /**
     * Check if the module is online.
     */
    public function isOnline(int $minutes = 5): bool
    {
        return $this->last_ping && $this->last_ping->gte(now()->subMinutes($minutes));
    }
}
