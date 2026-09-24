<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\DeviceResource;
use App\Http\Resources\InventoryMovementResource;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Device;
use App\Models\InventoryActionChallenge;
use App\Models\InventoryMovement;
use App\Models\ProductModel;
use App\Services\CatalogDeletionImpactService;
use App\Services\DeviceDeletionImpactService;
use App\Services\InventoryActionApprovalService;
use App\Services\InventoryMovementService;
use App\Services\SuperAdminSaleNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InventoryDispositionController extends BaseController
{
    public function __construct(
        private readonly InventoryActionApprovalService $approvalService,
        private readonly InventoryMovementService $movementService,
        private readonly SuperAdminSaleNotificationService $superAdminSaleNotificationService,
        private readonly CatalogDeletionImpactService $deletionImpact,
        private readonly DeviceDeletionImpactService $deviceDeletionImpact,
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
        ]), 'A verification PIN was sent by email and is available securely in notifications.', 201);
    }

    public function requestDeviceDeletion(Request $request, Device $device): JsonResponse
    {
        $this->authorizeSuperAdminDeletion($request);
        $devices = $this->deviceDeletionImpact->loadDevices([$device->id]);
        $currentDevice = $devices->firstOrFail();
        $this->authorizeInventoryBranch($request, $currentDevice);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'confirm_impact' => ['sometimes', 'boolean'],
            'impact_fingerprint' => ['required_with:confirm_impact', 'string', 'max:64'],
            'confirm_history_loss' => ['sometimes', 'boolean'],
        ]);
        $confirmImpact = (bool) ($validated['confirm_impact'] ?? false);
        $confirmHistoryLoss = (bool) ($validated['confirm_history_loss'] ?? false);
        $impact = $this->deviceDeletionImpact->forDevices($devices);

        if ($this->isStaleDeviceImpact($validated['impact_fingerprint'] ?? null, $impact)) {
            return $this->staleDeviceImpactConfirmation($impact);
        }

        if (! $confirmImpact) {
            return $this->deletionImpactConfirmation($impact, 'Review the device deletion impact and confirm to continue.', 'confirm_impact');
        }

        if ($this->requiresDeletionConfirmation($currentDevice) && ! $confirmHistoryLoss) {
            return $this->deletionImpactConfirmation($impact, 'This device has inventory history. Confirm the write-off and keep the audit movements.', 'confirm_history_loss');
        }

        $this->assertDeletable($currentDevice, $confirmHistoryLoss);

        $payload = [
            'device_id' => $currentDevice->id,
            'branch_id' => $currentDevice->branch_id,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
            'confirm_history_loss' => $confirmHistoryLoss,
            'impact' => $impact,
            'impact_fingerprint' => $this->deviceDeletionImpact->fingerprint($devices),
        ];
        $challenge = $this->approvalService->create($request->user(), InventoryActionChallenge::ACTION_DEVICE_DELETION, $payload, $currentDevice);

        return $this->success($this->challengeData($challenge, null, $impact), 'A verification PIN was sent by email and is available securely in notifications.', 202);
    }

    public function requestBulkDeviceDeletion(Request $request): JsonResponse
    {
        $this->authorizeSuperAdminDeletion($request);

        $validated = $request->validate([
            'device_ids' => ['required', 'array', 'min:2', 'max:50'],
            'device_ids.*' => ['integer', 'min:1', 'distinct'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'confirm_impact' => ['sometimes', 'boolean'],
            'impact_fingerprint' => ['required_with:confirm_impact', 'string', 'max:64'],
            'confirm_history_loss' => ['sometimes', 'boolean'],
        ]);
        $confirmImpact = (bool) ($validated['confirm_impact'] ?? false);
        $confirmHistoryLoss = (bool) ($validated['confirm_history_loss'] ?? false);
        $deviceIds = array_values(array_unique(array_map('intval', $validated['device_ids'])));
        $devices = $this->deviceDeletionImpact->loadDevices($deviceIds);

        if ($devices->count() !== count($deviceIds)) {
            throw ValidationException::withMessages([
                'device_ids' => ['One or more selected devices no longer exist or have already been deleted. Refresh the list and try again.'],
            ]);
        }

        foreach ($devices as $device) {
            $this->authorizeInventoryBranch($request, $device);
        }

        $impact = $this->deviceDeletionImpact->forDevices($devices);
        if ($this->isStaleDeviceImpact($validated['impact_fingerprint'] ?? null, $impact)) {
            return $this->staleDeviceImpactConfirmation($impact);
        }
        if (! $confirmImpact) {
            return $this->deletionImpactConfirmation($impact, 'Review the device deletion impact and confirm to continue.', 'confirm_impact');
        }

        $confirmationDevices = $devices->filter(fn (Device $device) => $this->requiresDeletionConfirmation($device));
        if ($confirmationDevices->isNotEmpty() && ! $confirmHistoryLoss) {
            return $this->deletionImpactConfirmation($impact, 'Some selected devices have inventory history. Confirm the write-off and keep the audit movements.', 'confirm_history_loss');
        }

        foreach ($devices as $device) {
            $this->assertDeletable($device, $confirmHistoryLoss);
        }

        $payload = [
            'device_ids' => $deviceIds,
            'branch_ids' => $devices->mapWithKeys(fn (Device $device) => [(string) $device->id => $device->branch_id])->all(),
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
            'confirm_history_loss' => $confirmHistoryLoss,
            'impact' => $impact,
            'impact_fingerprint' => $this->deviceDeletionImpact->fingerprint($devices),
        ];
        $firstDevice = $devices->firstOrFail();
        $challenge = $this->approvalService->create(
            $request->user(),
            InventoryActionChallenge::ACTION_DEVICE_DELETION,
            $payload,
            $firstDevice,
            count($deviceIds).' devices',
        );

        return $this->success($this->challengeData($challenge, null, $impact), 'A verification PIN was sent by email and is available securely in notifications.', 202);
    }

    public function deviceDeletionImpact(Request $request): JsonResponse
    {
        $this->authorizeSuperAdminDeletion($request);

        $validated = $request->validate([
            'device_ids' => ['required', 'array', 'min:1', 'max:50'],
            'device_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);

        $deviceIds = array_values(array_unique(array_map('intval', $validated['device_ids'])));
        $devices = $this->deviceDeletionImpact->loadDevices($deviceIds);

        if ($devices->count() !== count($deviceIds)) {
            throw ValidationException::withMessages([
                'device_ids' => ['One or more selected devices no longer exist or have already been deleted. Refresh the list and try again.'],
            ]);
        }

        foreach ($devices as $device) {
            $this->authorizeInventoryBranch($request, $device);
        }

        return $this->success($this->deviceDeletionImpact->forDevices($devices));
    }

    public function deviceDeletionImpactForDevice(Request $request, Device $device): JsonResponse
    {
        $this->authorizeSuperAdminDeletion($request);
        $devices = $this->deviceDeletionImpact->loadDevices([$device->id]);
        $currentDevice = $devices->firstOrFail();
        $this->authorizeInventoryBranch($request, $currentDevice);

        return $this->success($this->deviceDeletionImpact->forDevices($devices));
    }

    public function resend(Request $request, InventoryActionChallenge $challenge): JsonResponse
    {
        $this->authorizeChallengeAction($request, $challenge);
        $deviceIds = $this->challengeDeviceIds($challenge->payload ?? []);
        $devices = Device::query()->whereIn('id', $deviceIds)->orderBy('id')->get();
        if ($devices->count() !== count($deviceIds)) {
            throw ValidationException::withMessages(['challenge' => ['One or more devices in this challenge no longer exist. Request a new deletion challenge.']]);
        }
        foreach ($devices as $device) {
            $this->authorizeInventoryBranch($request, $device);
        }
        // A catalog challenge should be named after the catalog entry, not the
        // first device that happens to sit inside it.
        $targetLabel = match ($challenge->action) {
            InventoryActionChallenge::ACTION_PRODUCT_MODEL_DELETION,
            InventoryActionChallenge::ACTION_BRAND_DELETION => $challenge->payload['subject_label'] ?? null,
            default => count($deviceIds) > 1 ? count($deviceIds).' devices' : null,
        };

        $challenge = $this->approvalService->resend(
            $challenge,
            $request->user(),
            $devices->firstOrFail(),
            $targetLabel,
        );

        return $this->success($this->challengeData($challenge), 'A new verification PIN was sent by email and is available securely in notifications.');
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
                    InventoryActionChallenge::ACTION_DEVICE_DELETION => $this->executeDeviceDeletion($request, $payload, $lockedChallenge),
                    InventoryActionChallenge::ACTION_PRODUCT_MODEL_DELETION,
                    InventoryActionChallenge::ACTION_BRAND_DELETION => $this->executeCatalogDeletion($request, $payload, $lockedChallenge),
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

    public function reveal(Request $request, InventoryActionChallenge $challenge): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);
        $this->authorizeChallengeAction($request, $challenge);

        abort_unless($challenge->requested_by_id === $request->user()->id, 403, 'Only the initiating administrator can reveal this PIN.');

        $deviceIds = $this->challengeDeviceIds($challenge->payload ?? []);
        $devices = Device::query()->whereIn('id', $deviceIds)->get();
        if ($devices->count() !== count($deviceIds)) {
            throw ValidationException::withMessages(['challenge' => ['One or more devices in this challenge no longer exist. Request a new PIN.']]);
        }
        foreach ($devices as $device) {
            $this->authorizeInventoryBranch($request, $device);
        }

        $pin = $this->approvalService->reveal($challenge, $request->user(), $validated['password']);

        return $this->success([
            'challenge_id' => $challenge->id,
            'pin' => $pin,
            'expires_at' => $challenge->expires_at?->toIso8601String(),
        ], 'PIN revealed securely.')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
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

        $this->superAdminSaleNotificationService->notifyAdministrativeSale($movement->fresh(['device', 'branch', 'createdBy']));

        return [
            'device' => new DeviceResource($device->fresh(['category', 'branch', 'soldBy', 'inventoryBatch'])),
            'movement' => new InventoryMovementResource($movement->fresh(['device', 'batch', 'branch', 'createdBy'])),
        ];
    }

    private function executeDeviceDeletion(Request $request, array $payload, InventoryActionChallenge $challenge): array
    {
        if (array_key_exists('device_ids', $payload)) {
            return $this->executeBulkDeviceDeletion($request, $payload, $challenge);
        }

        $device = Device::query()->lockForUpdate()->findOrFail((int) $payload['device_id']);
        $this->authorizeInventoryBranch($request, $device);
        $this->assertDeletable($device, (bool) ($payload['confirm_history_loss'] ?? false));

        if ((int) ($payload['branch_id'] ?? 0) !== (int) $device->branch_id) {
            throw ValidationException::withMessages(['challenge' => ['The device branch changed after this PIN was requested. Request a new PIN.']]);
        }
        $this->assertDeviceImpactUnchanged(collect([$device]), $payload);

        $deviceName = trim("{$device->brand} {$device->model}");
        $previousStatus = $device->inventory_status?->value;
        if ($device->inventory_status?->isOnHand()) {
            $this->movementService->recordWriteOff(
                $device,
                $request->user(),
                (string) $payload['reason'],
                $payload['notes'] ?? null,
                Device::class,
                $device->id,
                [
                    'action' => InventoryActionChallenge::ACTION_DEVICE_DELETION,
                    'challenge_id' => (string) $challenge->id,
                    'previous_status' => $previousStatus,
                ],
            );
        }

        // Location history is telemetry, not catalog data. The device is
        // soft-deleted, so its trail survives for audit instead of being
        // destroyed as collateral.
        $device->geofences()->delete();
        if ($device->iot_module_id) {
            // The foreign key lives on devices; IotModule has no device_id column.
            $device->update(['iot_module_id' => null]);
        }
        $device->delete();

        $this->logActivity(null, $request->user(), 'device_deleted', 'Device deleted after PIN verification', [
            'device_id' => $payload['device_id'],
            'reason' => $payload['reason'],
            'confirm_history_loss' => (bool) ($payload['confirm_history_loss'] ?? false),
        ]);

        return ['device_id' => (int) $payload['device_id'], 'device_name' => $deviceName];
    }

    private function executeBulkDeviceDeletion(Request $request, array $payload, InventoryActionChallenge $challenge): array
    {
        $deviceIds = $this->challengeDeviceIds($payload);
        $devices = Device::query()
            ->whereIn('id', $deviceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($devices->count() !== count($deviceIds)) {
            throw ValidationException::withMessages([
                'challenge' => ['One or more selected devices no longer exist. Request a new deletion challenge.'],
            ]);
        }

        foreach ($devices as $device) {
            $this->authorizeInventoryBranch($request, $device);
            $this->assertDeletable($device, (bool) ($payload['confirm_history_loss'] ?? false));

            $branchIds = $payload['branch_ids'] ?? null;
            if (! is_array($branchIds) || ! array_key_exists((string) $device->id, $branchIds)) {
                throw ValidationException::withMessages([
                    'challenge' => ['The selected device locations could not be verified. Request a new PIN.'],
                ]);
            }
            $storedBranchId = (int) $branchIds[(string) $device->id];
            if ($storedBranchId !== (int) $device->branch_id) {
                throw ValidationException::withMessages([
                    'challenge' => ['A selected device changed branches after this PIN was requested. Request a new PIN.'],
                ]);
            }
        }

        $this->assertDeviceImpactUnchanged($devices, $payload);

        $deletedDevices = [];
        foreach ($devices as $device) {
            $deviceName = trim("{$device->brand} {$device->model}");
            $previousStatus = $device->inventory_status?->value;
            if ($device->inventory_status?->isOnHand()) {
                $this->movementService->recordWriteOff(
                    $device,
                    $request->user(),
                    (string) $payload['reason'],
                    $payload['notes'] ?? null,
                    Device::class,
                    $device->id,
                    [
                        'action' => InventoryActionChallenge::ACTION_DEVICE_DELETION,
                        'challenge_id' => (string) $challenge->id,
                        'previous_status' => $previousStatus,
                        'bulk' => true,
                    ],
                );
            }

            // Keep the device's location history; only its zones are hidden.
            $device->geofences()->delete();
            if ($device->iot_module_id) {
                $device->update(['iot_module_id' => null]);
            }
            $device->delete();

            $this->logActivity(null, $request->user(), 'device_deleted', 'Device deleted after PIN verification', [
                'device_id' => $device->id,
                'reason' => $payload['reason'],
                'confirm_history_loss' => (bool) ($payload['confirm_history_loss'] ?? false),
                'bulk' => true,
            ]);
            $deletedDevices[] = ['device_id' => $device->id, 'device_name' => $deviceName];
        }

        return [
            'deleted_count' => count($deletedDevices),
            'deleted_device_ids' => $deviceIds,
            'devices' => $deletedDevices,
        ];
    }

    /**
     * Reject a challenge if the reviewed impact changed while the PIN was pending.
     *
     * @param  Collection<int, Device>  $devices
     */
    private function assertDeviceImpactUnchanged(Collection $devices, array $payload): void
    {
        $expectedFingerprint = $payload['impact_fingerprint'] ?? null;
        if (! is_string($expectedFingerprint) || $expectedFingerprint === '') {
            throw ValidationException::withMessages([
                'challenge' => ['This deletion challenge has no reviewed impact. Request a new deletion challenge.'],
            ]);
        }

        $deviceIds = $devices->pluck('id')->map(fn ($id) => (int) $id)->all();
        $currentDevices = $this->deviceDeletionImpact->loadDevices($deviceIds);
        if ($currentDevices->count() !== count($deviceIds)
            || ! hash_equals($expectedFingerprint, $this->deviceDeletionImpact->fingerprint($currentDevices))) {
            throw ValidationException::withMessages([
                'challenge' => ['The selected device impact changed after the PIN was requested. Review the impact and request a new PIN.'],
            ]);
        }
    }

    /**
     * Cascade a catalog deletion that was reviewed and PIN-approved.
     *
     * Devices are soft-deleted so their sale, warranty and ledger records keep
     * resolving, and every unit that was still on hand is written off so the
     * stock reduction is explained in the ledger instead of happening silently.
     * Units already sold are not written off again, because their outflow is
     * already recorded on the sale movement.
     */
    private function executeCatalogDeletion(Request $request, array $payload, InventoryActionChallenge $challenge): array
    {
        $deviceIds = $this->challengeDeviceIds($payload);
        $isBrand = $challenge->action === InventoryActionChallenge::ACTION_BRAND_DELETION;

        $devices = Device::query()
            ->whereIn('id', $deviceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($devices->count() !== count($deviceIds)) {
            throw ValidationException::withMessages([
                'challenge' => ['One or more devices in this challenge no longer exist. Request a new deletion challenge.'],
            ]);
        }

        $subject = $isBrand
            ? Brand::query()->lockForUpdate()->find((int) ($payload['brand_id'] ?? 0))
            : ProductModel::query()->lockForUpdate()->find((int) ($payload['product_model_id'] ?? 0));

        if (! $subject) {
            throw ValidationException::withMessages([
                'challenge' => ['The catalog entry no longer exists. Request a new deletion challenge.'],
            ]);
        }

        // Re-derive the scope and confirm it still matches the set that was
        // reviewed. Catalog links can move between the PIN request and here.
        $impact = $isBrand
            ? $this->deletionImpact->forBrand($subject)
            : $this->deletionImpact->forModel($subject);

        $currentIds = array_map(fn (array $device) => (int) $device['id'], $impact['detail']);
        $expectedIds = $deviceIds;
        sort($currentIds);
        sort($expectedIds);

        if ($currentIds !== $expectedIds) {
            throw ValidationException::withMessages([
                'challenge' => ['The devices linked to this catalog entry changed after this PIN was requested. Request a new deletion challenge.'],
            ]);
        }

        $reason = (string) $payload['reason'];
        $notes = $payload['notes'] ?? null;
        $subjectLabel = $isBrand
            ? $subject->name
            : trim(($subject->brand?->name ?? '').' '.$subject->name);

        $writtenOff = [];
        $deleted = [];

        foreach ($devices as $device) {
            $status = $device->inventory_status;

            if ($status?->isOnHand()) {
                $movement = $this->movementService->recordWriteOff(
                    $device,
                    $request->user(),
                    $reason,
                    $notes,
                    $isBrand ? Brand::class : ProductModel::class,
                    (int) $subject->id,
                    [
                        'action' => $challenge->action,
                        'catalog_entry' => $subjectLabel,
                        'challenge_id' => $challenge->id,
                        'previous_status' => $status->value,
                    ],
                );

                $writtenOff[] = [
                    'movement_id' => $movement->id,
                    'movement_number' => $movement->movement_number,
                    'device_id' => $device->id,
                    'previous_status' => $status->value,
                    'unit_cost' => $movement->unit_cost,
                ];
            }

            // Keep the device's location history; only its zones are hidden.
            $device->geofences()->delete();
            if ($device->iot_module_id) {
                $device->update(['iot_module_id' => null]);
            }
            $device->delete();

            $deleted[] = ['device_id' => $device->id, 'inventory_status' => $status?->value];
        }

        $subjectId = (int) $subject->id;
        $subject->delete();

        $this->logActivity(null, $request->user(), $challenge->action, ucfirst($this->approvalService->labelFor($challenge->action)).' completed after PIN verification', [
            'challenge_id' => $challenge->id,
            'catalog_entry' => $subjectLabel,
            'catalog_entry_id' => $subjectId,
            'reason' => $reason,
            'deleted_device_count' => count($deleted),
            'written_off_device_count' => count($writtenOff),
            'deleted_device_ids' => array_column($deleted, 'device_id'),
        ]);

        return [
            'action' => $challenge->action,
            'catalog_entry' => $subjectLabel,
            'catalog_entry_id' => $subjectId,
            'deleted_count' => count($deleted),
            'written_off_count' => count($writtenOff),
            'devices' => $deleted,
            'write_offs' => $writtenOff,
        ];
    }

    private function challengeData(InventoryActionChallenge $challenge, ?array $financial = null, ?array $impact = null): array
    {
        $challenge->loadMissing('requestedBy');

        $data = [
            'challenge_id' => $challenge->id,
            'action' => $challenge->action,
            'masked_email' => $this->approvalService->maskedEmail($this->approvalService->approvalEmail()),
            'expires_at' => $challenge->expires_at?->toIso8601String(),
            'attempts_remaining' => max(0, $challenge->max_attempts - $challenge->attempts),
        ];
        if (isset($challenge->payload['device_ids']) && is_array($challenge->payload['device_ids'])) {
            $data['device_count'] = count($challenge->payload['device_ids']);
        } elseif ($challenge->action === InventoryActionChallenge::ACTION_DEVICE_DELETION && isset($challenge->payload['device_id'])) {
            $data['device_count'] = 1;
        }
        if ($financial !== null) {
            $data['financial'] = $financial;
        }
        $storedImpact = $impact ?? (is_array($challenge->payload['impact'] ?? null) ? $challenge->payload['impact'] : null);
        if ($storedImpact !== null) {
            $data['impact'] = $storedImpact;
        }

        return $data;
    }

    private function authorizeSuperAdminDeletion(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a super admin can delete devices.');
    }

    private function authorizeChallengeAction(Request $request, InventoryActionChallenge $challenge): void
    {
        $user = $request->user();
        if ($challenge->action === InventoryActionChallenge::ACTION_ADMINISTRATIVE_SALE && ! $user->can('manage-inventory')) {
            abort(403, 'You do not have permission to complete administrative sales.');
        }
        if ($challenge->action === InventoryActionChallenge::ACTION_DEVICE_DELETION) {
            if (! $user->hasRole('super_admin')) {
                abort(403, 'Only a super admin can delete devices.');
            }
            if (! $user->can('delete-devices')) {
                abort(403, 'You do not have permission to delete devices.');
            }
        }
        if (in_array($challenge->action, [
            InventoryActionChallenge::ACTION_PRODUCT_MODEL_DELETION,
            InventoryActionChallenge::ACTION_BRAND_DELETION,
        ], true) && ! $user->hasRole('super_admin')) {
            abort(403, 'Only a super admin can override a catalog deletion.');
        }
    }

    private function authorizeInventoryBranch(Request $request, Device $device): void
    {
        $user = $request->user();
        if ($user->hasRole('super_admin')) {
            return;
        }
        $userBranchId = $user->branchScopeId();
        if (! $userBranchId || (int) $userBranchId !== (int) $device->branch_id) {
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
        return $this->deviceDeletionImpact->requiresHistoryConfirmation($device);
    }

    private function isStaleDeviceImpact(mixed $submittedFingerprint, array $impact): bool
    {
        return is_string($submittedFingerprint)
            && $submittedFingerprint !== ''
            && ! hash_equals((string) ($impact['fingerprint'] ?? ''), $submittedFingerprint);
    }

    private function staleDeviceImpactConfirmation(array $impact): JsonResponse
    {
        $message = 'The device deletion impact changed after it was reviewed. Review the updated impact and confirm again.';

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [
                'impact_fingerprint' => [$message],
            ],
            'requires_confirmation' => true,
            'impact_stale' => true,
            'impact' => $impact,
        ], 409);
    }

    private function deletionImpactConfirmation(array $impact, string $message, string $field): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [
                $field => [$message],
            ],
            'requires_confirmation' => true,
            'requires_history_confirmation' => (bool) ($impact['devices']['requires_history_confirmation'] ?? false),
            'impact' => $impact,
        ], 409);
    }

    /**
     * @return array<int, int>
     */
    private function challengeDeviceIds(array $payload): array
    {
        $ids = array_key_exists('device_ids', $payload)
            ? (array) $payload['device_ids']
            : [(int) ($payload['device_id'] ?? 0)];

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        if ($ids === []) {
            throw ValidationException::withMessages(['challenge' => ['This challenge does not contain valid devices. Request a new deletion challenge.']]);
        }

        return $ids;
    }

    private function assertDeletable(Device $device, bool $allowInventoryBatch = false): void
    {
        $this->deviceDeletionImpact->assertDeletable($device, $allowInventoryBatch);
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
