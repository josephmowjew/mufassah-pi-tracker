<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Services\InventoryCatalogResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminDeviceController extends BaseController
{
    public function __construct(private readonly InventoryCatalogResolver $catalogResolver) {}

    /**
     * List all devices in the system with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Device::query()
            ->with(['category', 'owner', 'iotModule', 'lastLocation', 'branch']);

        $branchId = $this->resolveDeviceReadBranchId(
            $request,
            $request->filled('branch_id') ? $request->integer('branch_id') : null,
        );

        $query->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

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

    private function resolveDeviceReadBranchId(Request $request, ?int $requestedBranchId): ?int
    {
        $user = $request->user();

        if ($user?->hasRole('super_admin')) {
            // The UI sends this flag for an intentional all-branches view. Keep
            // the existing implicit branch fallback for direct API callers.
            if ($request->boolean('all_branches')) {
                return null;
            }

            return $requestedBranchId ?: ($user->branch_id ? (int) $user->branch_id : null);
        }

        return $user?->requiredBranchScopeId();
    }

    /**
     * Get a specific device with full details.
     */
    public function show(Request $request, Device $device): JsonResponse
    {
        $this->authorizeDeviceBranch($request, $device);
        $device->load(['category', 'owner', 'iotModule', 'lastLocation', 'warranty']);

        return $this->success(new DeviceResource($device));
    }

    /**
     * Update a device (admin can update any device).
     */
    public function update(Request $request, Device $device): JsonResponse
    {
        $this->authorizeDeviceBranch($request, $device);
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
            'category_id' => ['sometimes', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
        ]);

        if (array_key_exists('category_id', $validated)
            || array_key_exists('brand', $validated)
            || array_key_exists('model', $validated)) {
            $catalog = $this->catalogResolver->resolve(
                (int) ($validated['category_id'] ?? $device->category_id),
                $validated['brand'] ?? $device->brand,
                $validated['model'] ?? $device->model,
            );
            $validated = array_merge($validated, $catalog);
        }

        $device->update($validated);
        $device->load(['category', 'owner', 'iotModule', 'lastLocation']);

        return $this->success(new DeviceResource($device), 'Device updated successfully');
    }

    /**
     * Update device status (admin can change any device status).
     */
    public function updateStatus(Request $request, Device $device): JsonResponse
    {
        $this->authorizeDeviceBranch($request, $device);
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
        // Keep the legacy route as a hard stop so an old client cannot bypass
        // step-up verification. Actual deletion is performed by
        // InventoryDispositionController after a verified challenge.
        return $this->error('Device deletion requires PIN verification. Request a deletion challenge first.', 403);
    }

    private function authorizeDeviceBranch(Request $request, Device $device): void
    {
        abort_unless(
            $request->user()?->canAccessBranch($device->branch_id),
            403,
            'You cannot access devices for another branch.'
        );
    }
}
