<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\InventoryMovementResource;
use App\Models\Branch;
use App\Models\Device;
use App\Models\InventoryMovement;
use App\Services\InventoryMovementService;
use App\Support\LocalTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminStockMovementController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->resolveMovementBranchId($request, $request->filled('branch_id') ? $request->integer('branch_id') : null);
        $query = $this->baseMovementQuery($branchId)
            ->with(['device.category', 'batch', 'receipt', 'sale', 'category', 'branch', 'fromBranch', 'toBranch', 'createdBy'])
            ->latest();

        $this->applyFilters($query, $request);

        $perPage = min((int) $request->get('per_page', 15), 100);

        return $this->paginated(InventoryMovementResource::collection($query->paginate($perPage)));
    }

    public function summary(Request $request): JsonResponse
    {
        $branchId = $this->resolveMovementBranchId($request, $request->filled('branch_id') ? $request->integer('branch_id') : null);
        $query = $this->baseMovementQuery($branchId);
        $this->applyFilters($query, $request);

        $movements = $query->get();
        $byType = $movements
            ->groupBy('movement_type')
            ->map(fn ($items) => $items->sum('quantity'))
            ->toArray();

        $byBranch = $movements
            ->groupBy(fn (InventoryMovement $movement) => $movement->branch_id ?? $movement->to_branch_id ?? $movement->from_branch_id ?? 'unassigned')
            ->map(fn ($items) => $items->sum('quantity'))
            ->toArray();

        $byProduct = $movements
            ->groupBy(fn (InventoryMovement $movement) => trim("{$movement->brand} {$movement->model} ".($movement->color ?? '')))
            ->map(fn ($items, $product) => [
                'product' => $product,
                'quantity' => $items->sum('quantity'),
            ])
            ->values();

        $administrativeSales = $movements->where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE);

        return $this->success([
            'total_movements' => $movements->count(),
            'total_quantity' => $movements->sum('quantity'),
            'by_type' => $byType,
            'by_branch' => $byBranch,
            'by_product' => $byProduct,
            'administrative_sale_value' => round((float) $administrativeSales->sum('total_amount'), 2),
            'administrative_sale_profit' => round((float) $administrativeSales->sum('profit_amount'), 2),
        ]);
    }

    public function show(Request $request, InventoryMovement $stockMovement): JsonResponse
    {
        $this->authorizeMovementBranch($request, $stockMovement);

        $stockMovement->load(['device.category', 'batch', 'receipt', 'sale.items.device', 'category', 'branch', 'fromBranch', 'toBranch', 'createdBy']);

        return $this->success(new InventoryMovementResource($stockMovement));
    }

    public function transfer(Request $request, InventoryMovementService $movementService): JsonResponse
    {
        $validated = $request->validate([
            'device_ids' => 'required|array|min:1|max:100',
            'device_ids.*' => ['required', 'integer', Rule::exists('devices', 'id')->whereNull('deleted_at')],
            'to_branch_id' => ['required', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $toBranch = Branch::whereKey($validated['to_branch_id'])->where('is_active', true)->first();
        if (! $toBranch) {
            throw ValidationException::withMessages([
                'to_branch_id' => ['Destination branch must be active.'],
            ]);
        }

        return DB::transaction(function () use ($validated, $request, $movementService) {
            $user = $request->user();
            $devices = Device::whereIn('id', $validated['device_ids'])
                ->lockForUpdate()
                ->get();

            if ($devices->count() !== count(array_unique($validated['device_ids']))) {
                throw ValidationException::withMessages([
                    'device_ids' => ['One or more selected devices could not be found.'],
                ]);
            }

            $movements = collect();
            foreach ($devices as $device) {
                $this->authorizeTransferSource($request, $device);
                $device->assertNotHeldForLayby();

                if (! in_array($device->inventory_status, [InventoryStatus::IN_STOCK, InventoryStatus::RETURNED], true)) {
                    throw ValidationException::withMessages([
                        'device_ids' => ["Device {$device->serial_number} is not eligible for transfer."],
                    ]);
                }

                if ($device->stock_transfer_request_id) {
                    throw ValidationException::withMessages(['device_ids' => ["Device {$device->serial_number} is awaiting transfer approval."]]);
                }

                if (! $device->branch_id) {
                    throw ValidationException::withMessages([
                        'device_ids' => ["Device {$device->serial_number} must be assigned to a source branch before transfer."],
                    ]);
                }

                if ((int) $device->branch_id === (int) $validated['to_branch_id']) {
                    throw ValidationException::withMessages([
                        'to_branch_id' => ['Destination branch must be different from the current branch.'],
                    ]);
                }

                $fromBranchId = (int) $device->branch_id;
                $device->update(['branch_id' => (int) $validated['to_branch_id']]);
                $movements->push($movementService->recordTransfer(
                    $device->fresh(),
                    $fromBranchId,
                    (int) $validated['to_branch_id'],
                    $user,
                    $validated['reason'],
                    $validated['notes'] ?? null
                ));
            }

            $reloadedMovements = InventoryMovement::whereIn('id', $movements->pluck('id'))
                ->with(['device', 'fromBranch', 'toBranch', 'createdBy'])
                ->get();

            return $this->success([
                'movements' => InventoryMovementResource::collection($reloadedMovements),
                'count' => $movements->count(),
            ], "{$movements->count()} device(s) transferred successfully", 201);
        });
    }

    public function adjust(Request $request, InventoryMovementService $movementService): JsonResponse
    {
        $validated = $request->validate([
            'device_ids' => 'required|array|min:1|max:100',
            'device_ids.*' => ['required', 'integer', Rule::exists('devices', 'id')->whereNull('deleted_at')],
            // Never allow the generic adjustment endpoint to silently create
            // a sold device. Administrative sales have a separate PIN flow.
            'inventory_status' => ['required', Rule::in([
                InventoryStatus::IN_STOCK->value,
                InventoryStatus::RESERVED->value,
                InventoryStatus::RETURNED->value,
                InventoryStatus::FAULTY->value,
            ])],
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($validated, $request, $movementService) {
            $user = $request->user();
            $devices = Device::whereIn('id', $validated['device_ids'])
                ->lockForUpdate()
                ->get();

            if ($devices->count() !== count(array_unique($validated['device_ids']))) {
                throw ValidationException::withMessages([
                    'device_ids' => ['One or more selected devices could not be found.'],
                ]);
            }

            $movements = collect();
            foreach ($devices as $device) {
                $this->authorizeTransferSource($request, $device);
                $device->assertNotHeldForLayby();

                if ($device->stock_transfer_request_id) {
                    throw ValidationException::withMessages(['device_ids' => ["Device {$device->serial_number} is awaiting transfer approval."]]);
                }

                if ($device->inventory_status === InventoryStatus::SOLD) {
                    throw ValidationException::withMessages([
                        'device_ids' => ["Device {$device->serial_number} is sold. Process a refund instead."],
                    ]);
                }

                $previousStatus = $device->inventory_status?->value;
                $device->update([
                    'inventory_status' => $validated['inventory_status'],
                    'notes' => $validated['notes'] ?? $device->notes,
                ]);
                $movements->push($movementService->recordStatusChange(
                    $device->fresh(),
                    $previousStatus,
                    $validated['inventory_status'],
                    $user,
                    $validated['reason'],
                    $validated['notes'] ?? null
                ));
            }

            $reloadedMovements = InventoryMovement::whereIn('id', $movements->pluck('id'))
                ->with(['device', 'branch', 'createdBy'])
                ->get();

            return $this->success([
                'movements' => InventoryMovementResource::collection($reloadedMovements),
                'devices' => DeviceResource::collection($devices->fresh(['category', 'branch'])),
                'count' => $movements->count(),
            ], "{$movements->count()} device(s) adjusted successfully", 201);
        });
    }

    private function baseMovementQuery(?int $branchId)
    {
        return InventoryMovement::query()
            ->when($branchId, function ($q) use ($branchId) {
                $q->where(function ($branchQuery) use ($branchId) {
                    $branchQuery->where('branch_id', $branchId)
                        ->orWhere('from_branch_id', $branchId)
                        ->orWhere('to_branch_id', $branchId);
                });
            });
    }

    private function applyFilters($query, Request $request): void
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $query
            ->when($request->filled('movement_type'), fn ($q) => $q->where('movement_type', $request->string('movement_type')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('inventory_batch_id'), fn ($q) => $q->where('inventory_batch_id', $request->integer('inventory_batch_id')))
            ->when($request->filled('inventory_receipt_id'), fn ($q) => $q->where('inventory_receipt_id', $request->integer('inventory_receipt_id')))
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('sale_id'), fn ($q) => $q->where('sale_id', $request->integer('sale_id')))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', LocalTime::startOfDayUtc($request->input('date_from'))))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', LocalTime::endOfDayUtc($request->input('date_to'))));

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('movement_number', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('color', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhereHas('device', function ($deviceQuery) use ($search) {
                        $deviceQuery->where('serial_number', 'like', "%{$search}%")
                            ->orWhere('unique_device_code', 'like', "%{$search}%");
                    })
                    ->orWhereHas('batch', fn ($batchQuery) => $batchQuery->where('batch_number', 'like', "%{$search}%"))
                    ->orWhereHas('receipt', fn ($receiptQuery) => $receiptQuery->where('receipt_number', 'like', "%{$search}%"))
                    ->orWhereHas('sale', fn ($saleQuery) => $saleQuery->where('sale_number', 'like', "%{$search}%"));
            });
        }
    }

    private function resolveMovementBranchId(Request $request, ?int $requestedBranchId): ?int
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

    private function authorizeMovementBranch(Request $request, InventoryMovement $movement): void
    {
        $user = $request->user();

        if ($user?->hasRole('super_admin')) {
            return;
        }

        $branchId = (int) $user?->branchScopeId();
        if ($branchId && in_array($branchId, array_filter([
            $movement->branch_id,
            $movement->from_branch_id,
            $movement->to_branch_id,
        ]), true)) {
            return;
        }

        abort(403, 'You cannot access stock movements for another branch.');
    }

    private function authorizeTransferSource(Request $request, Device $device): void
    {
        $user = $request->user();

        if ($user?->hasRole('super_admin')) {
            return;
        }

        if ($user?->hasRole('store_admin') && (int) $user->branchScopeId() === (int) $device->branch_id) {
            return;
        }

        abort(403, 'You cannot manage stock outside your branch.');
    }
}
