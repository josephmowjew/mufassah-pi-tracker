<?php

use App\Enums\DeviceStatus;
use App\Enums\InventoryStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
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

    $this->customer = User::factory()->create();
    $this->customer->assignRole('customer');

    $this->category = DeviceCategory::factory()->create();
    $this->plan = SubscriptionPlan::factory()->create();
});

describe('Theft Report', function () {
    it('returns theft report summary', function () {
        $device = Device::factory()
            ->for($this->category, 'category')
            ->for($this->customer, 'owner')
            ->create();

        TheftReport::factory()->count(3)
            ->for($device)
            ->for($this->customer, 'reporter')
            ->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/theft');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => ['total', 'by_status', 'recovered', 'recovery_rate'],
                    'by_month',
                    'reports',
                ],
            ]);
    });

    it('filters theft reports by date range', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/theft?from=2024-01-01&to=2024-12-31');

        $response->assertSuccessful();
    });

    it('prevents customer from accessing theft reports', function () {
        $response = $this->actingAs($this->customer)
            ->getJson('/api/v1/admin/reports/theft');

        $response->assertForbidden();
    });
});

describe('Revenue Report', function () {
    it('returns revenue report summary', function () {
        $subscription = Subscription::factory()
            ->for($this->customer, 'user')
            ->for($this->plan, 'plan')
            ->create();

        Payment::factory()->count(5)
            ->for($this->customer, 'user')
            ->for($subscription)
            ->create([
                'status' => PaymentStatus::Completed,
                'amount' => 100,
                'paid_at' => now(),
            ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/revenue');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => ['total_revenue', 'total_transactions', 'average_transaction'],
                    'by_period',
                    'by_method',
                    'by_plan',
                ],
            ]);
        expect((float) $response->json('data.summary.total_revenue'))->toBe(500.0);
    });

    it('groups revenue by different periods', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/revenue?group_by=day');

        $response->assertSuccessful();
    });

    it('filters revenue by date range', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/revenue?from=2024-01-01&to=2024-12-31');

        $response->assertSuccessful();
    });
});

describe('Device Report', function () {
    it('returns device report summary', function () {
        Device::factory()->count(5)
            ->for($this->category, 'category')
            ->for($this->customer, 'owner')
            ->create(['status' => DeviceStatus::ACTIVE]);

        Device::factory()->count(2)
            ->for($this->category, 'category')
            ->for($this->customer, 'owner')
            ->create(['status' => DeviceStatus::STOLEN]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/devices');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => ['total', 'active', 'stolen', 'recovered'],
                    'by_status',
                    'by_category',
                    'registration_trend',
                    'iot_coverage',
                ],
            ])
            ->assertJsonPath('data.summary.total', 7)
            ->assertJsonPath('data.summary.active', 5)
            ->assertJsonPath('data.summary.stolen', 2);
    });

    it('scopes store admin device reports to their branch', function () {
        $branch = Branch::create(['name' => 'Report Branch', 'code' => 'RPB', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Other Report Branch', 'code' => 'ORB', 'is_active' => true]);
        $storeAdmin = User::factory()->create(['branch_id' => $branch->id]);
        $storeAdmin->assignRole('store_admin');

        Device::factory()->create(['branch_id' => $branch->id, 'category_id' => $this->category->id]);
        Device::factory()->count(2)->create(['branch_id' => $otherBranch->id, 'category_id' => $this->category->id]);

        $this->actingAs($storeAdmin)
            ->getJson('/api/v1/admin/reports/devices')
            ->assertSuccessful()
            ->assertJsonPath('data.summary.total', 1);
    });
});

describe('Stock Report', function () {
    it('returns stock report by range and branch', function () {
        $branch = Branch::create(['name' => 'Lilongwe', 'code' => 'LLW', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Blantyre', 'code' => 'BT', 'is_active' => true]);

        $batch = InventoryBatch::create([
            'batch_number' => 'BATCH-2026-00001',
            'branch_id' => $branch->id,
            'category_id' => $this->category->id,
            'brand' => 'Samsung',
            'model' => 'Crystal UHD 55"',
            'color' => 'Black',
            'quantity_received' => 3,
            'buying_price' => 100000,
            'target_selling_price' => 150000,
            'purchase_date' => '2026-07-01',
        ]);

        Device::factory()->count(2)->create([
            'branch_id' => $branch->id,
            'category_id' => $this->category->id,
            'inventory_batch_id' => $batch->id,
            'brand' => 'Samsung',
            'model' => 'Crystal UHD 55"',
            'color' => 'Black',
            'inventory_status' => InventoryStatus::IN_STOCK,
            'purchase_price' => 100000,
        ]);

        Device::factory()->create([
            'branch_id' => $branch->id,
            'category_id' => $this->category->id,
            'inventory_batch_id' => $batch->id,
            'brand' => 'Samsung',
            'model' => 'Crystal UHD 55"',
            'color' => 'Black',
            'inventory_status' => InventoryStatus::SOLD,
            'sold_at' => '2026-07-03 10:00:00',
            'purchase_price' => 100000,
        ]);

        Device::factory()->count(4)->create([
            'branch_id' => $otherBranch->id,
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/reports/stock?from=2026-07-01&to=2026-07-31&branch_id={$branch->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.filters.branch_id', $branch->id)
            ->assertJsonPath('data.summary.current_total', 3)
            ->assertJsonPath('data.summary.current_in_stock', 2)
            ->assertJsonPath('data.summary.current_sold', 1)
            ->assertJsonPath('data.summary.received_in_range', 3)
            ->assertJsonPath('data.summary.sold_in_range', 1)
            ->assertJsonPath('data.by_branch.0.branch_name', 'Lilongwe')
            ->assertJsonPath('data.by_branch.0.received_in_range', 3)
            ->assertJsonPath('data.by_branch.0.sold_in_range', 1)
            ->assertJsonPath('data.by_product.0.current_total', 3)
            ->assertJsonPath('data.by_batch.0.batch_number', 'BATCH-2026-00001');
    });

    it('scopes store admin stock reports to their branch', function () {
        $branch = Branch::create(['name' => 'Mzuzu', 'code' => 'MZ', 'is_active' => true]);
        $otherBranch = Branch::create(['name' => 'Zomba', 'code' => 'ZA', 'is_active' => true]);

        $storeAdmin = User::factory()->create(['branch_id' => $branch->id]);
        $storeAdmin->assignRole('store_admin');

        Device::factory()->count(2)->create([
            'branch_id' => $branch->id,
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        Device::factory()->count(5)->create([
            'branch_id' => $otherBranch->id,
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($storeAdmin)
            ->getJson("/api/v1/admin/reports/stock?branch_id={$otherBranch->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.filters.branch_id', $branch->id)
            ->assertJsonPath('data.summary.current_total', 2)
            ->assertJsonPath('data.by_branch.0.branch_name', 'Mzuzu');
    });
});

describe('Subscription Report', function () {
    it('returns subscription report summary', function () {
        Subscription::factory()->count(3)
            ->for($this->customer, 'user')
            ->for($this->plan, 'plan')
            ->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/subscriptions');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => ['total', 'active', 'churn_rate'],
                    'by_status',
                    'by_plan',
                    'trend',
                ],
            ]);
    });
});

describe('User Report', function () {
    it('returns user report summary', function () {
        User::factory()->count(5)->create()->each(fn ($u) => $u->assignRole('customer'));

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/users');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => ['total', 'verified', 'with_active_subscription', 'with_devices'],
                    'by_role',
                    'registration_trend',
                ],
            ]);
    });

    it('filters users by date range', function () {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/users?from=2024-01-01&to=2024-12-31');

        $response->assertSuccessful();
    });
});
