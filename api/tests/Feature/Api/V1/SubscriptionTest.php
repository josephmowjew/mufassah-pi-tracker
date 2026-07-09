<?php

use App\Enums\SubscriptionStatus;
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
});

describe('Subscription Plan Listing', function () {
    it('returns active plans for authenticated user', function () {
        SubscriptionPlan::factory()->create(['is_active' => true]);
        SubscriptionPlan::factory()->inactive()->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/subscription-plans');

        $response->assertSuccessful();
        expect($response->json('data'))->toHaveCount(2);
    });

    it('returns all plans including inactive for admin', function () {
        SubscriptionPlan::factory()->inactive()->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/subscription-plans');

        $response->assertSuccessful();
        expect($response->json('data'))->toHaveCount(2);
    });

    it('returns plan details with pricing', function () {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/subscription-plans/{$this->plan->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.name', 'Basic')
            ->assertJsonPath('data.pricing.currency', 'MWK');
    });
});

describe('Current Subscription', function () {
    it('returns current subscription for user', function () {
        $subscription = Subscription::factory()
            ->active()
            ->for($this->user)
            ->for($this->plan, 'plan')
            ->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/subscriptions/current');

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $subscription->id)
            ->assertJsonPath('data.is_active', true);
    });

    it('returns null data when user has no subscription', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/subscriptions/current');

        $response->assertSuccessful()
            ->assertJsonPath('data', null)
            ->assertJsonPath('success', true);
    });
});

describe('Subscribe to Plan', function () {
    it('initiates payment for subscription', function () {
        Http::fake([
            'api.paychangu.com/*' => Http::response([
                'status' => 'success',
                'data' => [
                    'checkout_url' => 'https://checkout.paychangu.com/123',
                    'tx_ref' => 'TX-123',
                ],
            ]),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/subscriptions/subscribe', [
                'plan_id' => $this->plan->id,
                'billing_cycle' => 'monthly',
                'payment_method' => 'mobile_money',
                'phone_number' => '0991234567',
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.checkout_url', 'https://checkout.paychangu.com/123');

        expect(Payment::where('user_id', $this->user->id)->exists())->toBeTrue();
    });

    it('prevents subscribing when already have active subscription', function () {
        Subscription::factory()
            ->active()
            ->for($this->user)
            ->for($this->plan, 'plan')
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/subscriptions/subscribe', [
                'plan_id' => $this->plan->id,
                'billing_cycle' => 'monthly',
                'payment_method' => 'mobile_money',
                'phone_number' => '0991234567',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'You already have an active subscription. Please upgrade or cancel your current subscription first.');
    });

    it('validates required fields', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/subscriptions/subscribe', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['plan_id', 'billing_cycle', 'payment_method']);
    });

    it('validates phone number for mobile money', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/subscriptions/subscribe', [
                'plan_id' => $this->plan->id,
                'billing_cycle' => 'monthly',
                'payment_method' => 'mobile_money',
                'phone_number' => 'invalid',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number']);
    });
});

describe('Cancel Subscription', function () {
    it('allows user to cancel their subscription', function () {
        $subscription = Subscription::factory()
            ->active()
            ->for($this->user)
            ->for($this->plan, 'plan')
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'reason' => 'Too expensive',
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.auto_renew', false);

        expect($subscription->fresh()->cancellation_reason)->toBe('Too expensive');
    });

    it('allows immediate cancellation', function () {
        $subscription = Subscription::factory()
            ->active()
            ->for($this->user)
            ->for($this->plan, 'plan')
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'reason' => 'Switching provider',
                'immediate' => true,
            ]);

        $response->assertSuccessful();
        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
    });

    it('requires cancellation reason', function () {
        $subscription = Subscription::factory()
            ->active()
            ->for($this->user)
            ->for($this->plan, 'plan')
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    });

    it('prevents cancelling already cancelled subscription', function () {
        $subscription = Subscription::factory()
            ->cancelled()
            ->for($this->user)
            ->for($this->plan, 'plan')
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [
                'reason' => 'Double cancel',
            ]);

        $response->assertStatus(400);
    });
});

describe('Toggle Auto Renew', function () {
    it('toggles auto renew setting', function () {
        $subscription = Subscription::factory()
            ->active()
            ->for($this->user)
            ->for($this->plan, 'plan')
            ->create(['auto_renew' => true]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/toggle-auto-renew");

        $response->assertSuccessful()
            ->assertJsonPath('data.auto_renew', false);
    });
});

describe('Admin Subscription Management', function () {
    it('allows admin to list all subscriptions', function () {
        Subscription::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/subscriptions');

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 3);
    });

    it('allows admin to suspend subscription', function () {
        $subscription = Subscription::factory()
            ->active()
            ->for($this->user)
            ->for($this->plan, 'plan')
            ->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/subscriptions/{$subscription->id}/suspend");

        $response->assertSuccessful();
        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Suspended);
    });

    it('allows admin to reactivate suspended subscription', function () {
        $subscription = Subscription::factory()
            ->suspended()
            ->for($this->user)
            ->for($this->plan, 'plan')
            ->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/subscriptions/{$subscription->id}/reactivate");

        $response->assertSuccessful();
        expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    });

    it('prevents non-admin from accessing admin routes', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/admin/subscriptions');

        $response->assertForbidden();
    });
});
