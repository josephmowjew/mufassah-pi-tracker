<?php

use App\Enums\DeviceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TheftReportStatus;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TheftReport;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    $this->storeAdmin = User::factory()->create();
    $this->storeAdmin->assignRole('store_admin');

    $this->customer = User::factory()->create();
    $this->customer->assignRole('customer');

    $this->category = DeviceCategory::factory()->create();
    $this->plan = SubscriptionPlan::factory()->create();
});

describe('Dashboard Access Control', function () {
    it('allows super admin to access dashboard', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'users',
                    'devices',
                    'subscriptions',
                    'revenue',
                    'theft_reports',
                    'iot_modules',
                ],
            ]);
    });

    it('allows store admin to access dashboard', function () {
        $response = $this->actingAs($this->storeAdmin)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertSuccessful();
    });

    it('prevents customer from accessing dashboard', function () {
        $response = $this->actingAs($this->customer)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertForbidden();
    });

    it('prevents unauthenticated access to dashboard', function () {
        $response = $this->getJson('/api/v1/admin/dashboard');

        $response->assertUnauthorized();
    });
});

describe('Dashboard Statistics', function () {
    it('returns correct user statistics', function () {
        User::factory()->count(5)->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertSuccessful()
            ->assertJsonPath('data.users.total', User::count());
    });

    it('returns correct device statistics', function () {
        Device::factory()->count(3)
            ->for($this->category, 'category')
            ->for($this->customer, 'owner')
            ->create(['status' => DeviceStatus::ACTIVE]);

        Device::factory()
            ->for($this->category, 'category')
            ->for($this->customer, 'owner')
            ->create(['status' => DeviceStatus::STOLEN]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertSuccessful()
            ->assertJsonPath('data.devices.total', 4)
            ->assertJsonPath('data.devices.active', 3)
            ->assertJsonPath('data.devices.stolen', 1);
    });

    it('returns correct subscription statistics', function () {
        Subscription::factory()->count(2)
            ->for($this->customer, 'user')
            ->for($this->plan, 'plan')
            ->create(['status' => SubscriptionStatus::Active]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertSuccessful()
            ->assertJsonPath('data.subscriptions.active', 2);
    });

    it('returns correct revenue statistics', function () {
        $subscription = Subscription::factory()
            ->for($this->customer, 'user')
            ->for($this->plan, 'plan')
            ->create();

        Payment::factory()->count(3)
            ->for($this->customer, 'user')
            ->for($subscription)
            ->create([
                'status' => PaymentStatus::Completed,
                'amount' => 100,
                'paid_at' => now(),
            ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertSuccessful();
        expect((float) $response->json('data.revenue.total'))->toBe(300.0);
    });

    it('returns correct theft report statistics', function () {
        $device = Device::factory()
            ->for($this->category, 'category')
            ->for($this->customer, 'owner')
            ->create();

        TheftReport::factory()->count(2)
            ->for($device)
            ->for($this->customer, 'reporter')
            ->create(['status' => TheftReportStatus::Reported]);

        TheftReport::factory()
            ->for($device)
            ->for($this->customer, 'reporter')
            ->create(['status' => TheftReportStatus::Recovered]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertSuccessful()
            ->assertJsonPath('data.theft_reports.total', 3)
            ->assertJsonPath('data.theft_reports.active', 2)
            ->assertJsonPath('data.theft_reports.recovered', 1);
    });
});

describe('Dashboard Users Endpoint', function () {
    it('returns user statistics with role breakdown', function () {
        User::factory()->count(3)->create()->each(fn ($u) => $u->assignRole('customer'));

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard/users');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total',
                    'by_role',
                    'with_subscriptions',
                    'with_devices',
                    'verified',
                    'growth',
                ],
            ]);
    });
});

describe('Dashboard Devices Endpoint', function () {
    it('returns device statistics with category breakdown', function () {
        Device::factory()->count(3)
            ->for($this->category, 'category')
            ->for($this->customer, 'owner')
            ->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard/devices');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total',
                    'by_status',
                    'by_category',
                    'with_iot_module',
                    'recently_active',
                    'categories',
                ],
            ]);
    });
});

describe('Dashboard Revenue Endpoint', function () {
    it('returns revenue statistics', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard/revenue');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_revenue',
                    'total_transactions',
                    'by_status',
                    'by_method',
                    'by_plan',
                    'trend',
                ],
            ]);
    });
});

describe('Dashboard Theft Reports Endpoint', function () {
    it('returns theft report statistics', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard/theft-reports');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total',
                    'by_status',
                    'recovery_rate',
                    'average_resolution_days',
                    'recent_active',
                    'this_month',
                ],
            ]);
    });
});

describe('Dashboard Subscriptions Endpoint', function () {
    it('returns subscription statistics', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard/subscriptions');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total',
                    'active',
                    'by_status',
                    'by_plan',
                    'auto_renew_enabled',
                    'expiring_soon',
                    'plans',
                ],
            ]);
    });
});

describe('Dashboard Support Endpoint', function () {
    it('returns support statistics', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard/support');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_claims',
                    'by_status',
                    'pending_count',
                    'recent_pending',
                    'this_month',
                ],
            ]);
    });
});
