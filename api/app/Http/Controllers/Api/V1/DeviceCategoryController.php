<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\DeviceCategory\StoreCategoryRequest;
use App\Http\Requests\Api\V1\DeviceCategory\UpdateCategoryRequest;
use App\Http\Resources\DeviceCategoryResource;
use App\Models\DeviceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Device Categories
 *
 * APIs for device categories - browse available device types.
 */
class DeviceCategoryController extends BaseController
{
    /**
     * List all active device categories.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DeviceCategory::query();

        // Only show active categories for non-admin users
        if (! $request->user()?->hasRole(['super_admin', 'store_admin'])) {
            $query->active();
        }

        // Optionally include device count
        if ($request->has('with_count')) {
            $query->withCount('devices');
        }

        // Optionally include available (in-stock) device count for sales
        if ($request->boolean('with_stock')) {
            $query->withCount(['devices as available_count' => function ($q) {
                $q->whereIn('inventory_status', ['in_stock', 'returned']);
            }]);
        }

        // Search functionality
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Check if pagination is requested
        $perPage = $request->get('per_page', 15);

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

        // Check if category has devices
        if ($deviceCategory->devices()->exists()) {
            return $this->error(
                'Cannot delete category with existing devices. Please reassign or delete the devices first.',
                400
            );
        }

        $deviceCategory->delete();

        return $this->success(null, 'Category deleted successfully.');
    }
}
