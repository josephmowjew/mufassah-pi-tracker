<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WarrantyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Warranty\StoreWarrantyRequest;
use App\Http\Requests\Api\V1\Warranty\UpdateWarrantyRequest;
use App\Http\Resources\WarrantyResource;
use App\Models\Device;
use App\Models\Warranty;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Warranties
 *
 * APIs for warranty management - register warranties and view warranty details.
 */
class WarrantyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Warranty::class);

        $query = Warranty::query()->with(['device']);

        if ($request->user()->hasRole(['store_admin', 'customer_service'])) {
            $branchId = $request->user()->requiredBranchScopeId();
            $query->whereHas('device', fn ($deviceQuery) => $deviceQuery->where('branch_id', $branchId));
        } elseif (! $request->user()->hasRole('super_admin')) {
            $query->whereHas('device', fn ($d) => $d->where('owner_id', $request->user()->id));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter active only
        if ($request->boolean('active_only')) {
            $query->active();
        }

        // Filter by device
        if ($request->has('device_id')) {
            $query->where('device_id', $request->device_id);
        }

        $warranties = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => WarrantyResource::collection($warranties),
            'meta' => [
                'current_page' => $warranties->currentPage(),
                'last_page' => $warranties->lastPage(),
                'per_page' => $warranties->perPage(),
                'total' => $warranties->total(),
            ],
        ]);
    }

    public function store(StoreWarrantyRequest $request): JsonResponse
    {
        $this->authorize('create', Warranty::class);
        $device = Device::findOrFail($request->integer('device_id'));
        abort_unless(
            $request->user()->canAccessBranch($device->branch_id),
            403,
            'You cannot create warranties for another branch.'
        );

        $startDate = \Carbon\Carbon::parse($request->start_date);
        $endDate = $startDate->copy()->addMonths($request->duration_months);

        $warranty = Warranty::create([
            'device_id' => $request->device_id,
            'warranty_number' => 'WRN-'.strtoupper(uniqid()),
            'status' => WarrantyStatus::Active,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'duration_months' => $request->duration_months,
            'coverage_details' => $request->coverage_details,
            'terms_conditions' => $request->terms_conditions,
            'provider' => $request->provider,
            'notes' => $request->notes,
        ]);

        $warranty->load('device');

        return response()->json([
            'success' => true,
            'message' => 'Warranty created successfully.',
            'data' => new WarrantyResource($warranty),
        ], 201);
    }

    public function show(Warranty $warranty): JsonResponse
    {
        $this->authorize('view', $warranty);

        $warranty->load(['device.category', 'device.owner', 'claims']);

        return response()->json([
            'success' => true,
            'data' => new WarrantyResource($warranty),
        ]);
    }

    public function update(UpdateWarrantyRequest $request, Warranty $warranty): JsonResponse
    {
        $this->authorize('update', $warranty);

        $warranty->update($request->validated());
        $warranty->load('device');

        return response()->json([
            'success' => true,
            'message' => 'Warranty updated successfully.',
            'data' => new WarrantyResource($warranty),
        ]);
    }

    public function destroy(Warranty $warranty): JsonResponse
    {
        $this->authorize('delete', $warranty);

        // Check if warranty has any claims
        if ($warranty->claims()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete warranty with existing claims.',
            ], 400);
        }

        $warranty->delete();

        return response()->json([
            'success' => true,
            'message' => 'Warranty deleted successfully.',
        ]);
    }

    public function void(Warranty $warranty): JsonResponse
    {
        $this->authorize('void', $warranty);

        if ($warranty->status === WarrantyStatus::Voided) {
            return response()->json([
                'success' => false,
                'message' => 'Warranty is already voided.',
            ], 400);
        }

        $warranty->update(['status' => WarrantyStatus::Voided]);

        return response()->json([
            'success' => true,
            'message' => 'Warranty voided successfully.',
            'data' => new WarrantyResource($warranty),
        ]);
    }

    public function forDevice(Device $device): JsonResponse
    {
        $this->authorize('view', $device);

        $warranty = $device->warranties()
            ->with('claims')
            ->latest()
            ->first();

        if (! $warranty) {
            return response()->json([
                'success' => false,
                'message' => 'No warranty found for this device.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new WarrantyResource($warranty),
        ]);
    }
}
