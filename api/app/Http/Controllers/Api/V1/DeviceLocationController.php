<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\DeviceLocationResource;
use App\Models\Device;
use App\Models\DeviceLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Device Locations
 *
 * APIs for device location tracking - history, latest location, analytics, and manual entry.
 */
class DeviceLocationController extends BaseController
{
    /**
     * Get location history for a device.
     */
    public function index(Request $request, Device $device): JsonResponse
    {
        $this->authorize('viewLocations', $device);

        $query = DeviceLocation::where('device_id', $device->id);

        // Filter by date range
        if ($request->has('from')) {
            $query->where('recorded_at', '>=', $request->from);
        }

        if ($request->has('to')) {
            $query->where('recorded_at', '<=', $request->to);
        }

        // Filter by source (manual or automatic)
        if ($request->has('is_manual')) {
            $query->where('is_manual', $request->boolean('is_manual'));
        }

        // Order by recorded_at descending (most recent first)
        $query->orderBy('recorded_at', 'desc');

        // Paginate or get all
        if ($request->has('per_page')) {
            $locations = $query->paginate($request->get('per_page', 50));

            return $this->paginated(DeviceLocationResource::collection($locations));
        }

        // Limit to last 100 if no pagination
        $locations = $query->limit($request->get('limit', 100))->get();

        return $this->success(DeviceLocationResource::collection($locations));
    }

    /**
     * Get the latest location for a device.
     */
    public function latest(Device $device): JsonResponse
    {
        $this->authorize('viewLocations', $device);

        // Try to get from cached last_location relationship
        if ($device->last_location_id) {
            $device->load('lastLocation');

            if ($device->lastLocation) {
                return $this->success(new DeviceLocationResource($device->lastLocation));
            }
        }

        // Fall back to querying the most recent location
        $location = DeviceLocation::where('device_id', $device->id)
            ->orderBy('recorded_at', 'desc')
            ->first();

        // Return null with 200 - no location is a valid state, not an error
        if (! $location) {
            return $this->success(null, 'No location data available for this device.');
        }

        return $this->success(new DeviceLocationResource($location));
    }

    /**
     * Store a manual location (user-provided).
     */
    public function storeManual(Request $request, Device $device): JsonResponse
    {
        $this->authorize('update', $device);

        $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $location = DeviceLocation::create([
            'device_id' => $device->id,
            'iot_module_id' => null,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'address' => $request->address,
            'is_manual' => true,
            'recorded_at' => now(),
        ]);

        // Update device's last location
        $device->update([
            'last_location_id' => $location->id,
            'last_seen_at' => now(),
        ]);

        return $this->created(
            new DeviceLocationResource($location),
            'Location recorded successfully.'
        );
    }

    /**
     * Get location statistics for a device.
     */
    public function statistics(Request $request, Device $device): JsonResponse
    {
        $this->authorize('viewLocations', $device);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = DeviceLocation::where('device_id', $device->id);

        if ($request->has('from')) {
            $query->where('recorded_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('recorded_at', '<=', $request->to);
        }

        $locations = $query->orderBy('recorded_at', 'asc')->get();

        // Calculate total distance traveled
        $totalDistance = 0;
        $previousLocation = null;
        foreach ($locations as $location) {
            if ($previousLocation) {
                $totalDistance += $previousLocation->distanceTo(
                    (float) $location->latitude,
                    (float) $location->longitude
                );
            }
            $previousLocation = $location;
        }

        // Calculate average speed
        $speedReadings = $locations->whereNotNull('speed')->pluck('speed');
        $averageSpeed = $speedReadings->isNotEmpty() ? $speedReadings->avg() : null;
        $maxSpeed = $speedReadings->isNotEmpty() ? $speedReadings->max() : null;

        // Get location counts
        $totalLocations = $locations->count();
        $manualLocations = $locations->where('is_manual', true)->count();
        $automaticLocations = $locations->where('is_manual', false)->count();

        return $this->success([
            'total_locations' => $totalLocations,
            'manual_locations' => $manualLocations,
            'automatic_locations' => $automaticLocations,
            'total_distance_meters' => round($totalDistance, 2),
            'total_distance_km' => round($totalDistance / 1000, 2),
            'average_speed_kmh' => $averageSpeed ? round($averageSpeed * 3.6, 2) : null,
            'max_speed_kmh' => $maxSpeed ? round($maxSpeed * 3.6, 2) : null,
            'first_location_at' => $locations->first()?->recorded_at?->toIso8601String(),
            'last_location_at' => $locations->last()?->recorded_at?->toIso8601String(),
        ]);
    }

    /**
     * Get heatmap data for a device (location density).
     */
    public function heatmap(Request $request, Device $device): JsonResponse
    {
        $this->authorize('viewLocations', $device);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'precision' => ['nullable', 'integer', 'between:1,6'],
        ]);

        $precision = $request->get('precision', 3);
        $roundFactor = pow(10, $precision);

        $query = DeviceLocation::where('device_id', $device->id);

        if ($request->has('from')) {
            $query->where('recorded_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('recorded_at', '<=', $request->to);
        }

        $locations = $query->get(['latitude', 'longitude']);

        // Group locations by rounded coordinates to create density clusters
        $heatmapData = $locations->groupBy(function ($location) use ($roundFactor) {
            $lat = round($location->latitude * $roundFactor) / $roundFactor;
            $lng = round($location->longitude * $roundFactor) / $roundFactor;

            return "{$lat},{$lng}";
        })->map(function ($group, $key) {
            [$lat, $lng] = explode(',', $key);

            return [
                'latitude' => (float) $lat,
                'longitude' => (float) $lng,
                'intensity' => $group->count(),
            ];
        })->values();

        return $this->success([
            'total_points' => $locations->count(),
            'clusters' => $heatmapData->count(),
            'data' => $heatmapData,
        ]);
    }

    /**
     * Get track/path data for playback visualization.
     */
    public function track(Request $request, Device $device): JsonResponse
    {
        $this->authorize('viewLocations', $device);

        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $locations = DeviceLocation::where('device_id', $device->id)
            ->whereBetween('recorded_at', [$request->from, $request->to])
            ->orderBy('recorded_at', 'asc')
            ->get();

        $track = $locations->map(function ($location, $index) use ($locations) {
            $previousLocation = $index > 0 ? $locations[$index - 1] : null;
            $distanceFromPrevious = $previousLocation
                ? $previousLocation->distanceTo((float) $location->latitude, (float) $location->longitude)
                : 0;

            return [
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'altitude' => $location->altitude ? (float) $location->altitude : null,
                'speed' => $location->speed ? (float) $location->speed : null,
                'heading' => $location->heading ? (float) $location->heading : null,
                'accuracy' => $location->accuracy ? (float) $location->accuracy : null,
                'recorded_at' => $location->recorded_at->toIso8601String(),
                'distance_from_previous' => round($distanceFromPrevious, 2),
            ];
        });

        // Calculate total distance
        $totalDistance = $track->sum('distance_from_previous');

        return $this->success([
            'device_id' => $device->id,
            'device_name' => $device->name,
            'from' => $request->from,
            'to' => $request->to,
            'total_points' => $track->count(),
            'total_distance_meters' => round($totalDistance, 2),
            'total_distance_km' => round($totalDistance / 1000, 2),
            'track' => $track,
        ]);
    }
}
