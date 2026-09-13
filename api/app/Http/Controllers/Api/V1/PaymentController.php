<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PayChanguService;
use App\Services\PaymentRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @group Payments
 *
 * APIs for payment processing - initiate payments and view payment history.
 */
class PaymentController extends Controller
{
    public function __construct(
        protected PayChanguService $payChanguService,
        protected PaymentRefundService $refundService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()->with(['subscription.plan', 'user']);
        if (! $request->user()->hasRole('super_admin')) {
            $branchId = $request->user()->requiredBranchScopeId();
            $query->whereHas('user', fn ($userQuery) => $userQuery->where('branch_id', $branchId));
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('subscription_id')) {
            $query->where('subscription_id', $request->subscription_id);
        }

        if ($request->has('search')) {
            $query->where('payment_number', 'like', '%'.$request->search.'%');
        }

        $payments = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => PaymentResource::collection($payments),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    public function myPayments(Request $request): JsonResponse
    {
        $query = Payment::query()
            ->where('user_id', $request->user()->id)
            ->with(['subscription.plan']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $payments = $query->latest()->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => PaymentResource::collection($payments),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        $payment->load(['subscription.plan', 'user']);

        return response()->json([
            'success' => true,
            'data' => new PaymentResource($payment),
        ]);
    }

    public function verify(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        if ($payment->status === PaymentStatus::Completed) {
            return response()->json([
                'success' => true,
                'message' => 'Payment already verified and completed.',
                'data' => new PaymentResource($payment->load(['subscription.plan'])),
            ]);
        }

        $txRef = $payment->provider_reference;
        if (! $txRef) {
            return response()->json([
                'success' => false,
                'message' => 'No transaction reference found for this payment.',
            ], 400);
        }

        $result = $this->payChanguService->verifyTransaction($txRef);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], 400);
        }

        if ($result['status'] === 'success' || $result['status'] === 'successful') {
            $subscription = $this->payChanguService->processSuccessfulPayment($payment, $result['data']);

            return response()->json([
                'success' => true,
                'message' => 'Payment verified successfully.',
                'data' => new PaymentResource($payment->fresh(['subscription.plan'])),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Payment not yet completed. Status: '.$result['status'],
            'data' => [
                'payment_status' => $result['status'],
            ],
        ], 400);
    }

    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('Signature', '');

        // Fail closed. Without a configured secret the caller cannot be verified,
        // so the request is refused. An unset secret must never silently disable
        // signature verification, which would let anyone activate a subscription.
        if (! config('services.paychangu.webhook_secret')) {
            Log::critical('PayChangu webhook rejected: PAYCHANGU_WEBHOOK_SECRET is not configured.');

            return response()->json(['error' => 'Webhook verification is not configured'], 500);
        }

        if (! $this->payChanguService->validateWebhookSignature($payload, $signature)) {
            Log::warning('Invalid PayChangu webhook signature', [
                'signature' => $signature,
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $data = $request->all();
        $event = $data['event'] ?? null;
        $txRef = $data['tx_ref'] ?? $data['data']['tx_ref'] ?? null;

        Log::info('PayChangu webhook received', [
            'event' => $event,
            'tx_ref' => $txRef,
        ]);

        if (! $txRef) {
            return response()->json(['error' => 'Missing tx_ref'], 400);
        }

        $payment = Payment::where('provider_reference', $txRef)
            ->orWhere('provider_transaction_id', $txRef)
            ->first();

        if (! $payment) {
            Log::error('Payment not found for webhook', ['tx_ref' => $txRef]);

            return response()->json(['error' => 'Payment not found'], 404);
        }

        $status = $data['status'] ?? $data['data']['status'] ?? null;

        if ($status === 'success' || $status === 'successful') {
            $this->payChanguService->processSuccessfulPayment($payment, $data);
            Log::info('Payment processed successfully', ['payment_id' => $payment->id]);
        } elseif ($status === 'failed' || $status === 'cancelled') {
            $this->payChanguService->processFailedPayment($payment, $data);
            Log::info('Payment marked as failed', ['payment_id' => $payment->id]);
        }

        return response()->json(['success' => true]);
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('refund', $payment);

        // Shape only. Whether a payout target is actually required depends on the
        // payment's method and state, so that check lives with the refund logic.
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'payout' => ['sometimes', 'array'],
            'payout.mobile' => ['nullable', 'string', 'max:20'],
            'payout.mobile_money_operator_ref_id' => ['nullable', 'string', 'max:100'],
            'payout.bank_uuid' => ['nullable', 'string', 'max:100'],
            'payout.bank_account_number' => ['nullable', 'string', 'max:50'],
            'payout.bank_account_name' => ['nullable', 'string', 'max:150'],
        ]);

        $result = $this->refundService->refund(
            $payment,
            $request->user(),
            $validated['reason'],
            $validated['payout'] ?? [],
        );

        // A rejected refund has no payment to return: the payment is untouched.
        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['http_status']);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => new PaymentResource($result['payment']->load(['subscription.plan'])),
        ], $result['http_status']);
    }
}
