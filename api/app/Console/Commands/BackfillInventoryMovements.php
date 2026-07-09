<?php

namespace App\Console\Commands;

use App\Enums\InventoryStatus;
use App\Enums\SaleStatus;
use App\Models\Device;
use App\Models\InventoryMovement;
use App\Models\SaleItem;
use App\Services\InventoryMovementService;
use Illuminate\Console\Command;

class BackfillInventoryMovements extends Command
{
    protected $signature = 'inventory:backfill-movements';

    protected $description = 'Backfill inventory movement ledger rows from existing receipts, batches, sales, and device statuses.';

    public function handle(InventoryMovementService $movementService): int
    {
        $created = 0;

        Device::with(['inventoryBatch.receipt'])->orderBy('id')->chunkById(200, function ($devices) use ($movementService, &$created) {
            foreach ($devices as $device) {
                $batch = $device->inventoryBatch;
                $receipt = $batch?->receipt;

                if ($batch && ! $this->movementExists(InventoryMovement::TYPE_RECEIPT, $device->id, $receipt?->id ?? $batch->id)) {
                    $movement = $movementService->recordReceipt($device, $batch, $receipt, null, ['source' => 'backfill']);
                    $movement->forceFill([
                        'is_backfilled' => true,
                        'created_at' => $receipt?->received_at ?? $batch->created_at ?? $device->created_at,
                        'updated_at' => $receipt?->received_at ?? $batch->created_at ?? $device->created_at,
                    ])->save();
                    $created++;
                }
            }
        });

        SaleItem::with(['sale', 'device'])->whereNotNull('device_id')->orderBy('id')->chunkById(200, function ($items) use ($movementService, &$created) {
            foreach ($items as $item) {
                $sale = $item->sale;
                $device = $item->device;
                if (! $sale || ! $device) {
                    continue;
                }

                if ($sale->status === SaleStatus::COMPLETED && ! $this->movementExists(InventoryMovement::TYPE_SALE, $device->id, $sale->id)) {
                    $movement = $movementService->recordSale($device, $sale, $item, null, InventoryStatus::IN_STOCK->value);
                    $movement->forceFill([
                        'is_backfilled' => true,
                        'created_at' => $sale->sale_date ?? $sale->created_at,
                        'updated_at' => $sale->sale_date ?? $sale->created_at,
                        'metadata' => ['source' => 'backfill'],
                    ])->save();
                    $created++;
                }

                if ($sale->status === SaleStatus::DRAFT && ! $this->movementExists(InventoryMovement::TYPE_RESERVATION, $device->id, $sale->id)) {
                    $movement = $movementService->recordReservation($device, $sale, $item);
                    $movement->forceFill([
                        'is_backfilled' => true,
                        'created_at' => $sale->created_at,
                        'updated_at' => $sale->created_at,
                        'metadata' => ['source' => 'backfill'],
                    ])->save();
                    $created++;
                }
            }
        });

        Device::whereIn('inventory_status', [InventoryStatus::FAULTY, InventoryStatus::RETURNED])->orderBy('id')->chunkById(200, function ($devices) use ($movementService, &$created) {
            foreach ($devices as $device) {
                $type = $device->inventory_status === InventoryStatus::FAULTY
                    ? InventoryMovement::TYPE_FAULTY
                    : InventoryMovement::TYPE_RETURNED;

                if ($this->movementExists($type, $device->id, $device->id)) {
                    continue;
                }

                $movement = $movementService->recordStatusChange($device, null, $device->inventory_status->value, null, 'Historical status backfill', $device->notes);
                $movement->forceFill([
                    'is_backfilled' => true,
                    'created_at' => $device->updated_at ?? $device->created_at,
                    'updated_at' => $device->updated_at ?? $device->created_at,
                    'metadata' => ['source' => 'backfill'],
                ])->save();
                $created++;
            }
        });

        $this->info("Backfilled {$created} inventory movement row(s).");

        return self::SUCCESS;
    }

    private function movementExists(string $type, int $deviceId, ?int $referenceId): bool
    {
        return InventoryMovement::query()
            ->where('movement_type', $type)
            ->where('device_id', $deviceId)
            ->when($referenceId, fn ($q) => $q->where('reference_id', $referenceId))
            ->exists();
    }
}
