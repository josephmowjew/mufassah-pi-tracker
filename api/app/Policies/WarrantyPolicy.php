<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warranty;

class WarrantyPolicy
{
    /**
     * Determine if the user can view any warranties.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can view the warranty.
     */
    public function view(User $user, Warranty $warranty): bool
    {
        // Device owner can view their warranty
        if ($warranty->device && $warranty->device->owner_id === $user->id) {
            return true;
        }

        // Admins can view any warranty
        return $user->hasRole(['super_admin', 'store_admin', 'customer_service']);
    }

    /**
     * Determine if the user can create warranties.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine if the user can update the warranty.
     */
    public function update(User $user, Warranty $warranty): bool
    {
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine if the user can void the warranty.
     */
    public function void(User $user, Warranty $warranty): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can delete the warranty.
     */
    public function delete(User $user, Warranty $warranty): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can submit a claim against this warranty.
     */
    public function submitClaim(User $user, Warranty $warranty): bool
    {
        // Only device owner can submit claims
        if ($warranty->device && $warranty->device->owner_id === $user->id) {
            return true;
        }

        // Admins can submit claims on behalf of users
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine if the user can view warranty claims.
     */
    public function viewClaims(User $user, Warranty $warranty): bool
    {
        return $this->view($user, $warranty);
    }
}
