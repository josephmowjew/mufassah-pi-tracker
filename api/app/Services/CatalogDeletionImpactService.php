<?php

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Models\Brand;
use App\Models\Device;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\ProductModel;
use Illuminate\Support\Collection;

/**
 * Describes what deleting a catalog entry will actually do, before it happens.
 *
 * Catalog identity is stored twice in this application: as foreign keys
 * (devices.brand_id, devices.product_model_id) and as free text
 * (devices.brand / devices.model) that pre-dates the catalog. The foreign
 * keys are authoritative for deletion scope because that is what the device
 * relations and the existing guards use. The text identity is reported
 * separately so a mismatch becomes visible instead of silently leaving
 * devices behind.
 */
class CatalogDeletionImpactService
{
    public function __construct(
        private readonly InventoryMovementService $movementService,
    ) {}

    public function forModel(ProductModel $model): array
    {
        $model->loadMissing('brand.category');
        $brand = $model->brand;

        return $this->build(
            subject: [
                'type' => 'product_model',
                'id' => $model->id,
                'name' => $model->name,
                'brand' => $brand?->name,
                'brand_id' => $model->brand_id,
                'category' => $brand?->category?->name,
                'category_id' => $brand?->category_id,
                'base_price' => $model->base_price,
            ],
            devices: $this->devicesForModel($model),
            textMatchCount: $this->textMatchedDeviceCount($brand?->name, $model->name),
            related: [
                'product_models' => 0,
                'inventory_batches' => $this->batchQuery($brand?->name, $model->name)->count(),
                'inventory_movements' => $this->movementQuery($brand?->name, $model->name)->count(),
            ],
        );
    }

    public function forBrand(Brand $brand): array
    {
        $brand->loadMissing('category');

        return $this->build(
            subject: [
                'type' => 'brand',
                'id' => $brand->id,
                'name' => $brand->name,
                'brand' => $brand->name,
                'brand_id' => $brand->id,
                'category' => $brand->category?->name,
                'category_id' => $brand->category_id,
                'base_price' => null,
            ],
            devices: $this->devicesForBrand($brand),
            textMatchCount: $this->textMatchedDeviceCount($brand->name, null),
            related: [
                'product_models' => $brand->productModels()->count(),
                'inventory_batches' => $this->batchQuery($brand->name, null)->count(),
                'inventory_movements' => $this->movementQuery($brand->name, null)->count(),
            ],
        );
    }

    /**
     * @return Collection<int, Device>
     */
    public function devicesForModel(ProductModel $model): Collection
    {
        return $this->deviceQuery()->where('product_model_id', $model->id)->get();
    }

    /**
     * Deleting a brand cascades to its product models at the database level,
     * so the blast radius is every device linked by brand_id *or* by one of
     * the brand's models. Scoping on brand_id alone would under-report.
     *
     * @return Collection<int, Device>
     */
    public function devicesForBrand(Brand $brand): Collection
    {
        $modelIds = $brand->productModels()->select('id');

        return $this->deviceQuery()
            ->where(function ($query) use ($brand, $modelIds) {
                $query->where('brand_id', $brand->id)
                    ->orWhereIn('product_model_id', $modelIds);
            })
            ->get();
    }

    private function deviceQuery()
    {
        return Device::query()
            ->with([
                'branch:id,name',
                'owner:id,name',
                'inventoryBatch:id,batch_number,buying_price',
            ])
            ->withCount([
                'warranties',
                'ownershipHistory',
                'inventoryMovements as protected_movements_count' => fn ($query) => $query->whereIn('movement_type', [
                    InventoryMovement::TYPE_SALE,
                    InventoryMovement::TYPE_SALE_CANCELLED,
                    InventoryMovement::TYPE_RESERVATION,
                    InventoryMovement::TYPE_RESERVATION_CANCELLED,
                    InventoryMovement::TYPE_REFUND,
                    InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
                ]),
            ])
            ->orderBy('id');
    }

    private function build(array $subject, Collection $devices, int $textMatchCount, array $related): array
    {
        $byStatus = [];
        foreach (InventoryStatus::cases() as $status) {
            $byStatus[$status->value] = $devices
                ->filter(fn (Device $device) => $device->inventory_status === $status)
                ->count();
        }

        $onHand = $devices->filter(fn (Device $device) => $device->inventory_status?->isOnHand() ?? false);
        $alreadyOut = $devices->reject(fn (Device $device) => $device->inventory_status?->isOnHand() ?? false);
        $protected = $devices->filter(fn (Device $device) => $this->protectionReasons($device) !== []);

        $detail = $devices->map(fn (Device $device) => $this->deviceDetail($device))->values()->all();
        $unlinkedTextMatches = max(0, $textMatchCount - $devices->count());

        return [
            'subject' => $subject,
            'devices' => [
                'total' => $devices->count(),
                'by_status' => $byStatus,
                'will_be_deleted' => $devices->count(),
                'will_be_written_off' => $onHand->count(),
                'already_out_of_stock' => $alreadyOut->count(),
                'with_protected_history' => $protected->count(),
                'protected_reasons' => $this->protectionSummary($protected),
                'unlinked_text_matches' => $unlinkedTextMatches,
            ],
            'stock_impact' => [
                'units_leaving_stock' => $onHand->count(),
                'units_already_out_of_stock' => $alreadyOut->count(),
                'value_at_cost' => round($onHand->sum(fn (Device $device) => $this->movementService->resolveUnitCost($device) ?? 0.0), 2),
                'value_at_cost_is_partial' => $onHand->contains(fn (Device $device) => $this->movementService->resolveUnitCost($device) === null),
            ],
            'related' => $related,
            'detail' => $detail,
            'warnings' => $this->warnings($subject, $devices, $protected, $byStatus, $unlinkedTextMatches),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function protectionReasons(Device $device): array
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
        if (($device->warranties_count ?? 0) > 0) {
            $reasons[] = 'under warranty';
        }
        if (($device->ownership_history_count ?? 0) > 0) {
            $reasons[] = 'has ownership history';
        }
        if (($device->protected_movements_count ?? 0) > 0) {
            $reasons[] = 'has sale or reservation movements in the ledger';
        }

        return $reasons;
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

    private function deviceDetail(Device $device): array
    {
        $status = $device->inventory_status;
        $reasons = $this->protectionReasons($device);

        return [
            'id' => $device->id,
            'unique_device_code' => $device->unique_device_code,
            'serial_number' => $device->serial_number,
            'brand' => $device->brand,
            'model' => $device->model,
            'color' => $device->color,
            'inventory_status' => $status?->value,
            'inventory_status_label' => $status?->label(),
            'branch' => $device->branch?->name,
            'owner' => $device->owner?->name,
            'batch_number' => $device->inventoryBatch?->batch_number,
            'will_be_written_off' => (bool) $status?->isOnHand(),
            'unit_cost' => $this->movementService->resolveUnitCost($device),
            'protected' => $reasons !== [],
            'protection_reasons' => $reasons,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function warnings(array $subject, Collection $devices, Collection $protected, array $byStatus, int $unlinkedTextMatches): array
    {
        $warnings = [];
        $label = trim(($subject['brand'] ?? '').' '.($subject['name'] ?? '')) ?: 'this catalog entry';

        if ($devices->isEmpty()) {
            return $warnings;
        }

        if ($protected->isNotEmpty()) {
            $warnings[] = $protected->count().' device(s) have sale, ownership, warranty or transfer history. Deleting them removes the device from those records; the sale, warranty and ledger records themselves are retained.';
        }

        if (($byStatus[InventoryStatus::RESERVED->value] ?? 0) > 0) {
            $warnings[] = $byStatus[InventoryStatus::RESERVED->value].' device(s) are reserved against pending sales. Those reservations will be left without a device.';
        }

        if (($byStatus[InventoryStatus::SOLD->value] ?? 0) > 0) {
            $warnings[] = $byStatus[InventoryStatus::SOLD->value].' device(s) are already sold and will not be written off again, because their stock outflow is already recorded on the sale movement.';
        }

        if ($unlinkedTextMatches > 0) {
            $warnings[] = $unlinkedTextMatches.' additional device(s) reference '.$label.' by text only and are not linked to the catalog. They will not be deleted, and they will keep their text identity.';
        }

        $warnings[] = 'This entry is soft deleted, not destroyed, so it can be restored. If new stock for '.$label.' is received later, the inventory intake form will restore this same entry rather than create a duplicate.';

        return $warnings;
    }

    private function textMatchedDeviceCount(?string $brandName, ?string $modelName): int
    {
        if (! $brandName) {
            return 0;
        }

        $query = Device::query()->where('brand', $brandName);
        if ($modelName !== null) {
            $query->where('model', $modelName);
        }

        return $query->count();
    }

    private function batchQuery(?string $brandName, ?string $modelName)
    {
        $query = InventoryBatch::query();
        if ($brandName) {
            $query->where('brand', $brandName);
        }
        if ($modelName !== null) {
            $query->where('model', $modelName);
        }

        return $query;
    }

    private function movementQuery(?string $brandName, ?string $modelName)
    {
        $query = InventoryMovement::query();
        if ($brandName) {
            $query->where('brand', $brandName);
        }
        if ($modelName !== null) {
            $query->where('model', $modelName);
        }

        return $query;
    }
}
