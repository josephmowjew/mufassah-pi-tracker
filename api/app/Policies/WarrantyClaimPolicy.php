<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WarrantyClaim;

class WarrantyClaimPolicy
{
    /**
     * Determine if the user can view any warranty claims.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine if the user can view the warranty claim.
     */
    public function view(User $user, WarrantyClaim $claim): bool
    {
        // Device owner can view claims for their warranty
        if ($claim->warranty && $claim->warranty->device && $claim->warranty->device->owner_id === $user->id) {
            return true;
        }

        // Admins can view any claim
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine if the user can update the warranty claim.
     */
    public function update(User $user, WarrantyClaim $claim): bool
    {
        // Device owner can update their own pending claims
        if ($claim->warranty && $claim->warranty->device && $claim->warranty->device->owner_id === $user->id) {
            return $claim->status->value === 'pending';
        }

        return false;
    }

    /**
     * Determine if the user can process the warranty claim.
     */
    public function process(User $user, WarrantyClaim $claim): bool
    {
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine if the user can delete the warranty claim.
     */
    public function delete(User $user, WarrantyClaim $claim): bool
    {
        // Only pending claims can be deleted by their owner
        if ($claim->warranty && $claim->warranty->device && $claim->warranty->device->owner_id === $user->id) {
            return $claim->status->value === 'pending';
        }

        // Super admin can delete any claim
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can add evidence to the claim.
     */
    public function addEvidence(User $user, WarrantyClaim $claim): bool
    {
        // Only for pending claims
        if ($claim->status->value !== 'pending') {
            return false;
        }

        // Device owner can add evidence
        if ($claim->warranty && $claim->warranty->device && $claim->warranty->device->owner_id === $user->id) {
            return true;
        }

        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }
}
