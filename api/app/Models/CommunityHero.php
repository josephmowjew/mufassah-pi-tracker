<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityHero extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'successful_recoveries',
        'tips_submitted',
        'tips_verified',
        'claims_approved',
        'total_bounties_earned',
        'reputation_score',
        'badges',
        'rank',
        'is_featured',
        'is_public',
        'display_name',
        'monthly_recoveries',
        'monthly_tips',
        'stats_reset_at',
    ];

    protected function casts(): array
    {
        return [
            'badges' => 'array',
            'total_bounties_earned' => 'decimal:2',
            'is_featured' => 'boolean',
            'is_public' => 'boolean',
            'stats_reset_at' => 'date',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeTopByRecoveries($query, int $limit = 10)
    {
        return $query->public()
            ->orderByDesc('successful_recoveries')
            ->limit($limit);
    }

    public function scopeTopByReputation($query, int $limit = 10)
    {
        return $query->public()
            ->orderByDesc('reputation_score')
            ->limit($limit);
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    public function incrementTipsSubmitted(): void
    {
        $this->increment('tips_submitted');
        $this->increment('monthly_tips');
        $this->recalculateReputation();
    }

    public function incrementTipsVerified(): void
    {
        $this->increment('tips_verified');
        $this->recalculateReputation();
    }

    public function incrementRecoveries(): void
    {
        $this->increment('successful_recoveries');
        $this->increment('monthly_recoveries');
        $this->recalculateReputation();
        $this->checkForBadges();
    }

    public function incrementClaimsApproved(): void
    {
        $this->increment('claims_approved');
        $this->recalculateReputation();
    }

    public function addBountyEarned(float $amount): void
    {
        $this->increment('total_bounties_earned', $amount);
        $this->checkForBadges();
    }

    public function recalculateReputation(): void
    {
        // Reputation formula: recoveries * 100 + verified tips * 20 + claims * 50
        $score = ($this->successful_recoveries * 100)
            + ($this->tips_verified * 20)
            + ($this->claims_approved * 50);

        $this->update(['reputation_score' => $score]);
    }

    public function checkForBadges(): void
    {
        $badges = $this->badges ?? [];

        // First recovery badge
        if ($this->successful_recoveries >= 1 && ! in_array('first_recovery', $badges)) {
            $badges[] = 'first_recovery';
        }

        // 5 recoveries badge
        if ($this->successful_recoveries >= 5 && ! in_array('recovery_hero', $badges)) {
            $badges[] = 'recovery_hero';
        }

        // 10 recoveries badge
        if ($this->successful_recoveries >= 10 && ! in_array('recovery_champion', $badges)) {
            $badges[] = 'recovery_champion';
        }

        // 10 verified tips badge
        if ($this->tips_verified >= 10 && ! in_array('tip_master', $badges)) {
            $badges[] = 'tip_master';
        }

        // High earner badge (earned MWK 50,000+)
        if ($this->total_bounties_earned >= 50000 && ! in_array('high_earner', $badges)) {
            $badges[] = 'high_earner';
        }

        $this->update(['badges' => $badges]);
    }

    public function resetMonthlyStats(): void
    {
        $this->update([
            'monthly_recoveries' => 0,
            'monthly_tips' => 0,
            'stats_reset_at' => now(),
        ]);
    }

    /**
     * Get displayable name (either custom display_name or user's actual name).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->attributes['display_name'] ?? $this->user?->name ?? 'Anonymous Hero';
    }

    /**
     * Get or create a hero record for a user.
     */
    public static function forUser(User $user): self
    {
        return self::firstOrCreate(
            ['user_id' => $user->id],
            [
                'successful_recoveries' => 0,
                'tips_submitted' => 0,
                'tips_verified' => 0,
                'claims_approved' => 0,
                'total_bounties_earned' => 0,
                'reputation_score' => 0,
                'badges' => [],
            ]
        );
    }
}
