<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\InventoryMovementResource;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\InventoryActionChallenge;
use App\Models\InventoryMovement;
use App\Services\InventoryActionApprovalService;
use App\Services\InventoryMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InventoryDispositionController extends BaseController
{
    public function __construct(
        private readonly InventoryActionApprovalService $approvalService,
        private readonly InventoryMovementService $movementService,
    ) {}

    public function requestAdministrativeSale(Request $request, Device $device): JsonResponse
    {
        $this->authorizeInventoryBranch($request, $device);
        $this->assertAdministrativeSaleEligible($device);

        $validated = $request->validate([
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'selling_price.min' => 'Sale amount cannot be negative.',
        ]);

        [$unitCost, $pricingSource] = $this->resolveUnitCost($device);
        $batchPrice = $device->inventoryBatch?->target_selling_price;
        $unitPrice = array_key_exists('selling_price', $validated) && $validated['selling_price'] !== null
            ? round((float) $validated['selling_price'], 2)
            : ($batchPrice !== null && (float) $batchPrice > 0
                ? round((float) $batchPrice, 2)
                : $unitCost);

        if ($unitPrice === null || $unitPrice <= 0) {
            throw ValidationException::withMessages([
                'selling_price' => ['An actual sale amount greater than zero is required because this device has no valid batch selling price or purchase cost.'],
            ]);
        }

        $payload = [
            'device_id' => $device->id,
            'inventory_batch_id' => $device->inventory_batch_id,
            'branch_id' => $device->branch_id,
            'unit_cost' => number_format($unitCost, 2, '.', ''),
            'unit_price' => number_format($unitPrice, 2, '.', ''),
            'pricing_source' => $pricingSource,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
        ];

        $challenge = $this->approvalService->create($request->user(), InventoryActionChallenge::ACTION_ADMINISTRATIVE_SALE, $payload, $device);

        return $this->success($this->challengeData($challenge, [
            'unit_cost' => $unitCost,
            'unit_price' => $unitPrice,
            'total_cost' => $unitCost,
            'total_amount' => $unitPrice,
            'profit_amount' => round($unitPrice - $unitCost, 2),
            'pricing_source' => $pricingSource,
        ]), 'A verification PIN was sent to the admin approval mailbox.', 201);
    }

    public function requestDeviceDeletion(Request $request, Device $device): JsonResponse
    {
        $this->authorizeInventoryBranch($request, $device);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'confirm_history_loss' => ['sometimes', 'boolean'],
        ]);
        $confirmHistoryLoss = (bool) ($validated['confirm_history_loss'] ?? false);

        if ($this->requiresDeletionConfirmation($device) && ! $confirmHistoryLoss) {
            return $this->deletionConfirmationWarning();
        }

        $this->assertDeletable($device, $confirmHistoryLoss);

        $payload = [
            'device_id' => $device->id,
            'branch_id' => $device->branch_id,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
            'confirm_history_loss' => $confirmHistoryLoss,
        ];
        $challenge = $this->approvalService->create($request->user(), InventoryActionChallenge::ACTION_DEVICE_DELETION, $payload, $device);

        return $this->success($this->challengeData($challenge), 'A verification PIN was sent to the admin approval mailbox.', 201);
    }

    public function resend(Request $request, InventoryActionChallenge $challenge): JsonResponse
    {
        $this->authorizeChallengeAction($request, $challenge);
        $device = Device::findOrFail((int) ($challenge->payload['device_id'] ?? 0));
        $this->authorizeInventoryBranch($request, $device);
        $challenge = $this->approvalService->resend($challenge, $request->user(), $device);

        return $this->success($this->challengeData($challenge), 'A new verification PIN was sent to the admin approval mailbox.');
    }

    public function verify(Request $request, InventoryActionChallenge $challenge): JsonResponse
    {
        $validated = $request->validate([
            'pin' => ['required', 'digits:6'],
        ]);
        $this->authorizeChallengeAction($request, $challenge);

        $result = $this->approvalService->verify(
            $challenge,
            $request->user(),
            $validated['pin'],
            function (array $payload, InventoryActionChallenge $lockedChallenge) use ($request) {
                return match ($lockedChallenge->action) {
                    InventoryActionChallenge::ACTION_ADMINISTRATIVE_SALE => $this->executeAdministrativeSale($request, $payload, $lockedChallenge),
                    InventoryActionChallenge::ACTION_DEVICE_DELETION => $this->executeDeviceDeletion($request, $payload),
                    default => throw ValidationException::withMessages(['challenge' => ['Unsupported inventory action.']]),
                };
            },
        );

        if (! $result['verified']) {
            return $this->error(
                $result['message'] ?? 'PIN verification failed.',
                422,
                isset($result['attempts_remaining']) ? ['attempts_remaining' => $result['attempts_remaining']] : null,
            );
        }

        return $this->success($result['result'] ?? null, 'Inventory action verified and completed.');
    }

    private function executeAdministrativeSale(Request $request, array $payload, InventoryActionChallenge $challenge): array
    {
        $device = Device::query()->with('inventoryBatch')->lockForUpdate()->findOrFail((int) $payload['device_id']);
        $this->authorizeInventoryBranch($request, $device);
        $this->assertAdministrativeSaleEligible($device);

        if ((int) ($payload['branch_id'] ?? 0) !== (int) $device->branch_id
            || (int) ($payload['inventory_batch_id'] ?? 0) !== (int) ($device->inventory_batch_id ?? 0)) {
            throw ValidationException::withMessages(['challenge' => ['The device location or batch changed after this PIN was requested. Request a new PIN.']]);
        }

        [$unitCost, $pricingSource] = $this->resolveUnitCost($device);
        $storedCost = number_format((float) ($payload['unit_cost'] ?? -1), 2, '.', '');
        $storedPrice = number_format((float) ($payload['unit_price'] ?? -1), 2, '.', '');
        if ($storedCost !== number_format($unitCost, 2, '.', '') || ($payload['pricing_source'] ?? null) !== $pricingSource) {
            throw ValidationException::withMessages(['challenge' => ['The device cost changed after this PIN was requested. Request a new PIN.']]);
        }

        $previousStatus = $device->inventory_status?->value;
        $movement = $this->movementService->recordAdministrativeSale(
            $device,
            $request->user(),
            $unitCost,
            (float) $storedPrice,
            (string) $payload['reason'],
            $payload['notes'] ?? null,
            $pricingSource,
            (string) $challenge->id,
            $previousStatus,
        );

        $device->update([
            'inventory_status' => InventoryStatus::SOLD,
            'sold_at' => now(),
            'sold_by_id' => $request->user()->id,
            'sale_id' => null,
            'owner_id' => null,
            'stock_transfer_request_id' => null,
        ]);

        $this->logActivity($device, $request->user(), 'administrative_sale', 'Administrative sale completed', [
            'challenge_id' => $challenge->id,
            'unit_cost' => $movement->unit_cost,
            'unit_price' => $movement->unit_price,
            'total_amount' => $movement->total_amount,
            'profit_amount' => $movement->profit_amount,
            'pricing_source' => $pricingSource,
        ]);

        return [
            'device' => new DeviceResource($device->fresh(['category', 'branch', 'soldBy', 'inventoryBatch'])),
            'movement' => new InventoryMovementResource($movement->fresh(['device', 'batch', 'branch', 'createdBy'])),
        ];
    }

    private function executeDeviceDeletion(Request $request, array $payload): array
    {
        $device = Device::query()->lockForUpdate()->findOrFail((int) $payload['device_id']);
        $this->authorizeInventoryBranch($request, $device);
        $this->assertDeletable($device, (bool) ($payload['confirm_history_loss'] ?? false));

        if ((int) ($payload['branch_id'] ?? 0) !== (int) $device->branch_id) {
            throw ValidationException::withMessages(['challenge' => ['The device branch changed after this PIN was requested. Request a new PIN.']]);
        }

        $deviceName = trim("{$device->brand} {$device->model}");
        $device->locations()->delete();
        $device->geofences()->delete();
        if ($device->iot_module_id) {
            $device->iotModule?->update(['device_id' => null]);
        }
        $device->delete();

        $this->logActivity(null, $request->user(), 'device_deleted', 'Device deleted after PIN verification', [
            'device_id' => $payload['device_id'],
            'reason' => $payload['reason'],
            'confirm_history_loss' => (bool) ($payload['confirm_history_loss'] ?? false),
        ]);

        return ['device_id' => (int) $payload['device_id'], 'device_name' => $deviceName];
    }

    private function challengeData(InventoryActionChallenge $challenge, ?array $financial = null): array
    {
        $challenge->loadMissing('requestedBy');

        $data = [
            'challenge_id' => $challenge->id,
            'action' => $challenge->action,
            'masked_email' => $this->approvalService->maskedEmail($this->approvalService->approvalEmail()),
            'expires_at' => $challenge->expires_at?->toIso8601String(),
            'attempts_remaining' => max(0, $challenge->max_attempts - $challenge->attempts),
        ];
        if ($financial !== null) {
            $data['financial'] = $financial;
        }

        return $data;
    }

    private function authorizeChallengeAction(Request $request, InventoryActionChallenge $challenge): void
    {
        $user = $request->user();
        if ($challenge->action === InventoryActionChallenge::ACTION_ADMINISTRATIVE_SALE && ! $user->can('manage-inventory')) {
            abort(403, 'You do not have permission to complete administrative sales.');
        }
        if ($challenge->action === InventoryActionChallenge::ACTION_DEVICE_DELETION && ! $user->can('delete-devices')) {
            abort(403, 'You do not have permission to delete devices.');
        }
    }

    private function authorizeInventoryBranch(Request $request, Device $device): void
    {
        $user = $request->user();
        if ($user->hasRole('super_admin')) {
            return;
        }
        if (! $user->branch_id || (int) $user->branch_id !== (int) $device->branch_id) {
            abort(403, 'You are not authorized to manage devices in this branch.');
        }
    }

    private function assertAdministrativeSaleEligible(Device $device): void
    {
        if ($device->stock_transfer_request_id) {
            throw ValidationException::withMessages(['device' => ['This device is awaiting transfer approval.']]);
        }

        if (! in_array($device->inventory_status, [InventoryStatus::IN_STOCK, InventoryStatus::RETURNED], true)) {
            $status = $device->inventory_status?->label() ?? 'unknown';
            throw ValidationException::withMessages(['device' => ["Administrative sale is only allowed for in-stock or returned devices (current status: {$status})."]]);
        }
    }

    private function resolveUnitCost(Device $device): array
    {
        $device->loadMissing('inventoryBatch');
        if ($device->purchase_price !== null && (float) $device->purchase_price > 0) {
            return [round((float) $device->purchase_price, 2), 'device.purchase_price'];
        }
        if ($device->inventoryBatch?->buying_price !== null && (float) $device->inventoryBatch->buying_price > 0) {
            return [round((float) $device->inventoryBatch->buying_price, 2), 'inventory_batch.buying_price'];
        }

        throw ValidationException::withMessages([
            'device' => ['Administrative sale is blocked because no purchase cost could be resolved for this device.'],
        ]);
    }

    private function requiresDeletionConfirmation(Device $device): bool
    {
        return ! $this->hasProtectedDeletionHistory($device)
            && ($device->inventory_batch_id !== null || $device->inventoryMovements()->exists());
    }

    private function deletionConfirmationWarning(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This device has inventory history and requires explicit confirmation before deletion.',
            'errors' => [
                'device' => ['This device has inventory history. Proceeding will soft-delete the device while preserving the existing audit movements.'],
            ],
            'requires_confirmation' => true,
        ], 409);
    }

    private function assertDeletable(Device $device, bool $allowInventoryBatch = false): void
    {
        if ($this->hasProtectedDeletionHistory($device)
            || (! $allowInventoryBatch && ($device->inventory_batch_id || $device->inventoryMovements()->exists()))) {
            throw ValidationException::withMessages([
                'device' => ['This device has ownership or inventory history and cannot be deleted. Use the appropriate inventory disposition instead.'],
            ]);
        }
    }

    private function hasProtectedDeletionHistory(Device $device): bool
    {
        if ($device->sale_id
            || $device->owner_id
            || $device->stock_transfer_request_id
            || $device->inventory_status === InventoryStatus::SOLD
            || $device->ownershipHistory()->exists()
            || $device->warranties()->exists()) {
            return true;
        }

        return $device->inventoryMovements()
            ->whereIn('movement_type', [
                InventoryMovement::TYPE_SALE,
                InventoryMovement::TYPE_SALE_CANCELLED,
                InventoryMovement::TYPE_RESERVATION,
                InventoryMovement::TYPE_RESERVATION_CANCELLED,
                InventoryMovement::TYPE_REFUND,
                InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            ])
            ->exists();
    }

    private function logActivity(?Device $device, $user, string $event, string $description, array $properties): void
    {
        ActivityLog::create([
            'log_name' => 'inventory',
            'description' => $description,
            'subject_type' => $device ? Device::class : null,
            'subject_id' => $device?->id,
            'causer_type' => $user ? get_class($user) : null,
            'causer_id' => $user?->id,
            'event' => $event,
            'properties' => $properties,
        ]);
    }
}
