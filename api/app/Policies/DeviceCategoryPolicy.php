<?php

namespace App\Policies;

use App\Models\DeviceCategory;
use App\Models\User;

class DeviceCategoryPolicy
{
    /**
     * Determine if the user can view any categories.
     */
    public function viewAny(?User $user): bool
    {
        // Anyone can view categories (even unauthenticated)
        return true;
    }

    /**
     * Determine if the user can view the category.
     */
    public function view(?User $user, DeviceCategory $category): bool
    {
        return true;
    }

    /**
     * Determine if the user can create categories.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine if the user can update the category.
     */
    public function update(User $user, DeviceCategory $category): bool
    {
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine if the user can delete the category.
     */
    public function delete(User $user, DeviceCategory $category): bool
    {
        // Only super admin can delete categories
        return $user->hasRole('super_admin');
    }
}
