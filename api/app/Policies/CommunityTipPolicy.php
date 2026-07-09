<?php

namespace App\Policies;

use App\Models\CommunityTip;
use App\Models\User;

class CommunityTipPolicy
{
    /**
     * Determine whether the user can view any tips.
     */
    public function viewAny(User $user): bool
    {
        // Admins can view all tips
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can view the tip.
     */
    public function view(User $user, CommunityTip $communityTip): bool
    {
        // Tipster can view their own tips (if not anonymous)
        if (! $communityTip->is_anonymous && $communityTip->tipster_id === $user->id) {
            return true;
        }

        // Alert owner can view tips for their alerts
        if ($communityTip->alert && $communityTip->alert->created_by === $user->id) {
            return true;
        }

        // Admins can view any tip
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can create tips.
     * Anyone can submit a tip (even anonymously).
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the tip.
     */
    public function update(User $user, CommunityTip $communityTip): bool
    {
        // Tipster can update their own tips if still pending
        if ($communityTip->tipster_id === $user->id && $communityTip->isPending()) {
            return true;
        }

        // Admins can update any tip
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can delete the tip.
     */
    public function delete(User $user, CommunityTip $communityTip): bool
    {
        // Tipster can delete their own pending tips
        if ($communityTip->tipster_id === $user->id && $communityTip->isPending()) {
            return true;
        }

        // Only super admin can delete any tip
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can restore the tip.
     */
    public function restore(User $user, CommunityTip $communityTip): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the tip.
     */
    public function forceDelete(User $user, CommunityTip $communityTip): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can verify/review the tip.
     */
    public function verify(User $user, CommunityTip $communityTip): bool
    {
        // Alert owner can verify tips for their alerts
        if ($communityTip->alert && $communityTip->alert->created_by === $user->id) {
            return true;
        }

        // Admins can verify any tip
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }
}
