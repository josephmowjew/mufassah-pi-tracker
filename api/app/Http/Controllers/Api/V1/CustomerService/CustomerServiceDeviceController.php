<?php

namespace App\Http\Controllers\Api\V1\CustomerService;

use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Support\LikePattern;
use App\Traits\PaginatesLists;
use App\Traits\SortsLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerServiceDeviceController extends BaseController
{
    use PaginatesLists;
    use SortsLists;

    /** @var array<int, string> */
    private const SORTABLE = ['serial_number', 'brand', 'model', 'created_at', 'updated_at'];

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
                $q->whereRaw('serial_number LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                    ->orWhereRaw('brand LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                    ->orWhereRaw('model LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)]);
            });
        }

        // Sort
        $this->applySort($query, $request, self::SORTABLE, ['created_at', 'desc']);

        // Paginate
        $perPage = $this->perPage($request);
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
        if (! $user->canAccessBranch($device->branch_id)) {
            return $this->error('You do not have permission to view this device', 403);
        }

        $device->load(['category', 'owner', 'sale.customer', 'sale.soldBy', 'branch']);

        return $this->success(new DeviceResource($device));
    }
}
