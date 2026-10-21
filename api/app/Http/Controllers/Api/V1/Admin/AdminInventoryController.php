<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DeviceStatus;
use App\Enums\InventoryStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Requests\Api\V1\Admin\BulkStoreDeviceRequest;
use App\Http\Requests\Api\V1\Admin\StoreInventoryReceiptRequest;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\InventoryBatchResource;
use App\Http\Resources\InventoryReceiptResource;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\InventoryBatch;
use App\Models\InventoryReceipt;
use App\Services\InventoryCatalogResolver;
use App\Services\InventoryMovementService;
use App\Services\InventoryPriceResolver;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminInventoryController extends BaseController
{
    public function __construct(
        private readonly InventoryMovementService $movementService,
        private readonly InventoryCatalogResolver $catalogResolver,
        private readonly InventoryPriceResolver $priceResolver,
    ) {}

    /**
     * List all devices in inventory with filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->resolveInventoryBranchId($request, $request->filled('branch_id') ? $request->integer('branch_id') : null);
        $query = Device::query()
            ->with(['category', 'iotModule', 'branch'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        // Filter by inventory status
        if ($request->has('inventory_status')) {
            $query->where('inventory_status', $request->inventory_status);
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Only show items in stock (available for sale)
        if ($request->boolean('available_only')) {
            $query->available();
            $query->whereNull('stock_transfer_request_id');
        }

        // Only show items in stock
        if ($request->boolean('in_stock_only')) {
            $query->inStock();
        }

        // Search by serial number, brand, model
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('unique_device_code', 'like', "%{$search}%");
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
     * Get inventory statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $branchId = $this->resolveInventoryBranchId($request, $request->filled('branch_id') ? $request->integer('branch_id') : null);
        $deviceScope = Device::query()->whereNull('stock_transfer_request_id')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $stats = [
            'total' => (clone $deviceScope)->count(),
            'in_stock' => (clone $deviceScope)->inStock()->count(),
            'reserved' => (clone $deviceScope)->where('inventory_status', InventoryStatus::RESERVED)->count(),
            'sold' => (clone $deviceScope)->sold()->count(),
            'returned' => (clone $deviceScope)->where('inventory_status', InventoryStatus::RETURNED)->count(),
            'faulty' => (clone $deviceScope)->where('inventory_status', InventoryStatus::FAULTY)->count(),
            'available' => (clone $deviceScope)->available()->count(),
        ];

        // Get stats by category
        $byCategory = (clone $deviceScope)
            ->selectRaw('category_id, inventory_status, COUNT(*) as count')
            ->groupBy('category_id', 'inventory_status')
            ->with('category:id,name')
            ->get()
            ->groupBy('category_id')
            ->map(function ($items) {
                $category = $items->first()->category;

                return [
                    'category_id' => $category?->id,
                    'category_name' => $category?->name ?? 'Uncategorized',
                    'counts' => $items->mapWithKeys(fn ($item) => [$item->inventory_status->value => $item->count]),
                ];
            })
            ->values();

        return $this->success([
            'summary' => $stats,
            'by_category' => $byCategory,
        ]);
    }

    /**
     * Get grouped inventory data by category, brand, model, and color.
     * Returns aggregated stock levels for each product combination.
     */
    public function grouped(Request $request): JsonResponse
    {
        $branchId = $this->resolveInventoryBranchId($request, $request->filled('branch_id') ? $request->integer('branch_id') : null);
        $query = Device::query()
            ->with('category:id,name')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        // Filter by inventory status
        if ($request->has('inventory_status')) {
            $query->where('inventory_status', $request->inventory_status);
        }

        if ($request->boolean('available_only')) {
            $query->available()->whereNull('stock_transfer_request_id');
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Search by brand or model
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        // Get all devices matching filters
        $devices = $query->get();

        // Group devices by category, brand, model, and color
        $grouped = $devices->groupBy(function ($device) {
            return $device->category_id.'|'.$device->brand.'|'.$device->model.'|'.($device->color ?? 'null');
        })->map(function ($group) {
            $first = $group->first();

            return [
                'category_id' => $first->category_id,
                'category_name' => $first->category?->name ?? 'Uncategorized',
                'brand' => $first->brand,
                'model' => $first->model,
                'color' => $first->color,
                'in_stock' => $group->where('inventory_status', InventoryStatus::IN_STOCK)->count(),
                'reserved' => $group->where('inventory_status', InventoryStatus::RESERVED)->count(),
                'sold' => $group->where('inventory_status', InventoryStatus::SOLD)->count(),
                'returned' => $group->where('inventory_status', InventoryStatus::RETURNED)->count(),
                'faulty' => $group->where('inventory_status', InventoryStatus::FAULTY)->count(),
                'available' => $group->whereIn('inventory_status', [InventoryStatus::IN_STOCK, InventoryStatus::RETURNED])->count(),
                'total' => $group->count(),
                'avg_price' => round($group->whereNotNull('purchase_price')->avg('purchase_price') ?? 0, 2),
                'min_price' => $group->whereNotNull('purchase_price')->min('purchase_price'),
                'max_price' => $group->whereNotNull('purchase_price')->max('purchase_price'),
            ];
        })->values();

        // Sort by category, brand, model
        $sorted = $grouped->sortBy([
            ['category_name', 'asc'],
            ['brand', 'asc'],
            ['model', 'asc'],
            ['color', 'asc'],
        ])->values();

        return $this->success([
            'data' => $sorted,
            'total' => $sorted->count(),
        ]);
    }

    /**
     * Get cascading product options (brands, models, colors) for hierarchical selection.
     * Used by sales interface to build smart dropdowns.
     */
    public function productOptions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'brand' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:150',
        ]);

        $categoryId = $validated['category_id'];
        $branchId = $this->resolveInventoryBranchId($request, $validated['branch_id'] ?? null);
        $brand = $validated['brand'] ?? null;
        $model = $validated['model'] ?? null;

        // Base query: available devices in the selected category and branch
        $baseQuery = Device::where('category_id', $categoryId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNull('stock_transfer_request_id')
            ->available();

        // Get brands (filtered by category only)
        $brands = (clone $baseQuery)
            ->selectRaw('brand, COUNT(*) as count')
            ->groupBy('brand')
            ->orderBy('brand')
            ->get()
            ->map(fn ($item) => ['name' => $item->brand, 'count' => $item->count]);

        // Get models (filtered by category + brand if provided)
        $modelsQuery = clone $baseQuery;
        if ($brand) {
            $modelsQuery->where('brand', $brand);
        }
        $models = $modelsQuery
            ->selectRaw('model, COUNT(*) as count')
            ->groupBy('model')
            ->orderBy('model')
            ->get()
            ->map(fn ($item) => ['name' => $item->model, 'count' => $item->count]);

        // Get colors (filtered by category + brand + model if provided)
        $colorsQuery = clone $baseQuery;
        if ($brand) {
            $colorsQuery->where('brand', $brand);
        }
        if ($model) {
            $colorsQuery->where('model', $model);
        }
        $colors = $colorsQuery
            ->selectRaw('color, COUNT(*) as count')
            ->groupBy('color')
            ->orderByRaw('color IS NULL, color')
            ->get()
            ->map(fn ($item) => ['name' => $item->color, 'count' => $item->count]);

        // Check if any devices in this selection have colors
        $hasColors = $colors->filter(fn ($item) => $item['name'] !== null)->isNotEmpty();

        // Get total available count for current filters
        $availableCount = (clone $baseQuery)
            ->when($brand, fn ($q) => $q->where('brand', $brand))
            ->when($model, fn ($q) => $q->where('model', $model))
            ->count();

        // A batch owns the selling price of devices received into it. Return the
        // FIFO tiers so the POS can explain mixed-batch prices before checkout.
        $priceTiers = collect();
        if ($brand && $model) {
            $priceTiers = $this->applyFifoOrder((clone $baseQuery)
                ->where('brand', $brand)
                ->where('model', $model)
                ->with('inventoryBatch:id,batch_number,buying_price,target_selling_price'))
                ->get()
                ->map(function (Device $device): array {
                    [$sellingPrice, $pricingSource] = $this->resolveProductOptionPrice($device);
                    $formattedPrice = $sellingPrice !== null
                        ? number_format($sellingPrice, 2, '.', '')
                        : null;

                    return [
                        'device' => $device,
                        'group_key' => $device->inventory_batch_id !== null
                            ? sprintf('batch:%d:%s', $device->inventory_batch_id, $formattedPrice ?? 'unpriced')
                            : sprintf('purchase:%s', $formattedPrice ?? 'unpriced'),
                        'selling_price' => $formattedPrice,
                        'pricing_source' => $pricingSource,
                    ];
                })
                ->groupBy('group_key')
                ->map(function ($devices): array {
                    $first = $devices->first();
                    $device = $first['device'];

                    return [
                        'batch_id' => $device->inventory_batch_id,
                        'batch_number' => $device->inventoryBatch?->batch_number,
                        'available_quantity' => $devices->count(),
                        'selling_price' => $first['selling_price'],
                        'pricing_source' => $first['pricing_source'],
                    ];
                })->values();
        }
        $sellingPrice = $priceTiers->first()['selling_price'] ?? null;

        return $this->success([
            'brands' => $brands,
            'models' => $models,
            'colors' => $colors,
            'has_colors' => $hasColors,
            'available_count' => $availableCount,
            'selling_price' => $sellingPrice !== null ? round((float) $sellingPrice, 2) : null,
            'pricing_source' => $priceTiers->first()['pricing_source'] ?? null,
            'price_tiers' => $priceTiers,
        ]);
    }

    /**
     * Prices a new intake would inherit for a product.
     *
     * Read-only helper for the receiving screen: it shows the price already in
     * use so the operator does not have to retype it for every delivery. The
     * same resolver runs server-side on intake, so a client that skips this
     * call still gets the inherited price.
     */
    public function priceSuggestion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->whereNull('deleted_at')],
        ]);

        $branchId = $this->resolveInventoryBranchId($request, $validated['branch_id'] ?? null);

        return $this->success($this->priceResolver->resolve(
            (int) $validated['category_id'],
            $validated['brand'],
            $validated['model'],
            $branchId,
        ));
    }

    public function batches(Request $request): JsonResponse
    {
        $branchId = $this->resolveInventoryBranchId($request, $request->filled('branch_id') ? $request->integer('branch_id') : null);
        $query = InventoryBatch::query()
            ->with(['branch', 'category', 'devices.sale.items'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('batch_number', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('purchase_location', 'like', "%{$search}%");
            });
        }

        $perPage = $request->get('per_page', 15);
        $batches = $query->latest()->paginate($perPage);

        return $this->paginated(InventoryBatchResource::collection($batches));
    }

    public function showBatch(Request $request, InventoryBatch $batch): JsonResponse
    {
        $this->authorizeInventoryBranch($request, $batch->branch_id);

        $batch->load([
            'receipt.branch',
            'receipt.receivedBy',
            'branch',
            'category',
            'devices.category',
            'devices.owner',
            'devices.soldBy',
            'devices.sale.items',
        ]);

        return $this->success(new InventoryBatchResource($batch));
    }

    public function updateBatchSellingPrice(Request $request, InventoryBatch $batch): JsonResponse
    {
        $this->authorizeInventoryBranch($request, $batch->branch_id);
        $validated = $request->validate(['selling_price' => 'required|numeric|min:0']);
        $inStock = $batch->devices()->where('inventory_status', InventoryStatus::IN_STOCK)->count();
        if ($inStock === 0) {
            return $this->error('This batch has no in-stock devices to reprice.', 422);
        }
        $oldPrice = $batch->target_selling_price;
        $batch->update(['target_selling_price' => $validated['selling_price']]);
        $this->logActivity($batch, $request->user(), 'batch_selling_price_updated', 'Batch selling price updated', [
            'old_price' => $oldPrice, 'new_price' => $validated['selling_price'], 'affected_in_stock_devices' => $inStock,
        ]);

        return $this->success(new InventoryBatchResource($batch->fresh()), 'Selling price updated for future sales of in-stock devices.');
    }

    public function receipts(Request $request): JsonResponse
    {
        $branchId = $this->resolveInventoryBranchId($request, $request->filled('branch_id') ? $request->integer('branch_id') : null);
        $query = InventoryReceipt::query()
            ->with(['branch', 'receivedBy', 'batches.devices.sale.items'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('supplier', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhere('purchase_location', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $receipts = $query->latest()->paginate($perPage);

        return $this->paginated(InventoryReceiptResource::collection($receipts));
    }

    public function showReceipt(Request $request, InventoryReceipt $receipt): JsonResponse
    {
        $this->authorizeInventoryBranch($request, $receipt->branch_id);

        $receipt->load(['branch', 'receivedBy', 'batches.branch', 'batches.category', 'batches.devices.category', 'batches.devices.sale.items']);

        return $this->success(new InventoryReceiptResource($receipt));
    }

    /**
     * Add a new device to inventory.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'serial_number' => 'required|string|max:100|unique:devices,serial_number',
            'category_id' => ['required', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
            'branch_id' => ['required', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'iot_module_id' => ['nullable', Rule::exists('iot_modules', 'id')->whereNull('deleted_at')],
            'brand' => 'required|string|max:100',
            'model' => 'required|string|max:100',
            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:1000',
            'purchase_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric|min:0',
            'purchase_location' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $validated['branch_id'] = $this->resolveInventoryWriteBranchId($request, (int) $validated['branch_id']);
        $catalog = $this->catalogResolver->resolve(
            (int) $validated['category_id'],
            $validated['brand'],
            $validated['model'],
        );

        // The ledger entry is what makes the device auditable. A device row with
        // no opening movement corrupts every stock-movement total from then on,
        // so the pair is written atomically.
        $device = DB::transaction(function () use ($validated, $catalog, $request) {
            $device = Device::create([
                ...$validated,
                ...$catalog,
                'unique_device_code' => $this->generateDeviceCode(),
                'status' => DeviceStatus::INACTIVE,
                'inventory_status' => InventoryStatus::IN_STOCK,
            ]);

            $this->movementService->recordDeviceMovement($device, [
                'movement_type' => \App\Models\InventoryMovement::TYPE_RECEIPT,
                'branch_id' => $device->branch_id,
                'to_branch_id' => $device->branch_id,
                'new_status' => InventoryStatus::IN_STOCK->value,
                'reference_type' => Device::class,
                'reference_id' => $device->id,
                'created_by_id' => $request->user()?->id,
            ]);

            return $device;
        });

        $device->load(['category', 'iotModule', 'branch']);

        return $this->success(new DeviceResource($device), 'Device added to inventory successfully', 201);
    }

    /**
     * Add multiple devices of the same product to inventory in bulk.
     */
    public function bulkStore(BulkStoreDeviceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['branch_id'] = $this->resolveInventoryWriteBranchId($request, (int) $validated['branch_id']);

        DB::beginTransaction();
        try {
            [$batch, $devices] = $this->createInventoryBatchDevices($validated['branch_id'], $validated);
            foreach ($devices as $device) {
                $this->movementService->recordReceipt($device, $batch, null, $request->user());
            }

            DB::commit();
        } catch (ValidationException $e) {
            // A business-rule failure is not a server fault. Roll back and let the
            // global handler render the documented 422 with its field errors —
            // the \Exception arm below would flatten it into a generic 500.
            DB::rollBack();

            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk inventory creation failed', [
                'error' => $e->getMessage(),
                'category_id' => $validated['category_id'] ?? null,
                'branch_id' => $validated['branch_id'] ?? null,
                'quantity' => $validated['quantity'] ?? null,
            ]);

            return $this->error('Failed to create inventory batch. Please check the request and try again.', 500);
        }

        // Load relationships
        $devicesCollection = Device::whereIn('id', array_map(fn ($d) => $d->id, $devices))
            ->with(['category', 'iotModule', 'branch'])
            ->get();

        $this->logActivity($batch, $request->user(), 'inventory_intake', 'Inventory batch received', [
            'quantity_received' => $validated['quantity'],
            'buying_price' => $validated['buying_price'],
            // Log what the batch actually carries: the caller may have omitted
            // the selling price and had one inherited for them.
            'target_selling_price' => $batch->target_selling_price,
        ]);

        return $this->success([
            'devices' => DeviceResource::collection($devicesCollection),
            'batch' => new InventoryBatchResource($batch->load(['branch', 'category', 'devices.sale.items'])),
            'count' => count($devices),
        ], "{$validated['quantity']} devices added to inventory successfully", 201);
    }

    public function storeReceipt(StoreInventoryReceiptRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['branch_id'] = $this->resolveInventoryWriteBranchId($request, (int) $validated['branch_id']);
        $receipt = null;
        $batches = collect();
        $deviceIds = [];

        DB::beginTransaction();
        try {
            $receipt = $this->createReceiptWithRetry([
                'branch_id' => $validated['branch_id'],
                'supplier' => $validated['supplier'] ?? null,
                'reference_number' => $validated['reference_number'] ?? null,
                'purchase_date' => $validated['purchase_date'] ?? null,
                'purchase_location' => $validated['purchase_location'] ?? null,
                'status' => 'received',
                'notes' => $validated['notes'] ?? null,
                'received_by_id' => $request->user()?->id,
                'received_at' => now(),
            ]);

            foreach ($validated['lines'] as $index => $line) {
                [$batch, $devices] = $this->createInventoryBatchDevices(
                    $validated['branch_id'],
                    [
                        ...$line,
                        'purchase_date' => $validated['purchase_date'] ?? null,
                        'purchase_location' => $validated['purchase_location'] ?? null,
                    ],
                    $receipt,
                    "lines.{$index}.target_selling_price",
                );

                $batches->push($batch);
                array_push($deviceIds, ...array_map(fn (Device $device) => $device->id, $devices));
                foreach ($devices as $device) {
                    $this->movementService->recordReceipt($device, $batch, $receipt, $request->user());
                }
            }

            DB::commit();
        } catch (ValidationException $e) {
            // Same reasoning as bulkStore(): keep validation failures out of the
            // 500 path so the operator sees which line was rejected.
            DB::rollBack();

            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Inventory receipt creation failed', [
                'error' => $e->getMessage(),
                'branch_id' => $validated['branch_id'] ?? null,
                'lines' => count($validated['lines'] ?? []),
            ]);

            return $this->error('Failed to receive inventory. Please check the request and try again.', 500);
        }

        $receipt->load(['branch', 'receivedBy', 'batches.branch', 'batches.category', 'batches.devices.sale.items']);

        $this->logActivity($receipt, $request->user(), 'inventory_receipt', 'Inventory receipt received', [
            'lines' => $batches->count(),
            'units' => $receipt->summary()['units'],
        ]);

        return $this->success([
            'receipt' => new InventoryReceiptResource($receipt),
            'batches' => InventoryBatchResource::collection($receipt->batches),
            'count' => count($deviceIds),
        ], "{$receipt->summary()['units']} devices received across {$batches->count()} product lines", 201);
    }

    /**
     * Update inventory status of a device.
     */
    public function updateStatus(Request $request, Device $device): JsonResponse
    {
        $this->authorizeInventoryBranch($request, $device->branch_id);

        $validated = $request->validate([
            // A sale is a financial disposition and must go through the
            // PIN-protected administrative-sale endpoint.
            'inventory_status' => ['required', Rule::in([
                InventoryStatus::IN_STOCK->value,
                InventoryStatus::RESERVED->value,
                InventoryStatus::RETURNED->value,
                InventoryStatus::FAULTY->value,
            ])],
            'notes' => 'nullable|string|max:500',
        ]);

        // Cannot change status of sold device without proper refund
        if ($device->isSold() && $validated['inventory_status'] !== InventoryStatus::SOLD->value) {
            return $this->error('Cannot change status of sold device. Process a refund instead.', 400);
        }

        $previousStatus = $device->inventory_status?->value;

        $device->update([
            'inventory_status' => $validated['inventory_status'],
            'notes' => $validated['notes'] ?? $device->notes,
        ]);
        $this->movementService->recordStatusChange(
            $device->fresh(),
            $previousStatus,
            $validated['inventory_status'],
            $request->user(),
            'Manual inventory status update',
            $validated['notes'] ?? null
        );
        $this->logActivity($device, $request->user(), 'inventory_status_updated', 'Inventory status updated', [
            'inventory_status' => $validated['inventory_status'],
        ]);

        $device->load(['category', 'iotModule']);

        return $this->success(new DeviceResource($device), 'Inventory status updated successfully');
    }

    /**
     * Mark a device as faulty.
     */
    public function markFaulty(Request $request, Device $device): JsonResponse
    {
        $this->authorizeInventoryBranch($request, $device->branch_id);

        if ($device->isSold()) {
            return $this->error('Cannot mark sold device as faulty. Process a return first.', 400);
        }

        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $previousStatus = $device->inventory_status?->value;

        $device->update([
            'inventory_status' => InventoryStatus::FAULTY,
            'notes' => $validated['notes'],
        ]);
        $this->movementService->recordStatusChange(
            $device->fresh(),
            $previousStatus,
            InventoryStatus::FAULTY->value,
            $request->user(),
            'Device marked faulty',
            $validated['notes']
        );
        $this->logActivity($device, $request->user(), 'inventory_status_updated', 'Device marked faulty', [
            'inventory_status' => InventoryStatus::FAULTY->value,
        ]);

        $device->load(['category', 'iotModule']);

        return $this->success(new DeviceResource($device), 'Device marked as faulty');
    }

    /**
     * Generate a unique device code.
     */
    private function generateDeviceCode(): string
    {
        do {
            $code = 'DEV-'.strtoupper(bin2hex(random_bytes(4)));
        } while (Device::where('unique_device_code', $code)->exists());

        return $code;
    }

    /**
     * Generate a unique serial number based on brand and model.
     */
    private function generateSerialNumber(string $brand, string $model): string
    {
        // Create prefix from brand and model initials
        $brandPrefix = strtoupper(substr($brand, 0, 3));
        $modelPrefix = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $model), 0, 3));
        $prefix = $brandPrefix.$modelPrefix;

        // Generate unique serial with prefix
        do {
            $randomPart = strtoupper(bin2hex(random_bytes(3)));
            $serial = $prefix.'-'.$randomPart;
        } while (Device::where('serial_number', $serial)->exists());

        return $serial;
    }

    private function resolveInventoryBranchId(Request $request, ?int $requestedBranchId): ?int
    {
        $user = $request->user();

        if ($user?->hasRole('super_admin')) {
            if ($request->boolean('all_branches')) {
                return null;
            }

            return $requestedBranchId ?: ($user->branch_id ? (int) $user->branch_id : null);
        }

        return $user?->requiredBranchScopeId();
    }

    private function resolveInventoryWriteBranchId(Request $request, int $requestedBranchId): int
    {
        $user = $request->user();

        if ($user?->hasRole('super_admin')) {
            return $requestedBranchId;
        }

        $branchId = $user?->branchScopeId();

        if (! $branchId) {
            abort(403, 'Your account must be assigned to a branch before managing inventory.');
        }

        if ($branchId !== $requestedBranchId) {
            abort(403, 'You cannot manage inventory for another branch.');
        }

        return $branchId;
    }

    private function authorizeInventoryBranch(Request $request, ?int $branchId): void
    {
        $user = $request->user();

        if ($user?->hasRole('super_admin')) {
            return;
        }

        $userBranchId = $user?->branchScopeId();
        if ($userBranchId && (int) $userBranchId === (int) $branchId) {
            return;
        }

        abort(403, 'You cannot access inventory for another branch.');
    }

    private function applyFifoOrder($query)
    {
        return $query
            ->orderByRaw('purchase_date IS NULL')
            ->orderBy('purchase_date')
            ->orderByRaw('inventory_batch_id IS NULL')
            ->orderBy('inventory_batch_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /** @return array{0: float|null, 1: string|null} */
    private function resolveProductOptionPrice(Device $device): array
    {
        $batchPrice = $device->inventoryBatch?->target_selling_price;
        if ($batchPrice !== null && (float) $batchPrice > 0) {
            return [round((float) $batchPrice, 2), 'batch_target_selling_price'];
        }

        $purchaseCost = $device->purchase_price;
        if ($purchaseCost === null || (float) $purchaseCost <= 0) {
            $purchaseCost = $device->inventoryBatch?->buying_price;
        }
        if ($purchaseCost !== null && (float) $purchaseCost > 0) {
            return [round((float) $purchaseCost, 2), 'purchase_cost'];
        }

        return [null, null];
    }

    private function createBatchWithRetry(array $attributes): InventoryBatch
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return InventoryBatch::create([
                    ...$attributes,
                    'batch_number' => InventoryBatch::generateBatchNumber(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e) || $attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to allocate an inventory batch number.');
    }

    private function createReceiptWithRetry(array $attributes): InventoryReceipt
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return InventoryReceipt::create([
                    ...$attributes,
                    'receipt_number' => InventoryReceipt::generateReceiptNumber(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e) || $attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to allocate an inventory receipt number.');
    }

    /**
     * @return array{0: InventoryBatch, 1: array<int, Device>}
     */
    private function createInventoryBatchDevices(int $branchId, array $line, ?InventoryReceipt $receipt = null, string $sellingPriceErrorKey = 'target_selling_price'): array
    {
        $catalog = $this->catalogResolver->resolve(
            (int) $line['category_id'],
            $line['brand'],
            $line['model'],
        );
        $line = array_merge($line, $catalog);

        // Intake may omit the selling price so that stock received today keeps
        // the price already in use for this product. The buying price is never
        // inferred — it is real cost data for this specific delivery.
        $targetSellingPrice = $line['target_selling_price'] ?? null;

        if ($targetSellingPrice === null) {
            $targetSellingPrice = $this->priceResolver->fallbackSellingPrice(
                (int) $line['category_id'],
                $line['brand'],
                $line['model'],
                $branchId,
            );

            if ($targetSellingPrice === null) {
                throw ValidationException::withMessages([
                    $sellingPriceErrorKey => "No selling price was given for {$line['brand']} {$line['model']} and none could be inherited from earlier stock. Enter a target selling price for this line.",
                ]);
            }
        }

        $quantity = (int) $line['quantity'];
        $autoGenerate = $line['auto_generate_serials'] ?? true;
        $providedSerials = $line['serial_numbers'] ?? [];

        $batch = $this->createBatchWithRetry([
            'inventory_receipt_id' => $receipt?->id,
            'branch_id' => $branchId,
            'category_id' => $line['category_id'],
            // Keep the catalog link alongside the text fields: price lookups
            // and the catalog reprice cascade match on this id, not on text.
            'product_model_id' => $line['product_model_id'] ?? null,
            'brand' => $line['brand'],
            'model' => $line['model'],
            'color' => $line['color'] ?? null,
            'quantity_received' => $quantity,
            'buying_price' => $line['buying_price'],
            'target_selling_price' => $targetSellingPrice,
            'purchase_date' => $line['purchase_date'] ?? null,
            'purchase_location' => $line['purchase_location'] ?? null,
            'notes' => $line['notes'] ?? null,
        ]);

        $devices = [];
        for ($i = 0; $i < $quantity; $i++) {
            $serialNumber = $autoGenerate
                ? $this->generateSerialNumber($line['brand'], $line['model'])
                : $providedSerials[$i];

            $devices[] = Device::create([
                'category_id' => $line['category_id'],
                'brand_id' => $line['brand_id'],
                'product_model_id' => $line['product_model_id'],
                'branch_id' => $branchId,
                'iot_module_id' => $line['iot_module_id'] ?? null,
                'brand' => $line['brand'],
                'model' => $line['model'],
                'color' => $line['color'] ?? null,
                'description' => $line['description'] ?? null,
                'purchase_date' => $line['purchase_date'] ?? null,
                'purchase_price' => $line['buying_price'],
                'purchase_location' => $line['purchase_location'] ?? null,
                'notes' => $line['notes'] ?? null,
                'status' => DeviceStatus::INACTIVE,
                'inventory_status' => InventoryStatus::IN_STOCK,
                'inventory_batch_id' => $batch->id,
                'serial_number' => $serialNumber,
                'unique_device_code' => $this->generateDeviceCode(),
            ]);
        }

        return [$batch, $devices];
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true) || $driverCode === '1062';
    }

    private function logActivity($subject, $user, string $event, string $description, array $properties = []): void
    {
        try {
            ActivityLog::create([
                'log_name' => 'inventory',
                'description' => $description,
                'subject_type' => get_class($subject),
                'subject_id' => $subject->id,
                'causer_type' => $user ? get_class($user) : null,
                'causer_id' => $user?->id,
                'properties' => $properties,
                'event' => $event,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write inventory activity log', [
                'subject_type' => is_object($subject) ? get_class($subject) : null,
                'subject_id' => $subject->id ?? null,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
