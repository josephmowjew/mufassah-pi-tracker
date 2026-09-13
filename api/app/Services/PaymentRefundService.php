<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues refunds for completed payments.
 *
 * PayChangu documents no refund endpoint, so money is returned through the
 * Disbursement (payout) API. Payouts are asynchronous, and that fact drives the
 * shape of this class:
 *
 *   1. claim   — under a row lock, move the payment to `refund_processing`
 *   2. payout  — call the provider *outside* the lock
 *   3. settle  — under a second lock, record what the provider actually said
 *
 * Step 1 is the idempotency guard: the lock makes check-and-set atomic, so two
 * concurrent requests cannot both start a payout. Step 2 is deliberately outside
 * the transaction, because holding a row lock across an external HTTP call
 * invites lock contention and connection-pool exhaustion.
 *
 * A crash between steps 1 and 3 leaves the payment in `refund_processing`, which
 * is the truthful state: a payout may or may not have been submitted, and it can
 * be reconciled against the provider's transfer record. That is strictly better
 * than the previous behaviour, which marked payments refunded without ever
 * sending the money.
 */
class PaymentRefundService
{
    public function __construct(protected PayChanguService $payChanguService) {}

    /**
     * Payment methods whose money can be returned through PayChangu.
     *
     * Cash is handed back at the counter and card refunds are settled on the
     * terminal, so those are recorded rather than paid out by us.
     *
     * @var list<PaymentMethod>
     */
    private const PROVIDER_REFUNDABLE = [
        PaymentMethod::MobileMoney,
        PaymentMethod::BankTransfer,
    ];

    /**
     * Refund a completed payment.
     *
     * @param  array<string, mixed>  $destination  Payout target for provider-issued refunds.
     * @return array{success: bool, http_status: int, message: string, payment: Payment}
     */
    public function refund(Payment $payment, User $actor, ?string $reason, array $destination = []): array
    {
        $claim = $this->claim($payment, $actor, $reason, $destination);

        if (! $claim['success']) {
            return $claim;
        }

        /** @var Payment $payment */
        $payment = $claim['payment'];

        if (! in_array($payment->payment_method, self::PROVIDER_REFUNDABLE, true)) {
            return $this->settleManually($payment);
        }

        return $this->payOut($payment, $destination);
    }

    /**
     * Atomically move a completed payment into the refund-in-progress state.
     *
     * @param  array<string, mixed>  $destination
     * @return array{success: bool, http_status: int, message: string, payment?: Payment}
     */
    private function claim(Payment $payment, User $actor, ?string $reason, array $destination): array
    {
        return DB::transaction(function () use ($payment, $actor, $reason, $destination) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Refunded) {
                return $this->reject(409, 'This payment has already been refunded.');
            }

            if ($locked->status === PaymentStatus::RefundProcessing) {
                return $this->reject(409, 'A refund for this payment is already in progress.');
            }

            if ($locked->status !== PaymentStatus::Completed) {
                return $this->reject(400, 'Only completed payments can be refunded.');
            }

            // Checked only once the payment is known to be refundable, so a
            // request for a non-refundable payment reports the state problem
            // rather than a missing payout target. Throwing here rolls the
            // transaction back, leaving the payment untouched.
            $this->assertDestinationIsPresent($locked, $destination);

            $metadata = $locked->metadata ?? [];
            $refund = $metadata['refund'] ?? [];

            $metadata['refund'] = array_merge($refund, [
                'status' => 'processing',
                'reason' => $reason,
                'requested_at' => now()->toIso8601String(),
                'requested_by' => $actor->id,
                'attempts' => ($refund['attempts'] ?? 0) + 1,
            ]);

            $locked->update([
                'status' => PaymentStatus::RefundProcessing,
                'metadata' => $metadata,
            ]);

            return ['success' => true, 'http_status' => 200, 'message' => '', 'payment' => $locked];
        });
    }

    /**
     * Ensure the request carries a payout target when one is required.
     *
     * @param  array<string, mixed>  $destination
     *
     * @throws ValidationException
     */
    private function assertDestinationIsPresent(Payment $payment, array $destination): void
    {
        $required = match ($payment->payment_method) {
            PaymentMethod::MobileMoney => [
                'payout.mobile' => 'the recipient mobile number',
                'payout.mobile_money_operator_ref_id' => 'the mobile money operator reference id',
            ],
            PaymentMethod::BankTransfer => [
                'payout.bank_uuid' => 'the destination bank uuid',
                'payout.bank_account_number' => 'the destination account number',
                'payout.bank_account_name' => 'the destination account name',
            ],
            default => [],
        };

        $missing = [];
        foreach ($required as $key => $label) {
            if (blank($destination[str_replace('payout.', '', $key)] ?? null)) {
                $missing[$key] = "Refunding a {$payment->payment_method->label()} payment requires {$label}.";
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    /**
     * Send the payout and record the provider's answer.
     *
     * @param  array<string, mixed>  $destination
     * @return array{success: bool, http_status: int, message: string, payment: Payment}
     */
    private function payOut(Payment $payment, array $destination): array
    {
        $amount = number_format((float) $payment->amount, 2, '.', '');

        $result = $payment->payment_method === PaymentMethod::BankTransfer
            ? $this->payChanguService->initiateBankPayout(
                (string) ($destination['bank_uuid'] ?? ''),
                (string) ($destination['bank_account_number'] ?? ''),
                (string) ($destination['bank_account_name'] ?? ''),
                $amount,
                $this->payoutReference($payment),
            )
            : $this->payChanguService->initiateMobileMoneyPayout(
                (string) ($destination['mobile_money_operator_ref_id'] ?? ''),
                (string) ($destination['mobile'] ?? ''),
                $amount,
                $this->payoutReference($payment),
                $payment->user?->email,
            );

        if (! $result['success']) {
            return $this->settleFailed($payment, $result['error'] ?? 'The refund could not be issued.');
        }

        // "success" means the provider settled the payout inside this call;
        // anything else (in practice "pending") means it is still in flight.
        return ($result['status'] ?? null) === 'success'
            ? $this->settleRefunded($payment, $result)
            : $this->settlePending($payment, $result);
    }

    /**
     * Build the payout reference.
     *
     * PayChangu requires `charge_id` to be unique for every transaction, so the
     * attempt counter is part of the reference and a retry never reuses one.
     */
    private function payoutReference(Payment $payment): string
    {
        $attempts = $payment->metadata['refund']['attempts'] ?? 1;

        return 'RFND-'.$payment->payment_number.'-'.$attempts;
    }

    /**
     * @param  array{status?: string|null, payout_id?: string|null, ref_id?: string|null}  $result
     * @return array{success: bool, http_status: int, message: string, payment: Payment}
     */
    private function settleRefunded(Payment $payment, array $result): array
    {
        return $this->recordOutcome($payment, PaymentStatus::Refunded, 'completed', [
            'provider_status' => $result['status'] ?? null,
            'provider_payout_id' => $result['payout_id'] ?? null,
            'provider_ref_id' => $result['ref_id'] ?? null,
            'completed_at' => now()->toIso8601String(),
        ], 'Payment refunded successfully.', 200);
    }

    /**
     * @param  array{status?: string|null, payout_id?: string|null, ref_id?: string|null}  $result
     * @return array{success: bool, http_status: int, message: string, payment: Payment}
     */
    private function settlePending(Payment $payment, array $result): array
    {
        return $this->recordOutcome($payment, PaymentStatus::RefundProcessing, 'processing', [
            'provider_status' => $result['status'] ?? null,
            'provider_payout_id' => $result['payout_id'] ?? null,
            'provider_ref_id' => $result['ref_id'] ?? null,
        ], 'Refund initiated. The provider is still processing the payout, so this payment will be marked refunded once the transfer settles.', 202);
    }

    /**
     * The provider refused the payout. Release the claim so it can be retried.
     *
     * @return array{success: bool, http_status: int, message: string, payment: Payment}
     */
    private function settleFailed(Payment $payment, string $error): array
    {
        return $this->recordOutcome($payment, PaymentStatus::Completed, 'failed', [
            'failure_reason' => $error,
            'failed_at' => now()->toIso8601String(),
        ], "The refund was not issued: {$error}", 502, success: false);
    }

    /**
     * Record a refund for a payment that was not made through PayChangu.
     *
     * The money moves outside the system (cash at the counter, card on the
     * terminal), so the payment is marked refunded on the operator's authority
     * and the absence of a provider payout is stated plainly rather than implied.
     *
     * @return array{success: bool, http_status: int, message: string, payment: Payment}
     */
    private function settleManually(Payment $payment): array
    {
        return $this->recordOutcome($payment, PaymentStatus::Refunded, 'completed', [
            'provider_status' => 'not_applicable',
            'settled_manually' => true,
            'completed_at' => now()->toIso8601String(),
        ], 'Payment marked as refunded. No provider payout was issued because this payment was not collected through PayChangu.', 200);
    }

    /**
     * Write the outcome of a refund attempt back onto the payment.
     *
     * @param  array<string, mixed>  $refundFields
     * @return array{success: bool, http_status: int, message: string, payment: Payment}
     */
    private function recordOutcome(
        Payment $payment,
        PaymentStatus $status,
        string $refundStatus,
        array $refundFields,
        string $message,
        int $httpStatus,
        bool $success = true,
    ): array {
        $settled = DB::transaction(function () use ($payment, $status, $refundStatus, $refundFields) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $metadata = $locked->metadata ?? [];
            $metadata['refund'] = array_merge($metadata['refund'] ?? [], $refundFields, [
                'status' => $refundStatus,
            ]);

            $locked->update([
                'status' => $status,
                'metadata' => $metadata,
            ]);

            return $locked;
        });

        return [
            'success' => $success,
            'http_status' => $httpStatus,
            'message' => $message,
            'payment' => $settled,
        ];
    }

    /**
     * @return array{success: false, http_status: int, message: string}
     */
    private function reject(int $status, string $message): array
    {
        return ['success' => false, 'http_status' => $status, 'message' => $message];
    }
}
