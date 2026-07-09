<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Family extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'owner_id',
        'avatar',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Family $family) {
            if (empty($family->code)) {
                $family->code = self::generateUniqueCode();
            }
        });
    }

    /**
     * Generate a unique invite code.
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * Regenerate the invite code.
     */
    public function regenerateCode(): string
    {
        $this->code = self::generateUniqueCode();
        $this->save();

        return $this->code;
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the owner of the family.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Get all family member records.
     */
    public function memberRecords(): HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }

    /**
     * Get active family member records.
     */
    public function activeMemberRecords(): HasMany
    {
        return $this->hasMany(FamilyMember::class)->where('status', 'active');
    }

    /**
     * Get all users who are members of this family.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'family_members')
            ->withPivot(['role', 'nickname', 'status', 'joined_at', 'invited_by', 'permissions', 'location_sharing_enabled', 'receive_family_alerts'])
            ->withTimestamps();
    }

    /**
     * Get active members.
     */
    public function activeMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('status', 'active');
    }

    /**
     * Get guardians (admin + guardian roles).
     */
    public function guardians(): BelongsToMany
    {
        return $this->members()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', ['admin', 'guardian']);
    }

    // =========================================================================
    // MEMBER MANAGEMENT
    // =========================================================================

    /**
     * Add a member to the family.
     */
    public function addMember(User $user, string $role = 'member', ?User $invitedBy = null): FamilyMember
    {
        return FamilyMember::create([
            'family_id' => $this->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
            'invited_by' => $invitedBy?->id,
        ]);
    }

    /**
     * Invite a member (pending status).
     */
    public function inviteMember(User $user, string $role = 'member', ?User $invitedBy = null): FamilyMember
    {
        return FamilyMember::create([
            'family_id' => $this->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'pending',
            'invited_by' => $invitedBy?->id,
        ]);
    }

    /**
     * Check if a user is a member of this family.
     */
    public function hasMember(User $user): bool
    {
        return $this->memberRecords()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Check if a user has a pending invite.
     */
    public function hasPendingInvite(User $user): bool
    {
        return $this->memberRecords()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * Get the member record for a user.
     */
    public function getMemberRecord(User $user): ?FamilyMember
    {
        return $this->memberRecords()
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Get the role of a user in this family.
     */
    public function getMemberRole(User $user): ?string
    {
        $member = $this->getMemberRecord($user);

        return $member?->role->value;
    }

    /**
     * Check if user is admin of this family.
     */
    public function isAdmin(User $user): bool
    {
        return $this->memberRecords()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('role', 'admin')
            ->exists();
    }

    /**
     * Check if user is guardian or admin.
     */
    public function isGuardianOrAbove(User $user): bool
    {
        return $this->memberRecords()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('role', ['admin', 'guardian'])
            ->exists();
    }

    /**
     * Check if this user is the owner.
     */
    public function isOwner(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Scope to families a user belongs to.
     */
    public function scopeForUser($query, User $user)
    {
        return $query->whereHas('memberRecords', function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->where('status', 'active');
        });
    }

    /**
     * Scope to families owned by a user.
     */
    public function scopeOwnedBy($query, User $user)
    {
        return $query->where('owner_id', $user->id);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get the count of active members.
     */
    public function getActiveMemberCountAttribute(): int
    {
        return $this->activeMemberRecords()->count();
    }

    /**
     * Get a setting value.
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Set a setting value.
     */
    public function setSetting(string $key, $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
        $this->save();
    }
}
