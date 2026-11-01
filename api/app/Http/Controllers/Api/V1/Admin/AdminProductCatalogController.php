<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Device;
use App\Models\InventoryActionChallenge;
use App\Models\InventoryBatch;
use App\Models\ProductModel;
use App\Rules\UniqueUnlessDeleted;
use App\Services\CatalogDeletionImpactService;
use App\Services\InventoryActionApprovalService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminProductCatalogController extends BaseController
{
    public function __construct(
        private readonly CatalogDeletionImpactService $deletionImpact,
        private readonly InventoryActionApprovalService $approvalService,
    ) {}

    // =========================================================================
    // BRANDS
    // =========================================================================

    /**
     * List all brands with optional filtering.
     */
    public function brandIndex(Request $request): JsonResponse
    {
        $query = Brand::query()
            ->with('category:id,name');

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        // Include product models count
        if ($request->boolean('with_models_count')) {
            $query->withCount('productModels');
        }

        // Include devices count
        if ($request->boolean('with_devices_count')) {
            $query->addSelect(['devices_count' => $this->brandInventoryCountQuery()]);
        }

        $brands = $query->ordered()->get();

        return $this->success($brands);
    }

    /**
     * Create a new brand.
     */
    public function brandStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // A soft-deleted category must not be usable as a parent.
            'category_id' => ['required', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
            'name' => [
                'required',
                'string',
                'max:100',
                new UniqueUnlessDeleted(Brand::class, 'name', 'brand', [
                    'category_id' => $request->category_id,
                ]),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                // The slug is derived from the name when omitted, so validate
                // the effective value — a trashed brand still holds its slug.
                function (string $attribute, mixed $value, Closure $fail) use ($request) {
                    $slug = $value ?: Str::slug((string) $request->name);

                    if ($slug !== '') {
                        (new UniqueUnlessDeleted(Brand::class, 'slug', 'brand'))->validate($attribute, $slug, $fail);
                    }
                },
            ],
            'logo' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $brand = Brand::create($validated);
        $brand->load('category:id,name');

        return $this->success($brand, 'Brand created successfully', 201);
    }

    /**
     * Get a specific brand.
     */
    public function brandShow(Brand $brand): JsonResponse
    {
        $brand->load(['category:id,name', 'productModels' => function ($query) {
            $query->ordered();
        }]);
        $brand->loadCount('productModels');
        $brand->setAttribute('devices_count', Device::query()
            ->where('category_id', $brand->category_id)
            ->where('brand', $brand->name)
            ->count());

        return $this->success($brand);
    }

    /**
     * Update a brand.
     */
    public function brandUpdate(Request $request, Brand $brand): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('brands')->where(function ($query) use ($request, $brand) {
                    return $query->where('category_id', $request->category_id ?? $brand->category_id);
                })->ignore($brand->id),
            ],
            'slug' => ['nullable', 'string', 'max:100', Rule::unique('brands', 'slug')->ignore($brand->id)],
            'logo' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $brand->update($validated);
        $brand->load('category:id,name');

        return $this->success($brand, 'Brand updated successfully');
    }

    /**
     * Preview what deleting a brand would do. Read-only, no PIN required.
     */
    public function brandDeletionImpact(Brand $brand): JsonResponse
    {
        return $this->success($this->deletionImpact->forBrand($brand));
    }

    /**
     * Delete a brand.
     *
     * A brand with no linked devices only removes catalog rows, so it still
     * deletes outright. Once devices are in scope the operation is
     * destructive and stock-affecting, so it needs an explicit impact
     * confirmation and a PIN-verified challenge before it runs.
     */
    public function brandDestroy(Request $request, Brand $brand): JsonResponse
    {
        $impact = $this->deletionImpact->forBrand($brand);

        if ($impact['devices']['total'] === 0) {
            $brand->delete();

            return $this->success(null, 'Brand deleted successfully');
        }

        if (! $request->user()?->hasRole('super_admin')) {
            return $this->error('Cannot delete brand with existing devices. Please reassign or delete the devices first.', 400);
        }

        return $this->requestCatalogDeletion(
            $request,
            $impact,
            InventoryActionChallenge::ACTION_BRAND_DELETION,
            ['brand_id' => $brand->id],
            $brand->name,
        );
    }

    // =========================================================================
    // PRODUCT MODELS
    // =========================================================================

    /**
     * List all product models with optional filtering.
     */
    public function modelIndex(Request $request): JsonResponse
    {
        $query = ProductModel::query()
            ->with(['brand:id,name,category_id', 'brand.category:id,name']);

        // Filter by brand
        if ($request->has('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        // Filter by category (through brand)
        if ($request->has('category_id')) {
            $query->whereHas('brand', function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        // Include devices count
        if ($request->boolean('with_devices_count')) {
            $query->addSelect(['devices_count' => $this->modelInventoryCountQuery()]);
        }

        $models = $query->ordered()->get();

        return $this->success($models);
    }

    /**
     * Create a new product model.
     */
    public function modelStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // A soft-deleted brand must not be usable as a parent.
            'brand_id' => ['required', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'name' => [
                'required',
                'string',
                'max:150',
                new UniqueUnlessDeleted(ProductModel::class, 'name', 'product model', [
                    'brand_id' => $request->brand_id,
                ]),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:150',
                // Slug is unique per brand and derived from the name when
                // omitted, so validate the effective value.
                function (string $attribute, mixed $value, Closure $fail) use ($request) {
                    $slug = $value ?: Str::slug((string) $request->name);

                    if ($slug !== '') {
                        (new UniqueUnlessDeleted(ProductModel::class, 'slug', 'product model', [
                            'brand_id' => $request->brand_id,
                        ]))->validate($attribute, $slug, $fail);
                    }
                },
            ],
            'description' => 'nullable|string|max:2000',
            'image' => 'nullable|string|max:255',
            'base_price' => 'nullable|numeric|min:0',
            'specifications' => 'nullable|array',
            'available_colors' => 'nullable|array',
            'available_colors.*' => 'string|max:50',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $model = ProductModel::create($validated);
        $model->load(['brand:id,name,category_id', 'brand.category:id,name']);

        return $this->success($model, 'Product model created successfully', 201);
    }

    /**
     * Get a specific product model.
     */
    public function modelShow(ProductModel $productModel): JsonResponse
    {
        $productModel->load(['brand:id,name,category_id', 'brand.category:id,name']);
        $productModel->setAttribute('devices_count', Device::query()
            ->where('category_id', $productModel->brand->category_id)
            ->where('brand', $productModel->brand->name)
            ->where('model', $productModel->name)
            ->count());

        return $this->success($productModel);
    }

    /**
     * Update a product model.
     */
    public function modelUpdate(Request $request, ProductModel $productModel): JsonResponse
    {
        $validated = $request->validate([
            'brand_id' => ['sometimes', 'required', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique('product_models')->where(function ($query) use ($request, $productModel) {
                    return $query->where('brand_id', $request->brand_id ?? $productModel->brand_id);
                })->ignore($productModel->id),
            ],
            'slug' => 'nullable|string|max:150',
            'description' => 'nullable|string|max:2000',
            'image' => 'nullable|string|max:255',
            'base_price' => 'nullable|numeric|min:0',
            'specifications' => 'nullable|array',
            'available_colors' => 'nullable|array',
            'available_colors.*' => 'string|max:50',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $oldPrice = $productModel->base_price;
        DB::transaction(function () use ($productModel, $validated, $request, $oldPrice) {
            $productModel->update($validated);

            $priceChanged = array_key_exists('base_price', $validated)
                && (string) $oldPrice !== (string) $productModel->base_price;

            // Clearing the catalog price must not cascade null into
            // inventory_batches.target_selling_price: the column is NOT NULL and
            // null would leave the stock unsellable.
            if (! $priceChanged || $productModel->base_price === null) {
                return;
            }

            $brand = $productModel->brand()->first();

            $batches = InventoryBatch::query()
                ->where(function ($query) use ($productModel, $brand) {
                    // Batches written since the catalog link exists match on id,
                    // which survives a rename. Older rows only carry the text
                    // identity and are matched as a fallback.
                    $query->where('product_model_id', $productModel->id)
                        ->orWhere(function ($legacy) use ($productModel, $brand) {
                            $legacy->whereNull('product_model_id')
                                ->where('category_id', $brand?->category_id)
                                ->where('brand', $brand?->name)
                                ->where('model', $productModel->name);
                        });
                })
                ->whereHas('devices', fn ($q) => $q->where('inventory_status', InventoryStatus::IN_STOCK))
                ->get();

            foreach ($batches as $batch) {
                $oldBatchPrice = $batch->target_selling_price;
                $affected = $batch->devices()->where('inventory_status', InventoryStatus::IN_STOCK)->count();
                $batch->update(['target_selling_price' => $productModel->base_price]);
                ActivityLog::create(['log_name' => 'price_management', 'description' => 'Catalog price cascaded to batch', 'subject_type' => InventoryBatch::class, 'subject_id' => $batch->id, 'causer_type' => get_class($request->user()), 'causer_id' => $request->user()->id, 'event' => 'catalog_price_cascade', 'properties' => ['old_price' => $oldBatchPrice, 'new_price' => $productModel->base_price, 'affected_in_stock_devices' => $affected]]);
            }
        });
        $productModel->load(['brand:id,name,category_id', 'brand.category:id,name']);

        return $this->success($productModel, 'Product model updated successfully');
    }

    /**
     * Preview what deleting a product model would do. Read-only, no PIN.
     */
    public function modelDeletionImpact(ProductModel $productModel): JsonResponse
    {
        return $this->success($this->deletionImpact->forModel($productModel));
    }

    /**
     * Delete a product model.
     *
     * A model with no devices still deletes outright. A model that owns
     * devices is a destructive, stock-affecting operation: the devices go
     * with it and their stock is written out of inventory, so the caller must
     * confirm the impact and clear a PIN challenge first.
     */
    public function modelDestroy(Request $request, ProductModel $productModel): JsonResponse
    {
        $impact = $this->deletionImpact->forModel($productModel);

        if ($impact['devices']['total'] === 0) {
            $productModel->delete();

            return $this->success(null, 'Product model deleted successfully');
        }

        if (! $request->user()?->hasRole('super_admin')) {
            return $this->error('Cannot delete product model with existing devices. Please reassign or delete the devices first.', 400);
        }

        return $this->requestCatalogDeletion(
            $request,
            $impact,
            InventoryActionChallenge::ACTION_PRODUCT_MODEL_DELETION,
            ['product_model_id' => $productModel->id],
            trim(($productModel->brand?->name ?? '').' '.$productModel->name),
        );
    }

    // =========================================================================
    // CATALOG OVERVIEW
    // =========================================================================

    /**
     * Get product catalog overview for dropdowns.
     * Returns hierarchical data: categories -> brands -> models
     */
    public function overview(Request $request): JsonResponse
    {
        $query = Brand::query()
            ->with(['category:id,name,slug', 'productModels' => function ($q) {
                $q->active()->ordered()->select(['id', 'brand_id', 'name', 'slug', 'base_price', 'available_colors']);
            }])
            ->active()
            ->ordered();

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $brands = $query->get();

        // Group by category
        $grouped = $brands->groupBy('category_id')->map(function ($categoryBrands) {
            $category = $categoryBrands->first()->category;

            return [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'category_slug' => $category->slug,
                'brands' => $categoryBrands->map(function ($brand) {
                    return [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'slug' => $brand->slug,
                        'logo' => $brand->logo,
                        'models' => $brand->productModels->map(function ($model) {
                            return [
                                'id' => $model->id,
                                'name' => $model->name,
                                'slug' => $model->slug,
                                'base_price' => $model->base_price,
                                'available_colors' => $model->available_colors,
                            ];
                        }),
                    ];
                }),
            ];
        })->values();

        return $this->success($grouped);
    }

    /**
     * Get brands for a specific category (for cascading dropdowns).
     */
    public function brandsByCategory(int $categoryId): JsonResponse
    {
        $brands = Brand::where('category_id', $categoryId)
            ->active()
            ->ordered()
            ->withCount(['productModels as models_count' => function ($q) {
                $q->active();
            }])
            ->get(['id', 'name', 'slug', 'logo']);

        return $this->success($brands);
    }

    /**
     * Get models for a specific brand (for cascading dropdowns).
     */
    public function modelsByBrand(int $brandId): JsonResponse
    {
        $models = ProductModel::where('brand_id', $brandId)
            ->active()
            ->ordered()
            ->get(['id', 'name', 'slug', 'base_price', 'available_colors']);

        return $this->success($models);
    }

    /**
     * Count the same text-based product identity used by the inventory ledger.
     *
     * Inventory records pre-date catalog foreign keys, and the intake workflow
     * keeps the human-readable brand/model values for sales and reporting.
     */
    private function brandInventoryCountQuery()
    {
        return Device::query()
            ->selectRaw('COUNT(*)')
            ->whereColumn('devices.category_id', 'brands.category_id')
            ->whereColumn('devices.brand', 'brands.name');
    }

    private function modelInventoryCountQuery()
    {
        return Device::query()
            ->selectRaw('COUNT(*)')
            ->join('brands as catalog_brands', 'catalog_brands.id', '=', 'product_models.brand_id')
            ->whereColumn('devices.category_id', 'catalog_brands.category_id')
            ->whereColumn('devices.brand', 'catalog_brands.name')
            ->whereColumn('devices.model', 'product_models.name');
    }

    /**
     * Shared override path for catalog deletions that would take devices with
     * them.
     *
     * Returns 409 carrying the full impact when the operator has not confirmed,
     * and otherwise opens a PIN challenge. The device set is captured into the
     * challenge payload so verification can detect drift instead of deleting a
     * different set of devices than the one that was reviewed.
     */
    private function requestCatalogDeletion(
        Request $request,
        array $impact,
        string $action,
        array $subjectKeys,
        string $subjectLabel,
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'confirm_impact' => ['sometimes', 'boolean'],
        ]);

        if (! ($validated['confirm_impact'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'This entry has devices. Deleting it also deletes those devices and writes their stock out of inventory.',
                'errors' => [
                    'confirm_impact' => ['Review the deletion impact and confirm to continue.'],
                ],
                'requires_confirmation' => true,
                'impact' => $impact,
            ], 409);
        }

        if (blank($validated['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => ['A reason is required to override a catalog deletion.'],
            ]);
        }

        $deviceIds = array_values(array_map(fn (array $device) => (int) $device['id'], $impact['detail']));

        if ($deviceIds === []) {
            throw ValidationException::withMessages([
                'confirm_impact' => ['This entry no longer has devices. Retry without confirmation.'],
            ]);
        }

        $targetLabel = $subjectLabel.' ('.(count($deviceIds) === 1 ? '1 device' : count($deviceIds).' devices').')';

        $payload = [
            ...$subjectKeys,
            'device_ids' => $deviceIds,
            'expected_device_count' => count($deviceIds),
            'subject_label' => $targetLabel,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
        ];

        $challenge = $this->approvalService->create(
            $request->user(),
            $action,
            $payload,
            Device::query()->findOrFail($deviceIds[0]),
            $targetLabel,
        );

        return response()->json([
            'success' => true,
            'message' => 'A verification PIN was sent by email and is available securely in notifications.',
            'data' => [
                'challenge_id' => $challenge->id,
                'action' => $challenge->action,
                'masked_email' => $this->approvalService->maskedEmail($this->approvalService->approvalEmail()),
                'expires_at' => $challenge->expires_at?->toLocalIso8601String(),
                'attempts_remaining' => max(0, $challenge->max_attempts - $challenge->attempts),
                'device_count' => count($deviceIds),
                'impact' => $impact,
            ],
        ], 202);
    }
}
