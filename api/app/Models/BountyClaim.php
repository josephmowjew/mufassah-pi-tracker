<?php

namespace App\Models;

use App\Enums\ClaimStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BountyClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'community_alert_id',
        'claimant_id',
        'claim_type',
        'description',
        'evidence_photos',
        'recovery_latitude',
        'recovery_longitude',
        'recovery_address',
        'recovery_date',
        'status',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'claim_amount',
        'approved_amount',
        'payment_method',
        'payment_account',
        'payment_reference',
        'paid_at',
        'owner_rating',
        'owner_feedback',
    ];

    protected function casts(): array
    {
        return [
            'status' => ClaimStatus::class,
            'evidence_photos' => 'array',
            'recovery_latitude' => 'decimal:8',
            'recovery_longitude' => 'decimal:8',
            'recovery_date' => 'datetime',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'claim_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function alert(): BelongsTo
    {
        return $this->belongsTo(CommunityAlert::class, 'community_alert_id');
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_id');
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
        return $query->where('status', ClaimStatus::Pending);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', ClaimStatus::Approved);
    }

    public function scopePaid($query)
    {
        return $query->where('status', ClaimStatus::Paid);
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->status === ClaimStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === ClaimStatus::Approved;
    }

    public function isPaid(): bool
    {
        return $this->status === ClaimStatus::Paid;
    }

    public function approve(float $amount, ?int $reviewerId = null): void
    {
        $this->update([
            'status' => ClaimStatus::Approved,
            'approved_amount' => $amount,
            'reviewed_by' => $reviewerId ?? auth()->id(),
            'reviewed_at' => now(),
        ]);
    }

    public function reject(string $reason, ?int $reviewerId = null): void
    {
        $this->update([
            'status' => ClaimStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_by' => $reviewerId ?? auth()->id(),
            'reviewed_at' => now(),
        ]);
    }

    public function markAsPaid(string $reference, ?string $method = null): void
    {
        $this->update([
            'status' => ClaimStatus::Paid,
            'payment_reference' => $reference,
            'payment_method' => $method ?? $this->payment_method,
            'paid_at' => now(),
        ]);
    }

    public function addRating(int $rating, ?string $feedback = null): void
    {
        $this->update([
            'owner_rating' => $rating,
            'owner_feedback' => $feedback,
        ]);
    }
}
