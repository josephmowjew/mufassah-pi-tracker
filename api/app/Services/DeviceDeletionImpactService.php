<?php

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Enums\InventoryStatus;
use App\Models\Device;
use App\Models\InventoryMovement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Computes and enforces the impact of removing selected devices from inventory.
 *
 * Device deletion is intentionally different from catalog deletion: catalog
 * deletion may write off a whole product family, while a device deletion must
 * make the impact of each selected unit explicit before a PIN is requested.
 */
class DeviceDeletionImpactService
{
    public function __construct(
        private readonly InventoryMovementService $movementService,
    ) {}

    /**
     * @param  array<int, int>  $deviceIds
     * @return Collection<int, Device>
     */
    public function loadDevices(array $deviceIds): Collection
    {
        return Device::query()
            ->with([
                'branch:id,name',
                'owner:id,name',
                'inventoryBatch:id,batch_number,buying_price',
                'iotModule:id,module_id',
            ])
            ->withCount([
                'locations',
                'geofences',
                'warranties',
                'ownershipHistory',
                'theftReports',
                'theftReports as open_theft_reports_count' => fn ($query) => $query->open(),
                'inventoryMovements',
                'inventoryMovements as protected_movements_count' => fn ($query) => $query->whereIn('movement_type', [
                    InventoryMovement::TYPE_SALE,
                    InventoryMovement::TYPE_SALE_CANCELLED,
                    InventoryMovement::TYPE_RESERVATION,
                    InventoryMovement::TYPE_RESERVATION_CANCELLED,
                    InventoryMovement::TYPE_REFUND,
                    InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
                ]),
            ])
            ->whereIn('id', $deviceIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Device>  $devices
     */
    public function forDevices(Collection $devices): array
    {
        $devices = $devices->sortBy('id')->values();
        $protected = $devices->filter(fn (Device $device) => $this->isProtected($device));
        $eligible = $devices->reject(fn (Device $device) => $this->isProtected($device));
        $historyConfirmation = $eligible->filter(fn (Device $device) => $this->requiresHistoryConfirmation($device));
        $onHand = $eligible->filter(fn (Device $device) => (bool) $device->inventory_status?->isOnHand());
        $alreadyOutOfStock = $devices->reject(fn (Device $device) => (bool) $device->inventory_status?->isOnHand());
        $byStatus = [];

        foreach (InventoryStatus::cases() as $status) {
            $byStatus[$status->value] = $devices->filter(fn (Device $device) => $device->inventory_status === $status)->count();
        }

        $unitCosts = $onHand->map(fn (Device $device) => $this->movementService->resolveUnitCost($device));
        $detail = $devices->map(fn (Device $device) => $this->deviceDetail($device))->values()->all();
        $geofenceCount = $eligible->sum(fn (Device $device) => (int) ($device->geofences_count ?? 0));
        $locationCount = $devices->sum(fn (Device $device) => (int) ($device->locations_count ?? 0));
        $movementCount = $devices->sum(fn (Device $device) => (int) ($device->inventory_movements_count ?? 0));
        $iotModuleCount = $eligible->filter(fn (Device $device) => $device->iot_module_id !== null)->count();
        $inventoryBatchCount = $devices->pluck('inventory_batch_id')->filter()->unique()->count();

        $impact = [
            'subject' => [
                'type' => 'device_selection',
                'count' => $devices->count(),
                'branches' => $this->branchSummary($devices),
            ],
            'devices' => [
                'total' => $devices->count(),
                'eligible' => $eligible->count(),
                'blocked' => $protected->count(),
                'requires_history_confirmation' => $historyConfirmation->count(),
                'by_status' => $byStatus,
                'will_be_deleted' => $eligible->count(),
                'will_be_written_off' => $onHand->count(),
                'already_out_of_stock' => $alreadyOutOfStock->count(),
                'with_protected_history' => $protected->count(),
                'protected_reasons' => $this->protectionSummary($protected),
            ],
            'stock_impact' => [
                'units_leaving_stock' => $onHand->count(),
                'units_already_out_of_stock' => $alreadyOutOfStock->count(),
                'value_at_cost' => round($unitCosts->filter()->sum(), 2),
                'value_at_cost_is_partial' => $unitCosts->contains(fn ($cost) => $cost === null),
            ],
            'related' => [
                'iot_modules' => $iotModuleCount,
                'iot_modules_unlinked' => $iotModuleCount,
                'geofences' => $geofenceCount,
                'geofences_removed' => $geofenceCount,
                'locations' => $locationCount,
                'locations_preserved' => $locationCount,
                'inventory_batches' => $inventoryBatchCount,
                'inventory_movements' => $movementCount,
                'inventory_movements_preserved' => $movementCount,
                'ownership_records_preserved' => $devices->sum(fn (Device $device) => (int) ($device->ownership_history_count ?? 0)),
                'warranties_preserved' => $devices->sum(fn (Device $device) => (int) ($device->warranties_count ?? 0)),
                'theft_reports_preserved' => $devices->sum(fn (Device $device) => (int) ($device->theft_reports_count ?? 0)),
                'open_theft_reports' => $devices->sum(fn (Device $device) => (int) ($device->open_theft_reports_count ?? 0)),
                'photos_preserved' => $devices->sum(fn (Device $device) => is_array($device->photos) ? count($device->photos) : 0),
                'sale_records_blocking' => $devices->filter(fn (Device $device) => $device->sale_id !== null)->count(),
                'stock_transfers_blocking' => $devices->filter(fn (Device $device) => $device->stock_transfer_request_id !== null)->count(),
            ],
            'side_effects' => [
                'soft_delete_devices' => true,
                'write_off_on_hand_stock' => true,
                'remove_geofences' => true,
                'unlink_iot_modules' => true,
                'preserve_location_history' => true,
                'preserve_inventory_movements' => true,
                'preserve_catalog_records' => true,
            ],
            'detail' => $detail,
            'warnings' => $this->warnings($devices, $protected, $historyConfirmation, $onHand, $unitCosts, $iotModuleCount, $geofenceCount),
        ];

        $impact['fingerprint'] = $this->fingerprintImpact($impact);

        return $impact;
    }

    /**
     * Return a stable fingerprint of the reviewed impact. It is stored in the
     * challenge and checked again at PIN verification time.
     *
     * @param  Collection<int, Device>  $devices
     */
    public function fingerprint(Collection $devices): string
    {
        return (string) $this->forDevices($devices)['fingerprint'];
    }

    private function fingerprintImpact(array $impact): string
    {
        return hash('sha256', json_encode($impact, JSON_THROW_ON_ERROR));
    }

    public function isProtected(Device $device): bool
    {
        return $this->protectionReasons($device) !== [];
    }

    public function requiresHistoryConfirmation(Device $device): bool
    {
        return ! $this->isProtected($device) && $this->hasInventoryHistory($device);
    }

    public function assertDeletable(Device $device, bool $allowInventoryHistory = false): void
    {
        if ($this->isProtected($device) || (! $allowInventoryHistory && $this->hasInventoryHistory($device))) {
            throw ValidationException::withMessages([
                'device' => ['This device has ownership or inventory history and cannot be deleted. Use the appropriate inventory disposition instead.'],
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    public function protectionReasons(Device $device): array
    {
        $reasons = [];

        if ($device->inventory_status === InventoryStatus::SOLD) {
            $reasons[] = 'already sold';
        }
        if ($device->inventory_status === InventoryStatus::RESERVED) {
            $reasons[] = 'reserved against a pending sale';
        }
        if ($device->sale_id) {
            $reasons[] = 'attached to a sale record';
        }
        if ($device->owner_id) {
            $reasons[] = 'assigned to an owner';
        }
        if ($device->stock_transfer_request_id) {
            $reasons[] = 'awaiting stock transfer approval';
        }
        if ($device->status === DeviceStatus::STOLEN) {
            $reasons[] = 'flagged as stolen';
        }
        if ($this->openTheftReportExists($device)) {
            $reasons[] = 'has an open theft report';
        }
        if ($this->relationExists($device, 'warranties', 'warranties_count')) {
            $reasons[] = 'under warranty';
        }
        if ($this->relationExists($device, 'ownershipHistory', 'ownership_history_count')) {
            $reasons[] = 'has ownership history';
        }
        if ($this->protectedMovementExists($device)) {
            $reasons[] = 'has sale or reservation movements in the ledger';
        }

        return $reasons;
    }

    private function hasInventoryHistory(Device $device): bool
    {
        return $device->inventory_batch_id !== null
            || $this->relationExists($device, 'inventoryMovements', 'inventory_movements_count');
    }

    private function openTheftReportExists(Device $device): bool
    {
        if (array_key_exists('open_theft_reports_count', $device->getAttributes())) {
            return (int) $device->getAttribute('open_theft_reports_count') > 0;
        }

        return $device->theftReports()->open()->exists();
    }

    private function protectedMovementExists(Device $device): bool
    {
        if (array_key_exists('protected_movements_count', $device->getAttributes())) {
            return (int) $device->getAttribute('protected_movements_count') > 0;
        }

        return $device->inventoryMovements()->whereIn('movement_type', [
            InventoryMovement::TYPE_SALE,
            InventoryMovement::TYPE_SALE_CANCELLED,
            InventoryMovement::TYPE_RESERVATION,
            InventoryMovement::TYPE_RESERVATION_CANCELLED,
            InventoryMovement::TYPE_REFUND,
            InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
        ])->exists();
    }

    private function relationExists(Device $device, string $relation, string $countAttribute): bool
    {
        if (array_key_exists($countAttribute, $device->getAttributes())) {
            return (int) $device->getAttribute($countAttribute) > 0;
        }

        return $device->{$relation}()->exists();
    }

    /**
     * @param  Collection<int, Device>  $protected
     * @return array<string, int>
     */
    private function protectionSummary(Collection $protected): array
    {
        $summary = [];
        foreach ($protected as $device) {
            foreach ($this->protectionReasons($device) as $reason) {
                $summary[$reason] = ($summary[$reason] ?? 0) + 1;
            }
        }

        return $summary;
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<int, array{id: int, name: string|null, count: int}>
     */
    private function branchSummary(Collection $devices): array
    {
        return $devices->groupBy('branch_id')->map(function (Collection $branchDevices, $branchId) {
            return [
                'id' => (int) $branchId,
                'name' => $branchDevices->first()?->branch?->name,
                'count' => $branchDevices->count(),
            ];
        })->values()->all();
    }

    private function deviceDetail(Device $device): array
    {
        $status = $device->inventory_status;
        $reasons = $this->protectionReasons($device);
        $isProtected = $reasons !== [];
        $willBeWrittenOff = ! $isProtected && (bool) $status?->isOnHand();

        return [
            'id' => $device->id,
            'unique_device_code' => $device->unique_device_code,
            'serial_number' => $device->serial_number,
            'brand' => $device->brand,
            'model' => $device->model,
            'color' => $device->color,
            'inventory_status' => $status?->value,
            'inventory_status_label' => $status?->label(),
            'tracking_status' => $device->status?->value,
            'open_theft_report_count' => (int) ($device->open_theft_reports_count ?? 0),
            'branch' => $device->branch?->name,
            'branch_id' => $device->branch_id,
            'owner' => $device->owner?->name,
            'batch_number' => $device->inventoryBatch?->batch_number,
            'iot_module_id' => $device->iotModule?->module_id,
            'geofence_count' => (int) ($device->geofences_count ?? 0),
            'location_count' => (int) ($device->locations_count ?? 0),
            'inventory_movement_count' => (int) ($device->inventory_movements_count ?? 0),
            'ownership_record_count' => (int) ($device->ownership_history_count ?? 0),
            'warranty_count' => (int) ($device->warranties_count ?? 0),
            'will_be_deleted' => ! $isProtected,
            'will_be_written_off' => $willBeWrittenOff,
            'unit_cost' => $this->movementService->resolveUnitCost($device),
            'protected' => $isProtected,
            'requires_confirmation' => $this->requiresHistoryConfirmation($device),
            'protection_reasons' => $reasons,
        ];
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, Device>  $protected
     * @param  Collection<int, Device>  $historyConfirmation
     * @param  Collection<int, Device>  $onHand
     * @param  Collection<int, float|null>  $unitCosts
     * @return array<int, string>
     */
    private function warnings(
        Collection $devices,
        Collection $protected,
        Collection $historyConfirmation,
        Collection $onHand,
        Collection $unitCosts,
        int $iotModuleCount,
        int $geofenceCount,
    ): array {
        $warnings = [];
        $soldCount = $devices->filter(fn (Device $device) => $device->inventory_status === InventoryStatus::SOLD)->count();
        $reservedCount = $devices->filter(fn (Device $device) => $device->inventory_status === InventoryStatus::RESERVED)->count();

        if ($protected->isNotEmpty()) {
            $warnings[] = $protected->count().' selected device(s) are protected by sale, ownership, warranty, theft, reservation or transfer history and cannot be deleted.';
        }
        if ($reservedCount > 0) {
            $warnings[] = $reservedCount.' selected device(s) are reserved against pending sales and cannot be deleted.';
        }
        if ($soldCount > 0) {
            $warnings[] = $soldCount.' sold device(s) are protected and will not be written off again.';
        }
        if ($historyConfirmation->isNotEmpty()) {
            $warnings[] = $historyConfirmation->count().' selected device(s) have inventory history. Confirm the write-off and keep the audit movements.';
        }
        if ($onHand->isNotEmpty()) {
            $warnings[] = $onHand->count().' on-hand device(s) will be written off in the stock ledger before removal.';
        }
        if ($iotModuleCount > 0) {
            $warnings[] = $iotModuleCount.' linked IoT module(s) will be unlinked from the deleted device(s).';
        }
        if ($geofenceCount > 0) {
            $warnings[] = $geofenceCount.' geofence(s) will be removed. Location history, ownership/sale records, warranties, theft reports and inventory movements will be preserved.';
        }
        if ($unitCosts->contains(fn ($cost) => $cost === null) && $unitCosts->isNotEmpty()) {
            $warnings[] = 'Some selected devices have no available cost, so the stock value shown is partial.';
        }
        if ($devices->isNotEmpty()) {
            $warnings[] = 'Devices are soft-deleted. Brand, model and category records are not deleted.';
        }

        return $warnings;
    }
}
