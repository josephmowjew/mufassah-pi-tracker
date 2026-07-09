<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminDeviceController extends BaseController
{
    /**
     * List all devices in the system with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Device::query()
            ->with(['category', 'owner', 'iotModule', 'lastLocation']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by owner
        if ($request->has('owner_id')) {
            $query->where('owner_id', $request->owner_id);
        }

        // Filter by IoT module status
        if ($request->boolean('has_iot_module')) {
            $query->whereNotNull('iot_module_id');
        }

        // Search by serial number, brand, model, owner name/email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('unique_device_code', 'like', "%{$search}%")
                    ->orWhereHas('owner', function ($ownerQuery) use ($search) {
                        $ownerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Sort
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Paginate
        $perPage = $request->get('per_page', 15);
        $devices = $query->paginate($perPage);

        return $this->paginated(DeviceResource::collection($devices));
    }

    /**
     * Get a specific device with full details.
     */
    public function show(Device $device): JsonResponse
    {
        $device->load(['category', 'owner', 'iotModule', 'lastLocation', 'warranty']);

        return $this->success(new DeviceResource($device));
    }

    /**
     * Update a device (admin can update any device).
     */
    public function update(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'brand' => 'sometimes|string|max:100',
            'model' => 'sometimes|string|max:100',
            'serial_number' => ['sometimes', 'string', 'max:100', Rule::unique('devices')->ignore($device->id)],
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
            'purchase_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric|min:0',
            'purchase_location' => 'nullable|string|max:255',
            'category_id' => 'sometimes|exists:device_categories,id',
        ]);

        $device->update($validated);
        $device->load(['category', 'owner', 'iotModule', 'lastLocation']);

        return $this->success(new DeviceResource($device), 'Device updated successfully');
    }

    /**
     * Update device status (admin can change any device status).
     */
    public function updateStatus(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(DeviceStatus::class)],
            'notes' => 'nullable|string|max:500',
        ]);

        $oldStatus = $device->status;
        $device->update(['status' => $validated['status']]);

        // Log the status change if notes provided
        if (isset($validated['notes'])) {
            $device->update(['notes' => $validated['notes']]);
        }

        $device->load(['category', 'owner', 'iotModule', 'lastLocation']);

        return $this->success(new DeviceResource($device), 'Device status updated successfully');
    }

    /**
     * Delete a device (admin can delete any device).
     */
    public function destroy(Device $device): JsonResponse
    {
        // Store device info for response
        $deviceName = "{$device->brand} {$device->model}";

        // Delete related records first
        $device->locations()->delete();
        $device->geofences()->delete();

        // Unlink IoT module if any
        if ($device->iot_module_id) {
            $device->iotModule?->update(['device_id' => null]);
        }

        $device->delete();

        return $this->success(null, "Device '{$deviceName}' has been deleted successfully");
    }
}
