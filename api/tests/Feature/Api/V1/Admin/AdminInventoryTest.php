<?php

use App\Enums\InventoryStatus;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use App\Models\InventoryReceipt;
use App\Models\ProductModel;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    $this->storeAdmin = User::factory()->create();
    $this->storeAdmin->assignRole('store_admin');

    $this->customerService = User::factory()->create();
    $this->customerService->assignRole('customer_service');

    $this->customer = User::factory()->create();
    $this->customer->assignRole('customer');

    $this->category = DeviceCategory::factory()->create(['name' => 'Smartphones']);
    $this->branch = Branch::create([
        'name' => 'Test Branch',
        'code' => 'TST',
        'is_active' => true,
    ]);
});

describe('Inventory Index', function () {
    it('does not allow customer service into broad admin inventory routes', function () {
        $this->actingAs($this->customerService)
            ->getJson('/api/v1/admin/inventory')
            ->assertForbidden();
    });

    it('lists inventory items', function () {
        Device::factory()->count(5)->create([
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/inventory');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'serial_number', 'brand', 'model', 'inventory_status'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    });

    it('filters by inventory status', function () {
        Device::factory()->count(3)->create([
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);
        Device::factory()->count(2)->create([
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::SOLD,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/inventory?inventory_status=in_stock');

        $response->assertSuccessful();
        expect($response->json('meta.total'))->toBe(3);
    });

    it('scopes store admin inventory listings to their branch', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $this->storeAdmin->update(['branch_id' => $this->branch->id]);

        Device::factory()->count(2)->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        Device::factory()->count(5)->create([
            'branch_id' => $otherBranch->id,
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->storeAdmin)
            ->getJson("/api/v1/admin/inventory?branch_id={$otherBranch->id}");

        $response->assertSuccessful();
        expect($response->json('meta.total'))->toBe(2);
    });

    it('lets super admins choose a branch and explicitly view all inventory branches', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        Device::factory()->count(2)->create([
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);
        Device::factory()->count(3)->create([
            'branch_id' => $otherBranch->id,
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/inventory?branch_id={$otherBranch->id}")
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/inventory/stats?branch_id='.$otherBranch->id)
            ->assertSuccessful()
            ->assertJsonPath('data.summary.total', 3);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/inventory?all_branches=1')
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 5);
    });

    it('searches by serial number', function () {
        Device::factory()->create([
            'category_id' => $this->category->id,
            'serial_number' => 'UNIQUE-SERIAL-12345',
        ]);
        Device::factory()->create([
            'category_id' => $this->category->id,
            'serial_number' => 'OTHER-SERIAL-99999',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/inventory?search=UNIQUE-SERIAL');

        $response->assertSuccessful();
        expect($response->json('meta.total'))->toBe(1);
    });
});

describe('Product Options', function () {
    it('uses the in-stock batch selling price ahead of the catalog default price', function () {
        $this->storeAdmin->update(['branch_id' => $this->branch->id]);

        $brand = Brand::create([
            'category_id' => $this->category->id,
            'name' => 'Apple',
            'is_active' => true,
        ]);
        $productModel = ProductModel::create([
            'brand_id' => $brand->id,
            'name' => 'iPhone 15',
            'base_price' => 750000,
            'is_active' => true,
        ]);
        $batch = InventoryBatch::create([
            'batch_number' => 'BATCH-2026-00000',
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15',
            'quantity_received' => 1,
            'buying_price' => 400000,
            'target_selling_price' => 500000,
            'purchase_date' => now(),
        ]);
        Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'inventory_batch_id' => $batch->id,
            'brand_id' => $brand->id,
            'product_model_id' => $productModel->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15',
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($this->storeAdmin)
            ->getJson("/api/v1/admin/inventory/product-options?category_id={$this->category->id}&brand=Apple&model=iPhone%2015")
            ->assertSuccessful()
            ->assertJsonPath('data.selling_price', 500000)
            ->assertJsonPath('data.price_tiers.0.selling_price', '500000.00');
    });

    it('uses device purchase cost when no batch selling price is available', function () {
        $this->storeAdmin->update(['branch_id' => $this->branch->id]);

        Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'inventory_batch_id' => null,
            'brand' => 'Legacy',
            'model' => 'Unbatched Device',
            'purchase_price' => 400000,
            'inventory_status' => InventoryStatus::IN_STOCK,
            'owner_id' => null,
        ]);

        $this->actingAs($this->storeAdmin)
            ->getJson("/api/v1/admin/inventory/product-options?category_id={$this->category->id}&brand=Legacy&model=Unbatched%20Device")
            ->assertSuccessful()
            ->assertJsonPath('data.selling_price', 400000)
            ->assertJsonPath('data.pricing_source', 'purchase_cost')
            ->assertJsonPath('data.price_tiers.0.selling_price', '400000.00')
            ->assertJsonPath('data.price_tiers.0.pricing_source', 'purchase_cost');
    });

    it('uses branch-scoped FIFO batch target price', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $this->storeAdmin->update(['branch_id' => $this->branch->id]);

        $storeBatch = InventoryBatch::create([
            'batch_number' => 'BATCH-2026-00001',
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15',
            'quantity_received' => 1,
            'buying_price' => 400000,
            'target_selling_price' => 500000,
            'purchase_date' => now()->subDays(2),
        ]);

        $otherBatch = InventoryBatch::create([
            'batch_number' => 'BATCH-2026-00002',
            'branch_id' => $otherBranch->id,
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15',
            'quantity_received' => 1,
            'buying_price' => 400000,
            'target_selling_price' => 900000,
            'purchase_date' => now()->subDays(5),
        ]);

        Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'inventory_batch_id' => $storeBatch->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15',
            'inventory_status' => InventoryStatus::IN_STOCK,
            'purchase_date' => now()->subDays(2),
        ]);

        Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $otherBranch->id,
            'inventory_batch_id' => $otherBatch->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15',
            'inventory_status' => InventoryStatus::IN_STOCK,
            'purchase_date' => now()->subDays(5),
        ]);

        $this->actingAs($this->storeAdmin)
            ->getJson("/api/v1/admin/inventory/product-options?category_id={$this->category->id}&brand=Apple&model=iPhone%2015")
            ->assertSuccessful()
            ->assertJsonPath('data.available_count', 1)
            ->assertJsonPath('data.selling_price', 500000);
    });
});

describe('Inventory Branch Access', function () {
    it('prevents store admins from viewing another branch batch directly', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $this->storeAdmin->update(['branch_id' => $this->branch->id]);

        $batch = InventoryBatch::create([
            'batch_number' => 'BATCH-2026-00999',
            'branch_id' => $otherBranch->id,
            'category_id' => $this->category->id,
            'brand' => 'Samsung',
            'model' => 'Galaxy S24',
            'quantity_received' => 1,
            'buying_price' => 500000,
            'target_selling_price' => 650000,
            'purchase_date' => now(),
        ]);

        $this->actingAs($this->storeAdmin)
            ->getJson("/api/v1/admin/inventory/batches/{$batch->id}")
            ->assertForbidden();
    });
});

describe('Inventory Stats', function () {
    it('returns inventory statistics', function () {
        Device::factory()->count(5)->create([
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);
        Device::factory()->count(3)->create([
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::SOLD,
        ]);
        Device::factory()->count(2)->create([
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::RESERVED,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/inventory/stats');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => ['total', 'in_stock', 'reserved', 'sold', 'returned', 'faulty', 'available'],
                    'by_category',
                ],
            ]);

        expect($response->json('data.summary.total'))->toBe(10);
        expect($response->json('data.summary.in_stock'))->toBe(5);
        expect($response->json('data.summary.sold'))->toBe(3);
        expect($response->json('data.summary.reserved'))->toBe(2);
    });
});

describe('Grouped Inventory', function () {
    it('returns grouped inventory by category, brand, model, and color', function () {
        // Create devices of same type
        Device::factory()->count(3)->create([
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15 Pro',
            'color' => 'Natural Titanium',
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);
        Device::factory()->count(2)->create([
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15 Pro',
            'color' => 'Natural Titanium',
            'inventory_status' => InventoryStatus::SOLD,
        ]);

        // Create different product
        Device::factory()->count(4)->create([
            'category_id' => $this->category->id,
            'brand' => 'Samsung',
            'model' => 'Galaxy S24',
            'color' => 'Black',
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/inventory/grouped');

        $response->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'category_id',
                            'category_name',
                            'brand',
                            'model',
                            'color',
                            'in_stock',
                            'reserved',
                            'sold',
                            'returned',
                            'faulty',
                            'total',
                            'avg_price',
                        ],
                    ],
                    'total',
                ],
            ]);

        // Should have 2 grouped products
        expect($response->json('data.total'))->toBe(2);
    });

    it('filters grouped inventory by search', function () {
        Device::factory()->count(3)->create([
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15 Pro',
            'color' => 'Space Gray',
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);
        Device::factory()->count(2)->create([
            'category_id' => $this->category->id,
            'brand' => 'Samsung',
            'model' => 'Galaxy S24',
            'color' => 'Black',
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/inventory/grouped?search=Apple');

        $response->assertSuccessful();
        expect($response->json('data.total'))->toBe(1);
        expect($response->json('data.data.0.brand'))->toBe('Apple');
    });

    it('filters grouped inventory by status', function () {
        Device::factory()->count(3)->create([
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15',
            'color' => 'Black',
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);
        Device::factory()->count(2)->create([
            'category_id' => $this->category->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15',
            'color' => 'Black',
            'inventory_status' => InventoryStatus::SOLD,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/inventory/grouped?inventory_status=in_stock');

        $response->assertSuccessful();
        // Only in_stock devices should be in the group
        expect($response->json('data.data.0.in_stock'))->toBe(3);
        expect($response->json('data.data.0.sold'))->toBe(0);
    });
});

describe('Bulk Store Devices', function () {
    it('creates multiple devices with auto-generated serial numbers', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15 Pro',
                'color' => 'Natural Titanium',
                'quantity' => 5,
                'buying_price' => 700000,
                'target_selling_price' => 900000,
                'auto_generate_serials' => true,
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'devices' => [
                        '*' => ['id', 'serial_number', 'brand', 'model'],
                    ],
                    'batch',
                    'count',
                ],
                'message',
            ]);

        expect($response->json('data.count'))->toBe(5);
        expect(Device::where('brand', 'Apple')->where('model', 'iPhone 15 Pro')->count())->toBe(5);
        expect(InventoryBatch::where('batch_number', $response->json('data.batch.batch_number'))->exists())->toBeTrue();
    });

    it('prevents store admins from receiving inventory into another branch', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $this->storeAdmin->update(['branch_id' => $this->branch->id]);

        $this->actingAs($this->storeAdmin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $otherBranch->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15 Pro',
                'quantity' => 1,
                'buying_price' => 700000,
                'target_selling_price' => 900000,
                'auto_generate_serials' => true,
            ])
            ->assertForbidden();

        expect(Device::where('branch_id', $otherBranch->id)->count())->toBe(0);
    });

    it('creates devices with manually provided serial numbers', function () {
        $serials = ['SN-001', 'SN-002', 'SN-003'];

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Samsung',
                'model' => 'Galaxy S24',
                'color' => 'Black',
                'quantity' => 3,
                'buying_price' => 500000,
                'target_selling_price' => 650000,
                'auto_generate_serials' => false,
                'serial_numbers' => $serials,
            ]);

        $response->assertCreated();
        expect($response->json('data.count'))->toBe(3);

        // Verify each serial number was used
        foreach ($serials as $serial) {
            expect(Device::where('serial_number', $serial)->exists())->toBeTrue();
        }
    });

    it('automatically creates and links missing catalog entries', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Samsung',
                'model' => 'Crystal UHD 55"',
                'quantity' => 2,
                'buying_price' => 500000,
                'target_selling_price' => 650000,
            ]);

        $response->assertCreated();

        $brand = Brand::query()
            ->where('category_id', $this->category->id)
            ->where('name', 'Samsung')
            ->first();
        $model = ProductModel::query()
            ->where('brand_id', $brand?->id)
            ->where('name', 'Crystal UHD 55"')
            ->first();

        expect($brand)->not->toBeNull()
            ->and($model)->not->toBeNull();

        $devices = Device::query()
            ->where('brand', 'Samsung')
            ->where('model', 'Crystal UHD 55"')
            ->get();

        expect($devices)->toHaveCount(2);
        foreach ($devices as $device) {
            expect($device->brand_id)->toBe($brand->id)
                ->and($device->product_model_id)->toBe($model->id);
        }

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/catalog/models?brand_id={$brand->id}&with_devices_count=true")
            ->assertSuccessful()
            ->assertJsonPath('data.0.devices_count', 2);
    });

    it('reuses catalog entries when inventory names differ only by whitespace or case', function () {
        $brand = Brand::create([
            'category_id' => $this->category->id,
            'name' => 'Samsung',
            'is_active' => true,
        ]);
        $model = ProductModel::create([
            'brand_id' => $brand->id,
            'name' => 'Galaxy S24',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => ' samsung ',
                'model' => '  Galaxy   S24 ',
                'quantity' => 1,
                'buying_price' => 500000,
                'target_selling_price' => 650000,
            ]);

        $response->assertCreated();

        expect(Brand::query()->where('category_id', $this->category->id)->get())
            ->toHaveCount(1);
        expect(ProductModel::query()->where('brand_id', $brand->id)->get())
            ->toHaveCount(1);

        $device = Device::query()->where('serial_number', $response->json('data.devices.0.serial_number'))->firstOrFail();
        expect($device->brand)->toBe('Samsung')
            ->and($device->model)->toBe('Galaxy S24')
            ->and($device->brand_id)->toBe($brand->id)
            ->and($device->product_model_id)->toBe($model->id);
    });

    it('validates required fields', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'branch_id', 'brand', 'model', 'quantity', 'buying_price', 'target_selling_price']);
    });

    it('validates quantity limits', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15',
                'quantity' => 150, // Exceeds max of 100
                'buying_price' => 400000,
                'target_selling_price' => 500000,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    });

    it('validates serial numbers count matches quantity', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15',
                'quantity' => 3,
                'buying_price' => 400000,
                'target_selling_price' => 500000,
                'auto_generate_serials' => false,
                'serial_numbers' => ['SN-001', 'SN-002'], // Only 2 but quantity is 3
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_numbers']);
    });

    it('validates unique serial numbers', function () {
        Device::factory()->create(['serial_number' => 'EXISTING-SERIAL']);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15',
                'quantity' => 1,
                'buying_price' => 400000,
                'target_selling_price' => 500000,
                'auto_generate_serials' => false,
                'serial_numbers' => ['EXISTING-SERIAL'],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_numbers.0']);
    });

    it('validates distinct serial numbers within batch', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15',
                'quantity' => 2,
                'buying_price' => 400000,
                'target_selling_price' => 500000,
                'auto_generate_serials' => false,
                'serial_numbers' => ['SAME-SERIAL', 'SAME-SERIAL'],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['serial_numbers.0', 'serial_numbers.1']);
    });

    it('sets all devices to in_stock status', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15',
                'quantity' => 3,
                'buying_price' => 400000,
                'target_selling_price' => 500000,
            ]);

        $response->assertCreated();

        $devices = Device::where('brand', 'Apple')->where('model', 'iPhone 15')->get();
        expect($devices)->toHaveCount(3);
        foreach ($devices as $device) {
            expect($device->inventory_status->value)->toBe('in_stock');
        }
    });

    it('prevents customer from bulk adding devices', function () {
        $response = $this->actingAs($this->customer)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15',
                'quantity' => 3,
                'buying_price' => 400000,
                'target_selling_price' => 500000,
            ]);

        $response->assertForbidden();
    });
});

describe('Inventory Receipts', function () {
    it('receives multiple product lines into one receipt with separate batches', function () {
        $laptopCategory = DeviceCategory::factory()->create(['name' => 'Laptops']);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/receipts', [
                'branch_id' => $this->branch->id,
                'supplier' => 'Dubai Electronics Market',
                'reference_number' => 'INV-1001',
                'purchase_date' => now()->toDateString(),
                'purchase_location' => 'Dubai',
                'notes' => 'Mixed shipment',
                'lines' => [
                    [
                        'category_id' => $this->category->id,
                        'brand' => 'Samsung',
                        'model' => 'Galaxy S24',
                        'color' => 'Black',
                        'quantity' => 3,
                        'buying_price' => 500000,
                        'target_selling_price' => 650000,
                        'auto_generate_serials' => true,
                    ],
                    [
                        'category_id' => $laptopCategory->id,
                        'brand' => 'Dell',
                        'model' => 'XPS 13',
                        'color' => 'Silver',
                        'quantity' => 2,
                        'buying_price' => 1200000,
                        'target_selling_price' => 1500000,
                        'auto_generate_serials' => false,
                        'serial_numbers' => ['DELL-XPS-001', 'DELL-XPS-002'],
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.count', 5)
            ->assertJsonPath('data.receipt.supplier', 'Dubai Electronics Market')
            ->assertJsonPath('data.receipt.summary.lines', 2)
            ->assertJsonPath('data.receipt.summary.units', 5)
            ->assertJsonPath('data.receipt.summary.total_cost', 3900000)
            ->assertJsonPath('data.receipt.summary.expected_revenue', 4950000)
            ->assertJsonPath('data.receipt.summary.expected_gross_profit', 1050000);

        $receipt = InventoryReceipt::where('reference_number', 'INV-1001')->first();

        expect($receipt)->not->toBeNull();
        expect($receipt->batches()->count())->toBe(2);
        expect(InventoryBatch::where('inventory_receipt_id', $receipt->id)->count())->toBe(2);
        expect(Device::where('inventory_batch_id', $receipt->batches()->where('brand', 'Samsung')->first()->id)->count())->toBe(3);
        expect(Device::whereIn('serial_number', ['DELL-XPS-001', 'DELL-XPS-002'])->count())->toBe(2);
    });

    it('validates manual serial counts per receipt line', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/receipts', [
                'branch_id' => $this->branch->id,
                'lines' => [
                    [
                        'category_id' => $this->category->id,
                        'brand' => 'Samsung',
                        'model' => 'Galaxy S24',
                        'quantity' => 2,
                        'buying_price' => 500000,
                        'target_selling_price' => 650000,
                        'auto_generate_serials' => false,
                        'serial_numbers' => ['ONE-SERIAL'],
                    ],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.serial_numbers']);
    });

    it('lists receipt summaries and their batches', function () {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/receipts', [
                'branch_id' => $this->branch->id,
                'supplier' => 'Local Supplier',
                'lines' => [
                    [
                        'category_id' => $this->category->id,
                        'brand' => 'Apple',
                        'model' => 'iPhone 15',
                        'quantity' => 1,
                        'buying_price' => 800000,
                        'target_selling_price' => 950000,
                    ],
                ],
            ])
            ->assertCreated();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/inventory/receipts');

        $response->assertSuccessful()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.supplier', 'Local Supplier')
            ->assertJsonPath('data.0.summary.lines', 1)
            ->assertJsonPath('data.0.summary.units', 1);
    });
});

describe('Single Device Add', function () {
    it('adds a single device to inventory', function () {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory', [
                'serial_number' => 'UNIQUE-SN-123',
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Apple',
                'model' => 'iPhone 15',
                'color' => 'Black',
                'purchase_price' => 999.99,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.serial_number', 'UNIQUE-SN-123')
            ->assertJsonPath('data.brand', 'Apple')
            ->assertJsonPath('data.model', 'iPhone 15');

        expect(Device::where('serial_number', 'UNIQUE-SN-123')->exists())->toBeTrue();
    });
});

describe('Status Update', function () {
    it('updates device inventory status', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/inventory/{$device->id}/status", [
                'inventory_status' => 'reserved',
                'notes' => 'Reserved for VIP customer',
            ]);

        $response->assertSuccessful();
        expect($device->fresh()->inventory_status->value)->toBe('reserved');
        expect($device->fresh()->notes)->toBe('Reserved for VIP customer');
    });

    it('prevents changing status of sold device', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'inventory_status' => InventoryStatus::SOLD,
        ]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/inventory/{$device->id}/status", [
                'inventory_status' => 'in_stock',
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Cannot change status of sold device. Process a refund instead.');
    });
});
