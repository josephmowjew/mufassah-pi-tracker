<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SuccessStory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'community_alert_id',
        'author_id',
        'title',
        'story',
        'photos',
        'device_brand',
        'device_model',
        'device_type',
        'days_to_recover',
        'bounty_paid',
        'recovery_city',
        'is_published',
        'is_featured',
        'published_at',
        'view_count',
        'likes_count',
        'shares_count',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'bounty_paid' => 'decimal:2',
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function alert(): BelongsTo
    {
        return $this->belongsTo(CommunityAlert::class, 'community_alert_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function likedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'success_story_likes')
            ->withTimestamps();
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopePending($query)
    {
        return $query->where('is_published', false)
            ->whereNull('rejection_reason');
    }

    public function scopePopular($query)
    {
        return $query->published()
            ->orderByDesc('likes_count')
            ->orderByDesc('view_count');
    }

    public function scopeRecent($query)
    {
        return $query->published()
            ->orderByDesc('published_at');
    }

    public function scopeFromCity($query, string $city)
    {
        return $query->where('recovery_city', $city);
    }

    // -------------------------------------------------------------------------
    // Helper Methods
    // -------------------------------------------------------------------------

    public function isPublished(): bool
    {
        return $this->is_published;
    }

    public function isFeatured(): bool
    {
        return $this->is_featured;
    }

    public function isPending(): bool
    {
        return ! $this->is_published && ! $this->rejection_reason;
    }

    public function isRejected(): bool
    {
        return ! $this->is_published && $this->rejection_reason !== null;
    }

    public function publish(?int $approverId = null): void
    {
        $this->update([
            'is_published' => true,
            'published_at' => now(),
            'approved_by' => $approverId ?? auth()->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    public function unpublish(): void
    {
        $this->update([
            'is_published' => false,
            'is_featured' => false,
        ]);
    }

    public function reject(string $reason, ?int $approverId = null): void
    {
        $this->update([
            'is_published' => false,
            'rejection_reason' => $reason,
            'approved_by' => $approverId ?? auth()->id(),
            'approved_at' => now(),
        ]);
    }

    public function feature(): void
    {
        if (! $this->is_published) {
            $this->publish();
        }

        $this->update(['is_featured' => true]);
    }

    public function unfeature(): void
    {
        $this->update(['is_featured' => false]);
    }

    public function incrementViewCount(): void
    {
        $this->increment('view_count');
    }

    public function incrementShareCount(): void
    {
        $this->increment('shares_count');
    }

    /**
     * Toggle like for a user.
     */
    public function toggleLike(User $user): bool
    {
        if ($this->isLikedBy($user)) {
            $this->likedByUsers()->detach($user->id);
            $this->decrement('likes_count');

            return false;
        }

        $this->likedByUsers()->attach($user->id);
        $this->increment('likes_count');

        return true;
    }

    /**
     * Check if a user has liked this story.
     */
    public function isLikedBy(User $user): bool
    {
        return $this->likedByUsers()->where('user_id', $user->id)->exists();
    }

    /**
     * Get the device description for display.
     */
    public function getDeviceDescriptionAttribute(): string
    {
        $parts = array_filter([
            $this->device_brand,
            $this->device_model,
        ]);

        if (empty($parts)) {
            return $this->device_type ?? 'Device';
        }

        return implode(' ', $parts);
    }

    /**
     * Create a success story from a resolved alert.
     */
    public static function createFromAlert(
        CommunityAlert $alert,
        string $title,
        string $story,
        ?array $photos = null
    ): self {
        $device = $alert->theftReport?->device;

        return self::create([
            'community_alert_id' => $alert->id,
            'author_id' => $alert->created_by,
            'title' => $title,
            'story' => $story,
            'photos' => $photos,
            'device_brand' => $device?->brand,
            'device_model' => $device?->model,
            'device_type' => $device?->device_type?->value ?? $device?->device_type,
            'days_to_recover' => $alert->resolved_at
                ? $alert->created_at->diffInDays($alert->resolved_at)
                : null,
            'bounty_paid' => $alert->bounty_amount,
            'recovery_city' => null, // Can be filled in later
            'is_published' => false,
            'is_featured' => false,
        ]);
    }
}
