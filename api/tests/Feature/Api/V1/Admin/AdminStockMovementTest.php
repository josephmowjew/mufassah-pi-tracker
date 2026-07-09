<?php

use App\Enums\InventoryStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryMovement;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->branch = Branch::create([
        'name' => 'Main Branch',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $this->otherBranch = Branch::create([
        'name' => 'Second Branch',
        'code' => 'SEC',
        'is_active' => true,
    ]);

    $this->destinationManager = User::factory()->create(['branch_id' => $this->otherBranch->id]);
    $this->destinationManager->assignRole('store_admin');
    $this->otherBranch->update(['manager_id' => $this->destinationManager->id]);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');

    $this->storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->storeAdmin->assignRole('store_admin');

    $this->customerService = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->customerService->assignRole('customer_service');

    $this->customer = User::factory()->create();
    $this->customer->assignRole('customer');

    $this->category = DeviceCategory::factory()->create(['name' => 'Television']);
});

describe('Admin Stock Movements', function () {
    it('allows only super admins and store admins to access stock movements', function () {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/stock-movements')
            ->assertSuccessful();

        $this->actingAs($this->storeAdmin)
            ->getJson('/api/v1/admin/stock-movements')
            ->assertSuccessful();

        $this->actingAs($this->customerService)
            ->getJson('/api/v1/admin/stock-movements')
            ->assertForbidden();

        $this->actingAs($this->customer)
            ->getJson('/api/v1/admin/stock-movements')
            ->assertForbidden();
    });

    it('records receipt movements when inventory is received', function () {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/inventory/receipts', [
            'branch_id' => $this->branch->id,
            'supplier' => 'Supplier Ltd',
            'purchase_date' => now()->toDateString(),
            'lines' => [[
                'category_id' => $this->category->id,
                'brand' => 'Samsung',
                'model' => 'Crystal UHD 55',
                'color' => 'Black',
                'quantity' => 2,
                'buying_price' => 100000,
                'target_selling_price' => 150000,
                'auto_generate_serials' => true,
            ]],
        ]);

        $response->assertCreated();

        expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_RECEIPT)->count())->toBe(2);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/stock-movements?movement_type=receipt')
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 2);
    });

    it('records a sale movement when a completed sale consumes stock', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
            'purchase_price' => 80000,
        ]);

        $this->actingAs($this->admin)->postJson('/api/v1/admin/sales', [
            'customer_id' => $this->customer->id,
            'payment_method' => 'cash',
            'payment_amount' => 150000,
            'status' => 'completed',
            'items' => [[
                'category_id' => $device->category_id,
                'brand' => $device->brand,
                'model' => $device->model,
                'color' => $device->color,
                'quantity' => 1,
                'unit_price' => 150000,
            ]],
        ])->assertCreated();

        $movement = InventoryMovement::where('movement_type', InventoryMovement::TYPE_SALE)->first();

        expect($movement)->not->toBeNull();
        expect($movement->device_id)->toBe($device->id);
        expect($movement->previous_status)->toBe('in_stock');
        expect($movement->new_status)->toBe('sold');
    });

    it('creates a pending request instead of immediately transferring stock', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($this->storeAdmin)->postJson('/api/v1/admin/stock-movements/transfer-requests', [
            'device_ids' => [$device->id],
            'to_branch_id' => $this->otherBranch->id,
            'reason' => 'Restock second branch',
        ])->assertCreated();

        $device->refresh();
        expect($device->branch_id)->toBe($this->branch->id);
        expect($device->stock_transfer_request_id)->not->toBeNull();
    });

    it('holds stock until the destination manager approves a transfer request', function () {
        $device = Device::factory()->create(['category_id' => $this->category->id, 'branch_id' => $this->branch->id, 'owner_id' => null, 'inventory_status' => InventoryStatus::IN_STOCK]);

        $response = $this->actingAs($this->storeAdmin)->postJson('/api/v1/admin/stock-movements/transfer-requests', [
            'device_ids' => [$device->id], 'to_branch_id' => $this->otherBranch->id, 'reason' => 'Restock second branch',
        ])->assertCreated();

        $requestId = $response->json('data.id');
        expect($device->fresh()->branch_id)->toBe($this->branch->id);
        expect($device->fresh()->stock_transfer_request_id)->toBe($requestId);

        $this->actingAs($this->destinationManager)->postJson("/api/v1/admin/stock-movements/transfer-requests/{$requestId}/approve")
            ->assertSuccessful();

        expect($device->fresh()->branch_id)->toBe($this->otherBranch->id);
        expect($device->fresh()->stock_transfer_request_id)->toBeNull();
        expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_TRANSFER)->exists())->toBeTrue();
    });

    it('prevents store admins from transferring stock outside their source branch', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->otherBranch->id,
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($this->storeAdmin)->postJson('/api/v1/admin/stock-movements/transfer-requests', [
            'device_ids' => [$device->id],
            'to_branch_id' => $this->branch->id,
            'reason' => 'Unauthorized transfer',
        ])->assertUnprocessable();
    });

    it('requires a reason for stock adjustments and records valid adjustments', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($this->storeAdmin)->postJson('/api/v1/admin/stock-movements/adjustments', [
            'device_ids' => [$device->id],
            'inventory_status' => 'faulty',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($this->storeAdmin)->postJson('/api/v1/admin/stock-movements/adjustments', [
            'device_ids' => [$device->id],
            'inventory_status' => 'faulty',
            'reason' => 'Screen damaged',
        ])->assertCreated();

        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::FAULTY);
        expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_FAULTY)->exists())->toBeTrue();
    });

    it('scopes store admin movement listings to their branch', function () {
        $mainDevice = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);
        $otherDevice = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->otherBranch->id,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        InventoryMovement::create([
            'movement_number' => 'MOVE-2026-000001',
            'movement_type' => InventoryMovement::TYPE_RECEIPT,
            'device_id' => $mainDevice->id,
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'brand' => 'Sony',
            'model' => 'Bravia',
            'quantity' => 1,
        ]);
        InventoryMovement::create([
            'movement_number' => 'MOVE-2026-000002',
            'movement_type' => InventoryMovement::TYPE_RECEIPT,
            'device_id' => $otherDevice->id,
            'branch_id' => $this->otherBranch->id,
            'category_id' => $this->category->id,
            'brand' => 'LG',
            'model' => 'OLED',
            'quantity' => 1,
        ]);

        $this->actingAs($this->storeAdmin)
            ->getJson("/api/v1/admin/stock-movements?branch_id={$this->otherBranch->id}")
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.branch_id', $this->branch->id);
    });
});
