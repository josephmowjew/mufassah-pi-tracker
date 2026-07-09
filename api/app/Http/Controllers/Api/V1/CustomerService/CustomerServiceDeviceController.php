<?php

namespace App\Http\Controllers\Api\V1\CustomerService;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerServiceDeviceController extends BaseController
{
    /**
     * List all devices (read-only access).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Device::query()
            ->forCurrentUserBranch()
            ->with(['category', 'owner', 'sale', 'branch']);

        // Filter by inventory status
        if ($request->has('inventory_status')) {
            $query->where('inventory_status', $request->inventory_status);
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Search by serial number, brand, or model
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
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
     * Get a specific device.
     */
    public function show(Request $request, Device $device): JsonResponse
    {
        $user = $request->user();

        // Ensure the device belongs to the user's branch
        if ($device->branch_id !== $user->branch_id) {
            return $this->error('You do not have permission to view this device', 403);
        }

        $device->load(['category', 'owner', 'sale.customer', 'sale.soldBy', 'branch']);

        return $this->success(new DeviceResource($device));
    }
}
