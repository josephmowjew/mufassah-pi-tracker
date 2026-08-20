<?php

use App\Enums\InventoryStatus;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\ProductModel;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->branch = Branch::create([
        'name' => 'Blantyre Branch',
        'code' => 'BLT',
        'is_active' => true,
    ]);

    $this->otherBranch = Branch::create([
        'name' => 'Lilongwe Branch',
        'code' => 'LLW',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');

    $this->category = DeviceCategory::factory()->create();
});

it('filters admin device listings by branch and supports an explicit all-branches scope', function () {
    Device::factory()->count(2)->create([
        'branch_id' => $this->branch->id,
        'category_id' => $this->category->id,
        'inventory_status' => InventoryStatus::IN_STOCK,
    ]);

    Device::factory()->count(3)->create([
        'branch_id' => $this->otherBranch->id,
        'category_id' => $this->category->id,
        'inventory_status' => InventoryStatus::IN_STOCK,
    ]);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/devices?branch_id={$this->otherBranch->id}")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.branch_id', $this->otherBranch->id)
        ->assertJsonPath('data.0.branch.name', 'Lilongwe Branch');

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/devices?all_branches=1')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 5);
});

it('keeps store admin device listings scoped to their assigned branch', function () {
    $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
    $storeAdmin->assignRole('store_admin');

    Device::factory()->count(2)->create([
        'branch_id' => $this->branch->id,
        'category_id' => $this->category->id,
    ]);
    Device::factory()->count(3)->create([
        'branch_id' => $this->otherBranch->id,
        'category_id' => $this->category->id,
    ]);

    $this->actingAs($storeAdmin)
        ->getJson("/api/v1/admin/devices?branch_id={$this->otherBranch->id}")
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 2);
});

it('scopes device listings to the branch managed by the store admin', function () {
    $manager = User::factory()->create(['branch_id' => $this->otherBranch->id]);
    $manager->assignRole('store_admin');
    $this->branch->update(['manager_id' => $manager->id]);

    Device::factory()->create([
        'branch_id' => $this->branch->id,
        'category_id' => $this->category->id,
    ]);
    Device::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'category_id' => $this->category->id,
    ]);

    $this->actingAs($manager)
        ->getJson('/api/v1/admin/devices?all_branches=1')
        ->assertSuccessful()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.branch_id', $this->branch->id);
});

it('blocks store admins from direct access to another branch device', function () {
    $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
    $storeAdmin->assignRole('store_admin');
    $device = Device::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'category_id' => $this->category->id,
    ]);

    $this->actingAs($storeAdmin)
        ->getJson("/api/v1/admin/devices/{$device->id}")
        ->assertForbidden();

    $this->actingAs($storeAdmin)
        ->putJson("/api/v1/admin/devices/{$device->id}", ['notes' => 'Cross-branch update'])
        ->assertForbidden();
});

it('fails closed when a store admin has no branch assignment', function () {
    $storeAdmin = User::factory()->create(['branch_id' => null]);
    $storeAdmin->assignRole('store_admin');

    $this->actingAs($storeAdmin)
        ->getJson('/api/v1/admin/devices')
        ->assertForbidden();
});

it('keeps catalog links synchronized when an admin changes a device identity', function () {
    $device = Device::factory()->create([
        'branch_id' => $this->branch->id,
        'category_id' => $this->category->id,
        'brand' => 'Legacy Brand',
        'model' => 'Legacy Model',
        'brand_id' => null,
        'product_model_id' => null,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/v1/admin/devices/{$device->id}", [
            'brand' => 'Samsung',
            'model' => 'Crystal UHD 55"',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.brand', 'Samsung')
        ->assertJsonPath('data.model', 'Crystal UHD 55"');

    $device->refresh();
    $brand = Brand::query()
        ->where('category_id', $this->category->id)
        ->where('name', 'Samsung')
        ->first();
    $model = ProductModel::query()
        ->where('brand_id', $brand?->id)
        ->where('name', 'Crystal UHD 55"')
        ->first();

    expect($brand)->not->toBeNull()
        ->and($model)->not->toBeNull()
        ->and($device->brand_id)->toBe($brand->id)
        ->and($device->product_model_id)->toBe($model->id);
});
