<?php

namespace App\Policies;

use App\Models\IotModule;
use App\Models\User;

class IotModulePolicy
{
    /**
     * Determine if the user can view any IoT modules.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['super_admin', 'store_admin']);
    }

    /**
     * Determine if the user can view the IoT module.
     */
    public function view(User $user, IotModule $iotModule): bool
    {
        // Check if user owns a device with this module
        if ($iotModule->device && $iotModule->device->owner_id === $user->id) {
            return true;
        }

        return $user->hasRole('super_admin')
            || ($user->hasRole('store_admin')
                && $iotModule->device
                && $user->canAccessBranch($iotModule->device->branch_id));
    }

    /**
     * Determine if the user can create IoT modules.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can update the IoT module.
     */
    public function update(User $user, IotModule $iotModule): bool
    {
        return $this->view($user, $iotModule);
    }

    /**
     * Determine if the user can delete the IoT module.
     */
    public function delete(User $user, IotModule $iotModule): bool
    {
        return $user->hasRole('super_admin');
    }
}
