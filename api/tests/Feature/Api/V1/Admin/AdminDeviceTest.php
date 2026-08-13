<?php

use App\Enums\InventoryStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
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
