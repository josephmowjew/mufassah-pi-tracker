<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGeofenceRequest;
use App\Http\Requests\UpdateGeofenceRequest;
use App\Http\Resources\GeofenceEventResource;
use App\Http\Resources\GeofenceResource;
use App\Models\Device;
use App\Models\Geofence;
use App\Traits\PaginatesLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * @group Geofences
 *
 * APIs for geofencing - create virtual boundaries with entry/exit alerts for tracked devices.
 */
class GeofenceController extends Controller
{
    use PaginatesLists;

    /**
     * List all geofences for the authenticated user.
     *
     * Returns all geofences across all devices owned by the current user.
     */
    public function list(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $geofences = Geofence::whereHas('device', function ($query) use ($user) {
            $query->where('owner_id', $user->id);
        })
            ->with('device')
            ->when($request->has('active'), function ($query) use ($request) {
                $query->where('is_active', $request->boolean('active'));
            })
            ->withCount('events')
            ->latest()
            ->get();

        return GeofenceResource::collection($geofences);
    }

    /**
     * List geofences for a device.
     */
    public function index(Request $request, Device $device): AnonymousResourceCollection
    {
        Gate::authorize('view', $device);

        $geofences = $device->geofences()
            ->when($request->has('active'), function ($query) use ($request) {
                $query->where('is_active', $request->boolean('active'));
            })
            ->withCount('events')
            ->latest()
            ->paginate($this->perPage($request));

        return GeofenceResource::collection($geofences);
    }

    /**
     * Create a new geofence for a device.
     */
    public function store(StoreGeofenceRequest $request): GeofenceResource|JsonResponse
    {
        $device = Device::findOrFail($request->device_id);

        Gate::authorize('createForDevice', [Geofence::class, $device]);

        $geofence = Geofence::create([
            'device_id' => $device->id,
            'name' => $request->name,
            'description' => $request->description,
            'shape_type' => $request->shape_type,
            'center_latitude' => $request->center_latitude,
            'center_longitude' => $request->center_longitude,
            'radius_meters' => $request->radius_meters,
            'polygon_coordinates' => $request->polygon_coordinates,
            'is_active' => $request->boolean('is_active', true),
            'notify_on_entry' => $request->boolean('notify_on_entry', true),
            'notify_on_exit' => $request->boolean('notify_on_exit', true),
            'active_days' => $request->active_days ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            'active_time_start' => $request->active_time_start,
            'active_time_end' => $request->active_time_end,
        ]);

        return new GeofenceResource($geofence);
    }

    /**
     * Get a specific geofence.
     */
    public function show(Geofence $geofence): GeofenceResource
    {
        Gate::authorize('view', $geofence);

        $geofence->load('device');
        $geofence->loadCount('events');

        return new GeofenceResource($geofence);
    }

    /**
     * Update a geofence.
     */
    public function update(UpdateGeofenceRequest $request, Geofence $geofence): GeofenceResource
    {
        Gate::authorize('update', $geofence);

        $geofence->update($request->validated());

        return new GeofenceResource($geofence);
    }

    /**
     * Delete a geofence.
     */
    public function destroy(Geofence $geofence): JsonResponse
    {
        Gate::authorize('delete', $geofence);

        $geofence->delete();

        return response()->json([
            'success' => true,
            'message' => 'Geofence deleted successfully.',
        ]);
    }

    /**
     * Toggle geofence active status.
     */
    public function toggle(Geofence $geofence): GeofenceResource
    {
        Gate::authorize('update', $geofence);

        $geofence->update([
            'is_active' => ! $geofence->is_active,
        ]);

        return new GeofenceResource($geofence);
    }

    /**
     * Get events for a geofence.
     */
    public function events(Request $request, Geofence $geofence): AnonymousResourceCollection
    {
        Gate::authorize('view', $geofence);

        $events = $geofence->events()
            ->with('location')
            ->when($request->has('event_type'), function ($query) use ($request) {
                $query->where('event_type', $request->input('event_type'));
            })
            ->when($request->has('from'), function ($query) use ($request) {
                $query->where('event_time', '>=', $request->input('from'));
            })
            ->when($request->has('to'), function ($query) use ($request) {
                $query->where('event_time', '<=', $request->input('to'));
            })
            ->latest('event_time')
            ->paginate($this->perPage($request));

        return GeofenceEventResource::collection($events);
    }
}
