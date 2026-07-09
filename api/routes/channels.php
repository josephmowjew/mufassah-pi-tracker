<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Channel for user's devices - receives all location updates for devices they own
Broadcast::channel('user.{userId}.devices', function (User $user, int $userId) {
    return $user->id === $userId;
});

// Channel for specific device location tracking
Broadcast::channel('device.{deviceId}.location', function (User $user, int $deviceId) {
    $device = Device::find($deviceId);

    if (! $device) {
        return false;
    }

    // Allow device owner
    if ($device->owner_id === $user->id) {
        return true;
    }

    // Allow admins
    if ($user->hasAnyRole(['super_admin', 'store_admin', 'customer_service'])) {
        return true;
    }

    return false;
});

// Channel for admin dashboard real-time updates
Broadcast::channel('admin.dashboard', function (User $user) {
    return $user->hasAnyRole(['super_admin', 'store_admin']);
});

// Channel for theft report updates (for admins and the reporter)
Broadcast::channel('theft-report.{reportId}', function (User $user, int $reportId) {
    $report = \App\Models\TheftReport::find($reportId);

    if (! $report) {
        return false;
    }

    // Allow the reporter
    if ($report->reported_by === $user->id) {
        return true;
    }

    // Allow admins
    if ($user->hasAnyRole(['super_admin', 'store_admin', 'customer_service'])) {
        return true;
    }

    return false;
});
