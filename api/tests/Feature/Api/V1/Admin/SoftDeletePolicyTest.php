<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\ProductModel;
use App\Models\StockTransferRequest;
use App\Models\User;
use App\Services\InventoryCatalogResolver;

/**
 * The rule this file defends: catalog and inventory records are soft deleted,
 * never destroyed. Everything here would have failed before `deleted_at`
 * existed on these tables.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->branch = Branch::create(['name' => 'Policy Branch', 'code' => 'POL', 'is_active' => true]);
    $this->admin = User::factory()->create(['email' => 'policy-admin@example.test', 'branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');

    $this->category = DeviceCategory::factory()->create(['name' => 'Power Banks', 'slug' => 'power-banks']);
    $this->brand = Brand::create([
        'category_id' => $this->category->id,
        'name' => 'Anker',
        'slug' => 'anker-power-banks',
        'is_active' => true,
    ]);
    $this->model = ProductModel::create([
        'brand_id' => $this->brand->id,
        'name' => 'PowerCore 26800',
        'is_active' => true,
    ]);
});

it('keeps a deleted catalog entry in the database', function () {
    $this->brand->delete();

    // Gone from the default scope, but the row is still there and recoverable.
    expect(Brand::find($this->brand->id))->toBeNull()
        ->and(Brand::withTrashed()->find($this->brand->id))->not->toBeNull();

    $this->assertSoftDeleted('brands', ['id' => $this->brand->id]);
});

it('cascades a brand soft delete to its product models', function () {
    $this->brand->delete();

    expect(ProductModel::find($this->model->id))->toBeNull()
        ->and(ProductModel::withTrashed()->find($this->model->id))->not->toBeNull();

    // Restoring the brand brings its models back with it.
    $this->brand->restore();

    expect(ProductModel::find($this->model->id))->not->toBeNull()
        ->and(ProductModel::withTrashed()->find($this->model->id)->deleted_at)->toBeNull();
});

it('cascades a category soft delete through brands to models', function () {
    $this->category->delete();

    expect(Brand::find($this->brand->id))->toBeNull()
        ->and(ProductModel::find($this->model->id))->toBeNull()
        // Nothing was destroyed — all three rows survive.
        ->and(Brand::withTrashed()->count())->toBe(1)
        ->and(ProductModel::withTrashed()->count())->toBe(1);
});

it('never reissues a number that a trashed row still holds', function () {
    $batch = InventoryBatch::create([
        'batch_number' => InventoryBatch::generateBatchNumber(),
        'branch_id' => $this->branch->id,
        'category_id' => $this->category->id,
        'brand' => 'Anker',
        'model' => 'PowerCore 26800',
        'quantity_received' => 1,
        'buying_price' => 1000,
        'target_selling_price' => 2000,
        'purchase_date' => now()->toDateString(),
    ]);
    $batchNumber = $batch->batch_number;
    $batch->delete();

    expect(InventoryBatch::generateBatchNumber())->not->toBe($batchNumber);

    $receipt = InventoryReceipt::create([
        'receipt_number' => InventoryReceipt::generateReceiptNumber(),
        'branch_id' => $this->branch->id,
    ]);
    $receiptNumber = $receipt->receipt_number;
    $receipt->delete();

    expect(InventoryReceipt::generateReceiptNumber())->not->toBe($receiptNumber);

    $movement = InventoryMovement::create([
        'movement_number' => InventoryMovement::generateMovementNumber(),
        'movement_type' => InventoryMovement::TYPE_RECEIPT,
        'quantity' => 1,
    ]);
    $movementNumber = $movement->movement_number;
    $movement->delete();

    expect(InventoryMovement::generateMovementNumber())->not->toBe($movementNumber);

    $transfer = StockTransferRequest::create([
        'request_number' => StockTransferRequest::nextNumber(),
        'from_branch_id' => $this->branch->id,
        'to_branch_id' => $this->branch->id,
        'requested_by_id' => $this->admin->id,
        'reason' => 'Policy test',
    ]);
    $requestNumber = $transfer->request_number;
    $transfer->delete();

    expect(StockTransferRequest::nextNumber())->not->toBe($requestNumber);
});

it('restores a deleted catalog entry when stock arrives for it again', function () {
    $this->model->delete();
    $this->brand->delete();

    expect(Brand::find($this->brand->id))->toBeNull();

    $resolved = app(InventoryCatalogResolver::class)->resolve($this->category->id, 'Anker', 'PowerCore 26800');

    // The original rows come back rather than duplicates being inserted —
    // which is what the unique slug index would have rejected anyway.
    expect($resolved['brand_id'])->toBe($this->brand->id)
        ->and($resolved['product_model_id'])->toBe($this->model->id)
        ->and(Brand::count())->toBe(1)
        ->and(ProductModel::count())->toBe(1)
        ->and(Brand::withTrashed()->count())->toBe(1);
});

it('says a name clash is with a deleted entry so it can be restored', function () {
    $this->category->delete();

    $this->actingAs($this->admin)
        ->postJson(apiUrl('admin/device-categories'), ['name' => 'Power Banks'])
        ->assertStatus(422)
        ->assertJsonPath(
            'errors.name.0',
            'A deleted category with this name already exists. Restore it instead of creating a new one.'
        );
});

it('still reports a clash with a live entry as a plain duplicate', function () {
    $this->actingAs($this->admin)
        ->postJson(apiUrl('admin/device-categories'), ['name' => 'Power Banks'])
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', 'A category with this name already exists.');
});

it('refuses to file a brand under a deleted category', function () {
    $this->category->delete();

    $this->actingAs($this->admin)
        ->postJson(apiUrl('admin/catalog/brands'), [
            'category_id' => $this->category->id,
            'name' => 'Samsung',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('category_id');
});

it('refuses to file a model under a deleted brand', function () {
    $this->brand->delete();

    $this->actingAs($this->admin)
        ->postJson(apiUrl('admin/catalog/models'), [
            'brand_id' => $this->brand->id,
            'name' => 'Soundcore 3',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('brand_id');
});
