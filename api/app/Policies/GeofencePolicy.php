<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\Geofence;
use App\Models\User;

class GeofencePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Geofence $geofence): bool
    {
        if ($user->hasRole(['super_admin', 'store_admin', 'customer_service'])) {
            return true;
        }

        return $geofence->device->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function createForDevice(User $user, Device $device): bool
    {
        if ($user->hasRole(['super_admin', 'store_admin'])) {
            return true;
        }

        return $device->owner_id === $user->id;
    }

    public function update(User $user, Geofence $geofence): bool
    {
        if ($user->hasRole(['super_admin', 'store_admin'])) {
            return true;
        }

        return $geofence->device->owner_id === $user->id;
    }

    public function delete(User $user, Geofence $geofence): bool
    {
        if ($user->hasRole(['super_admin', 'store_admin'])) {
            return true;
        }

        return $geofence->device->owner_id === $user->id;
    }

    public function restore(User $user, Geofence $geofence): bool
    {
        return $user->hasRole('super_admin');
    }

    public function forceDelete(User $user, Geofence $geofence): bool
    {
        return $user->hasRole('super_admin');
    }
}
