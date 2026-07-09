<?php

namespace App\Policies;

use App\Models\CommunityAlert;
use App\Models\User;

class CommunityAlertPolicy
{
    /**
     * Determine whether the user can view any alerts.
     * Public alerts can be viewed by anyone.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the alert.
     */
    public function view(User $user, CommunityAlert $communityAlert): bool
    {
        // Active/public alerts can be viewed by anyone
        if ($communityAlert->isActive()) {
            return true;
        }

        // Creator can view their own alerts
        if ($communityAlert->created_by === $user->id) {
            return true;
        }

        // Admins can view any alert
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can create alerts.
     * Users need to have a theft report to create an alert.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the alert.
     */
    public function update(User $user, CommunityAlert $communityAlert): bool
    {
        // Creator can update their own alerts
        if ($communityAlert->created_by === $user->id) {
            return true;
        }

        // Admins can update any alert
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can delete the alert.
     */
    public function delete(User $user, CommunityAlert $communityAlert): bool
    {
        // Creator can delete their own active alerts
        if ($communityAlert->created_by === $user->id && $communityAlert->isActive()) {
            return true;
        }

        // Only super admin can delete any alert
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can restore the alert.
     */
    public function restore(User $user, CommunityAlert $communityAlert): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the alert.
     */
    public function forceDelete(User $user, CommunityAlert $communityAlert): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can set/update the bounty.
     */
    public function manageBounty(User $user, CommunityAlert $communityAlert): bool
    {
        // Only alert creator can manage bounty
        if ($communityAlert->created_by === $user->id) {
            return true;
        }

        // Admins can manage bounty for any alert
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine whether the user can pause/resume the alert.
     */
    public function toggleStatus(User $user, CommunityAlert $communityAlert): bool
    {
        // Creator can pause/resume their own alerts
        if ($communityAlert->created_by === $user->id) {
            return true;
        }

        // Admins can toggle status for any alert
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can resolve the alert.
     */
    public function resolve(User $user, CommunityAlert $communityAlert): bool
    {
        // Creator can resolve their own alerts
        if ($communityAlert->created_by === $user->id) {
            return true;
        }

        // Admins can resolve any alert
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can view tips for this alert.
     */
    public function viewTips(User $user, CommunityAlert $communityAlert): bool
    {
        // Creator can view tips for their own alerts
        if ($communityAlert->created_by === $user->id) {
            return true;
        }

        // Admins can view tips for any alert
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can view claims for this alert.
     */
    public function viewClaims(User $user, CommunityAlert $communityAlert): bool
    {
        // Creator can view claims for their own alerts
        if ($communityAlert->created_by === $user->id) {
            return true;
        }

        // Admins can view claims for any alert
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }
}
