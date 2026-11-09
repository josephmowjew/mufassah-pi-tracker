<?php

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Models\Device;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Database\QueryException;

class InventoryMovementService
{
    public function recordReceipt(Device $device, ?InventoryBatch $batch = null, ?InventoryReceipt $receipt = null, ?User $user = null, array $metadata = []): InventoryMovement
    {
        return $this->recordDeviceMovement($device, [
            'movement_type' => InventoryMovement::TYPE_RECEIPT,
            'inventory_batch_id' => $batch?->id ?? $device->inventory_batch_id,
            'inventory_receipt_id' => $receipt?->id ?? $batch?->inventory_receipt_id,
            'branch_id' => $device->branch_id,
            'to_branch_id' => $device->branch_id,
            'new_status' => InventoryStatus::IN_STOCK->value,
            'reference_type' => $receipt ? InventoryReceipt::class : InventoryBatch::class,
            'reference_id' => $receipt?->id ?? $batch?->id,
            'created_by_id' => $user?->id,
            'metadata' => $metadata,
        ]);
    }

    public function recordReservation(Device $device, Sale $sale, ?SaleItem $saleItem = null, ?User $user = null): InventoryMovement
    {
        return $this->recordDeviceMovement($device, [
            'movement_type' => InventoryMovement::TYPE_RESERVATION,
            'sale_id' => $sale->id,
            'sale_item_id' => $saleItem?->id,
            'branch_id' => $device->branch_id,
            'previous_status' => InventoryStatus::IN_STOCK->value,
            'new_status' => InventoryStatus::RESERVED->value,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'created_by_id' => $user?->id,
        ]);
    }

    public function recordInvoiceReservation(Device $device, Invoice $invoice, ?InvoiceItem $invoiceItem = null, ?User $user = null, ?string $previousStatus = null): InventoryMovement
    {
        return $this->recordDeviceMovement($device, [
            'movement_type' => InventoryMovement::TYPE_RESERVATION,
            'branch_id' => $device->branch_id,
            'previous_status' => $previousStatus ?? InventoryStatus::IN_STOCK->value,
            'new_status' => InventoryStatus::RESERVED->value,
            'reference_type' => Invoice::class,
            'reference_id' => $invoice->id,
            'created_by_id' => $user?->id,
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_item_id' => $invoiceItem?->id,
                'expires_at' => $invoice->expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function recordInvoiceReservationCancelled(Device $device, Invoice $invoice, ?InvoiceItem $invoiceItem = null, ?User $user = null, ?string $reason = null, ?string $newStatus = null): InventoryMovement
    {
        return $this->recordDeviceMovement($device, [
            'movement_type' => InventoryMovement::TYPE_RESERVATION_CANCELLED,
            'branch_id' => $device->branch_id,
            'previous_status' => InventoryStatus::RESERVED->value,
            'new_status' => $newStatus ?? InventoryStatus::IN_STOCK->value,
            'reference_type' => Invoice::class,
            'reference_id' => $invoice->id,
            'reason' => $reason,
            'created_by_id' => $user?->id,
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_item_id' => $invoiceItem?->id,
            ],
        ]);
    }

    public function recordSale(Device $device, Sale $sale, ?SaleItem $saleItem = null, ?User $user = null, ?string $previousStatus = null): InventoryMovement
    {
        return $this->recordDeviceMovement($device, [
            'movement_type' => InventoryMovement::TYPE_SALE,
            'sale_id' => $sale->id,
            'sale_item_id' => $saleItem?->id,
            'branch_id' => $device->branch_id,
            'previous_status' => $previousStatus ?? InventoryStatus::IN_STOCK->value,
            'new_status' => InventoryStatus::SOLD->value,
            'previous_owner_id' => null,
            'new_owner_id' => $sale->customer_id,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'created_by_id' => $user?->id,
        ]);
    }

    public function recordReservationCancelled(Device $device, Sale $sale, ?SaleItem $saleItem = null, ?User $user = null, ?string $reason = null): InventoryMovement
    {
        return $this->recordDeviceMovement($device, [
            'movement_type' => InventoryMovement::TYPE_RESERVATION_CANCELLED,
            'sale_id' => $sale->id,
            'sale_item_id' => $saleItem?->id,
            'branch_id' => $device->branch_id,
            'previous_status' => InventoryStatus::RESERVED->value,
            'new_status' => InventoryStatus::IN_STOCK->value,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'reason' => $reason,
            'created_by_id' => $user?->id,
        ]);
    }

    public function recordRefund(Device $device, Sale $sale, ?SaleItem $saleItem = null, ?User $user = null, ?string $reason = null, InventoryStatus $newStatus = InventoryStatus::IN_STOCK): InventoryMovement
    {
        return $this->recordDeviceMovement($device, [
            'movement_type' => InventoryMovement::TYPE_REFUND,
            'sale_id' => $sale->id,
            'sale_item_id' => $saleItem?->id,
            'branch_id' => $device->branch_id,
            'previous_status' => InventoryStatus::SOLD->value,
            'new_status' => $newStatus->value,
            'previous_owner_id' => $sale->customer_id,
            'new_owner_id' => null,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'reason' => $reason,
            'created_by_id' => $user?->id,
        ]);
    }

    public function recordStatusChange(Device $device, ?string $previousStatus, string $newStatus, ?User $user = null, ?string $reason = null, ?string $notes = null): InventoryMovement
    {
        $movementType = match ($newStatus) {
            InventoryStatus::FAULTY->value => InventoryMovement::TYPE_FAULTY,
            InventoryStatus::RETURNED->value => InventoryMovement::TYPE_RETURNED,
            default => $this->isPositiveAdjustment($previousStatus, $newStatus)
                ? InventoryMovement::TYPE_ADJUSTMENT_IN
                : InventoryMovement::TYPE_ADJUSTMENT_OUT,
        };

        return $this->recordDeviceMovement($device, [
            'movement_type' => $movementType,
            'branch_id' => $device->branch_id,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'reference_type' => Device::class,
            'reference_id' => $device->id,
            'reason' => $reason,
            'notes' => $notes,
            'created_by_id' => $user?->id,
        ]);
    }

    public function recordAdministrativeSale(
        Device $device,
        User $user,
        float|string $unitCost,
        float|string $unitPrice,
        string $reason,
        ?string $notes = null,
        ?string $pricingSource = null,
        ?string $challengeId = null,
        ?string $previousStatus = null,
    ): InventoryMovement {
        $quantity = 1;
        $unitCost = round((float) $unitCost, 2);
        $unitPrice = round((float) $unitPrice, 2);
        $totalCost = round($unitCost * $quantity, 2);
        $totalAmount = round($unitPrice * $quantity, 2);

        return $this->recordDeviceMovement($device, [
            'movement_type' => InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            'branch_id' => $device->branch_id,
            'previous_status' => $previousStatus ?? $device->inventory_status?->value,
            'new_status' => InventoryStatus::SOLD->value,
            'previous_owner_id' => $device->owner_id,
            'new_owner_id' => null,
            'reference_type' => Device::class,
            'reference_id' => $device->id,
            'reason' => $reason,
            'notes' => $notes,
            'created_by_id' => $user->id,
            'unit_cost' => $unitCost,
            'unit_price' => $unitPrice,
            'total_cost' => $totalCost,
            'total_amount' => $totalAmount,
            'profit_amount' => round($totalAmount - $totalCost, 2),
            'metadata' => [
                'pricing_source' => $pricingSource,
                'challenge_id' => $challengeId,
                'action' => InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            ],
        ]);
    }

    public function recordTransfer(Device $device, int $fromBranchId, int $toBranchId, ?User $user = null, ?string $reason = null, ?string $notes = null): InventoryMovement
    {
        return $this->recordDeviceMovement($device, [
            'movement_type' => InventoryMovement::TYPE_TRANSFER,
            'branch_id' => $toBranchId,
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $toBranchId,
            'previous_status' => $device->inventory_status?->value,
            'new_status' => $device->inventory_status?->value,
            'reference_type' => Device::class,
            'reference_id' => $device->id,
            'reason' => $reason,
            'notes' => $notes,
            'created_by_id' => $user?->id,
        ]);
    }

    /**
     * Record stock leaving inventory without a sale.
     *
     * Used when a catalog entry is deleted and its devices are removed with
     * it, so the ledger still explains why those units disappeared instead of
     * them vanishing silently.
     *
     * Callers must only write off devices that are still on hand. A sold
     * device already had its outflow recorded on the sale movement, and
     * writing it off again would double-count the reduction.
     */
    public function recordWriteOff(
        Device $device,
        User $user,
        string $reason,
        ?string $notes = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
    ): InventoryMovement {
        $device->loadMissing('inventoryBatch');

        $unitCost = $this->resolveUnitCost($device);

        return $this->recordDeviceMovement($device, [
            'movement_type' => InventoryMovement::TYPE_WRITE_OFF,
            'branch_id' => $device->branch_id,
            'previous_status' => $device->inventory_status?->value,
            'new_status' => null,
            'reference_type' => $referenceType ?? Device::class,
            'reference_id' => $referenceId ?? $device->id,
            'reason' => $reason,
            'notes' => $notes,
            'unit_cost' => $unitCost,
            'total_cost' => $unitCost,
            'created_by_id' => $user->id,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Best available cost for a unit: the device's own purchase price, then
     * the batch buying price. Null when neither is known, rather than
     * fabricating a zero that would understate the write-off.
     */
    public function resolveUnitCost(Device $device): ?float
    {
        if ($device->purchase_price !== null && (float) $device->purchase_price > 0) {
            return round((float) $device->purchase_price, 2);
        }

        $batchCost = $device->inventoryBatch?->buying_price;
        if ($batchCost !== null && (float) $batchCost > 0) {
            return round((float) $batchCost, 2);
        }

        return null;
    }

    public function recordDeviceMovement(Device $device, array $attributes): InventoryMovement
    {
        $device->loadMissing('inventoryBatch');

        return $this->createWithRetry([
            'device_id' => $device->id,
            'inventory_batch_id' => $attributes['inventory_batch_id'] ?? $device->inventory_batch_id,
            'inventory_receipt_id' => $attributes['inventory_receipt_id'] ?? $device->inventoryBatch?->inventory_receipt_id,
            'category_id' => $device->category_id,
            'branch_id' => $attributes['branch_id'] ?? $device->branch_id,
            'brand' => $device->brand,
            'model' => $device->model,
            'color' => $device->color,
            'quantity' => 1,
            ...$attributes,
        ]);
    }

    public function createWithRetry(array $attributes): InventoryMovement
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return InventoryMovement::create([
                    ...$attributes,
                    'movement_number' => InventoryMovement::generateMovementNumber(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e) || $attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to allocate an inventory movement number.');
    }

    private function isPositiveAdjustment(?string $previousStatus, string $newStatus): bool
    {
        return in_array($newStatus, [InventoryStatus::IN_STOCK->value, InventoryStatus::RETURNED->value], true)
            && ! in_array($previousStatus, [InventoryStatus::IN_STOCK->value, InventoryStatus::RETURNED->value], true);
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true) || $driverCode === '1062';
    }
}
