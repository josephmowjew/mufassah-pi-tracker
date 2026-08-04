<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryActionChallenge extends Model
{
    use HasFactory;

    public const ACTION_ADMINISTRATIVE_SALE = 'administrative_sale';

    public const ACTION_DEVICE_DELETION = 'device_deletion';

    protected $fillable = [
        'requested_by_id',
        'action',
        'payload',
        'payload_hash',
        'pin_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'last_sent_at',
        'consumed_at',
        'invalidated_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function isPending(): bool
    {
        return $this->consumed_at === null
            && $this->invalidated_at === null
            && $this->attempts < $this->max_attempts
            && $this->expires_at?->isFuture();
    }
}
