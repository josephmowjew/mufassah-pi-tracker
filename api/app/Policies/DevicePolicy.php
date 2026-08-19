<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\User;

class DevicePolicy
{
    /**
     * Determine if the user can view any devices.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can view the device.
     */
    public function view(User $user, Device $device): bool
    {
        // Owner can view their device
        if ($device->owner_id === $user->id) {
            return true;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->hasAnyRole(['store_admin', 'customer_service'])
            && $user->canAccessBranch($device->branch_id);
    }

    /**
     * Determine if the user can create devices.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can update the device.
     */
    public function update(User $user, Device $device): bool
    {
        // Owner can update their device
        if ($device->owner_id === $user->id) {
            return true;
        }

        return $user->hasRole('super_admin')
            || ($user->hasRole('store_admin') && $user->canAccessBranch($device->branch_id));
    }

    /**
     * Determine if the user can delete the device.
     */
    public function delete(User $user, Device $device): bool
    {
        // Owner can delete their device
        if ($device->owner_id === $user->id) {
            return true;
        }

        // Only super admin can delete any device
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can transfer the device.
     */
    public function transfer(User $user, Device $device): bool
    {
        // Only owner can transfer their device
        if ($device->owner_id === $user->id) {
            return true;
        }

        return $user->hasRole('super_admin')
            || ($user->hasRole('store_admin') && $user->canAccessBranch($device->branch_id));
    }

    /**
     * Determine if the user can update device status.
     */
    public function updateStatus(User $user, Device $device): bool
    {
        // Owner can update status of their device
        if ($device->owner_id === $user->id) {
            return true;
        }

        return $user->hasRole('super_admin')
            || ($user->hasAnyRole(['store_admin', 'customer_service']) && $user->canAccessBranch($device->branch_id));
    }

    /**
     * Determine if the user can link IoT module to device.
     */
    public function linkModule(User $user, Device $device): bool
    {
        // Owner can link/unlink IoT modules on their device
        if ($device->owner_id === $user->id) {
            return true;
        }

        return $user->hasRole('super_admin')
            || ($user->hasRole('store_admin') && $user->canAccessBranch($device->branch_id));
    }

    /**
     * Determine if the user can view device locations.
     */
    public function viewLocations(User $user, Device $device): bool
    {
        // Owner can view their device locations
        if ($device->owner_id === $user->id) {
            return true;
        }

        return $user->hasRole('super_admin')
            || ($user->hasRole('store_admin') && $user->canAccessBranch($device->branch_id));
    }
}
