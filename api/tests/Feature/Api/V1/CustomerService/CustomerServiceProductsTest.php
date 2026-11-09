<?php

use App\Enums\InventoryStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use App\Models\StockTransferRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

it('shows branch stock, price tiers and searchable unit details without exposing costs', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $branch = Branch::create(['name' => 'Main store', 'code' => 'MAIN', 'is_active' => true]);
    $other = Branch::create(['name' => 'Other store', 'code' => 'OTHER', 'is_active' => true]);
    $staff = User::factory()->create(['branch_id' => $branch->id])->assignRole('customer_service');
    $phones = DeviceCategory::factory()->create(['name' => 'Phones']);
    $audio = DeviceCategory::factory()->create(['name' => 'Audio']);
    $batch = fn (int $price) => InventoryBatch::create([
        'batch_number' => 'BATCH-'.$price, 'category_id' => $phones->id, 'branch_id' => $branch->id,
        'brand' => 'Example', 'model' => 'Phone X', 'quantity_received' => 1, 'buying_price' => 54321,
        'target_selling_price' => $price,
    ]);
    $unit = fn (array $data) => Device::factory()->create($data + [
        'category_id' => $phones->id, 'branch_id' => $branch->id, 'owner_id' => null,
        'brand' => 'Example', 'model' => 'Phone X', 'inventory_status' => InventoryStatus::IN_STOCK, 'purchase_price' => 54321,
    ]);

    $first = $unit(['inventory_batch_id' => $batch(75000)->id, 'serial_number' => 'PHONE-MAIN-001', 'color' => 'Blue']);
    $unit(['inventory_batch_id' => $batch(80000)->id, 'inventory_status' => InventoryStatus::RETURNED]);
    $unit(['serial_number' => 'LEGACY-UNPRICED']);
    $unit(['inventory_batch_id' => $batch(90000)->id, 'inventory_status' => InventoryStatus::RESERVED]);
    $transfer = StockTransferRequest::create([
        'request_number' => 'TRANSFER-PRODUCT-TEST', 'from_branch_id' => $branch->id, 'to_branch_id' => $other->id,
        'requested_by_id' => $staff->id, 'status' => StockTransferRequest::PENDING, 'reason' => 'Stock balancing',
    ]);
    $unit(['stock_transfer_request_id' => $transfer->id, 'serial_number' => 'PENDING-TRANSFER']);
    $unit(['category_id' => $audio->id, 'model' => 'Speaker', 'inventory_status' => InventoryStatus::SOLD]);
    $remote = $unit(['branch_id' => $other->id, 'serial_number' => 'OTHER-BRANCH-ONLY']);

    $base = '/api/v1/customer-service/products';
    $this->actingAs($staff)->getJson($base.'?branch_id='.$other->id.'&search=PHONE-MAIN-001')
        ->assertOk()
        ->assertJsonPath('data.branch.name', 'Main store')
        ->assertJsonPath('data.summary', ['products' => 2, 'available_units' => 3, 'held_units' => 2])
        ->assertJsonPath('data.products.total', 1)
        ->assertJsonPath('data.products.data.0.total_units', 5)
        ->assertJsonPath('data.products.data.0.available_units', 3)
        ->assertJsonPath('data.products.data.0.price_tiers', [
            ['price' => 75000, 'quantity' => 1],
            ['price' => 80000, 'quantity' => 1],
            ['price' => null, 'quantity' => 1],
        ])->assertDontSee('54321')->assertDontSee('90000');

    $this->getJson($base.'?category_id='.$audio->id.'&availability=unavailable')
        ->assertOk()->assertJsonPath('data.products.total', 1)
        ->assertJsonPath('data.products.data.0.model', 'Speaker');
    $this->getJson($base.'?category_id='.$audio->id.'&availability=available')
        ->assertOk()->assertJsonPath('data.products.total', 0);
    $this->getJson($base.'/'.$first->id.'/units?search=PHONE-MAIN-001')->assertOk()
        ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.color', 'Blue')
        ->assertJsonPath('data.data.0.selling_price', 75000)->assertDontSee('54321');
    $this->getJson($base.'/'.$first->id.'/units?search=PENDING-TRANSFER')->assertOk()
        ->assertJsonPath('data.data.0.available', false)->assertJsonPath('data.data.0.hold', 'Awaiting transfer');
    $this->getJson($base.'/'.$remote->id.'/units')->assertNotFound();
});
