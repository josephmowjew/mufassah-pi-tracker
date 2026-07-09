<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceOwnershipHistory extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceOwnershipHistoryFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'device_ownership_history';

    /**
     * Indicates if the model should be timestamped.
     * Only created_at is used for this table.
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
        'from_user_id',
        'to_user_id',
        'transfer_reason',
        'notes',
        'transferred_at',
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
            'transferred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::creating(function (DeviceOwnershipHistory $history) {
            $history->created_at = $history->created_at ?? now();
        });
    }

    /**
     * Get the device associated with the history record.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Get the previous owner.
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * Get the new owner.
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * Scope a query to filter by device.
     */
    public function scopeForDevice($query, int $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }
}
