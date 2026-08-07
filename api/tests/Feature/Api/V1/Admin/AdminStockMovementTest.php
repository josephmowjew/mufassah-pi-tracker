<?php

use App\Enums\InventoryStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryMovement;
use App\Models\StockTransferRequest;
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

    it('creates a transfer from a requested product quantity', function () {
        $devices = collect(range(1, 3))->map(fn () => Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Samsung',
            'model' => 'Galaxy S25',
            'color' => 'Black',
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]));

        $response = $this->actingAs($this->storeAdmin)
            ->postJson('/api/v1/admin/stock-movements/transfer-requests', [
                'product_quantities' => [[
                    'category_id' => $this->category->id,
                    'brand' => 'Samsung',
                    'model' => 'Galaxy S25',
                    'color' => 'Black',
                    'quantity' => 2,
                ]],
                'to_branch_id' => $this->otherBranch->id,
                'reason' => 'Quantity-based restock',
            ])
            ->assertCreated()
            ->assertJsonPath('data.item_count', 2);

        $transfer = StockTransferRequest::findOrFail($response->json('data.id'));
        $selectedIds = $transfer->items()->pluck('device_id');
        expect($selectedIds)->toHaveCount(2);
        expect($devices->pluck('id')->diff($selectedIds))->toHaveCount(1);
        expect($devices->whereIn('id', $selectedIds)->every(fn ($device) => $device->fresh()->stock_transfer_request_id === $transfer->id))->toBeTrue();

        $this->actingAs($this->destinationManager)
            ->postJson("/api/v1/admin/stock-movements/transfer-requests/{$transfer->id}/approve")
            ->assertSuccessful();

        expect($devices->whereIn('id', $selectedIds)->every(fn ($device) => (int) $device->fresh()->branch_id === (int) $this->otherBranch->id))->toBeTrue();
        expect($devices->whereNotIn('id', $selectedIds)->first()->fresh()->branch_id)->toBe($this->branch->id);
    });

    it('rejects zero or negative product transfer quantities with a clear validation error', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'LG',
            'model' => 'OLED C4',
            'color' => null,
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->storeAdmin)
            ->postJson('/api/v1/admin/stock-movements/transfer-requests', [
                'product_quantities' => [[
                    'category_id' => $device->category_id,
                    'brand' => $device->brand,
                    'model' => $device->model,
                    'quantity' => -1,
                ]],
                'to_branch_id' => $this->otherBranch->id,
                'reason' => 'Invalid quantity',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_quantities.0.quantity');

        expect($response->json('errors')['product_quantities.0.quantity'][0])->toBe('Quantity must be at least 1.');
    });

    it('rejects a product quantity above the available count', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia 9',
            'color' => 'Black',
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->storeAdmin)
            ->postJson('/api/v1/admin/stock-movements/transfer-requests', [
                'product_quantities' => [[
                    'category_id' => $device->category_id,
                    'brand' => $device->brand,
                    'model' => $device->model,
                    'color' => $device->color,
                    'quantity' => 2,
                ]],
                'to_branch_id' => $this->otherBranch->id,
                'reason' => 'Too many devices',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_quantities.0.quantity');

        expect($response->json('errors')['product_quantities.0.quantity'][0])->toBe('Only 1 available for Sony Bravia 9 Black; you requested 2.');

        expect($device->fresh()->stock_transfer_request_id)->toBeNull();
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

    it('allows a super admin to override destination receipt when no branch manager is assigned', function () {
        $managerlessBranch = Branch::create([
            'name' => 'Unstaffed Branch',
            'code' => 'UNSTAFFED',
            'is_active' => true,
        ]);
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);
        $payload = [
            'device_ids' => [$device->id],
            'to_branch_id' => $managerlessBranch->id,
            'reason' => 'Rebalance unstaffed branch',
            'notes' => 'Super-admin direct transfer',
        ];

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/stock-movements/transfer-requests', $payload)
            ->assertStatus(409)
            ->assertJsonPath('requires_override', true)
            ->assertJsonPath('data', null);

        expect($device->fresh()->branch_id)->toBe($this->branch->id);
        expect($device->fresh()->stock_transfer_request_id)->toBeNull();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/stock-movements/transfer-requests', [
                ...$payload,
                'override_destination_receipt' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', StockTransferRequest::APPROVED)
            ->assertJsonPath('data.is_override', true)
            ->assertJsonPath('data.overridden_by_id', $this->admin->id);

        $transferId = $response->json('data.id');
        expect($device->fresh()->branch_id)->toBe($managerlessBranch->id);
        expect($device->fresh()->stock_transfer_request_id)->toBeNull();
        expect(StockTransferRequest::find($transferId)->overridden_at)->not->toBeNull();
        expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_TRANSFER)
            ->where('device_id', $device->id)
            ->where('from_branch_id', $this->branch->id)
            ->where('to_branch_id', $managerlessBranch->id)
            ->exists())->toBeTrue();
    });

    it('does not allow a store admin to bypass destination receipt', function () {
        $managerlessBranch = Branch::create([
            'name' => 'Unstaffed Branch',
            'code' => 'UNSTAFFED',
            'is_active' => true,
        ]);
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($this->storeAdmin)
            ->postJson('/api/v1/admin/stock-movements/transfer-requests', [
                'device_ids' => [$device->id],
                'to_branch_id' => $managerlessBranch->id,
                'reason' => 'Unauthorized direct transfer',
                'override_destination_receipt' => true,
            ])
            ->assertForbidden();
    });

    it('allows a device to be transferred again after a completed transfer', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $firstTransfer = $this->actingAs($this->storeAdmin)
            ->postJson('/api/v1/admin/stock-movements/transfer-requests', [
                'device_ids' => [$device->id],
                'to_branch_id' => $this->otherBranch->id,
                'reason' => 'Initial transfer',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->destinationManager)
            ->postJson("/api/v1/admin/stock-movements/transfer-requests/{$firstTransfer}/approve")
            ->assertSuccessful();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/stock-movements/transfer-requests', [
                'device_ids' => [$device->id],
                'from_branch_id' => $this->otherBranch->id,
                'to_branch_id' => $this->branch->id,
                'reason' => 'Return transfer',
                'override_destination_receipt' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_override', true);

        expect($device->fresh()->branch_id)->toBe($this->branch->id);
        expect(StockTransferRequest::whereHas('items', fn ($query) => $query->where('device_id', $device->id))->count())->toBe(2);
        expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_TRANSFER)->where('device_id', $device->id)->count())->toBe(2);
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
