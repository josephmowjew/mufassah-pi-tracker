<?php

use App\Enums\InventoryStatus;
use App\Models\Brand;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\ProductModel;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
});

it('counts inventory by the brand and model identity used by inventory records', function () {
    $category = DeviceCategory::factory()->create();
    $brand = Brand::create(['category_id' => $category->id, 'name' => 'Anker', 'is_active' => true]);
    $model = ProductModel::create(['brand_id' => $brand->id, 'name' => 'PowerCore 26800', 'is_active' => true]);

    Device::factory()->count(3)->create([
        'category_id' => $category->id,
        'brand' => 'Anker',
        'model' => 'PowerCore 26800',
        'brand_id' => null,
        'product_model_id' => null,
        'inventory_status' => InventoryStatus::IN_STOCK,
    ]);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/catalog/brands?with_devices_count=true')
        ->assertOk()
        ->assertJsonPath('data.0.devices_count', 3);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/catalog/models?brand_id={$brand->id}&with_devices_count=true")
        ->assertOk()
        ->assertJsonPath('data.0.id', $model->id)
        ->assertJsonPath('data.0.devices_count', 3);
});

it('backfills catalog links for existing inventory records idempotently', function () {
    $category = DeviceCategory::factory()->create(['name' => 'Televisions']);
    $device = Device::factory()->create([
        'category_id' => $category->id,
        'brand' => 'Samsung',
        'model' => 'Crystal UHD 55"',
        'brand_id' => null,
        'product_model_id' => null,
    ]);

    $migration = require database_path('migrations/2026_08_20_000001_sync_inventory_catalog_links.php');
    $migration->up();
    $migration->up();

    $device->refresh();
    $brand = Brand::query()
        ->where('category_id', $category->id)
        ->where('name', 'Samsung')
        ->first();
    $model = ProductModel::query()
        ->where('brand_id', $brand?->id)
        ->where('name', 'Crystal UHD 55"')
        ->first();

    expect($brand)->not->toBeNull()
        ->and($model)->not->toBeNull()
        ->and(Brand::query()->where('category_id', $category->id)->count())->toBe(1)
        ->and(ProductModel::query()->where('brand_id', $brand->id)->count())->toBe(1)
        ->and($device->brand_id)->toBe($brand->id)
        ->and($device->product_model_id)->toBe($model->id);
});

it('allows shared catalog reads but reserves global catalog writes for super admins', function () {
    $category = DeviceCategory::factory()->create();
    Brand::create(['category_id' => $category->id, 'name' => 'Shared Brand', 'is_active' => true]);
    $storeAdmin = User::factory()->create();
    $storeAdmin->assignRole('store_admin');

    $this->actingAs($storeAdmin)
        ->getJson('/api/v1/admin/catalog/brands')
        ->assertOk();

    $this->actingAs($storeAdmin)
        ->postJson('/api/v1/admin/catalog/brands', [
            'category_id' => $category->id,
            'name' => 'Unauthorized Brand',
        ])
        ->assertForbidden();
});
