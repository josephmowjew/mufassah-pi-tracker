<?php

namespace App\Http\Controllers\Api\V1\CustomerService;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerServiceProductController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category_id' => 'sometimes|integer|min:1',
            'search' => 'sometimes|string|max:100',
            'availability' => 'sometimes|in:available,unavailable',
            'page' => 'sometimes|integer|min:1',
        ]);
        $branchId = $request->user()->requiredBranchScopeId();
        $stock = Device::query()->where('devices.branch_id', $branchId);
        $available = (clone $stock)->availableForSale();
        $groups = (clone $stock)
            ->leftJoinSub((clone $available)->select('id'), 'sellable', 'sellable.id', '=', 'devices.id')
            ->selectRaw('MIN(devices.id) AS id, devices.category_id, devices.brand, devices.model,
                COUNT(*) AS total_units, COUNT(sellable.id) AS available_units,
                SUM(CASE WHEN devices.inventory_status = ? OR devices.reserved_layby_id IS NOT NULL
                    OR devices.stock_transfer_request_id IS NOT NULL THEN 1 ELSE 0 END) AS held_units',
                [InventoryStatus::RESERVED->value])
            ->groupBy('devices.category_id', 'devices.brand', 'devices.model');

        $summary = DB::query()->fromSub(clone $groups, 'products')
            ->selectRaw('COUNT(*) AS products, COALESCE(SUM(available_units), 0) AS available_units, COALESCE(SUM(held_units), 0) AS held_units')
            ->first();
        $categories = DeviceCategory::query()
            ->whereIn('id', (clone $stock)->select('category_id')->distinct())
            ->orderBy('name')->get(['id', 'name']);

        if (isset($filters['category_id'])) {
            $groups->where('devices.category_id', $filters['category_id']);
        }
        foreach (preg_split('/\s+/u', $filters['search'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $pattern = '%'.$word.'%';
            $groups->havingRaw('MAX(CASE WHEN devices.brand LIKE ? OR devices.model LIKE ? OR devices.serial_number LIKE ?
                OR devices.unique_device_code LIKE ? THEN 1 ELSE 0 END) = 1', array_fill(0, 4, $pattern));
        }
        if (isset($filters['availability'])) {
            $groups->havingRaw($filters['availability'] === 'available' ? 'COUNT(sellable.id) > 0' : 'COUNT(sellable.id) = 0');
        }

        $products = $groups->with('category:id,name')
            ->orderByRaw('CASE WHEN COUNT(sellable.id) > 0 THEN 0 ELSE 1 END')
            ->orderBy('devices.brand')->orderBy('devices.model')->orderBy('devices.category_id')
            ->paginate(15);

        // Use the same database matching rules as checkout, including its collation.
        // Aggregate per batch so even a large delivery produces one price row.
        $priceBuckets = DB::query()
            ->fromSub((clone $available)->select('category_id', 'brand', 'model', 'inventory_batch_id'), 'units')
            ->join('devices as product', function ($join) {
                $join->on('product.category_id', '=', 'units.category_id')
                    ->on('product.brand', '=', 'units.brand')
                    ->on('product.model', '=', 'units.model');
            })
            ->whereIn('product.id', $products->pluck('id'))
            ->selectRaw('product.id AS product_id, units.inventory_batch_id, COUNT(*) AS quantity')
            ->groupBy('product.id', 'units.inventory_batch_id')->get();
        $batches = InventoryBatch::query()->whereIn('id', $priceBuckets->pluck('inventory_batch_id')->filter()->unique())
            ->get(['id', 'target_selling_price'])->keyBy('id');
        $pricesByProduct = $priceBuckets->groupBy('product_id');

        return $this->success([
            'branch' => Branch::query()->findOrFail($branchId, ['id', 'name', 'code']),
            'summary' => array_map('intval', (array) $summary),
            'categories' => $categories,
            'products' => $products->through(function (Device $product) use ($pricesByProduct, $batches) {
                $tiers = ($pricesByProduct->get($product->id) ?? collect())
                    ->map(fn ($bucket) => [
                        'price' => $batches->get($bucket->inventory_batch_id)?->sellingPrice(),
                        'quantity' => (int) $bucket->quantity,
                    ])
                    ->groupBy(fn ($tier) => $tier['price'] === null ? 'unpriced' : (string) $tier['price'])
                    ->map(fn ($tier) => ['price' => $tier->first()['price'], 'quantity' => $tier->sum('quantity')])
                    ->sortBy(fn ($tier) => $tier['price'] ?? INF)->values();

                return [
                    'id' => $product->id,
                    'brand' => $product->brand,
                    'model' => $product->model,
                    'category' => $product->category?->name,
                    'available_units' => (int) $product->available_units,
                    'held_units' => (int) $product->held_units,
                    'total_units' => (int) $product->total_units,
                    'price_tiers' => $tiers,
                ];
            }),
        ]);
    }

    public function units(Request $request, Device $device): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'search' => 'sometimes|string|max:100',
        ]);
        $branchId = $request->user()->requiredBranchScopeId();
        abort_unless((int) $device->branch_id === $branchId, 404);

        $stock = Device::query()->where('devices.branch_id', $branchId)
            ->where('category_id', $device->category_id)->where('brand', $device->brand)->where('model', $device->model);
        $available = (clone $stock)->availableForSale()->select('id');
        $units = $stock
            ->leftJoinSub($available, 'sellable', 'sellable.id', '=', 'devices.id')
            ->select('devices.*')->selectRaw('sellable.id IS NOT NULL AS available')
            ->when(isset($filters['search']), function ($query) use ($filters) {
                $pattern = '%'.$filters['search'].'%';
                $query->where(fn ($query) => $query->where('serial_number', 'like', $pattern)
                    ->orWhere('unique_device_code', 'like', $pattern)->orWhere('color', 'like', $pattern));
            })
            ->with(['owner:id,name', 'inventoryBatch:id,batch_number,target_selling_price'])
            ->orderByDesc('available')->orderBy('devices.serial_number')->paginate(15);

        return $this->success($units->through(fn (Device $unit) => [
            'id' => $unit->id,
            'serial_number' => $unit->serial_number,
            'device_code' => $unit->unique_device_code,
            'color' => $unit->color,
            'description' => $unit->description,
            'owner' => $unit->owner?->name,
            'batch' => $unit->inventoryBatch?->batch_number,
            'status' => $unit->inventory_status?->label(),
            'device_status' => $unit->status?->label(),
            'hold' => match (true) {
                $unit->reserved_layby_id !== null => 'Layby hold',
                $unit->reserved_invoice_id !== null => 'Invoice hold',
                $unit->stock_transfer_request_id !== null => 'Awaiting transfer',
                default => null,
            },
            'available' => (bool) $unit->available,
            'selling_price' => $unit->available ? $unit->inventoryBatch?->sellingPrice() : null,
        ]));
    }
}
