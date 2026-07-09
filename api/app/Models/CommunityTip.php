<?php

namespace App\Models;

use App\Enums\TipStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityTip extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_alert_id',
        'tipster_id',
        'tip_type',
        'description',
        'spotted_latitude',
        'spotted_longitude',
        'spotted_address',
        'spotted_at',
        'is_anonymous',
        'contact_preference',
        'contact_info',
        'photos',
        'status',
        'is_helpful',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TipStatus::class,
            'spotted_latitude' => 'decimal:8',
            'spotted_longitude' => 'decimal:8',
            'spotted_at' => 'datetime',
            'is_anonymous' => 'boolean',
            'is_helpful' => 'boolean',
            'photos' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function alert(): BelongsTo
    {
        return $this->belongsTo(CommunityAlert::class, 'community_alert_id');
    }

    public function tipster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tipster_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePending($query)
    {
        return $query->where('status', TipStatus::Pending);
    }

    public function scopeVerified($query)
    {
        return $query->where('status', TipStatus::Verified);
    }

    public function scopeHelpful($query)
    {
        return $query->where('is_helpful', true);
    }

    public function scopeWithLocation($query)
    {
        return $query->whereNotNull('spotted_latitude')
            ->whereNotNull('spotted_longitude');
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    public function isAnonymous(): bool
    {
        return $this->is_anonymous || $this->tipster_id === null;
    }

    public function isPending(): bool
    {
        return $this->status === TipStatus::Pending;
    }

    public function isVerified(): bool
    {
        return $this->status === TipStatus::Verified;
    }

    public function markAsVerified(?int $reviewerId = null): void
    {
        $this->update([
            'status' => TipStatus::Verified,
            'is_helpful' => true,
            'reviewed_by' => $reviewerId ?? auth()->id(),
            'reviewed_at' => now(),
        ]);
    }

    public function markAsFalseLead(?int $reviewerId = null, ?string $notes = null): void
    {
        $this->update([
            'status' => TipStatus::FalseLead,
            'is_helpful' => false,
            'reviewed_by' => $reviewerId ?? auth()->id(),
            'reviewed_at' => now(),
            'admin_notes' => $notes,
        ]);
    }

    public function hasLocation(): bool
    {
        return $this->spotted_latitude && $this->spotted_longitude;
    }

    /**
     * Get displayable tipster name (anonymized if needed).
     */
    public function getTipsterDisplayNameAttribute(): string
    {
        if ($this->isAnonymous()) {
            return 'Anonymous';
        }

        return $this->tipster?->name ?? 'Unknown';
    }
}
