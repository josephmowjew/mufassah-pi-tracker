<?php

use App\Enums\InventoryStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\Sale;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->branch = Branch::create([
        'name' => 'Test Branch',
        'code' => 'TST',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');

    $this->customer = User::factory()->create();
    $this->customer->assignRole('customer');

    $this->category = DeviceCategory::factory()->create(['name' => 'Television']);
});

function salePayload(Device $device, User $customer, array $overrides = []): array
{
    return array_replace_recursive([
        'customer_id' => $customer->id,
        'payment_method' => 'cash',
        'payment_amount' => 1500,
        'status' => 'completed',
        'items' => [[
            'category_id' => $device->category_id,
            'brand' => $device->brand,
            'model' => $device->model,
            'color' => $device->color,
            'quantity' => 1,
            'unit_price' => 1200,
            'discount' => 100,
            'tax' => 210,
        ]],
    ], $overrides);
}

describe('Admin Sales', function () {
    it('creates a completed paid sale and snapshots profit', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer));

        $response->assertCreated()
            ->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.payment_status.value', 'completed')
            ->assertJsonPath('data.payment_amount', '1500.00')
            ->assertJsonPath('data.change_due', '190.00')
            ->assertJsonPath('data.items.0.cost_price', '800.00')
            ->assertJsonPath('data.items.0.selling_price', '1200.00')
            ->assertJsonPath('data.items.0.profit_amount', '300.00');

        $device->refresh();
        expect($device->inventory_status)->toBe(InventoryStatus::SOLD);
        expect($device->owner_id)->toBe($this->customer->id);
        expect($device->sold_at)->not->toBeNull();
    });

    it('creates a draft sale that reserves stock only', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'White',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'status' => 'draft',
                'payment_amount' => null,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.payment_status.value', 'pending');

        $device->refresh();
        expect($device->inventory_status)->toBe(InventoryStatus::RESERVED);
        expect($device->owner_id)->toBeNull();
        expect($device->sold_at)->toBeNull();
    });

    it('completes a draft sale and marks reserved devices sold', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Silver',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $saleId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'status' => 'draft',
                'payment_amount' => null,
            ]))
            ->json('data.id');

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/sales/{$saleId}/complete", [
                'payment_amount' => 1500,
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.payment_status.value', 'completed');

        $device->refresh();
        expect($device->inventory_status)->toBe(InventoryStatus::SOLD);
        expect($device->owner_id)->toBe($this->customer->id);
    });

    it('requires payment amount when completing a cash draft sale', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Purple',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $saleId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'status' => 'draft',
                'payment_amount' => null,
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/sales/{$saleId}/complete")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_amount');

        expect(Sale::find($saleId)->status->value)->toBe('draft');
        expect(Sale::find($saleId)->payment_amount)->toBeNull();
        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::RESERVED);
    });

    it('rejects underpaid completed cash sales', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Blue',
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'payment_amount' => 100,
            ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('payment_amount');
        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK);
    });

    it('rejects item discounts greater than unit price', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Blue',
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'items' => [[
                    'category_id' => $device->category_id,
                    'brand' => $device->brand,
                    'model' => $device->model,
                    'color' => $device->color,
                    'quantity' => 1,
                    'unit_price' => 1200,
                    'discount' => 1201,
                    'tax' => 0,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK);
    });

    it('does not allow a store admin to sell stock from another branch', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $storeAdmin->assignRole('store_admin');

        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $otherBranch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($storeAdmin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'items' => [[
                    'device_id' => $device->id,
                    'unit_price' => 1200,
                    'discount' => 0,
                    'tax' => 0,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK);
    });

    it('scopes store admin sale listings to their branch', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $storeAdmin->assignRole('store_admin');

        Sale::create([
            'sale_number' => 'SALE-2026-00001',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'sold_by_id' => $storeAdmin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now(),
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);

        Sale::create([
            'sale_number' => 'SALE-2026-00002',
            'customer_id' => $this->customer->id,
            'branch_id' => $otherBranch->id,
            'sold_by_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now(),
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);

        $response = $this->actingAs($storeAdmin)
            ->getJson("/api/v1/admin/sales?branch_id={$otherBranch->id}");

        $response->assertSuccessful();
        expect($response->json('meta.total'))->toBe(1);
        expect($response->json('data.0.sale_number'))->toBe('SALE-2026-00001');
    });

    it('prevents store admins from viewing another branch sale directly', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $storeAdmin->assignRole('store_admin');

        $sale = Sale::create([
            'sale_number' => 'SALE-2026-00003',
            'customer_id' => $this->customer->id,
            'branch_id' => $otherBranch->id,
            'sold_by_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now(),
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);

        $this->actingAs($storeAdmin)
            ->getJson("/api/v1/admin/sales/{$sale->id}")
            ->assertForbidden();

        $this->actingAs($storeAdmin)
            ->getJson("/api/v1/admin/sales/{$sale->id}/receipt-url")
            ->assertForbidden();
    });

    it('allocates product-spec sales only from the seller branch', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $storeAdmin->assignRole('store_admin');

        Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $otherBranch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($storeAdmin)
            ->postJson('/api/v1/admin/sales', salePayload(Device::first(), $this->customer, [
                'payment_amount' => 1500,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    });

    it('rejects a second sale attempt for a device that is no longer in stock', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Gold',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $payload = salePayload($device, $this->customer);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', $payload)
            ->assertCreated();

        $saleCount = Sale::count();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        expect(Sale::count())->toBe($saleCount);
        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::SOLD);
    });

    it('does not complete a draft sale when the reserved device changed state', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Green',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $saleId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'status' => 'draft',
                'payment_amount' => null,
            ]))
            ->assertCreated()
            ->json('data.id');

        $reservedDevice = Sale::find($saleId)->items()->first()->device;
        $reservedDevice->update([
            'inventory_status' => InventoryStatus::IN_STOCK,
            'sale_id' => null,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/sales/{$saleId}/complete", [
                'payment_amount' => 1500,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        expect(Sale::find($saleId)->status->value)->toBe('draft');
        expect($reservedDevice->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK);
    });
});
