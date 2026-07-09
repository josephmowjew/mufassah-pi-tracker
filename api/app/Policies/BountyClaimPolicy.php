<?php

namespace App\Policies;

use App\Models\BountyClaim;
use App\Models\User;

class BountyClaimPolicy
{
    /**
     * Determine whether the user can view any claims.
     */
    public function viewAny(User $user): bool
    {
        // Admins can view all claims
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can view the claim.
     */
    public function view(User $user, BountyClaim $bountyClaim): bool
    {
        // Claimant can view their own claims
        if ($bountyClaim->claimant_id === $user->id) {
            return true;
        }

        // Alert owner can view claims for their alerts
        if ($bountyClaim->alert && $bountyClaim->alert->created_by === $user->id) {
            return true;
        }

        // Admins can view any claim
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can create claims.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the claim.
     */
    public function update(User $user, BountyClaim $bountyClaim): bool
    {
        // Claimant can update their own pending claims
        if ($bountyClaim->claimant_id === $user->id && $bountyClaim->isPending()) {
            return true;
        }

        // Admins can update any claim
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can delete the claim.
     */
    public function delete(User $user, BountyClaim $bountyClaim): bool
    {
        // Claimant can delete their own pending claims
        if ($bountyClaim->claimant_id === $user->id && $bountyClaim->isPending()) {
            return true;
        }

        // Only super admin can delete any claim
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can restore the claim.
     */
    public function restore(User $user, BountyClaim $bountyClaim): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the claim.
     */
    public function forceDelete(User $user, BountyClaim $bountyClaim): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can approve/reject the claim.
     */
    public function review(User $user, BountyClaim $bountyClaim): bool
    {
        // Alert owner can review claims for their alerts
        if ($bountyClaim->alert && $bountyClaim->alert->created_by === $user->id) {
            return true;
        }

        // Admins can review any claim
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine whether the user can mark the claim as paid.
     */
    public function markPaid(User $user, BountyClaim $bountyClaim): bool
    {
        // Alert owner can mark claims as paid for their alerts
        if ($bountyClaim->alert && $bountyClaim->alert->created_by === $user->id) {
            return true;
        }

        // Admins can mark any claim as paid
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine whether the user can rate the claimant.
     */
    public function rate(User $user, BountyClaim $bountyClaim): bool
    {
        // Only alert owner can rate the claimant after payment
        return $bountyClaim->alert
            && $bountyClaim->alert->created_by === $user->id
            && $bountyClaim->isPaid();
    }
}
