<?php

namespace App\Models;

use App\Enums\BountyStatus;
use App\Enums\CommunityAlertStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunityAlert extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'theft_report_id',
        'created_by',
        'status',
        'broadcast_latitude',
        'broadcast_longitude',
        'alert_radius_km',
        'opt_in_notifications',
        'bounty_amount',
        'bounty_currency',
        'bounty_status',
        'view_count',
        'share_count',
        'tips_count',
        'expires_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommunityAlertStatus::class,
            'bounty_status' => BountyStatus::class,
            'broadcast_latitude' => 'decimal:8',
            'broadcast_longitude' => 'decimal:8',
            'bounty_amount' => 'decimal:2',
            'opt_in_notifications' => 'boolean',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function theftReport(): BelongsTo
    {
        return $this->belongsTo(TheftReport::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tips(): HasMany
    {
        return $this->hasMany(CommunityTip::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(BountyClaim::class);
    }

    public function successStory(): HasOne
    {
        return $this->hasOne(SuccessStory::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('status', CommunityAlertStatus::Active);
    }

    public function scopeWithBounty($query)
    {
        return $query->where('bounty_status', '!=', BountyStatus::None)
            ->whereNotNull('bounty_amount')
            ->where('bounty_amount', '>', 0);
    }

    public function scopeNearby($query, float $latitude, float $longitude, int $radiusKm = 10)
    {
        // Haversine formula for distance calculation
        $haversine = '(6371 * acos(cos(radians(?)) * cos(radians(broadcast_latitude)) * cos(radians(broadcast_longitude) - radians(?)) + sin(radians(?)) * sin(radians(broadcast_latitude))))';

        return $query->selectRaw("*, {$haversine} AS distance", [$latitude, $longitude, $latitude])
            ->havingRaw('distance <= ?', [$radiusKm])
            ->orderBy('distance');
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === CommunityAlertStatus::Active;
    }

    public function hasBounty(): bool
    {
        return $this->bounty_status !== BountyStatus::None
            && $this->bounty_amount
            && $this->bounty_amount > 0;
    }

    public function canReceiveTips(): bool
    {
        return $this->isActive() && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    public function incrementShareCount(): void
    {
        $this->increment('share_count');
    }

    public function incrementTipsCount(): void
    {
        $this->increment('tips_count');
    }

    public function resolve(): void
    {
        $this->update([
            'status' => CommunityAlertStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function pause(): void
    {
        $this->update(['status' => CommunityAlertStatus::Paused]);
    }

    public function resume(): void
    {
        $this->update(['status' => CommunityAlertStatus::Active]);
    }

    public function setBounty(float $amount, string $currency = 'MWK'): void
    {
        $this->update([
            'bounty_amount' => $amount,
            'bounty_currency' => $currency,
            'bounty_status' => BountyStatus::Offered,
        ]);
    }

    public function removeBounty(): void
    {
        $this->update([
            'bounty_amount' => null,
            'bounty_status' => BountyStatus::None,
        ]);
    }

    /**
     * Get distance from a given location in kilometers.
     */
    public function distanceFrom(float $latitude, float $longitude): float
    {
        $earthRadius = 6371; // km

        $latFrom = deg2rad($this->broadcast_latitude);
        $lonFrom = deg2rad($this->broadcast_longitude);
        $latTo = deg2rad($latitude);
        $lonTo = deg2rad($longitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) ** 2 +
            cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        return $earthRadius * 2 * asin(sqrt($a));
    }
}
