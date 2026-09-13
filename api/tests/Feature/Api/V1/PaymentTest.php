<?php

use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'customer_service', 'guard_name' => 'api']);

    $this->user = User::factory()->create();
    $this->user->assignRole('customer');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
    $this->plan = SubscriptionPlan::factory()->basic()->create();
    $this->subscription = Subscription::factory()
        ->active()
        ->for($this->user)
        ->for($this->plan, 'plan')
        ->create();
});

/**
 * POST a PayChangu webhook with a raw body.
 *
 * The signature must cover the exact bytes that are sent, so the request is
 * dispatched raw rather than through postJson (which re-encodes the payload).
 *
 * @param  string|null  $secret  Secret to configure; null simulates an unset secret.
 * @param  string|null  $signature  Explicit signature, or null to sign with $secret.
 */
function postWebhook(string $path, array $payload, ?string $secret = 'test-webhook-secret', ?string $signature = null)
{
    config(['services.paychangu.webhook_secret' => $secret]);

    $body = json_encode($payload);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($signature !== null) {
        // An empty string simulates a missing Signature header.
        $server['HTTP_SIGNATURE'] = $signature;
    } elseif ($secret !== null) {
        $server['HTTP_SIGNATURE'] = hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', $path, [], [], [], $server, $body);
}

describe('My Payments', function () {
    it('returns payments for authenticated user', function () {
        Payment::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
        ]);

        $otherUser = User::factory()->create();
        Payment::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/payments/my-payments');

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 3);
    });

    it('filters payments by status', function () {
        Payment::factory()->completed()->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
        ]);
        Payment::factory()->pending()->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/payments/my-payments?status=completed');

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 1);
    });
});

describe('Payment Details', function () {
    it('allows user to view their payment', function () {
        $payment = Payment::factory()->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/payments/{$payment->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $payment->id);
    });

    it('prevents user from viewing others payment', function () {
        $otherUser = User::factory()->create();
        $payment = Payment::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/payments/{$payment->id}");

        $response->assertForbidden();
    });

    it('allows admin to view any payment', function () {
        $payment = Payment::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/payments/{$payment->id}");

        $response->assertSuccessful();
    });
});

describe('Payment Verification', function () {
    it('verifies successful payment and creates subscription', function () {
        $payment = Payment::factory()->pending()->create([
            'user_id' => $this->user->id,
            'subscription_id' => null,
            'provider_reference' => 'TX-TEST-123',
            'metadata' => [
                'plan_id' => $this->plan->id,
                'billing_cycle' => 'monthly',
            ],
        ]);

        Http::fake([
            'api.paychangu.com/*' => Http::response([
                'status' => 'success',
                'data' => [
                    'status' => 'success',
                    'trans_id' => 'TRANS-123',
                    'amount' => 5000,
                    'currency' => 'MWK',
                ],
            ]),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payments/{$payment->id}/verify");

        $response->assertSuccessful();
        expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
    });

    it('returns already completed for verified payment', function () {
        $payment = Payment::factory()->completed()->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/payments/{$payment->id}/verify");

        $response->assertSuccessful()
            ->assertJsonPath('message', 'Payment already verified and completed.');
    });
});

describe('Webhook Handling', function () {
    it('processes successful payment webhook', function () {
        $payment = Payment::factory()->pending()->create([
            'user_id' => $this->user->id,
            'provider_reference' => 'TX-WEBHOOK-123',
            'metadata' => [
                'plan_id' => $this->plan->id,
                'billing_cycle' => 'monthly',
            ],
        ]);

        $response = postWebhook('/api/v1/payments/webhook', [
            'event' => 'api.charge.payment',
            'status' => 'success',
            'tx_ref' => 'TX-WEBHOOK-123',
            'data' => [
                'tx_ref' => 'TX-WEBHOOK-123',
                'status' => 'success',
                'trans_id' => 'TRANS-456',
            ],
        ]);

        $response->assertSuccessful();
        expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
    });

    it('processes failed payment webhook', function () {
        $payment = Payment::factory()->pending()->create([
            'user_id' => $this->user->id,
            'provider_reference' => 'TX-FAILED-123',
        ]);

        $response = postWebhook('/api/v1/payments/webhook', [
            'event' => 'api.charge.payment',
            'status' => 'failed',
            'tx_ref' => 'TX-FAILED-123',
            'message' => 'Insufficient funds',
        ]);

        $response->assertSuccessful();
        expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('returns 404 for unknown transaction', function () {
        $response = postWebhook('/api/v1/payments/webhook', [
            'event' => 'api.charge.payment',
            'status' => 'success',
            'tx_ref' => 'UNKNOWN-TX-REF',
        ]);

        $response->assertNotFound();
    });
});

describe('Admin Payment Management', function () {
    it('allows admin to list all payments', function () {
        Payment::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/payments');

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 5);
    });

    it('allows admin to refund payment', function () {
        $payment = Payment::factory()->completed()->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/payments/{$payment->id}/refund", [
                'reason' => 'Customer request',
            ]);

        $response->assertSuccessful();
        expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded);
    });

    it('prevents refunding non-completed payment', function () {
        $payment = Payment::factory()->pending()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/payments/{$payment->id}/refund", [
                'reason' => 'Test',
            ]);

        $response->assertStatus(400);
    });

    it('prevents non-admin from refunding', function () {
        $payment = Payment::factory()->completed()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/admin/payments/{$payment->id}/refund", [
                'reason' => 'Test',
            ]);

        $response->assertForbidden();
    });

    it('filters payments by user_id', function () {
        Payment::factory()->count(3)->create(['user_id' => $this->user->id]);
        Payment::factory()->count(2)->create();

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/payments?user_id={$this->user->id}");

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 3);
    });

    it('searches payments by payment number', function () {
        $payment = Payment::factory()->create([
            'payment_number' => 'PAY-SEARCH-123',
        ]);
        Payment::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/payments?search=SEARCH-123');

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 1);
    });

    it('limits store admins to payments for users in their branch', function () {
        $branch = Branch::create(['name' => 'Payment Branch', 'code' => 'PAY', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Other Payment Branch', 'code' => 'OPY', 'is_active' => true]);
        $storeAdmin = User::factory()->create(['branch_id' => $branch->id]);
        $storeAdmin->assignRole('store_admin');
        $ownUser = User::factory()->create(['branch_id' => $branch->id]);
        $otherUser = User::factory()->create(['branch_id' => $otherBranch->id]);
        $ownPayment = Payment::factory()->create(['user_id' => $ownUser->id]);
        $otherPayment = Payment::factory()->create(['user_id' => $otherUser->id]);

        $this->actingAs($storeAdmin)
            ->getJson('/api/v1/admin/payments')
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ownPayment->id);

        $this->actingAs($storeAdmin)
            ->postJson("/api/v1/admin/payments/{$otherPayment->id}/refund", ['reason' => 'Cross branch'])
            ->assertForbidden();
    });
});

describe('PayChangu Webhook Signature Enforcement', function () {
    it('rejects the webhook when no secret is configured', function () {
        $payment = Payment::factory()->pending()->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
            'provider_reference' => 'tx-unset-secret',
        ]);

        $payload = ['event' => 'api.charge.payment', 'status' => 'success', 'tx_ref' => 'tx-unset-secret'];

        // Even a signature computed over an empty key must be refused: with no
        // configured secret the caller cannot be verified, so nothing is trusted.
        $emptyKeySignature = hash_hmac('sha256', json_encode($payload), '');

        postWebhook('/api/v1/webhooks/paychangu', $payload, null, $emptyKeySignature)
            ->assertStatus(500);

        expect($payment->fresh()->status)->not->toBe(PaymentStatus::Completed);
    });

    it('rejects the webhook when the signature is wrong', function () {
        $payment = Payment::factory()->pending()->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
            'provider_reference' => 'tx-bad-signature',
        ]);

        postWebhook('/api/v1/webhooks/paychangu', [
            'event' => 'api.charge.payment',
            'status' => 'success',
            'tx_ref' => 'tx-bad-signature',
        ], 'test-webhook-secret', 'not-a-valid-signature')->assertUnauthorized();

        expect($payment->fresh()->status)->not->toBe(PaymentStatus::Completed);
    });

    it('rejects the webhook when the signature header is missing', function () {
        $payment = Payment::factory()->pending()->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
            'provider_reference' => 'tx-no-signature',
        ]);

        postWebhook('/api/v1/webhooks/paychangu', [
            'event' => 'api.charge.payment',
            'status' => 'success',
            'tx_ref' => 'tx-no-signature',
        ], 'test-webhook-secret', '')->assertUnauthorized();

        expect($payment->fresh()->status)->not->toBe(PaymentStatus::Completed);
    });

    it('rejects a signature computed with the wrong secret', function () {
        $payment = Payment::factory()->pending()->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
            'provider_reference' => 'tx-wrong-secret',
        ]);

        $payload = ['event' => 'api.charge.payment', 'status' => 'success', 'tx_ref' => 'tx-wrong-secret'];
        $wrongSecretSignature = hash_hmac('sha256', json_encode($payload), 'the-wrong-secret');

        postWebhook('/api/v1/webhooks/paychangu', $payload, 'test-webhook-secret', $wrongSecretSignature)
            ->assertUnauthorized();

        expect($payment->fresh()->status)->not->toBe(PaymentStatus::Completed);
    });

    it('accepts the webhook when the signature is valid', function () {
        $payment = Payment::factory()->pending()->create([
            'user_id' => $this->user->id,
            'subscription_id' => $this->subscription->id,
            'provider_reference' => 'tx-valid-signature',
        ]);

        postWebhook('/api/v1/webhooks/paychangu', [
            'event' => 'api.charge.payment',
            'status' => 'success',
            'tx_ref' => 'tx-valid-signature',
        ])->assertOk();

        expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
    });
});

describe('Activation Idempotency', function () {
    it('does not create a second subscription when a webhook replays a verified payment', function () {
        $user = User::factory()->create();
        $user->assignRole('customer');

        $payment = Payment::factory()->pending()->create([
            'user_id' => $user->id,
            'subscription_id' => null,
            'provider_reference' => 'TX-REPLAY-API',
            'metadata' => ['plan_id' => $this->plan->id, 'billing_cycle' => 'monthly'],
        ]);

        Http::fake([
            'api.paychangu.com/*' => Http::response([
                'status' => 'success',
                'data' => ['status' => 'success', 'trans_id' => 'TRANS-API'],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/payments/{$payment->id}/verify")
            ->assertSuccessful();

        expect(Subscription::where('user_id', $user->id)->count())->toBe(1);

        // PayChangu retries the webhook for the same charge.
        postWebhook('/api/v1/webhooks/paychangu', [
            'event' => 'api.charge.payment',
            'status' => 'success',
            'tx_ref' => 'TX-REPLAY-API',
            'data' => ['tx_ref' => 'TX-REPLAY-API', 'status' => 'success', 'trans_id' => 'TRANS-API'],
        ])->assertSuccessful();

        expect(Subscription::where('user_id', $user->id)->count())->toBe(1);
    });
});
