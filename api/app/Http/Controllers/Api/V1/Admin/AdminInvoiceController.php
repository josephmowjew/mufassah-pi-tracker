<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Api\V1\BaseController;
use App\Http\Resources\InvoiceResource;
use App\Models\InventoryActionChallenge;
use App\Models\Invoice;
use App\Notifications\InvoiceEmailNotification;
use App\Services\InventoryActionApprovalService;
use App\Services\InvoiceDocumentService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminInvoiceController extends BaseController
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly InvoiceDocumentService $documentService,
        private readonly InventoryActionApprovalService $approvalService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $user = $request->user();
        $query = Invoice::query()
            ->with(['customer', 'branch', 'createdBy'])
            ->withCount('items');

        if ($user->hasRole('customer_service')) {
            $query->where('created_by_id', $user->id)
                ->where('branch_id', $user->requiredBranchScopeId());
        } elseif (! $user->hasRole('super_admin')) {
            $query->where('branch_id', $user->requiredBranchScopeId());
        } else {
            $branchId = $request->boolean('all_branches') ? null : ($validated['branch_id'] ?? $user->branch_id);
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['date_from'])) {
            $query->where('issued_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->where('issued_at', '<=', $validated['date_to'].' 23:59:59');
        }
        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('recipient_email', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"));
            });
        }

        $invoices = $query
            ->orderByDesc('issued_at')
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 15))));

        return $this->paginated(InvoiceResource::collection($invoices));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'vat_applicable' => ['required', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_label' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.device_id' => ['required_without:items.*.category_id', 'integer', Rule::exists('devices', 'id')->whereNull('deleted_at')],
            'items.*.category_id' => ['required_without:items.*.device_id', 'integer', Rule::exists('device_categories', 'id')->whereNull('deleted_at')],
            'items.*.brand' => ['required_with:items.*.category_id', 'string', 'max:100'],
            'items.*.model' => ['required_with:items.*.category_id', 'string', 'max:150'],
            'items.*.color' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['required_with:items.*.category_id', 'integer', 'min:1', 'max:100'],
            'items.*.subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.price_override' => ['nullable', 'numeric', 'min:0'],
            'items.*.price_override_reason' => ['required_with:items.*.price_override', 'string', 'max:500'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'vat_applicable.required' => 'Choose whether VAT applies to this invoice.',
            'items.required' => 'Add at least one device to the invoice.',
        ]);

        $invoice = $this->invoiceService->issue($validated, $request->user());

        Notification::route('mail', $invoice->recipient_email)
            ->notify(new InvoiceEmailNotification($invoice));
        $invoice->forceFill(['emailed_at' => now()])->save();

        return $this->success(
            new InvoiceResource($invoice->fresh(['customer', 'branch', 'createdBy', 'items.device.category'])),
            'Invoice issued and emailed successfully',
            201,
        );
    }

    public function resend(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        Notification::route('mail', $invoice->recipient_email)
            ->notify(new InvoiceEmailNotification($invoice));
        $invoice->forceFill(['emailed_at' => now()])->save();

        return $this->success(new InvoiceResource($invoice->fresh()), 'Invoice email queued successfully');
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $invoice = $this->invoiceService->expireIfNeeded($invoice, $request->user());
        $this->authorizeInvoice($request, $invoice);
        $invoice->load(['customer', 'branch', 'createdBy', 'items.device.category', 'convertedSale.items.device.category']);

        return $this->success(new InvoiceResource($invoice));
    }

    public function publicShow(Request $request, string $token): JsonResponse
    {
        $invoice = Invoice::query()->where('public_token', $token)->firstOrFail();
        $invoice = $this->invoiceService->expireIfNeeded($invoice);
        $invoice->load(['customer', 'branch', 'items.device.category']);

        return $this->success(new InvoiceResource($invoice));
    }

    public function redeem(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $validated = $request->validate([
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $invoice = $this->invoiceService->redeem($invoice, $validated, $request->user());
        $invoice->load(['customer', 'branch', 'createdBy', 'items.device.category', 'convertedSale.items.device.category']);

        return $this->success(new InvoiceResource($invoice), 'Invoice redeemed successfully');
    }

    public function cancel(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($invoice->status !== InvoiceStatus::SENT) {
            throw ValidationException::withMessages([
                'invoice' => ['Only an active invoice can be cancelled.'],
            ]);
        }

        if ($invoice->isExpired()) {
            $this->invoiceService->expireIfNeeded($invoice, $request->user());
            throw ValidationException::withMessages([
                'invoice' => ['This invoice has already expired and cannot be cancelled.'],
            ]);
        }

        $items = $invoice->items()->with('device')->get();
        $firstDevice = $items->first()?->device;
        if (! $firstDevice) {
            throw ValidationException::withMessages([
                'invoice' => ['This invoice has no devices to release.'],
            ]);
        }

        $challenge = $this->approvalService->create(
            $request->user(),
            InventoryActionChallenge::ACTION_INVOICE_CANCELLATION,
            [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'reason' => $validated['reason'],
                'device_ids' => $items->pluck('device_id')->map(fn ($id) => (int) $id)->all(),
            ],
            $firstDevice,
            "Invoice {$invoice->invoice_number}",
        );

        return $this->success(
            $this->challengeData($challenge),
            'A verification PIN was sent by email and is available securely in notifications.',
            202,
        );
    }

    public function document(Request $request, Invoice $invoice): mixed
    {
        $this->authorizeInvoice($request, $invoice);
        $format = $request->get('format', 'pdf');

        if (! in_array($format, ['pdf', 'html'], true)) {
            abort(400, 'Invoice format must be pdf or html.');
        }

        if ($format === 'html') {
            return response($this->documentService->generateHtml($invoice))
                ->header('Content-Type', 'text/html');
        }

        return $this->documentService->generatePdf($invoice);
    }

    private function challengeData(InventoryActionChallenge $challenge): array
    {
        $challenge->loadMissing('requestedBy');

        return [
            'challenge_id' => $challenge->id,
            'action' => $challenge->action,
            'masked_email' => $this->approvalService->maskedEmail($this->approvalService->approvalEmail()),
            'expires_at' => $challenge->expires_at?->toLocalIso8601String(),
            'attempts_remaining' => max(0, $challenge->max_attempts - $challenge->attempts),
            'device_count' => count((array) ($challenge->payload['device_ids'] ?? [])),
        ];
    }

    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        $user = $request->user();

        if ($user->hasRole('super_admin')) {
            return;
        }

        if ($user->hasRole('customer_service')) {
            abort_unless(
                (int) $invoice->created_by_id === (int) $user->id
                    && (int) $invoice->branch_id === $user->requiredBranchScopeId(),
                403,
                'You are not allowed to access this invoice.',
            );

            return;
        }

        abort_unless(
            $user->canAccessBranch($invoice->branch_id),
            403,
            'You are not allowed to access this invoice.',
        );
    }
}
