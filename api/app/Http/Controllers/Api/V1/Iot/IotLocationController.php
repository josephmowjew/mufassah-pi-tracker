<?php

namespace App\Http\Controllers\Api\V1\Iot;

use App\Enums\DeviceStatus;
use App\Enums\GeofenceEventType;
use App\Events\LocationUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceLocationResource;
use App\Models\Device;
use App\Models\DeviceLocation;
use App\Models\Geofence;
use App\Models\GeofenceEvent;
use App\Models\IotModule;
use App\Notifications\GeofenceViolationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group IoT Location Updates
 *
 * APIs for IoT devices to send location updates and heartbeats.
 * Authentication uses module_id:api_token instead of user Bearer tokens.
 */
class IotLocationController extends Controller
{
    /**
     * Store a location update from an IoT module.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'altitude' => ['nullable', 'numeric'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'battery_level' => ['nullable', 'integer', 'between:0,100'],
            'signal_strength' => ['nullable', 'integer', 'between:0,100'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        /** @var IotModule $module */
        $module = $request->get('iot_module');
        /** @var Device $device */
        $device = $request->get('device');

        // Create location record
        $location = DeviceLocation::create([
            'device_id' => $device->id,
            'iot_module_id' => $module->id,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'altitude' => $validated['altitude'] ?? null,
            'accuracy' => $validated['accuracy'] ?? null,
            'speed' => $validated['speed'] ?? null,
            'heading' => $validated['heading'] ?? null,
            'is_manual' => false,
            'recorded_at' => $validated['recorded_at'] ?? now(),
        ]);

        // Update device's last location and seen timestamp
        $device->update([
            'last_location_id' => $location->id,
            'last_seen_at' => now(),
        ]);

        // Update module's battery and signal strength if provided
        $moduleUpdates = ['last_location_update' => now()];
        if (isset($validated['battery_level'])) {
            $moduleUpdates['battery_level'] = $validated['battery_level'];
        }
        if (isset($validated['signal_strength'])) {
            $moduleUpdates['signal_strength'] = $validated['signal_strength'];
        }
        $module->update($moduleUpdates);

        // Check geofences if device is being tracked (stolen status triggers intensive tracking)
        $this->checkGeofences($device, $location);

        // Broadcast the location update for real-time tracking
        broadcast(new LocationUpdated($device, $location))->toOthers();

        return response()->json([
            'success' => true,
            'message' => 'Location recorded successfully.',
            'data' => new DeviceLocationResource($location),
        ], 201);
    }

    /**
     * Store multiple location updates (batch upload for offline sync).
     */
    public function storeBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locations' => ['required', 'array', 'max:100'],
            'locations.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'locations.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'locations.*.altitude' => ['nullable', 'numeric'],
            'locations.*.accuracy' => ['nullable', 'numeric', 'min:0'],
            'locations.*.speed' => ['nullable', 'numeric', 'min:0'],
            'locations.*.heading' => ['nullable', 'numeric', 'between:0,360'],
            'locations.*.recorded_at' => ['required', 'date'],
        ]);

        /** @var IotModule $module */
        $module = $request->get('iot_module');
        /** @var Device $device */
        $device = $request->get('device');

        $locations = collect($validated['locations'])->map(function ($locationData) use ($device, $module) {
            return DeviceLocation::create([
                'device_id' => $device->id,
                'iot_module_id' => $module->id,
                'latitude' => $locationData['latitude'],
                'longitude' => $locationData['longitude'],
                'altitude' => $locationData['altitude'] ?? null,
                'accuracy' => $locationData['accuracy'] ?? null,
                'speed' => $locationData['speed'] ?? null,
                'heading' => $locationData['heading'] ?? null,
                'is_manual' => false,
                'recorded_at' => $locationData['recorded_at'],
            ]);
        });

        // Update device with the most recent location
        $latestLocation = $locations->sortByDesc('recorded_at')->first();
        if ($latestLocation) {
            $device->update([
                'last_location_id' => $latestLocation->id,
                'last_seen_at' => now(),
            ]);

            // Check geofences for latest location
            $this->checkGeofences($device, $latestLocation);

            // Broadcast the latest location update
            broadcast(new LocationUpdated($device, $latestLocation))->toOthers();
        }

        // Update module
        $module->update(['last_location_update' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Batch locations recorded successfully.',
            'data' => [
                'count' => $locations->count(),
            ],
        ], 201);
    }

    /**
     * Send a heartbeat/ping from the IoT module.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'battery_level' => ['nullable', 'integer', 'between:0,100'],
            'signal_strength' => ['nullable', 'integer', 'between:0,100'],
            'firmware_version' => ['nullable', 'string', 'max:50'],
        ]);

        /** @var IotModule $module */
        $module = $request->get('iot_module');

        $updates = ['last_ping' => now()];
        if (isset($validated['battery_level'])) {
            $updates['battery_level'] = $validated['battery_level'];
        }
        if (isset($validated['signal_strength'])) {
            $updates['signal_strength'] = $validated['signal_strength'];
        }
        if (isset($validated['firmware_version'])) {
            $updates['firmware_version'] = $validated['firmware_version'];
        }

        $module->update($updates);

        // Return configuration if available
        $config = $module->configuration ?? [];

        // Determine tracking interval based on device status
        $device = $request->get('device');
        $trackingInterval = $this->getTrackingInterval($device);

        return response()->json([
            'success' => true,
            'message' => 'Heartbeat received.',
            'data' => [
                'server_time' => now()->toIso8601String(),
                'tracking_interval' => $trackingInterval,
                'config' => $config,
            ],
        ]);
    }

    /**
     * Check if location violates any active geofences.
     */
    protected function checkGeofences(Device $device, DeviceLocation $location): void
    {
        // Get all active geofences for this device
        $geofences = Geofence::where('device_id', $device->id)
            ->where('is_active', true)
            ->get();

        foreach ($geofences as $geofence) {
            $this->checkGeofenceViolation($geofence, $location, $device);
        }
    }

    /**
     * Check if location violates a specific geofence.
     */
    protected function checkGeofenceViolation(Geofence $geofence, DeviceLocation $location, Device $device): void
    {
        $isInside = $this->isInsideGeofence($geofence, (float) $location->latitude, (float) $location->longitude);

        // Get the last event for this geofence to determine state change
        $lastEvent = GeofenceEvent::where('geofence_id', $geofence->id)
            ->orderBy('created_at', 'desc')
            ->first();

        $wasInside = $lastEvent && $lastEvent->event_type === GeofenceEventType::Entry;

        // Determine event type based on state change
        $eventType = null;
        if ($isInside && ! $wasInside && $geofence->notify_on_entry) {
            $eventType = GeofenceEventType::Entry;
        } elseif (! $isInside && $wasInside && $geofence->notify_on_exit) {
            $eventType = GeofenceEventType::Exit;
        }

        if ($eventType) {
            // Create geofence event
            $event = GeofenceEvent::create([
                'geofence_id' => $geofence->id,
                'device_location_id' => $location->id,
                'event_type' => $eventType,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
            ]);

            // Send notification to device owner
            $device->owner->notify(new GeofenceViolationNotification($event));
        }
    }

    /**
     * Check if a point is inside a circular geofence.
     */
    protected function isInsideGeofence(Geofence $geofence, float $latitude, float $longitude): bool
    {
        $distance = $this->calculateDistance(
            (float) $geofence->center_latitude,
            (float) $geofence->center_longitude,
            $latitude,
            $longitude
        );

        return $distance <= $geofence->radius_meters;
    }

    /**
     * Calculate distance between two points using Haversine formula.
     */
    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // meters

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos($latFrom) * cos($latTo) *
            sin($lonDelta / 2) * sin($lonDelta / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Determine tracking interval based on device status.
     */
    protected function getTrackingInterval(Device $device): int
    {
        // Intensive tracking for stolen devices (every 30 seconds)
        if ($device->status === DeviceStatus::STOLEN) {
            return 30;
        }

        // Normal tracking interval (5 minutes)
        return 300;
    }
}
