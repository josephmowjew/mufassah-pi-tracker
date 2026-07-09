<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * The guard name for Spatie Permission.
     */
    protected string $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'national_id',
        'address',
        'city',
        'avatar',
        'branch_id',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'email_verified_at',
        'suspended_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'national_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            'national_id' => 'encrypted',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    /**
     * Get the devices owned by the user.
     */
    public function devices()
    {
        return $this->hasMany(\App\Models\Device::class, 'owner_id');
    }

    /**
     * Get the branch that the user belongs to.
     */
    public function branch()
    {
        return $this->belongsTo(\App\Models\Branch::class);
    }

    /**
     * Get the subscriptions for the user.
     */
    public function subscriptions()
    {
        return $this->hasMany(\App\Models\Subscription::class);
    }

    /**
     * Get the active subscription for the user.
     */
    public function activeSubscription()
    {
        return $this->hasOne(\App\Models\Subscription::class)
            ->where('status', 'active')
            ->latest();
    }

    /**
     * Get sales where this user is the customer.
     */
    public function salesAsCustomer()
    {
        return $this->hasMany(\App\Models\Sale::class, 'customer_id');
    }

    /**
     * Get sales made by this user (as seller).
     */
    public function madeSales()
    {
        return $this->hasMany(\App\Models\Sale::class, 'sold_by_id');
    }

    /**
     * Get the shifts for this user.
     */
    public function shifts()
    {
        return $this->hasMany(\App\Models\Shift::class);
    }

    /**
     * Get the active shift for this user.
     */
    public function activeShift()
    {
        return $this->hasOne(\App\Models\Shift::class)->active();
    }

    /**
     * Get the alerts for the user.
     */
    public function alerts()
    {
        return $this->hasMany(\App\Models\Alert::class);
    }

    /**
     * Get the theft reports filed by the user.
     */
    public function theftReports()
    {
        return $this->hasMany(\App\Models\TheftReport::class, 'reported_by');
    }

    // =========================================================================
    // FAMILY RELATIONSHIPS
    // =========================================================================

    /**
     * Get all family membership records for this user.
     */
    public function familyMemberships()
    {
        return $this->hasMany(\App\Models\FamilyMember::class);
    }

    /**
     * Get active family memberships.
     */
    public function activeFamilyMemberships()
    {
        return $this->hasMany(\App\Models\FamilyMember::class)->where('status', 'active');
    }

    /**
     * Get all families this user belongs to.
     */
    public function families()
    {
        return $this->belongsToMany(\App\Models\Family::class, 'family_members')
            ->withPivot(['role', 'nickname', 'status', 'joined_at', 'invited_by', 'permissions', 'location_sharing_enabled', 'receive_family_alerts'])
            ->withTimestamps();
    }

    /**
     * Get active families this user belongs to.
     */
    public function activeFamilies()
    {
        return $this->families()->wherePivot('status', 'active');
    }

    /**
     * Get families owned by this user.
     */
    public function ownedFamilies()
    {
        return $this->hasMany(\App\Models\Family::class, 'owner_id');
    }

    /**
     * Get pending family invitations.
     */
    public function pendingFamilyInvitations()
    {
        return $this->familyMemberships()->where('status', 'pending');
    }

    /**
     * Check if user is member of a family.
     */
    public function isMemberOf(\App\Models\Family $family): bool
    {
        return $this->activeFamilyMemberships()
            ->where('family_id', $family->id)
            ->exists();
    }

    /**
     * Get user's role in a family.
     */
    public function getFamilyRole(\App\Models\Family $family): ?string
    {
        $membership = $this->activeFamilyMemberships()
            ->where('family_id', $family->id)
            ->first();

        return $membership?->role->value;
    }

    /**
     * Check if user has an active subscription.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }

    /**
     * Check if user is on premium plan.
     */
    public function isPremium(): bool
    {
        $subscription = $this->activeSubscription;

        return $subscription && $subscription->plan->slug === 'premium';
    }

    /**
     * Scope to filter users by branch.
     */
    public function scopeInBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * Scope to eager load branch relationship.
     */
    public function scopeWithBranch($query)
    {
        return $query->with('branch');
    }

    /**
     * Send the password reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Send the email verification notification.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }
}
