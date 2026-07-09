<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\PayChanguService;
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
    public function __construct(protected PayChanguService $payChanguService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Payment::class);

        $query = Payment::query()->with(['subscription.plan', 'user']);

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

        if (config('services.paychangu.webhook_secret') && ! $this->payChanguService->validateWebhookSignature($payload, $signature)) {
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

        if ($payment->status !== PaymentStatus::Completed) {
            return response()->json([
                'success' => false,
                'message' => 'Only completed payments can be refunded.',
            ], 400);
        }

        $payment->update([
            'status' => PaymentStatus::Refunded,
            'metadata' => array_merge($payment->metadata ?? [], [
                'refunded_at' => now()->toIso8601String(),
                'refunded_by' => $request->user()->id,
                'refund_reason' => $request->reason,
            ]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment refunded successfully.',
            'data' => new PaymentResource($payment),
        ]);
    }
}
