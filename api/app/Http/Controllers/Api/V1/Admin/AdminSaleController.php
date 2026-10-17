<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InventoryStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\SaleResource;
use App\Models\ActivityLog;
use App\Models\CommunicationSettings;
use App\Models\Device;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Notifications\NewSaleNotification;
use App\Services\InventoryMovementService;
use App\Services\ReceiptService;
use App\Services\SuperAdminSaleNotificationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminSaleController extends BaseController
{
    public function __construct(
        private readonly InventoryMovementService $movementService,
        private readonly SuperAdminSaleNotificationService $superAdminSaleNotificationService,
    ) {}

    /**
     * List all sales with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateSaleListFilters($request);
        $query = $this->buildSaleReadQuery($request, $validated)
            ->with(['customer', 'soldBy', 'branch'])
            ->withCount('items');

        // Sort
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Paginate
        $perPage = $request->get('per_page', 15);
        $sales = $query->paginate($perPage);

        return $this->paginated(SaleResource::collection($sales));
    }

    /**
     * Summarize all sales matching the current list filters, independent of pagination.
     */
    public function summary(Request $request): JsonResponse
    {
        $validated = $this->validateSaleListFilters($request);
        $saleQuery = $this->buildSaleReadQuery($request, $validated);
        $summary = (clone $saleQuery)
            ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total_amount), 0) as total_amount, COALESCE(AVG(total_amount), 0) as average_amount')
            ->first();

        $branchId = $this->resolveSaleReadBranchId(
            $request,
            $request->filled('branch_id') ? $request->integer('branch_id') : null,
        );
        $administrativeSaleQuery = InventoryMovement::query()
            ->where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId));

        if (! empty($validated['date_from'])) {
            $administrativeSaleQuery->where('created_at', '>=', Carbon::parse($validated['date_from'])->startOfDay());
        }
        if (! empty($validated['date_to'])) {
            $administrativeSaleQuery->where('created_at', '<=', Carbon::parse($validated['date_to'])->endOfDay());
        }

        $administrativeSaleAmount = (float) (clone $administrativeSaleQuery)->sum('total_amount');
        $administrativeSaleTransactions = (int) (clone $administrativeSaleQuery)->count();
        $posTotalAmount = round((float) ($summary?->total_amount ?? 0), 2);

        return $this->success([
            'sales_count' => (int) ($summary?->sales_count ?? 0),
            'total_amount' => $posTotalAmount,
            'average_amount' => round((float) ($summary?->average_amount ?? 0), 2),
            'administrative_sale_amount' => round($administrativeSaleAmount, 2),
            'administrative_sale_transactions' => $administrativeSaleTransactions,
            'combined_total_amount' => round($posTotalAmount + $administrativeSaleAmount, 2),
        ]);
    }

    /**
     * Get a specific sale with full details.
     */
    public function show(Request $request, Sale $sale): JsonResponse
    {
        $this->authorizeSaleView($request, $sale);

        $sale->load(['customer', 'soldBy', 'branch', 'items.device.category', 'items.subscriptionPlan']);

        return $this->success(new SaleResource($sale));
    }

    /**
     * Create a new sale (POS transaction).
     * Supports two formats:
     * 1. Legacy: device_id (specific device)
     * 2. New: category_id, brand, model, color, quantity (product specs)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => 'nullable|string|max:100',
            'payment_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'vat_applicable' => 'nullable|boolean',
            'status' => ['nullable', Rule::in([SaleStatus::DRAFT->value, SaleStatus::COMPLETED->value])],
            'items' => 'required|array|min:1',
            // Legacy format (direct device_id)
            'items.*.device_id' => ['required_without:items.*.category_id', Rule::exists('devices', 'id')->whereNull('deleted_at')],
            // New format (product specs + quantity)
            'items.*.category_id' => ['required_without:items.*.device_id', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
            'items.*.brand' => 'required_with:items.*.category_id|string|max:100',
            'items.*.model' => 'required_with:items.*.category_id|string|max:150',
            'items.*.color' => 'nullable|string|max:50',
            'items.*.quantity' => 'required_with:items.*.category_id|integer|min:1|max:100',
            // Common fields
            'items.*.subscription_plan_id' => 'nullable|exists:subscription_plans,id',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.price_override' => 'nullable|numeric|min:0',
            'items.*.price_override_reason' => 'required_with:items.*.price_override|string|max:500',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax' => 'nullable|numeric|min:0',
        ], [
            'payment_amount.min' => 'Payment amount cannot be negative.',
            'items.*.quantity.min' => 'Quantity must be at least 1.',
            'items.*.unit_price.min' => 'Sale price cannot be negative.',
            'items.*.price_override.min' => 'Sale price override cannot be negative.',
            'items.*.discount.min' => 'Discount cannot be negative.',
            'items.*.tax.min' => 'Tax cannot be negative.',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $user = $request->user();
            foreach ($validated['items'] as $item) {
                if (isset($item['price_override']) && ! $user->can('manage-inventory')) {
                    throw ValidationException::withMessages(['items' => ['Only inventory managers may override a selling price.']]);
                }
            }
            $branchId = $this->resolveSaleBranchId($request, $validated);

            // Get active shift for customer service users
            $activeShift = null;
            if ($user->hasRole('customer_service')) {
                $activeShift = $user->activeShift()->first();
            }

            $paymentMethod = PaymentMethod::from($validated['payment_method']);
            $requestedStatus = $validated['status'] ?? null;
            $shouldComplete = $requestedStatus
                ? $requestedStatus === SaleStatus::COMPLETED->value
                : $paymentMethod !== PaymentMethod::Credit;

            $sale = $this->createSaleWithRetry([
                'customer_id' => $validated['customer_id'],
                'branch_id' => $branchId,
                'sold_by_id' => $user->id,
                'shift_id' => $activeShift?->id,
                'payment_method' => $validated['payment_method'],
                'payment_reference' => $validated['payment_reference'] ?? null,
                'payment_amount' => $validated['payment_amount'] ?? null,
                'payment_status' => $shouldComplete ? PaymentStatus::Completed : PaymentStatus::Pending,
                'status' => $shouldComplete ? SaleStatus::COMPLETED : SaleStatus::DRAFT,
                'notes' => $validated['notes'] ?? null,
                'sale_date' => now(),
                'vat_applicable' => $validated['vat_applicable'] ?? true,
                'subtotal' => 0,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 0,
            ]);

            // Create sale items and update devices
            foreach ($validated['items'] as $itemData) {
                $discount = round((float) ($itemData['discount'] ?? 0), 2);

                // Determine if using legacy (device_id) or new (product specs) format
                if (isset($itemData['device_id'])) {
                    // Legacy format: single device
                    $device = Device::whereKey($itemData['device_id'])->lockForUpdate()->firstOrFail();

                    if (! $device->isInStock()) {
                        throw ValidationException::withMessages([
                            'items' => ["Device {$device->serial_number} is not available for sale."],
                        ]);
                    }

                    if ($branchId !== null && (int) $device->branch_id !== (int) $branchId) {
                        throw ValidationException::withMessages([
                            'items' => ["Device {$device->serial_number} does not belong to the sale branch."],
                        ]);
                    }
                    $unitPrice = $this->resolveSellingPrice($device, $itemData);
                    $this->assertPositiveSellingPrice($device, $unitPrice);
                    if ($discount > $unitPrice) {
                        throw ValidationException::withMessages(['items' => ['Discount cannot be greater than the unit price.']]);
                    }
                    $tax = $sale->vat_applicable ? round($unitPrice * 0.175, 2) : 0;
                    $totalPrice = round(($unitPrice - $discount) + $tax, 2);

                    $costPrice = $this->resolveCostPrice($device);
                    $saleItem = SaleItem::create([
                        'sale_id' => $sale->id,
                        'device_id' => $device->id,
                        'subscription_plan_id' => $itemData['subscription_plan_id'] ?? null,
                        'item_description' => "{$device->brand} {$device->model} ({$device->serial_number})",
                        'unit_price' => $unitPrice,
                        'discount' => $discount,
                        'tax' => $tax,
                        'total_price' => $totalPrice,
                        'cost_price' => $costPrice,
                        'selling_price' => $unitPrice,
                        'profit_amount' => round($unitPrice - $discount - $costPrice, 2),
                    ]);

                    $this->applySaleDeviceState($device, $sale, $saleItem, $shouldComplete, $user);
                } else {
                    // New format: product specs with quantity
                    $quantity = $itemData['quantity'];
                    $categoryId = $itemData['category_id'];
                    $brand = $itemData['brand'];
                    $model = $itemData['model'];
                    $color = $itemData['color'] ?? null;

                    // Find available devices matching the product specs
                    $devicesQuery = Device::where('category_id', $categoryId)
                        ->where('brand', $brand)
                        ->where('model', $model)
                        ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                        ->available()
                        ->orderByRaw('purchase_date IS NULL')
                        ->orderBy('purchase_date')
                        ->orderByRaw('inventory_batch_id IS NULL')
                        ->orderBy('inventory_batch_id')
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->lockForUpdate();

                    // Handle color matching (including null)
                    if ($color === null) {
                        $devicesQuery->whereNull('color');
                    } else {
                        $devicesQuery->where('color', $color);
                    }

                    $devices = $devicesQuery->limit($quantity)->get();

                    // Verify we have enough devices
                    if ($devices->count() < $quantity) {
                        $colorText = $color ? " ({$color})" : '';
                        throw ValidationException::withMessages([
                            'items' => ["Not enough {$brand} {$model}{$colorText} devices available. Requested: {$quantity}, Available: {$devices->count()}."],
                        ]);
                    }

                    // Create a sale item for each device
                    foreach ($devices as $device) {
                        $unitPrice = $this->resolveSellingPrice($device, $itemData);
                        $this->assertPositiveSellingPrice($device, $unitPrice);
                        if ($discount > $unitPrice) {
                            throw ValidationException::withMessages(['items' => ['Discount cannot be greater than the unit price.']]);
                        }
                        $tax = $sale->vat_applicable ? round($unitPrice * 0.175, 2) : 0;
                        $totalPrice = round(($unitPrice - $discount) + $tax, 2);
                        $colorText = $device->color ? " - {$device->color}" : '';
                        $costPrice = $this->resolveCostPrice($device);
                        $saleItem = SaleItem::create([
                            'sale_id' => $sale->id,
                            'device_id' => $device->id,
                            'subscription_plan_id' => $itemData['subscription_plan_id'] ?? null,
                            'item_description' => "{$device->brand} {$device->model}{$colorText} ({$device->serial_number})",
                            'unit_price' => $unitPrice,
                            'discount' => $discount,
                            'tax' => $tax,
                            'total_price' => $totalPrice,
                            'cost_price' => $costPrice,
                            'selling_price' => $unitPrice,
                            'profit_amount' => round($unitPrice - $discount - $costPrice, 2),
                        ]);

                        $this->applySaleDeviceState($device, $sale, $saleItem, $shouldComplete, $user);
                        if (isset($itemData['price_override'])) {
                            $this->logActivity($sale, $user, 'sale_price_overridden', 'Sale price overridden', ['device_id' => $device->id, 'batch_price' => $device->inventoryBatch?->target_selling_price, 'override_price' => $unitPrice, 'reason' => $itemData['price_override_reason']]);
                        }
                    }
                }
            }

            // Recalculate totals
            $sale->load('items');
            $sale->calculateTotals();

            // Validate payment amount and calculate change due for cash payments
            if ($sale->payment_method === PaymentMethod::Cash && $sale->payment_amount !== null) {
                if ($sale->payment_amount < $sale->total_amount) {
                    throw ValidationException::withMessages([
                        'payment_amount' => ['Payment amount must be greater than or equal to the total amount for cash payments.'],
                    ]);
                }
                $sale->change_due = round($sale->payment_amount - $sale->total_amount, 2);
            }

            if ($shouldComplete && $sale->payment_method === PaymentMethod::Cash && $sale->payment_amount === null) {
                throw ValidationException::withMessages([
                    'payment_amount' => ['Payment amount is required for completed cash sales.'],
                ]);
            }

            $sale->save();
            $this->logActivity($sale, $user, $shouldComplete ? 'completed' : 'draft_created', 'Sale created', [
                'status' => $sale->status->value,
                'payment_status' => $sale->payment_status->value,
                'total_amount' => $sale->total_amount,
            ]);

            $sale->load(['customer', 'soldBy', 'branch', 'items.device.category']);

            if ($shouldComplete) {
                $this->notifyCompletedSale($sale);
            }

            return $this->success(new SaleResource($sale), 'Sale created successfully', 201);
        });
    }

    /**
     * Complete a sale (finalize transaction).
     */
    public function complete(Request $request, Sale $sale): JsonResponse
    {
        if ($sale->status === SaleStatus::COMPLETED) {
            return $this->error('Sale is already completed', 400);
        }

        if ($sale->status === SaleStatus::CANCELLED) {
            return $this->error('Cannot complete a cancelled sale', 400);
        }

        $validated = $request->validate([
            'payment_reference' => 'nullable|string|max:100',
            'payment_amount' => 'nullable|numeric|min:0',
        ], [
            'payment_amount.min' => 'Payment amount cannot be negative.',
        ]);

        return DB::transaction(function () use ($sale, $validated, $request) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $this->authorizeSaleMutation($request, $sale);

            if ($sale->status === SaleStatus::COMPLETED) {
                return $this->error('Sale is already completed', 400);
            }

            if ($sale->status === SaleStatus::CANCELLED) {
                return $this->error('Cannot complete a cancelled sale', 400);
            }

            // Calculate change due for cash payments
            $changeDue = null;
            if (isset($validated['payment_amount']) && $sale->payment_method === PaymentMethod::Cash) {
                if ($validated['payment_amount'] < $sale->total_amount) {
                    return $this->error('Payment amount must be greater than or equal to the total amount for cash payments.', 422);
                }
                $changeDue = round($validated['payment_amount'] - $sale->total_amount, 2);
            }

            if ($sale->payment_method === PaymentMethod::Cash && ! isset($validated['payment_amount']) && $sale->payment_amount === null) {
                throw ValidationException::withMessages([
                    'payment_amount' => ['Payment amount is required for completed cash sales.'],
                ]);
            }

            // Update sale status
            $sale->update([
                'status' => SaleStatus::COMPLETED,
                'payment_status' => PaymentStatus::Completed,
                'payment_reference' => $validated['payment_reference'] ?? $sale->payment_reference,
                'payment_amount' => $validated['payment_amount'] ?? $sale->payment_amount,
                'change_due' => $changeDue ?? $sale->change_due,
            ]);

            // Transfer device ownership and mark as sold
            foreach ($sale->items()->with('device')->get() as $item) {
                $device = Device::whereKey($item->device_id)->lockForUpdate()->firstOrFail();

                if ($device->sale_id !== $sale->id || $device->inventory_status !== InventoryStatus::RESERVED) {
                    throw ValidationException::withMessages([
                        'items' => ["Device {$device->serial_number} is no longer reserved for this sale."],
                    ]);
                }

                $previousStatus = $device->inventory_status?->value;
                $device->update([
                    'inventory_status' => InventoryStatus::SOLD,
                    'owner_id' => $sale->customer_id,
                    'sold_at' => now(),
                    'sold_by_id' => $request->user()->id,
                ]);
                $this->movementService->recordSale($device->fresh(), $sale, $item, $request->user(), $previousStatus);
            }

            $this->logActivity($sale, $request->user(), 'completed', 'Sale completed', [
                'payment_amount' => $sale->payment_amount,
                'change_due' => $sale->change_due,
            ]);

            $sale->load(['customer', 'soldBy', 'branch', 'items.device.category']);

            $this->notifyCompletedSale($sale);

            return $this->success(new SaleResource($sale), 'Sale completed successfully');
        });
    }

    /**
     * Cancel a sale.
     */
    public function cancel(Request $request, Sale $sale): JsonResponse
    {
        if ($sale->status === SaleStatus::COMPLETED) {
            return $this->error('Cannot cancel a completed sale. Use refund instead.', 400);
        }

        if ($sale->status === SaleStatus::CANCELLED) {
            return $this->error('Sale is already cancelled', 400);
        }

        return DB::transaction(function () use ($sale, $request) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $this->authorizeSaleMutation($request, $sale);

            if ($sale->status === SaleStatus::COMPLETED) {
                return $this->error('Cannot cancel a completed sale. Use refund instead.', 400);
            }

            if ($sale->status === SaleStatus::CANCELLED) {
                return $this->error('Sale is already cancelled', 400);
            }

            // Return devices to stock
            foreach ($sale->items()->with('device')->get() as $item) {
                $device = Device::whereKey($item->device_id)->lockForUpdate()->firstOrFail();
                if ($device->sale_id !== $sale->id || $device->inventory_status !== InventoryStatus::RESERVED) {
                    throw ValidationException::withMessages([
                        'items' => ["Device {$device->serial_number} is no longer reserved for this sale."],
                    ]);
                }

                $device->update([
                    'inventory_status' => InventoryStatus::IN_STOCK,
                    'sale_id' => null,
                ]);
                $this->movementService->recordReservationCancelled($device->fresh(), $sale, $item, $request->user(), 'Sale cancelled');
            }

            // Update sale status
            $sale->update([
                'status' => SaleStatus::CANCELLED,
            ]);
            $this->logActivity($sale, $request->user(), 'cancelled', 'Sale cancelled');

            $sale->load(['customer', 'soldBy', 'branch', 'items.device.category']);

            return $this->success(new SaleResource($sale), 'Sale cancelled successfully');
        });
    }

    /**
     * Refund a completed sale.
     */
    public function refund(Request $request, Sale $sale): JsonResponse
    {
        if ($sale->status !== SaleStatus::COMPLETED) {
            return $this->error('Can only refund completed sales', 400);
        }

        if ($sale->status === SaleStatus::REFUNDED) {
            return $this->error('Sale is already refunded', 400);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        return DB::transaction(function () use ($sale, $validated, $request) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $this->authorizeSaleMutation($request, $sale);

            if ($sale->status !== SaleStatus::COMPLETED) {
                return $this->error('Can only refund completed sales', 400);
            }

            // Return devices to stock and remove ownership
            foreach ($sale->items()->with('device')->get() as $item) {
                $device = Device::whereKey($item->device_id)->lockForUpdate()->firstOrFail();
                if ($device->sale_id !== $sale->id || $device->inventory_status !== InventoryStatus::SOLD) {
                    throw ValidationException::withMessages([
                        'items' => ["Device {$device->serial_number} is no longer sold under this sale."],
                    ]);
                }

                $device->update([
                    'inventory_status' => InventoryStatus::IN_STOCK,
                    'owner_id' => null,
                    'sale_id' => null,
                    'sold_at' => null,
                    'sold_by_id' => null,
                ]);
                $this->movementService->recordRefund($device->fresh(), $sale, $item, $request->user(), $validated['reason']);
            }

            // Update sale status
            $sale->update([
                'status' => SaleStatus::REFUNDED,
                'payment_status' => PaymentStatus::Refunded,
                'refund_reason' => $validated['reason'],
            ]);
            $this->logActivity($sale, $request->user(), 'refunded', 'Sale refunded', [
                'reason' => $validated['reason'],
            ]);

            $sale->load(['customer', 'soldBy', 'branch', 'items.device.category']);

            return $this->success(new SaleResource($sale), 'Sale refunded successfully');
        });
    }

    /**
     * Delete a draft sale.
     */
    public function destroy(Request $request, Sale $sale): JsonResponse
    {
        if ($sale->status !== SaleStatus::DRAFT) {
            return $this->error('Can only delete draft sales', 400);
        }

        return DB::transaction(function () use ($sale, $request) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $this->authorizeSaleMutation($request, $sale);

            if ($sale->status !== SaleStatus::DRAFT) {
                return $this->error('Can only delete draft sales', 400);
            }

            // Return devices to stock
            foreach ($sale->items()->with('device')->get() as $item) {
                $device = Device::whereKey($item->device_id)->lockForUpdate()->firstOrFail();
                if ($device->sale_id !== $sale->id || $device->inventory_status !== InventoryStatus::RESERVED) {
                    throw ValidationException::withMessages([
                        'items' => ["Device {$device->serial_number} is no longer reserved for this sale."],
                    ]);
                }

                $device->update([
                    'inventory_status' => InventoryStatus::IN_STOCK,
                    'sale_id' => null,
                ]);
                $this->movementService->recordReservationCancelled($device->fresh(), $sale, $item, $request->user(), 'Draft sale deleted');
            }

            $saleNumber = $sale->sale_number;
            $this->logActivity($sale, $request->user(), 'deleted', 'Draft sale deleted');
            $sale->items()->delete();
            $sale->delete();

            return $this->success(null, "Sale '{$saleNumber}' has been deleted successfully");
        });
    }

    /**
     * Generate and display receipt for a sale.
     */
    public function receipt(Request $request, Sale $sale): mixed
    {
        $this->authorizeSaleView($request, $sale);

        $receiptService = new ReceiptService;

        $format = $request->get('format', 'a4'); // a4 or thermal
        $output = $request->get('output', 'html'); // html or pdf
        $receiptCopy = $request->get('copy', 'customer');

        if (! \in_array($receiptCopy, ['customer', 'company'], true)) {
            return $this->error('Invalid copy. Use "customer" or "company"', 400);
        }

        if (! \in_array($format, ['a4', 'thermal'], true)) {
            return $this->error('Invalid format. Use "a4" or "thermal"', 400);
        }

        if (! \in_array($output, ['html', 'pdf'], true)) {
            return $this->error('Invalid output. Use "html" or "pdf"', 400);
        }

        if ($output === 'pdf') {
            return $receiptService->generatePdf($sale, $format, $receiptCopy);
        }

        // Return HTML view
        $html = $receiptService->generateHtml($sale, $format, $receiptCopy);

        return response($html)
            ->header('Content-Type', 'text/html');
    }

    /**
     * Generate a signed URL for receipt access.
     */
    public function generateReceiptUrl(Request $request, Sale $sale): JsonResponse
    {
        $this->authorizeSaleView($request, $sale);

        $format = $request->get('format', 'a4');
        $receiptCopy = $request->get('copy', 'customer');

        if (! \in_array($receiptCopy, ['customer', 'company'], true)) {
            return $this->error('Invalid copy. Use "customer" or "company"', 400);
        }

        if (! \in_array($format, ['a4', 'thermal'], true)) {
            return $this->error('Invalid format. Use "a4" or "thermal"', 400);
        }

        // Generate signed URL valid for 15 minutes
        $signedUrl = URL::signedRoute(
            'sales.public-receipt',
            ['sale' => $sale->id, 'format' => $format, 'copy' => $receiptCopy],
            now()->addMinutes(15)
        );

        return $this->success([
            'signed_url' => $signedUrl,
            'expires_in' => 900, // 15 minutes in seconds
        ], 'Signed URL generated successfully');
    }

    /**
     * Public receipt access using signed URL.
     */
    public function publicReceipt(Request $request, Sale $sale): mixed
    {
        // Verify the URL signature
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired receipt URL');
        }

        $receiptService = new ReceiptService;
        $format = $request->get('format', 'a4');
        $receiptCopy = $request->get('copy', 'customer');

        if (! \in_array($receiptCopy, ['customer', 'company'], true)) {
            abort(400, 'Invalid copy');
        }

        if (! \in_array($format, ['a4', 'thermal'], true)) {
            abort(400, 'Invalid format');
        }

        // Return HTML view
        $html = $receiptService->generateHtml($sale, $format, $receiptCopy);

        return response($html)
            ->header('Content-Type', 'text/html');
    }

    private function applySaleDeviceState(Device $device, Sale $sale, SaleItem $saleItem, bool $completed, $user): void
    {
        if ($device->stock_transfer_request_id) {
            throw ValidationException::withMessages(['items' => ["Device {$device->serial_number} is awaiting transfer approval."]]);
        }
        if ($completed) {
            $previousStatus = $device->inventory_status?->value;
            $device->update([
                'inventory_status' => InventoryStatus::SOLD,
                'sale_id' => $sale->id,
                'owner_id' => $sale->customer_id,
                'sold_at' => now(),
                'sold_by_id' => $user->id,
            ]);
            $this->movementService->recordSale($device->fresh(), $sale, $saleItem, $user, $previousStatus);

            return;
        }

        $device->update([
            'inventory_status' => InventoryStatus::RESERVED,
            'sale_id' => $sale->id,
        ]);
        $this->movementService->recordReservation($device->fresh(), $sale, $saleItem, $user);
    }

    private function resolveSaleBranchId(Request $request, array $validated): ?int
    {
        $user = $request->user();

        if ($user->hasRole('super_admin')) {
            return isset($validated['branch_id']) ? (int) $validated['branch_id'] : ($user->branch_id ? (int) $user->branch_id : null);
        }

        $branchId = $user->branchScopeId();

        if (! $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => ['Your account must be assigned to a branch before creating sales.'],
            ]);
        }

        return $branchId;
    }

    /** The batch price is authoritative unless an inventory manager supplied a one-off override. */
    private function resolveSellingPrice(Device $device, array $itemData): float
    {
        if (array_key_exists('price_override', $itemData) && $itemData['price_override'] !== null) {
            return round((float) $itemData['price_override'], 2);
        }

        $device->loadMissing('inventoryBatch');
        if ($device->inventoryBatch?->target_selling_price !== null) {
            $batchPrice = (float) $device->inventoryBatch->target_selling_price;
            if ($batchPrice > 0) {
                return round($batchPrice, 2);
            }
        }

        $purchaseCost = $device->purchase_price;
        if ($purchaseCost === null || (float) $purchaseCost <= 0) {
            $purchaseCost = $device->inventoryBatch?->buying_price;
        }
        if ($purchaseCost !== null && (float) $purchaseCost > 0) {
            return round((float) $purchaseCost, 2);
        }

        $requestedUnitPrice = $itemData['unit_price'] ?? null;
        if ($requestedUnitPrice !== null && (float) $requestedUnitPrice > 0) {
            return round((float) $requestedUnitPrice, 2);
        }

        return 0;
    }

    private function resolveCostPrice(Device $device): float
    {
        $device->loadMissing('inventoryBatch');
        $costPrice = $device->purchase_price;
        if ($costPrice === null || (float) $costPrice <= 0) {
            $costPrice = $device->inventoryBatch?->buying_price;
        }

        return round((float) ($costPrice ?? 0), 2);
    }

    private function assertPositiveSellingPrice(Device $device, float $unitPrice): void
    {
        if ($unitPrice <= 0) {
            throw ValidationException::withMessages([
                'items' => ["Device {$device->serial_number} has no valid selling price. Set a batch selling price or purchase cost before selling."],
            ]);
        }
    }

    private function validateSaleListFilters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function buildSaleReadQuery(Request $request, array $validated): Builder
    {
        $branchId = $this->resolveSaleReadBranchId(
            $request,
            $request->filled('branch_id') ? $request->integer('branch_id') : null,
        );
        $query = Sale::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('sale_date', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('sale_date', '<=', $validated['date_to']);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('sale_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    private function resolveSaleReadBranchId(Request $request, ?int $requestedBranchId): ?int
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

    private function authorizeSaleMutation(Request $request, Sale $sale): void
    {
        $user = $request->user();

        if ($user->hasRole('super_admin')) {
            return;
        }

        if ($user->hasRole('store_admin') && (int) $sale->branch_id === (int) $user->branchScopeId()) {
            return;
        }

        if (
            $user->hasRole('customer_service')
            && (int) $sale->branch_id === (int) $user->branchScopeId()
            && (int) $sale->sold_by_id === (int) $user->id
        ) {
            return;
        }

        abort(403, 'You are not allowed to modify this sale.');
    }

    private function authorizeSaleView(Request $request, Sale $sale): void
    {
        $user = $request->user();

        if ($user->hasRole('super_admin')) {
            return;
        }

        if ($user->hasRole('store_admin') && (int) $sale->branch_id === (int) $user->branchScopeId()) {
            return;
        }

        if (
            $user->hasRole('customer_service')
            && (int) $sale->branch_id === (int) $user->branchScopeId()
            && (int) $sale->sold_by_id === (int) $user->id
        ) {
            return;
        }

        abort(403, 'You are not allowed to view this sale.');
    }

    private function logActivity(Sale $sale, $user, string $event, string $description, array $properties = []): void
    {
        try {
            ActivityLog::create([
                'log_name' => 'sales',
                'description' => $description,
                'subject_type' => Sale::class,
                'subject_id' => $sale->id,
                'causer_type' => $user ? get_class($user) : null,
                'causer_id' => $user?->id,
                'properties' => $properties,
                'event' => $event,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write sale activity log', [
                'sale_id' => $sale->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyCompletedSale(Sale $sale): void
    {
        $this->superAdminSaleNotificationService->notifySale($sale);

        $settings = CommunicationSettings::current();

        if (! $settings->sale_notifications_enabled) {
            return;
        }

        $emails = [
            ...$settings->getEmailsArray(),
            config('inventory.approval_email'),
        ];

        $recipients = array_values(array_unique(array_filter(
            array_map(fn ($email) => trim((string) $email), $emails),
            fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        )));

        foreach ($recipients as $email) {
            Notification::route('mail', $email)->notify(new NewSaleNotification($sale));
        }
    }
}
