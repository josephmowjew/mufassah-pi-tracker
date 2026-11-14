<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\DeviceCategory\StoreCategoryRequest;
use App\Http\Requests\Api\V1\DeviceCategory\UpdateCategoryRequest;
use App\Http\Resources\DeviceCategoryResource;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Support\LikePattern;
use App\Traits\PaginatesLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Device Categories
 *
 * APIs for device categories - browse available device types.
 */
class DeviceCategoryController extends BaseController
{
    use PaginatesLists;

    /**
     * List all active device categories.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DeviceCategory::query();
        $branchId = $this->resolveStockBranchId($request);

        // Only show active categories for non-admin users
        if (! $request->user()?->hasRole(['super_admin', 'store_admin'])) {
            $query->active();
        }

        // Optionally include device count
        if ($request->has('with_count')) {
            $query->withCount(['devices' => function ($deviceQuery) use ($branchId) {
                $deviceQuery->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
            }]);
        }

        // Optionally include available (in-stock) device count for sales
        if ($request->boolean('with_stock')) {
            $query->withCount(['devices as available_count' => function ($q) use ($branchId) {
                $q->whereIn('inventory_status', ['in_stock', 'returned']);
                $q->whereNull('stock_transfer_request_id');
                $q->when($branchId, fn ($query) => $query->where('branch_id', $branchId));
            }]);
        }

        // Search functionality
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereRaw('name LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)])
                    ->orWhereRaw('description LIKE ? '.LikePattern::SQL_ESCAPE, [LikePattern::contains($search)]);
            });
        }

        // Check if pagination is requested
        $perPage = $this->perPage($request);

        if ($request->has('page') || $request->has('per_page')) {
            $categories = $query->orderBy('name')->paginate($perPage);

            return $this->paginated(DeviceCategoryResource::collection($categories));
        }

        $categories = $query->orderBy('name')->get();

        return $this->success(DeviceCategoryResource::collection($categories));
    }

    /**
     * View a specific category.
     */
    public function show(DeviceCategory $deviceCategory): JsonResponse
    {
        return $this->success(new DeviceCategoryResource($deviceCategory));
    }

    private function resolveStockBranchId(Request $request): ?int
    {
        $user = $request->user();

        if ($user?->hasRole(['customer_service', 'store_admin'])) {
            return $user->requiredBranchScopeId();
        }

        return null;
    }

    /**
     * Create a new category (admin only).
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['slug'] = $request->slug ?? \Illuminate\Support\Str::slug($data['name']);
        $data['is_active'] = $data['is_active'] ?? true;

        $category = DeviceCategory::create($data);

        return $this->created(
            new DeviceCategoryResource($category),
            'Category created successfully.'
        );
    }

    /**
     * Update a category (admin only).
     */
    public function update(UpdateCategoryRequest $request, DeviceCategory $deviceCategory): JsonResponse
    {
        $deviceCategory->update($request->validated());

        return $this->success(
            new DeviceCategoryResource($deviceCategory),
            'Category updated successfully.'
        );
    }

    /**
     * Delete a category (admin only).
     */
    public function destroy(DeviceCategory $deviceCategory): JsonResponse
    {
        $this->authorize('delete', $deviceCategory);

        // The category foreign keys on devices and inventory_batches are ON DELETE
        // CASCADE, and the one on inventory_movements is ON DELETE SET NULL. A hard
        // delete would therefore destroy stock rows and erase the ledger's category
        // attribution. Soft deletes never reach a foreign key, but the guard still has
        // to cover every child the database would act on - it used to check devices
        // only, so a category holding batches passed and those were left unguarded.
        $linkedRecords = [
            'devices' => $deviceCategory->devices()->count(),
            'inventory_batches' => InventoryBatch::where('category_id', $deviceCategory->id)->count(),
            'inventory_movements' => InventoryMovement::where('category_id', $deviceCategory->id)->count(),
        ];

        if (collect($linkedRecords)->contains(fn (int $count) => $count > 0)) {
            // `linked_records` sits at the top level to match BranchController::destroy,
            // so a client can render "what is blocking this delete" uniformly.
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete category with existing devices, inventory batches, or stock movements. Please reassign them first.',
                'linked_records' => $linkedRecords,
            ], 400);
        }

        $deviceCategory->delete();

        return $this->success(null, 'Category deleted successfully.');
    }
}
