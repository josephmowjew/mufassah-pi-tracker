<?php

use App\Enums\DeviceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TheftReportStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\TheftReport;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'api']);
    Role::firstOrCreate(['name' => 'store_admin', 'guard_name' => 'api']);

    $this->branch = Branch::create(['name' => 'Dashboard Branch', 'code' => 'DSH', 'is_active' => true]);
    $this->otherBranch = Branch::create(['name' => 'Other Dashboard Branch', 'code' => 'ODB', 'is_active' => true]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    $this->storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
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
                    'sales',
                    'administrative_sales',
                    'filters',
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

    it('scopes store admin dashboard totals to their branch', function () {
        Device::factory()->create(['category_id' => $this->category->id, 'branch_id' => $this->branch->id]);
        Device::factory()->count(2)->create(['category_id' => $this->category->id, 'branch_id' => $this->otherBranch->id]);

        $this->actingAs($this->storeAdmin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertSuccessful()
            ->assertJsonPath('data.devices.total', 1);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertSuccessful()
            ->assertJsonPath('data.devices.total', 3);
    });

    it('fails closed for an unassigned store admin', function () {
        $unassigned = User::factory()->create(['branch_id' => null]);
        $unassigned->assignRole('store_admin');

        $this->actingAs($unassigned)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account must be assigned to a branch before accessing branch data.');
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

    it('returns administrative sales separately with real month-over-month changes', function () {
        $currentMonth = now()->startOfMonth()->addDay();
        $lastMonth = now()->subMonth()->startOfMonth()->addDay();

        $subscription = Subscription::factory()
            ->for($this->customer, 'user')
            ->for($this->plan, 'plan')
            ->create();

        Payment::factory()
            ->for($this->customer, 'user')
            ->for($subscription)
            ->create([
                'status' => PaymentStatus::Completed,
                'amount' => 150,
                'paid_at' => $currentMonth,
            ]);

        Payment::factory()
            ->for($this->customer, 'user')
            ->for($subscription)
            ->create([
                'status' => PaymentStatus::Completed,
                'amount' => 100,
                'paid_at' => $lastMonth,
            ]);

        InventoryMovement::create([
            'movement_number' => 'MOVE-ADMIN-CURRENT',
            'movement_type' => InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            'quantity' => 1,
            'total_amount' => 300,
            'created_at' => $currentMonth,
            'updated_at' => $currentMonth,
        ]);

        InventoryMovement::create([
            'movement_number' => 'MOVE-ADMIN-LAST',
            'movement_type' => InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            'quantity' => 1,
            'total_amount' => 200,
            'created_at' => $lastMonth,
            'updated_at' => $lastMonth,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertSuccessful()
            ->assertJsonPath('data.revenue.this_month', 150)
            ->assertJsonPath('data.revenue.last_month', 100)
            ->assertJsonPath('data.revenue.month_over_month_change_percentage', 50)
            ->assertJsonPath('data.administrative_sales.total', 500)
            ->assertJsonPath('data.administrative_sales.this_month', 300)
            ->assertJsonPath('data.administrative_sales.last_month', 200)
            ->assertJsonPath('data.administrative_sales.month_over_month_change_percentage', 50);
    });

    it('returns completed POS sales for the selected range with a branch breakdown', function () {
        $otherBranch = Branch::create([
            'name' => 'Second Sales Branch',
            'code' => 'SSB',
            'is_active' => true,
        ]);
        $from = now()->subDays(3)->toDateString();
        $to = now()->subDay()->toDateString();

        foreach ([
            ['number' => 'SALE-DASHBOARD-01', 'branch_id' => $this->branch->id, 'amount' => 125],
            ['number' => 'SALE-DASHBOARD-02', 'branch_id' => $otherBranch->id, 'amount' => 275],
        ] as $saleData) {
            Sale::create([
                'sale_number' => $saleData['number'],
                'customer_id' => $this->customer->id,
                'branch_id' => $saleData['branch_id'],
                'sold_by_id' => $this->admin->id,
                'payment_method' => PaymentMethod::Cash,
                'payment_status' => PaymentStatus::Completed,
                'status' => SaleStatus::COMPLETED,
                'sale_date' => now()->subDay(),
                'subtotal' => $saleData['amount'],
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $saleData['amount'],
            ]);
        }

        Sale::create([
            'sale_number' => 'SALE-DASHBOARD-OUTSIDE-RANGE',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'sold_by_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now()->subDays(10),
            'subtotal' => 999,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 999,
        ]);

        InventoryMovement::create([
            'movement_number' => 'MOVE-DASHBOARD-ADMIN-SALE',
            'movement_type' => InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            'branch_id' => $otherBranch->id,
            'quantity' => 1,
            'total_amount' => 80,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/dashboard?all_branches=1&date_from={$from}&date_to={$to}");

        $response->assertSuccessful()
            ->assertJsonPath('data.sales.total_amount', 400)
            ->assertJsonPath('data.sales.total_transactions', 2)
            ->assertJsonPath('data.sales.administrative_sale_amount', 80)
            ->assertJsonPath('data.sales.combined_total_amount', 480)
            ->assertJsonPath('data.sales.date_from', $from)
            ->assertJsonPath('data.sales.date_to', $to)
            ->assertJsonPath('data.filters.all_branches', true);

        $breakdown = collect($response->json('data.sales.by_branch'))->keyBy('branch_id');
        expect((float) $breakdown[$this->branch->id]['pos_total_amount'])->toBe(125.0)
            ->and((float) $breakdown[$otherBranch->id]['pos_total_amount'])->toBe(275.0)
            ->and((float) $breakdown[$otherBranch->id]['administrative_sale_amount'])->toBe(80.0);

        $daily = collect($response->json('data.sales.daily_breakdown'))->keyBy('date');
        $combinedTrend = $response->json('data.sales.combined_trend');
        expect($daily->has($to))->toBeTrue()
            ->and((float) $daily[$to]['pos_total_amount'])->toBe(400.0)
            ->and((float) $daily[$to]['administrative_sale_amount'])->toBe(80.0)
            ->and((float) $daily[$to]['combined_total_amount'])->toBe(480.0)
            ->and((float) ($combinedTrend[$to] ?? 0))->toBe(480.0);

        $dailyBranches = collect($daily[$to]['by_branch'])->keyBy('branch_id');
        expect((float) $dailyBranches[$otherBranch->id]['combined_total_amount'])->toBe(355.0)
            ->and((float) $dailyBranches[$otherBranch->id]['administrative_sale_amount'])->toBe(80.0);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/dashboard?branch_id={$this->branch->id}&date_from={$from}&date_to={$to}")
            ->assertSuccessful()
            ->assertJsonPath('data.sales.total_amount', 125)
            ->assertJsonPath('data.sales.total_transactions', 1)
            ->assertJsonPath('data.sales.by_branch.0.branch_id', $this->branch->id);
    });

    it('scopes dashboard sales to a store admin branch even when all branches is requested', function () {
        $otherBranch = Branch::create([
            'name' => 'Store Admin Other Branch',
            'code' => 'SAO',
            'is_active' => true,
        ]);

        foreach ([
            ['number' => 'SALE-STORE-DASHBOARD-01', 'branch_id' => $this->branch->id, 'amount' => 150],
            ['number' => 'SALE-STORE-DASHBOARD-02', 'branch_id' => $otherBranch->id, 'amount' => 850],
        ] as $saleData) {
            Sale::create([
                'sale_number' => $saleData['number'],
                'customer_id' => $this->customer->id,
                'branch_id' => $saleData['branch_id'],
                'sold_by_id' => $this->admin->id,
                'payment_method' => PaymentMethod::Cash,
                'payment_status' => PaymentStatus::Completed,
                'status' => SaleStatus::COMPLETED,
                'sale_date' => now(),
                'subtotal' => $saleData['amount'],
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $saleData['amount'],
            ]);
        }

        InventoryMovement::create([
            'movement_number' => 'MOVE-STORE-DASHBOARD-ADMIN-01',
            'movement_type' => InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            'branch_id' => $this->branch->id,
            'quantity' => 1,
            'total_amount' => 45,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        InventoryMovement::create([
            'movement_number' => 'MOVE-STORE-DASHBOARD-ADMIN-02',
            'movement_type' => InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            'branch_id' => $otherBranch->id,
            'quantity' => 1,
            'total_amount' => 900,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->storeAdmin)
            ->getJson('/api/v1/admin/dashboard?all_branches=1')
            ->assertSuccessful()
            ->assertJsonPath('data.sales.total_amount', 150)
            ->assertJsonPath('data.sales.total_transactions', 1)
            ->assertJsonPath('data.sales.administrative_sale_amount', 45)
            ->assertJsonPath('data.sales.combined_total_amount', 195)
            ->assertJsonCount(1, 'data.sales.by_branch');
    });

    it('rejects a dashboard date range whose end precedes its start', function () {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard?date_from=2026-08-13&date_to=2026-08-12')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
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
