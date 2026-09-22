<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\StockTransferRequestResource;
use App\Models\Branch;
use App\Models\Device;
use App\Models\StockTransferRequest;
use App\Models\User;
use App\Notifications\StockTransferDecisionNotification;
use App\Notifications\StockTransferRequestNotification;
use App\Services\InventoryMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockTransferRequestController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $branchId = $user->hasRole('super_admin') ? null : $user->requiredBranchScopeId();
        $query = StockTransferRequest::with(['fromBranch', 'toBranch', 'requestedBy', 'decidedBy', 'overriddenBy'])
            ->withCount('items')
            ->latest();

        if (! $user->hasRole('super_admin')) {
            $query->where(fn ($q) => $q->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->string('direction')->toString() === 'incoming') {
            $query->where('to_branch_id', $branchId);
        }
        if ($request->string('direction')->toString() === 'outgoing') {
            $query->where('from_branch_id', $branchId);
        }

        return $this->paginated(StockTransferRequestResource::collection($query->paginate(min((int) $request->get('per_page', 15), 100))));
    }

    public function show(Request $request, StockTransferRequest $transferRequest): JsonResponse
    {
        $this->viewable($request->user(), $transferRequest);

        return $this->success(new StockTransferRequestResource($transferRequest->load([
            'fromBranch',
            'toBranch',
            'requestedBy',
            'decidedBy',
            'overriddenBy',
            'items.device.category',
        ])));
    }

    public function store(Request $request, InventoryMovementService $movementService): JsonResponse
    {
        $data = $request->validate([
            'device_ids' => 'nullable|array|min:1|max:100|required_without:product_quantities',
            'device_ids.*' => ['integer', 'distinct', Rule::exists('devices', 'id')->whereNull('deleted_at')],
            'product_quantities' => 'nullable|array|min:1|max:100|required_without:device_ids',
            'product_quantities.*.category_id' => ['required', 'integer', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
            'product_quantities.*.brand' => 'required|string|max:100',
            'product_quantities.*.model' => 'required|string|max:150',
            'product_quantities.*.color' => 'nullable|string|max:100',
            'product_quantities.*.quantity' => 'required|integer|min:1|max:100',
            'to_branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'from_branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'override_destination_receipt' => 'sometimes|boolean',
        ], [
            'device_ids.required_without' => 'Select at least one device or enter a product quantity.',
            'product_quantities.required_without' => 'Select at least one device or enter a product quantity.',
            'product_quantities.*.quantity.integer' => 'Quantity must be a whole number.',
            'product_quantities.*.quantity.min' => 'Quantity must be at least 1.',
            'product_quantities.*.quantity.max' => 'Quantity cannot exceed 100 per product.',
        ]);

        $user = $request->user();
        $override = (bool) ($data['override_destination_receipt'] ?? false);
        $deviceIds = array_values($data['device_ids'] ?? []);
        $productQuantities = array_values($data['product_quantities'] ?? []);

        if ((bool) $deviceIds === (bool) $productQuantities) {
            throw ValidationException::withMessages([
                'device_ids' => ['Choose either individual devices or product quantities, not both.'],
            ]);
        }
        if ($productQuantities) {
            $this->assertQuantityGroupsValid($productQuantities);
        }

        if ($override && ! $user->hasRole('super_admin')) {
            abort(403, 'Only a super admin can bypass destination receipt.');
        }

        return DB::transaction(function () use ($data, $user, $override, $movementService, $deviceIds, $productQuantities) {
            $branchId = $user->hasRole('super_admin') ? $user->branchScopeId() : $user->requiredBranchScopeId();
            if ($deviceIds) {
                $devices = Device::whereIn('id', $deviceIds)->lockForUpdate()->get();
                $fromBranchId = $user->hasRole('super_admin')
                    ? (int) ($data['from_branch_id'] ?? $devices->first()?->branch_id)
                    : (int) $branchId;
            } else {
                $fromBranchId = $user->hasRole('super_admin')
                    ? (int) ($data['from_branch_id'] ?? 0)
                    : (int) $branchId;

                if (! $fromBranchId) {
                    throw ValidationException::withMessages([
                        'from_branch_id' => ['Select a source branch when transferring by quantity.'],
                    ]);
                }

                $devices = $this->resolveQuantityDevices($productQuantities, $fromBranchId);
            }

            if (! $fromBranchId
                || ($deviceIds && $devices->count() !== count($deviceIds))
                || $devices->contains(fn ($device) => (int) $device->branch_id !== $fromBranchId)) {
                throw ValidationException::withMessages(['device_ids' => ['Devices must all belong to the source branch.']]);
            }

            $destination = Branch::with('manager.roles')->findOrFail($data['to_branch_id']);
            if (! $destination->is_active || $fromBranchId === (int) $destination->id) {
                throw ValidationException::withMessages(['to_branch_id' => ['Choose a different active destination branch.']]);
            }

            $hasEligibleManager = $this->hasEligibleDestinationManager($destination);
            if (! $hasEligibleManager && ! $override) {
                if ($user->hasRole('super_admin')) {
                    return $this->destinationReceiptOverrideWarning($destination);
                }

                throw ValidationException::withMessages(['to_branch_id' => ['The destination branch needs an assigned store or super admin manager.']]);
            }

            foreach ($devices as $device) {
                if ($device->stock_transfer_request_id
                    || ! in_array($device->inventory_status, [InventoryStatus::IN_STOCK, InventoryStatus::RETURNED], true)) {
                    throw ValidationException::withMessages(['device_ids' => ["{$device->serial_number} is no longer available for transfer."]]);
                }
            }

            $transfer = StockTransferRequest::create([
                'request_number' => StockTransferRequest::nextNumber(),
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $destination->id,
                'requested_by_id' => $user->id,
                'status' => StockTransferRequest::PENDING,
                'is_override' => $override,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($devices as $device) {
                $transfer->items()->create(['device_id' => $device->id]);
                $device->update(['stock_transfer_request_id' => $transfer->id]);
            }

            if ($override) {
                $this->completeTransfer(
                    $transfer,
                    $devices,
                    $user,
                    $movementService,
                    'Destination receipt bypassed by super-admin override.',
                );

                return $this->success(
                    new StockTransferRequestResource($transfer->fresh()->load([
                        'fromBranch',
                        'toBranch',
                        'requestedBy',
                        'decidedBy',
                        'overriddenBy',
                    ])->loadCount('items')),
                    'Stock transfer completed by super-admin override.',
                    201,
                );
            }

            $transfer->load(['fromBranch', 'toBranch', 'requestedBy'])->loadCount('items');
            $recipients = User::role('super_admin')
                ->get()
                ->push($destination->manager)
                ->unique('id')
                ->reject(fn ($recipient) => $recipient->id === $user->id);

            foreach ($recipients as $recipient) {
                $recipient->notify(new StockTransferRequestNotification($transfer));
            }

            return $this->success(new StockTransferRequestResource($transfer), 'Stock transfer request created.', 201);
        });
    }

    public function approve(Request $request, StockTransferRequest $transferRequest, InventoryMovementService $movementService): JsonResponse
    {
        return DB::transaction(function () use ($request, $transferRequest, $movementService) {
            $transfer = StockTransferRequest::whereKey($transferRequest->id)->lockForUpdate()->firstOrFail();
            $this->approvable($request->user(), $transfer);

            if ($transfer->status !== StockTransferRequest::PENDING) {
                throw ValidationException::withMessages(['request' => ['This request is no longer pending.']]);
            }

            $devices = Device::whereIn('id', $transfer->items()->pluck('device_id'))->lockForUpdate()->get();
            $this->assertTransferDevicesEligible($devices, $transfer);
            $this->completeTransfer($transfer, $devices, $request->user(), $movementService);
            $transfer->requestedBy->notify(new StockTransferDecisionNotification($transfer));

            return $this->success(
                new StockTransferRequestResource($transfer->fresh()->load([
                    'fromBranch',
                    'toBranch',
                    'requestedBy',
                    'decidedBy',
                    'overriddenBy',
                ])->loadCount('items')),
                'Stock transfer approved.',
            );
        });
    }

    public function reject(Request $request, StockTransferRequest $transferRequest): JsonResponse
    {
        return $this->close($request, $transferRequest, StockTransferRequest::REJECTED, true);
    }

    public function cancel(Request $request, StockTransferRequest $transferRequest): JsonResponse
    {
        return $this->close($request, $transferRequest, StockTransferRequest::CANCELLED, false);
    }

    private function close(Request $request, StockTransferRequest $transferRequest, string $status, bool $approval): JsonResponse
    {
        $data = $request->validate(['reason' => $approval ? 'required|string|max:1000' : 'nullable|string|max:1000']);

        return DB::transaction(function () use ($request, $transferRequest, $status, $approval, $data) {
            $transfer = StockTransferRequest::whereKey($transferRequest->id)->lockForUpdate()->firstOrFail();
            if ($approval) {
                $this->approvable($request->user(), $transfer);
            } elseif ($transfer->requested_by_id !== $request->user()->id) {
                abort(403, 'Only the requester can cancel this request.');
            }

            if ($transfer->status !== StockTransferRequest::PENDING) {
                throw ValidationException::withMessages(['request' => ['This request is no longer pending.']]);
            }

            Device::where('stock_transfer_request_id', $transfer->id)->update(['stock_transfer_request_id' => null]);
            $transfer->update([
                'status' => $status,
                'decided_by_id' => $request->user()->id,
                'decided_at' => now(),
                'decision_reason' => $data['reason'] ?? null,
            ]);

            if ($transfer->requested_by_id !== $request->user()->id) {
                $transfer->requestedBy->notify(new StockTransferDecisionNotification($transfer));
            }

            return $this->success(
                new StockTransferRequestResource($transfer->fresh()->load([
                    'fromBranch',
                    'toBranch',
                    'requestedBy',
                    'decidedBy',
                    'overriddenBy',
                ])->loadCount('items')),
                "Stock transfer {$status}.",
            );
        });
    }

    private function completeTransfer(
        StockTransferRequest $transfer,
        $devices,
        User $user,
        InventoryMovementService $movementService,
        ?string $overrideReason = null,
    ): void {
        foreach ($devices as $device) {
            $device->update([
                'branch_id' => $transfer->to_branch_id,
                'stock_transfer_request_id' => null,
            ]);

            $movementService->recordTransfer(
                $device->fresh(),
                $transfer->from_branch_id,
                $transfer->to_branch_id,
                $user,
                $transfer->reason,
                $transfer->notes,
            );
        }

        $attributes = [
            'status' => StockTransferRequest::APPROVED,
            'decided_by_id' => $user->id,
            'decided_at' => now(),
        ];

        if ($overrideReason !== null) {
            $attributes += [
                'is_override' => true,
                'overridden_by_id' => $user->id,
                'overridden_at' => now(),
                'decision_reason' => $overrideReason,
            ];
        }

        $transfer->update($attributes);
    }

    private function assertTransferDevicesEligible($devices, StockTransferRequest $transfer): void
    {
        if ($devices->contains(fn ($device) => $device->stock_transfer_request_id !== $transfer->id
            || (int) $device->branch_id !== (int) $transfer->from_branch_id
            || ! in_array($device->inventory_status, [InventoryStatus::IN_STOCK, InventoryStatus::RETURNED], true))) {
            throw ValidationException::withMessages(['request' => ['One or more devices are no longer eligible.']]);
        }
    }

    private function assertQuantityGroupsValid(array $groups): void
    {
        $seen = [];
        $totalQuantity = 0;

        foreach ($groups as $index => $group) {
            $key = $this->quantityGroupKey($group);
            if (array_key_exists($key, $seen)) {
                throw ValidationException::withMessages([
                    "product_quantities.{$index}.quantity" => ['Combine duplicate product selections into one quantity.'],
                ]);
            }

            $seen[$key] = true;
            $totalQuantity += (int) $group['quantity'];
        }

        if ($totalQuantity > 100) {
            throw ValidationException::withMessages([
                'product_quantities' => ['The total quantity cannot exceed 100 devices per transfer.'],
            ]);
        }
    }

    private function resolveQuantityDevices(array $groups, int $fromBranchId): Collection
    {
        $devices = collect();

        foreach ($groups as $index => $group) {
            $query = Device::query()
                ->where('branch_id', $fromBranchId)
                ->where('category_id', (int) $group['category_id'])
                ->where('brand', $group['brand'])
                ->where('model', $group['model'])
                ->whereNull('stock_transfer_request_id')
                ->whereIn('inventory_status', [InventoryStatus::IN_STOCK, InventoryStatus::RETURNED])
                ->orderBy('id')
                ->lockForUpdate();

            if (array_key_exists('color', $group) && $group['color'] !== null) {
                $query->where('color', $group['color']);
            } else {
                $query->whereNull('color');
            }

            $available = $query->get();
            $requested = (int) $group['quantity'];
            if ($available->count() < $requested) {
                $product = $this->quantityGroupLabel($group);
                throw ValidationException::withMessages([
                    "product_quantities.{$index}.quantity" => ["Only {$available->count()} available for {$product}; you requested {$requested}."],
                ]);
            }

            $devices = $devices->merge($available->take($requested));
        }

        return $devices;
    }

    private function quantityGroupKey(array $group): string
    {
        return json_encode([
            (int) $group['category_id'],
            $group['brand'],
            $group['model'],
            $group['color'] ?? null,
        ], JSON_THROW_ON_ERROR);
    }

    private function quantityGroupLabel(array $group): string
    {
        return trim(implode(' ', array_filter([
            $group['brand'] ?? null,
            $group['model'] ?? null,
            $group['color'] ?? null,
        ])));
    }

    private function hasEligibleDestinationManager(Branch $destination): bool
    {
        return $destination->manager
            && ($destination->manager->hasRole('store_admin') || $destination->manager->hasRole('super_admin'));
    }

    private function destinationReceiptOverrideWarning(Branch $destination): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This destination has no eligible branch manager to receive the stock.',
            'errors' => [
                'to_branch_id' => ["{$destination->name} has no assigned store or super admin manager."],
            ],
            'requires_override' => true,
            'warning' => 'A super-admin override will move the selected devices immediately, mark the transfer approved, and make the stock available at the destination without a receiving step.',
        ], 409);
    }

    private function viewable(User $user, StockTransferRequest $transfer): void
    {
        if (! $user->hasRole('super_admin')
            && ! in_array((int) $user->branchScopeId(), [(int) $transfer->from_branch_id, (int) $transfer->to_branch_id], true)) {
            abort(403);
        }
    }

    private function approvable(User $user, StockTransferRequest $transfer): void
    {
        if ($user->hasRole('super_admin')) {
            return;
        }
        if ($user->hasRole('store_admin') && (int) $transfer->toBranch->manager_id === (int) $user->id) {
            return;
        }

        abort(403, 'Only the destination branch manager can approve this request.');
    }
}
