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
