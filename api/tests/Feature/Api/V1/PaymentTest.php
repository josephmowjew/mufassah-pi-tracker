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

        $response = $this->postJson('/api/v1/payments/webhook', [
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

        $response = $this->postJson('/api/v1/payments/webhook', [
            'event' => 'api.charge.payment',
            'status' => 'failed',
            'tx_ref' => 'TX-FAILED-123',
            'message' => 'Insufficient funds',
        ]);

        $response->assertSuccessful();
        expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
    });

    it('returns 404 for unknown transaction', function () {
        $response = $this->postJson('/api/v1/payments/webhook', [
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
