<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayChanguService
{
    protected string $baseUrl;

    protected string $secretKey;

    protected string $webhookSecret;

    public function __construct()
    {
        $this->baseUrl = config('services.paychangu.base_url') ?? 'https://api.paychangu.com';
        $this->secretKey = config('services.paychangu.secret_key') ?? '';
        $this->webhookSecret = config('services.paychangu.webhook_secret') ?? '';
    }

    /**
     * Initiate a payment session with PayChangu.
     *
     * @return array{success: bool, checkout_url?: string, tx_ref?: string, payment?: Payment, error?: string}
     */
    public function initiatePayment(
        User $user,
        SubscriptionPlan $plan,
        string $billingCycle,
        PaymentMethod $paymentMethod,
        ?string $phoneNumber = null,
        ?string $returnUrl = null
    ): array {
        $amount = $billingCycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $txRef = $this->generateTransactionReference();

        $payment = Payment::create([
            'user_id' => $user->id,
            'subscription_id' => null,
            'payment_number' => 'PAY-'.strtoupper(uniqid()),
            'amount' => $amount,
            'currency' => $plan->currency,
            'status' => PaymentStatus::Pending,
            'payment_method' => $paymentMethod,
            'payment_provider' => 'paychangu',
            'provider_reference' => $txRef,
            'description' => "Subscription payment for {$plan->name} ({$billingCycle})",
            'metadata' => [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_cycle' => $billingCycle,
                'phone_number' => $phoneNumber,
            ],
        ]);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->secretKey}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/payment", [
                'amount' => (string) $amount,
                'currency' => $plan->currency,
                'email' => $user->email,
                'first_name' => $user->first_name ?? $user->name,
                'last_name' => $user->last_name ?? '',
                'callback_url' => route('api.v1.payments.webhook'),
                'return_url' => $returnUrl ?? config('app.frontend_url', config('app.url')).'/subscription/complete',
                'tx_ref' => $txRef,
                'customization' => [
                    'title' => config('app.name').' Subscription',
                    'description' => "Subscribe to {$plan->name} plan",
                ],
                'meta' => [
                    'payment_id' => $payment->id,
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => $billingCycle,
                ],
            ]);

            if ($response->successful() && $response->json('status') === 'success') {
                $data = $response->json('data');

                $payment->update([
                    'provider_transaction_id' => $data['tx_ref'] ?? $txRef,
                ]);

                return [
                    'success' => true,
                    'checkout_url' => $data['checkout_url'],
                    'tx_ref' => $data['tx_ref'],
                    'payment' => $payment,
                ];
            }

            Log::error('PayChangu payment initiation failed', [
                'response' => $response->json(),
                'status' => $response->status(),
            ]);

            $payment->update(['status' => PaymentStatus::Failed]);

            return [
                'success' => false,
                'error' => $response->json('message') ?? 'Payment initiation failed',
            ];
        } catch (RequestException $e) {
            Log::error('PayChangu API error', [
                'error' => $e->getMessage(),
                'payment_id' => $payment->id,
            ]);

            $payment->update(['status' => PaymentStatus::Failed]);

            return [
                'success' => false,
                'error' => 'Payment service unavailable. Please try again later.',
            ];
        }
    }

    /**
     * Verify a transaction with PayChangu.
     *
     * @return array{success: bool, status?: string, data?: array, error?: string}
     */
    public function verifyTransaction(string $txRef): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->secretKey}",
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/verify-payment/{$txRef}");

            if ($response->successful() && $response->json('status') === 'success') {
                return [
                    'success' => true,
                    'status' => $response->json('data.status'),
                    'data' => $response->json('data'),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json('message') ?? 'Verification failed',
            ];
        } catch (RequestException $e) {
            Log::error('PayChangu verification error', [
                'tx_ref' => $txRef,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Verification service unavailable',
            ];
        }
    }

    /**
     * Validate webhook signature.
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        // An empty secret produces an HMAC that anyone can compute, so it must be
        // treated as "cannot verify" rather than as a valid signature.
        if ($this->webhookSecret === '' || $signature === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Process successful payment and activate subscription.
     *
     * The whole activation runs in one transaction with the payment row locked.
     * That lock is the idempotency guard: the webhook and a manual verify both
     * key on the same payment row, so whichever arrives second waits for the
     * first to commit, re-reads the row, sees `completed` and stops. Without the
     * lock both pass the status check and the customer ends up with two
     * subscriptions for a single payment.
     *
     * The status check is re-done against the locked row rather than the caller's
     * instance, so a caller holding a stale model cannot slip past the guard.
     */
    public function processSuccessfulPayment(Payment $payment, array $webhookData): ?Subscription
    {
        return DB::transaction(function () use ($payment, $webhookData) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            // Already settled: a replayed webhook is a no-op.
            if ($payment->status === PaymentStatus::Completed) {
                return $payment->subscription;
            }

            $payment->update([
                'status' => PaymentStatus::Completed,
                'provider_transaction_id' => $webhookData['trans_id'] ?? $payment->provider_transaction_id,
                'paid_at' => $payment->paid_at ?? now(),
            ]);

            $metadata = $payment->metadata ?? [];
            $planId = $metadata['plan_id'] ?? null;
            $billingCycle = $metadata['billing_cycle'] ?? 'monthly';

            if (! $planId) {
                Log::error('Missing plan_id in payment metadata', ['payment_id' => $payment->id]);

                return null;
            }

            $plan = SubscriptionPlan::find($planId);
            if (! $plan) {
                Log::error('Plan not found for payment', ['plan_id' => $planId, 'payment_id' => $payment->id]);

                return null;
            }

            $months = $billingCycle === 'yearly' ? 12 : 1;

            $existingSubscription = Subscription::where('user_id', $payment->user_id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existingSubscription) {
                // `ends_at` is nullable, so fall back to now() instead of calling
                // addMonths() on null. copy() avoids mutating the model's cached
                // date attribute before the update is written.
                $base = ($existingSubscription->ends_at ?? now())->copy();

                $existingSubscription->update([
                    'plan_id' => $plan->id,
                    'billing_cycle' => $billingCycle,
                    'ends_at' => $base->addMonths($months),
                    'last_payment_id' => $payment->id,
                ]);
                $subscription = $existingSubscription;
            } else {
                $subscription = Subscription::create([
                    'user_id' => $payment->user_id,
                    'plan_id' => $plan->id,
                    'status' => SubscriptionStatus::Active,
                    'billing_cycle' => $billingCycle,
                    'starts_at' => now(),
                    'ends_at' => now()->addMonths($months),
                    'auto_renew' => true,
                    'last_payment_id' => $payment->id,
                ]);
            }

            $payment->update(['subscription_id' => $subscription->id]);

            return $subscription;
        });
    }

    /**
     * Process failed payment.
     */
    public function processFailedPayment(Payment $payment, array $webhookData): void
    {
        $payment->update([
            'status' => PaymentStatus::Failed,
            'metadata' => array_merge($payment->metadata ?? [], [
                'failure_reason' => $webhookData['message'] ?? 'Payment failed',
            ]),
        ]);
    }

    /**
     * Initiate a payout to a customer's mobile money account.
     *
     * PayChangu documents no refund endpoint, so money is returned through the
     * Disbursement (payout) API. Payouts are asynchronous: a successful call
     * returns status "pending" and settles afterwards, so a successful response
     * must not be read as settled funds.
     *
     * @param  string  $chargeId  Client-generated reference. PayChangu requires it
     *                            to be unique for every transaction, so a retried
     *                            payout must use a fresh value.
     * @return array{success: bool, status?: string|null, payout_id?: string|null, ref_id?: string|null, data?: array, error?: string}
     */
    public function initiateMobileMoneyPayout(
        string $operatorRefId,
        string $mobile,
        string $amount,
        string $chargeId,
        ?string $email = null,
        ?string $firstName = null,
        ?string $lastName = null,
    ): array {
        // Drop the optional identity fields entirely rather than sending nulls.
        $payload = array_filter([
            'mobile_money_operator_ref_id' => $operatorRefId,
            'mobile' => $mobile,
            'amount' => $amount,
            'charge_id' => $chargeId,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ], fn ($value) => $value !== null);

        return $this->sendPayout('/mobile-money/payouts/initialize', $payload);
    }

    /**
     * Initiate a payout to a customer's bank account.
     *
     * @return array{success: bool, status?: string|null, payout_id?: string|null, ref_id?: string|null, data?: array, error?: string}
     */
    public function initiateBankPayout(
        string $bankUuid,
        string $accountNumber,
        string $accountName,
        string $amount,
        string $chargeId,
    ): array {
        return $this->sendPayout('/direct-charge/payouts/initialize', [
            'payout_method' => 'bank_transfer',
            'bank_uuid' => $bankUuid,
            'bank_account_number' => $accountNumber,
            'bank_account_name' => $accountName,
            'amount' => $amount,
            'charge_id' => $chargeId,
        ]);
    }

    /**
     * Send a payout request and normalise the provider's response.
     *
     * The two payout endpoints answer with different envelopes: mobile money
     * returns the payout directly under `data`, while the bank endpoint nests it
     * under `data.transaction`. Both are flattened to the same shape here.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status?: string|null, payout_id?: string|null, ref_id?: string|null, data?: array, error?: string}
     */
    protected function sendPayout(string $path, array $payload): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->secretKey}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}{$path}", $payload);

            if ($response->successful() && $response->json('status') === 'success') {
                $data = $response->json('data.transaction') ?? $response->json('data') ?? [];

                return [
                    'success' => true,
                    'status' => $data['status'] ?? null,
                    'payout_id' => $data['charge_id'] ?? null,
                    'ref_id' => $data['ref_id'] ?? null,
                    'data' => $data,
                ];
            }

            Log::error('PayChangu payout rejected', [
                'path' => $path,
                'http_status' => $response->status(),
                'response' => $response->json(),
            ]);

            return [
                'success' => false,
                'error' => $response->json('message') ?? 'The payment provider rejected the payout request.',
            ];
        } catch (RequestException $e) {
            Log::error('PayChangu payout error', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'The payment provider was unreachable while issuing the refund.',
            ];
        }
    }

    /**
     * Generate a unique transaction reference.
     */
    protected function generateTransactionReference(): string
    {
        return 'TX-'.strtoupper(uniqid()).'-'.time();
    }
}
