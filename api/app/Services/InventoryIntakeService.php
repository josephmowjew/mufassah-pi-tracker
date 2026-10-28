<?php

namespace App\Services;

use App\Enums\DeviceStatus;
use App\Enums\InventoryStatus;
use App\Models\Device;
use App\Models\InventoryBatch;
use App\Models\InventoryReceipt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a delivery into stock: receipt → batch(es) → device rows → ledger.
 *
 * This used to live as private methods on {@see \App\Http\Controllers\Api\V1\Admin\AdminInventoryController}.
 * It moved here when spreadsheet import arrived, because there must be exactly
 * one implementation of intake: the manual "Receive Stock" form and an uploaded
 * file have to produce identical batches, identical price inheritance and
 * identical movement rows, or the two entry paths drift apart and the reports
 * stop reconciling. Anything that receives stock calls this class.
 *
 * Ordering inside a receipt matters and is preserved deliberately:
 *   1. the receipt row (one per branch per delivery);
 *   2. one batch per product line, carrying the cost and target price;
 *   3. one device per unit, pointing at its batch;
 *   4. one `receipt` movement per device, so the unit is auditable from day one.
 *
 * A device row without its opening movement corrupts every stock-movement total
 * from then on, which is why callers wrap the whole thing in one transaction and
 * a failure leaves nothing behind.
 */
class InventoryIntakeService
{
    /** Serial generation retries before giving up on a collision. */
    private const UNIQUE_ATTEMPTS = 5;

    public function __construct(
        private readonly InventoryMovementService $movementService,
        private readonly InventoryCatalogResolver $catalogResolver,
        private readonly InventoryPriceResolver $priceResolver,
    ) {}

    /**
     * Receive a full delivery for one branch.
     *
     * @param  array{
     *     branch_id: int,
     *     supplier?: string|null,
     *     reference_number?: string|null,
     *     purchase_date?: string|null,
     *     purchase_location?: string|null,
     *     notes?: string|null,
     *     status?: string,
     * }  $header
     * @param  list<array{
     *     category_id: int|string,
     *     brand: string,
     *     model: string,
     *     quantity: int|string,
     *     buying_price: float|string,
     *     color?: string|null,
     *     description?: string|null,
     *     target_selling_price?: float|string|null,
     *     notes?: string|null,
     *     auto_generate_serials?: bool,
     *     serial_numbers?: list<string>,
     *     iot_module_id?: int|null,
     * }>  $lines
     * @param  string  $sellingPriceErrorKeyPattern  `sprintf` pattern for the
     *                                               validation key used when a line has no resolvable selling price.
     *                                               It defaults to the manual receipt form's `lines.N.…` shape so the
     *                                               existing form keeps highlighting the right field; the spreadsheet
     *                                               importer passes `rows.N.…` instead.
     * @return array{receipt: InventoryReceipt, batches: list<InventoryBatch>, devices: list<Device>}
     *
     * @throws ValidationException when a line has no usable selling price.
     */
    public function receiveReceipt(
        array $header,
        array $lines,
        ?User $user = null,
        string $sellingPriceErrorKeyPattern = 'lines.%d.target_selling_price',
    ): array {
        return DB::transaction(function () use ($header, $lines, $user, $sellingPriceErrorKeyPattern) {
            $receipt = $this->createReceipt([
                'branch_id' => (int) $header['branch_id'],
                'supplier' => $header['supplier'] ?? null,
                'reference_number' => $header['reference_number'] ?? null,
                'purchase_date' => $header['purchase_date'] ?? null,
                'purchase_location' => $header['purchase_location'] ?? null,
                'status' => $header['status'] ?? 'received',
                'notes' => $header['notes'] ?? null,
                'received_by_id' => $user?->id,
                'received_at' => now(),
            ]);

            $batches = [];
            $devices = [];

            foreach (array_values($lines) as $index => $line) {
                [$batch, $lineDevices] = $this->createBatchWithDevices(
                    (int) $header['branch_id'],
                    $line,
                    $receipt,
                    sprintf($sellingPriceErrorKeyPattern, $index),
                );

                $batches[] = $batch;

                foreach ($lineDevices as $device) {
                    $this->movementService->recordReceipt($device, $batch, $receipt, $user);
                    $devices[] = $device;
                }
            }

            return ['receipt' => $receipt, 'batches' => $batches, 'devices' => $devices];
        });
    }

    /**
     * Receive one product line without a receipt document.
     *
     * Kept separate from {@see receiveReceipt()} because the quick "add N units"
     * form has no supplier or invoice to attach; the batch is still created so
     * cost and price stay attached to the lot rather than the device.
     *
     * @return array{0: InventoryBatch, 1: list<Device>}
     */
    public function receiveStandaloneBatch(int $branchId, array $line, ?User $user = null): array
    {
        return DB::transaction(function () use ($branchId, $line, $user) {
            [$batch, $devices] = $this->createBatchWithDevices($branchId, $line, null);

            foreach ($devices as $device) {
                $this->movementService->recordReceipt($device, $batch, null, $user);
            }

            return [$batch, $devices];
        });
    }

    /**
     * Create one batch and its device rows.
     *
     * The selling price may be omitted so that stock received today keeps the
     * price already in use for the product; the buying price is never inferred,
     * because purchase cost is real data for this specific delivery.
     *
     * @param  array<string, mixed>  $line
     * @return array{0: InventoryBatch, 1: list<Device>}
     *
     * @throws ValidationException when the price cannot be resolved.
     */
    public function createBatchWithDevices(
        int $branchId,
        array $line,
        ?InventoryReceipt $receipt = null,
        string $sellingPriceErrorKey = 'target_selling_price',
    ): array {
        $catalog = $this->catalogResolver->resolve(
            (int) $line['category_id'],
            (string) $line['brand'],
            (string) $line['model'],
        );
        $line = array_merge($line, $catalog);

        $targetSellingPrice = $line['target_selling_price'] ?? null;

        if ($targetSellingPrice === null || $targetSellingPrice === '') {
            $targetSellingPrice = $this->priceResolver->fallbackSellingPrice(
                (int) $line['category_id'],
                (string) $line['brand'],
                (string) $line['model'],
                $branchId,
            );

            if ($targetSellingPrice === null) {
                throw ValidationException::withMessages([
                    $sellingPriceErrorKey => "No selling price was given for {$line['brand']} {$line['model']} and none could be inherited from earlier stock. Enter a target selling price for this line.",
                ]);
            }
        }

        $quantity = (int) $line['quantity'];
        $autoGenerate = filter_var($line['auto_generate_serials'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $providedSerials = $line['serial_numbers'] ?? [];

        $batch = $this->createBatch([
            'inventory_receipt_id' => $receipt?->id,
            'branch_id' => $branchId,
            'category_id' => $line['category_id'],
            // Keep the catalog link alongside the text fields: price lookups
            // and the catalog reprice cascade match on this id, not on text.
            'product_model_id' => $line['product_model_id'] ?? null,
            'brand' => $line['brand'],
            'model' => $line['model'],
            'color' => $line['color'] ?? null,
            'quantity_received' => $quantity,
            'buying_price' => $line['buying_price'],
            'target_selling_price' => $targetSellingPrice,
            'purchase_date' => $line['purchase_date'] ?? null,
            'purchase_location' => $line['purchase_location'] ?? null,
            'notes' => $line['notes'] ?? null,
        ]);

        $devices = [];

        for ($index = 0; $index < $quantity; $index++) {
            $serialNumber = $autoGenerate
                ? $this->generateSerialNumber((string) $line['brand'], (string) $line['model'])
                : $providedSerials[$index];

            $devices[] = Device::create([
                'category_id' => $line['category_id'],
                'brand_id' => $line['brand_id'],
                'product_model_id' => $line['product_model_id'],
                'branch_id' => $branchId,
                'iot_module_id' => $line['iot_module_id'] ?? null,
                'brand' => $line['brand'],
                'model' => $line['model'],
                'color' => $line['color'] ?? null,
                'description' => $line['description'] ?? null,
                'purchase_date' => $line['purchase_date'] ?? null,
                'purchase_price' => $line['buying_price'],
                'purchase_location' => $line['purchase_location'] ?? null,
                'notes' => $line['notes'] ?? null,
                'status' => DeviceStatus::INACTIVE,
                'inventory_status' => InventoryStatus::IN_STOCK,
                'inventory_batch_id' => $batch->id,
                'serial_number' => $serialNumber,
                'unique_device_code' => $this->generateDeviceCode(),
            ]);
        }

        return [$batch, $devices];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createBatch(array $attributes): InventoryBatch
    {
        return $this->withUniqueRetry(
            static fn () => InventoryBatch::create([
                ...$attributes,
                'batch_number' => InventoryBatch::generateBatchNumber(),
            ]),
            'Unable to allocate an inventory batch number.'
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createReceipt(array $attributes): InventoryReceipt
    {
        return $this->withUniqueRetry(
            static fn () => InventoryReceipt::create([
                ...$attributes,
                'receipt_number' => InventoryReceipt::generateReceiptNumber(),
            ]),
            'Unable to allocate an inventory receipt number.'
        );
    }

    public function generateDeviceCode(): string
    {
        do {
            $code = 'DEV-'.strtoupper(bin2hex(random_bytes(4)));
        } while (Device::where('unique_device_code', $code)->exists());

        return $code;
    }

    /**
     * Generate a unique serial number based on brand and model.
     */
    public function generateSerialNumber(string $brand, string $model): string
    {
        $brandPrefix = strtoupper(substr($brand, 0, 3));
        $modelPrefix = strtoupper(substr((string) preg_replace('/[^a-zA-Z0-9]/', '', $model), 0, 3));
        $prefix = $brandPrefix.$modelPrefix;

        do {
            $serial = $prefix.'-'.strtoupper(bin2hex(random_bytes(3)));
        } while (Device::where('serial_number', $serial)->exists());

        return $serial;
    }

    /**
     * Retry a document-number allocation that lost a race.
     *
     * `generateReceiptNumber()` reads the last row and increments, so two
     * concurrent deliveries can compute the same number. A unique-index
     * violation is the signal to re-read and try again, not an error to surface.
     */
    private function withUniqueRetry(callable $create, string $failureMessage)
    {
        for ($attempt = 0; $attempt < self::UNIQUE_ATTEMPTS; $attempt++) {
            try {
                return $create();
            } catch (QueryException $exception) {
                if (! $this->isUniqueConstraintViolation($exception) || $attempt === self::UNIQUE_ATTEMPTS - 1) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException($failureMessage);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true) || $driverCode === '1062';
    }
}
