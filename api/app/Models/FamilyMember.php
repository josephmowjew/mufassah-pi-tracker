<?php

namespace App\Models;

use App\Enums\FamilyMemberStatus;
use App\Enums\FamilyRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'family_id',
        'user_id',
        'role',
        'nickname',
        'joined_at',
        'invited_by',
        'status',
        'permissions',
        'location_sharing_enabled',
        'receive_family_alerts',
    ];

    protected function casts(): array
    {
        return [
            'role' => FamilyRole::class,
            'status' => FamilyMemberStatus::class,
            'joined_at' => 'datetime',
            'permissions' => 'array',
            'location_sharing_enabled' => 'boolean',
            'receive_family_alerts' => 'boolean',
        ];
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the family.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who invited this member.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Scope to active members.
     */
    public function scopeActive($query)
    {
        return $query->where('status', FamilyMemberStatus::Active);
    }

    /**
     * Scope to pending members.
     */
    public function scopePending($query)
    {
        return $query->where('status', FamilyMemberStatus::Pending);
    }

    /**
     * Scope to members with guardian role or above.
     */
    public function scopeGuardians($query)
    {
        return $query->whereIn('role', [FamilyRole::Admin, FamilyRole::Guardian]);
    }

    /**
     * Scope to members of a specific family.
     */
    public function scopeInFamily($query, Family $family)
    {
        return $query->where('family_id', $family->id);
    }

    /**
     * Scope to members for a specific user.
     */
    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    // =========================================================================
    // STATUS MANAGEMENT
    // =========================================================================

    /**
     * Accept the invitation.
     */
    public function accept(): void
    {
        $this->update([
            'status' => FamilyMemberStatus::Active,
            'joined_at' => now(),
        ]);
    }

    /**
     * Decline/remove from family.
     */
    public function remove(): void
    {
        $this->update([
            'status' => FamilyMemberStatus::Removed,
        ]);
    }

    /**
     * Check if this membership is active.
     */
    public function isActive(): bool
    {
        return $this->status === FamilyMemberStatus::Active;
    }

    /**
     * Check if this is a pending invite.
     */
    public function isPending(): bool
    {
        return $this->status === FamilyMemberStatus::Pending;
    }

    // =========================================================================
    // ROLE CHECKS
    // =========================================================================

    /**
     * Check if member is admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === FamilyRole::Admin;
    }

    /**
     * Check if member is guardian or admin.
     */
    public function isGuardianOrAbove(): bool
    {
        return in_array($this->role, [FamilyRole::Admin, FamilyRole::Guardian]);
    }

    /**
     * Check if member is a child.
     */
    public function isChild(): bool
    {
        return $this->role === FamilyRole::Child;
    }

    /**
     * Check if this member can manage other members.
     */
    public function canManageMembers(): bool
    {
        return $this->role->canManageMembers();
    }

    /**
     * Check if this member can manage family settings.
     */
    public function canManageSettings(): bool
    {
        return $this->role->canManageSettings();
    }

    /**
     * Check if this member's location must always be shared.
     */
    public function mustShareLocation(): bool
    {
        return $this->role->locationAlwaysShared();
    }

    // =========================================================================
    // PERMISSION HELPERS
    // =========================================================================

    /**
     * Get a specific permission override.
     */
    public function getPermission(string $key, $default = null)
    {
        return data_get($this->permissions, $key, $default);
    }

    /**
     * Set a permission override.
     */
    public function setPermission(string $key, $value): void
    {
        $permissions = $this->permissions ?? [];
        data_set($permissions, $key, $value);
        $this->permissions = $permissions;
        $this->save();
    }

    /**
     * Get the display name (nickname or user's name).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->nickname ?? $this->user?->name ?? 'Unknown';
    }
}
