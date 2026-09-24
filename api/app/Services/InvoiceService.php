<?php

namespace App\Services;

use App\Enums\InventoryStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly InventoryMovementService $movementService,
    ) {}

    /**
     * Issue an invoice and reserve its exact devices in the same transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function issue(array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($data, $user): Invoice {
            $branchId = $this->resolveBranchId($data, $user);
            $customer = User::query()->whereKey($data['customer_id'])->firstOrFail();
            $recipientEmail = trim((string) ($data['recipient_email'] ?? $customer->email ?? ''));
            if (filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages([
                    'recipient_email' => ['A valid recipient email is required to issue an invoice.'],
                ]);
            }

            $vatApplicable = (bool) ($data['vat_applicable'] ?? true);
            $taxRate = $vatApplicable
                ? round((float) ($data['tax_rate'] ?? config('invoice.default_tax_rate', 17.5)), 2)
                : 0.0;

            if ($vatApplicable && $taxRate <= 0) {
                throw ValidationException::withMessages([
                    'tax_rate' => ['VAT rate must be greater than zero when VAT is enabled.'],
                ]);
            }

            $invoice = $this->createInvoiceWithRetry([
                'customer_id' => $customer->id,
                'branch_id' => $branchId,
                'created_by_id' => $user->id,
                'status' => InvoiceStatus::SENT->value,
                'public_token' => Str::random(80),
                'recipient_email' => $recipientEmail,
                'issued_at' => now(),
                'expires_at' => now()->addHours((int) config('invoice.validity_hours', 24)),
                'vat_applicable' => $vatApplicable,
                'tax_rate' => $taxRate,
                'tax_label' => trim((string) ($data['tax_label'] ?? 'VAT')) ?: 'VAT',
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 0,
                'currency' => 'MWK',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $itemData) {
                $devices = $this->resolveDevices($itemData, $branchId);

                $requestedQuantity = (int) ($itemData['quantity'] ?? 1);
                if ($devices->count() < $requestedQuantity) {
                    throw ValidationException::withMessages([
                        'items' => ["Not enough devices are available for one of the requested invoice products. Requested: {$requestedQuantity}, Available: {$devices->count()}."],
                    ]);
                }

                foreach ($devices as $device) {
                    $this->assertDeviceCanBeReserved($device, $branchId);
                    [$unitPrice, $pricingSource] = $this->resolveSellingPrice($device, $itemData, $user);
                    $discount = round((float) ($itemData['discount'] ?? 0), 2);
                    $costPrice = $this->resolveCostPrice($device);

                    if ($discount > $unitPrice) {
                        throw ValidationException::withMessages([
                            'items' => ['Invoice discount cannot be greater than the unit price.'],
                        ]);
                    }

                    $tax = $vatApplicable
                        ? round(max(0, $unitPrice - $discount) * ($taxRate / 100), 2)
                        : 0.0;
                    $totalPrice = round(($unitPrice - $discount) + $tax, 2);
                    $previousStatus = $device->inventory_status?->value ?? InventoryStatus::IN_STOCK->value;

                    $invoiceItem = $invoice->items()->create([
                        'device_id' => $device->id,
                        'inventory_batch_id' => $device->inventory_batch_id,
                        'branch_id' => $device->branch_id,
                        'subscription_plan_id' => $itemData['subscription_plan_id'] ?? null,
                        'item_description' => $this->descriptionForDevice($device),
                        'unit_price' => $unitPrice,
                        'discount' => $discount,
                        'tax_rate' => $taxRate,
                        'tax' => $tax,
                        'total_price' => $totalPrice,
                        'cost_price' => $costPrice,
                        'selling_price' => $unitPrice,
                        'profit_amount' => round(($unitPrice - $discount) - $costPrice, 2),
                        'pricing_source' => $pricingSource,
                        'product_specs' => $this->productSpecsForDevice($device),
                        'reserved_from_status' => $previousStatus,
                    ]);

                    $device->update([
                        'inventory_status' => InventoryStatus::RESERVED,
                        'sale_id' => null,
                        'reserved_invoice_id' => $invoice->id,
                    ]);

                    $this->movementService->recordInvoiceReservation(
                        $device->fresh(),
                        $invoice,
                        $invoiceItem,
                        $user,
                        $previousStatus,
                    );
                }
            }

            $invoice->load('items');
            $invoice->calculateTotals();
            $invoice->save();
            $this->logActivity($invoice, $user, 'issued', 'Invoice issued', [
                'total_amount' => $invoice->total_amount,
                'expires_at' => $invoice->expires_at?->toIso8601String(),
                'vat_applicable' => $invoice->vat_applicable,
            ]);

            return $invoice->fresh(['customer', 'branch', 'createdBy', 'items.device.category']);
        });
    }

    /**
     * Convert an active invoice to a sale using only its frozen snapshots.
     *
     * @param  array<string, mixed>  $data
     */
    public function redeem(Invoice $invoice, array $data, User $user): Invoice
    {
        if ($invoice->status === InvoiceStatus::SENT && $invoice->isExpired()) {
            $this->expireIfNeeded($invoice, $user);
        }

        return DB::transaction(function () use ($invoice, $data, $user): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === InvoiceStatus::CONVERTED) {
                return $invoice->load(['convertedSale', 'items.device.category']);
            }

            if ($invoice->status !== InvoiceStatus::SENT) {
                throw ValidationException::withMessages([
                    'invoice' => ['This invoice is no longer active and cannot be redeemed.'],
                ]);
            }

            if ($invoice->isExpired()) {
                throw ValidationException::withMessages([
                    'invoice' => ['This invoice has expired and its device reservation has been released.'],
                ]);
            }

            $paymentMethod = PaymentMethod::from($data['payment_method']);
            $completed = $paymentMethod !== PaymentMethod::Credit;
            $paymentAmount = array_key_exists('payment_amount', $data) && $data['payment_amount'] !== null
                ? round((float) $data['payment_amount'], 2)
                : null;

            if ($completed && $paymentMethod === PaymentMethod::Cash && $paymentAmount === null) {
                throw ValidationException::withMessages([
                    'payment_amount' => ['Payment amount is required when redeeming an invoice with cash.'],
                ]);
            }

            $changeDue = null;
            if ($completed && $paymentMethod === PaymentMethod::Cash) {
                if ($paymentAmount < (float) $invoice->total_amount) {
                    throw ValidationException::withMessages([
                        'payment_amount' => ['Payment amount must be greater than or equal to the invoice total.'],
                    ]);
                }
                $changeDue = round($paymentAmount - (float) $invoice->total_amount, 2);
            }

            $activeShift = $user->hasRole('customer_service')
                ? $user->activeShift()->first()
                : null;

            $sale = $this->createSaleWithRetry([
                'customer_id' => $invoice->customer_id,
                'branch_id' => $invoice->branch_id,
                'sold_by_id' => $user->id,
                'shift_id' => $activeShift?->id,
                'subtotal' => $invoice->subtotal,
                'tax_amount' => $invoice->tax_amount,
                'discount_amount' => $invoice->discount_amount,
                'total_amount' => $invoice->total_amount,
                'payment_method' => $paymentMethod->value,
                'payment_status' => $completed ? PaymentStatus::Completed->value : PaymentStatus::Pending->value,
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_amount' => $paymentAmount,
                'change_due' => $changeDue,
                'status' => $completed ? SaleStatus::COMPLETED->value : SaleStatus::DRAFT->value,
                'notes' => $data['notes'] ?? $invoice->notes,
                'sale_date' => now(),
                'vat_applicable' => $invoice->vat_applicable,
            ]);

            foreach ($invoice->items()->with('device')->get() as $invoiceItem) {
                $device = Device::query()->whereKey($invoiceItem->device_id)->lockForUpdate()->firstOrFail();

                if ($device->reserved_invoice_id !== $invoice->id || $device->inventory_status !== InventoryStatus::RESERVED) {
                    throw ValidationException::withMessages([
                        'invoice' => ["Device {$device->serial_number} is no longer reserved for invoice {$invoice->invoice_number}."],
                    ]);
                }

                $saleItem = SaleItem::create([
                    'sale_id' => $sale->id,
                    'device_id' => $device->id,
                    'subscription_plan_id' => $invoiceItem->subscription_plan_id,
                    'item_description' => $invoiceItem->item_description,
                    'unit_price' => $invoiceItem->unit_price,
                    'discount' => $invoiceItem->discount,
                    'tax' => $invoiceItem->tax,
                    'total_price' => $invoiceItem->total_price,
                    'cost_price' => $invoiceItem->cost_price,
                    'selling_price' => $invoiceItem->selling_price,
                    'profit_amount' => $invoiceItem->profit_amount,
                ]);

                if ($completed) {
                    $previousStatus = $device->inventory_status?->value;
                    $device->update([
                        'inventory_status' => InventoryStatus::SOLD,
                        'sale_id' => $sale->id,
                        'reserved_invoice_id' => null,
                        'owner_id' => $sale->customer_id,
                        'sold_at' => now(),
                        'sold_by_id' => $user->id,
                    ]);
                    $this->movementService->recordSale($device->fresh(), $sale, $saleItem, $user, $previousStatus);
                } else {
                    // Keep the existing reservation movement and hand the reserved
                    // device to the draft sale for the normal completion flow.
                    $device->update([
                        'sale_id' => $sale->id,
                        'reserved_invoice_id' => null,
                    ]);
                }
            }

            $sale->load('items');
            $sale->calculateTotals();
            $sale->save();

            $invoice->update([
                'status' => InvoiceStatus::CONVERTED->value,
                'converted_sale_id' => $sale->id,
                'redeemed_at' => now(),
            ]);
            $this->logActivity($invoice, $user, 'redeemed', 'Invoice redeemed', [
                'sale_id' => $sale->id,
                'total_amount' => $sale->total_amount,
            ]);

            return $invoice->fresh([
                'customer',
                'branch',
                'createdBy',
                'items.device.category',
                'convertedSale.items.device.category',
            ]);
        });
    }

    public function cancel(Invoice $invoice, User $user, ?string $reason = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $user, $reason): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === InvoiceStatus::CONVERTED) {
                throw ValidationException::withMessages(['invoice' => ['A redeemed invoice cannot be cancelled.']]);
            }

            if ($invoice->status !== InvoiceStatus::SENT) {
                return $invoice;
            }

            if ($invoice->isExpired()) {
                $this->releaseReservation($invoice, $user, 'Invoice expired');
                $invoice->update(['status' => InvoiceStatus::EXPIRED->value]);
                $this->logActivity($invoice, $user, 'expired', 'Invoice expired', [
                    'reason' => 'Validity period elapsed',
                ]);

                return $invoice->fresh(['items.device.category', 'customer', 'branch', 'createdBy']);
            }

            $this->releaseReservation($invoice, $user, $reason ?: 'Invoice cancelled');
            $invoice->update([
                'status' => InvoiceStatus::CANCELLED->value,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);
            $this->logActivity($invoice, $user, 'cancelled', 'Invoice cancelled', [
                'reason' => $reason,
            ]);

            return $invoice->fresh(['items.device.category', 'customer', 'branch', 'createdBy']);
        });
    }

    public function expireIfNeeded(Invoice $invoice, ?User $user = null): Invoice
    {
        if ($invoice->status !== InvoiceStatus::SENT || ! $invoice->isExpired()) {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice, $user): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== InvoiceStatus::SENT || ! $invoice->isExpired()) {
                return $invoice;
            }

            $this->releaseReservation($invoice, $user, 'Invoice expired');
            $invoice->update([
                'status' => InvoiceStatus::EXPIRED->value,
            ]);
            $this->logActivity($invoice, $user, 'expired', 'Invoice expired', [
                'reason' => 'Validity period elapsed',
            ]);

            return $invoice->fresh(['items.device.category', 'customer', 'branch', 'createdBy']);
        });
    }

    public function expireDueInvoices(?User $user = null): int
    {
        $count = 0;
        Invoice::query()
            ->where('status', InvoiceStatus::SENT->value)
            ->where('expires_at', '<=', now())
            ->select('id')
            ->chunkById(100, function ($invoices) use (&$count, $user): void {
                foreach ($invoices as $invoice) {
                    $this->expireIfNeeded(Invoice::query()->findOrFail($invoice->id), $user);
                    $count++;
                }
            });

        return $count;
    }

    private function releaseReservation(Invoice $invoice, ?User $user, string $reason): void
    {
        foreach ($invoice->items()->with('device')->get() as $invoiceItem) {
            $device = Device::query()->whereKey($invoiceItem->device_id)->lockForUpdate()->firstOrFail();

            if ($device->reserved_invoice_id !== $invoice->id || $device->inventory_status !== InventoryStatus::RESERVED) {
                continue;
            }

            $newStatus = $invoiceItem->reserved_from_status ?: InventoryStatus::IN_STOCK->value;
            if (! in_array($newStatus, [InventoryStatus::IN_STOCK->value, InventoryStatus::RETURNED->value], true)) {
                $newStatus = InventoryStatus::IN_STOCK->value;
            }

            $device->update([
                'inventory_status' => $newStatus,
                'sale_id' => null,
                'reserved_invoice_id' => null,
            ]);
            $this->movementService->recordInvoiceReservationCancelled(
                $device->fresh(),
                $invoice,
                $invoiceItem,
                $user,
                $reason,
                $newStatus,
            );
        }
    }

    /** @return Collection<int, Device> */
    private function resolveDevices(array $itemData, ?int $branchId): Collection
    {
        if (! empty($itemData['device_id'])) {
            return Device::query()
                ->whereKey($itemData['device_id'])
                ->lockForUpdate()
                ->get();
        }

        $query = Device::query()
            ->where('category_id', $itemData['category_id'])
            ->where('brand', $itemData['brand'])
            ->where('model', $itemData['model'])
            ->whereIn('inventory_status', [InventoryStatus::IN_STOCK->value, InventoryStatus::RETURNED->value])
            ->whereNull('sale_id')
            ->whereNull('reserved_invoice_id')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByRaw('purchase_date IS NULL')
            ->orderBy('purchase_date')
            ->orderByRaw('inventory_batch_id IS NULL')
            ->orderBy('inventory_batch_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate();

        if (! empty($itemData['color'])) {
            $query->where('color', $itemData['color']);
        }

        return $query->limit((int) $itemData['quantity'])->get();
    }

    private function assertDeviceCanBeReserved(Device $device, ?int $branchId): void
    {
        if (! in_array($device->inventory_status, [InventoryStatus::IN_STOCK, InventoryStatus::RETURNED], true)
            || $device->sale_id !== null
            || $device->reserved_invoice_id !== null) {
            throw ValidationException::withMessages([
                'items' => ["Device {$device->serial_number} is not available for invoice reservation."],
            ]);
        }

        if ($branchId !== null && (int) $device->branch_id !== $branchId) {
            throw ValidationException::withMessages([
                'items' => ["Device {$device->serial_number} does not belong to the invoice branch."],
            ]);
        }

        if ($device->stock_transfer_request_id !== null) {
            throw ValidationException::withMessages([
                'items' => ["Device {$device->serial_number} is awaiting transfer approval."],
            ]);
        }
    }

    /** @return array{0: float, 1: string} */
    private function resolveSellingPrice(Device $device, array $itemData, User $user): array
    {
        $device->loadMissing('inventoryBatch');

        if (array_key_exists('price_override', $itemData) && $itemData['price_override'] !== null) {
            if (! $user->can('manage-inventory')) {
                throw ValidationException::withMessages([
                    'items' => ['Only inventory managers may override an invoice selling price.'],
                ]);
            }

            return [round((float) $itemData['price_override'], 2), 'price_override'];
        }

        $batchPrice = $device->inventoryBatch?->target_selling_price;
        if ($batchPrice !== null && (float) $batchPrice > 0) {
            return [round((float) $batchPrice, 2), 'batch_target_selling_price'];
        }

        $purchaseCost = $device->purchase_price;
        if ($purchaseCost === null || (float) $purchaseCost <= 0) {
            $purchaseCost = $device->inventoryBatch?->buying_price;
        }
        if ($purchaseCost !== null && (float) $purchaseCost > 0) {
            return [round((float) $purchaseCost, 2), $device->purchase_price !== null ? 'purchase_cost' : 'batch_purchase_cost'];
        }

        $requestedPrice = $itemData['unit_price'] ?? null;
        if ($requestedPrice !== null && (float) $requestedPrice > 0) {
            return [round((float) $requestedPrice, 2), 'requested_unit_price'];
        }

        throw ValidationException::withMessages([
            'items' => ["Device {$device->serial_number} has no valid selling price. Set a batch selling price or purchase cost before issuing an invoice."],
        ]);
    }

    private function resolveCostPrice(Device $device): float
    {
        $device->loadMissing('inventoryBatch');
        $cost = $device->purchase_price;
        if ($cost === null || (float) $cost <= 0) {
            $cost = $device->inventoryBatch?->buying_price;
        }

        return round((float) ($cost ?? 0), 2);
    }

    private function descriptionForDevice(Device $device): string
    {
        $color = $device->color ? " - {$device->color}" : '';

        return "{$device->brand} {$device->model}{$color} ({$device->serial_number})";
    }

    private function productSpecsForDevice(Device $device): array
    {
        return [
            'category_id' => $device->category_id,
            'brand' => $device->brand,
            'model' => $device->model,
            'color' => $device->color,
        ];
    }

    private function resolveBranchId(array $data, User $user): ?int
    {
        if ($user->hasRole('super_admin')) {
            return isset($data['branch_id']) ? (int) $data['branch_id'] : ($user->branch_id ? (int) $user->branch_id : null);
        }

        $branchId = $user->branchScopeId();
        if (! $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => ['Your account must be assigned to a branch before issuing invoices.'],
            ]);
        }

        return $branchId;
    }

    private function logActivity(Invoice $invoice, ?User $user, string $event, string $description, array $properties = []): void
    {
        try {
            ActivityLog::create([
                'log_name' => 'invoices',
                'description' => $description,
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->id,
                'causer_type' => $user ? $user::class : null,
                'causer_id' => $user?->id,
                'properties' => $properties,
                'event' => $event,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function createInvoiceWithRetry(array $attributes): Invoice
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Invoice::create([
                    ...$attributes,
                    'invoice_number' => Invoice::generateInvoiceNumber(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e) || $attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to allocate an invoice number.');
    }

    private function createSaleWithRetry(array $attributes): Sale
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Sale::create([
                    ...$attributes,
                    'sale_number' => Sale::generateSaleNumber(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueConstraintViolation($e) || $attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to allocate a sale number.');
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true) || $driverCode === '1062';
    }
}
