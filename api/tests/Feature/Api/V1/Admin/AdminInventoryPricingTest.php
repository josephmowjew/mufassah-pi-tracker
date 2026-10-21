<?php

use App\Enums\InventoryStatus;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use App\Models\ProductModel;
use App\Models\User;

/**
 * Price inheritance at intake.
 *
 * Price lives on the batch (the lot), so receiving new stock used to mean
 * retyping the selling price on every delivery. Intake now inherits the price
 * already in use for the product instead. These tests pin the resolution order
 * down because getting it wrong silently changes what customers are charged.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    $this->category = DeviceCategory::factory()->create(['name' => 'Smartphones']);

    $this->branch = Branch::create([
        'name' => 'Test Branch',
        'code' => 'TST',
        'is_active' => true,
    ]);
});

function pricingReceiveStock(object $test, array $line = [], ?int $branchId = null, array $extra = []): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->admin)->postJson('/api/v1/admin/inventory/receipts', [
        'branch_id' => $branchId ?? $test->branch->id,
        'lines' => [
            array_merge([
                'category_id' => $test->category->id,
                'brand' => 'Samsung',
                'model' => 'Galaxy S24',
                'quantity' => 2,
                'buying_price' => 500000,
                'auto_generate_serials' => true,
            ], $line),
        ],
        ...$extra,
    ]);
}

function pricingLatestBatch(): InventoryBatch
{
    return InventoryBatch::query()->orderByDesc('id')->firstOrFail();
}

describe('Price inheritance at intake', function () {
    it('inherits the selling price from the last batch of the same product', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();

        // No target_selling_price on the second delivery.
        pricingReceiveStock($this)->assertCreated();

        expect((float) pricingLatestBatch()->target_selling_price)->toBe(650000.0)
            ->and((float) pricingLatestBatch()->buying_price)->toBe(500000.0);
    });

    it('still records the new delivery as its own batch with its own cost', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();
        pricingReceiveStock($this, ['buying_price' => 480000])->assertCreated();

        expect(InventoryBatch::query()->count())->toBe(2)
            ->and((float) pricingLatestBatch()->buying_price)->toBe(480000.0)
            ->and((float) pricingLatestBatch()->target_selling_price)->toBe(650000.0);
    });

    it('prefers the receiving branch over a newer batch in another branch', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();
        pricingReceiveStock($this, ['target_selling_price' => 700000], $otherBranch->id)->assertCreated();

        // The other branch's batch is newer, but this branch's own price wins.
        pricingReceiveStock($this)->assertCreated();

        expect((float) pricingLatestBatch()->target_selling_price)->toBe(650000.0);
    });

    it('falls back to the catalog base price when the product has never been received', function () {
        $brand = Brand::create([
            'category_id' => $this->category->id,
            'name' => 'Tecno',
            'slug' => 'tecno',
            'is_active' => true,
        ]);
        ProductModel::create([
            'brand_id' => $brand->id,
            'name' => 'Camon 20',
            'slug' => 'camon-20',
            'base_price' => 300000,
            'is_active' => true,
        ]);

        pricingReceiveStock($this, ['brand' => 'Tecno', 'model' => 'Camon 20'])->assertCreated();

        expect((float) pricingLatestBatch()->target_selling_price)->toBe(300000.0);
    });

    it('rejects a line with no supplied and no inheritable price', function () {
        $response = pricingReceiveStock($this, ['brand' => 'Nokia', 'model' => 'Never Stocked']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.target_selling_price']);

        expect(InventoryBatch::query()->count())->toBe(0);
    });

    it('keeps an explicitly supplied selling price instead of inheriting', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();
        pricingReceiveStock($this, ['target_selling_price' => 725000])->assertCreated();

        expect((float) pricingLatestBatch()->target_selling_price)->toBe(725000.0);
    });

    it('inherits the price for a bulk add without a target selling price', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/inventory/bulk', [
                'category_id' => $this->category->id,
                'branch_id' => $this->branch->id,
                'brand' => 'Samsung',
                'model' => 'Galaxy S24',
                'quantity' => 1,
                'buying_price' => 500000,
                'auto_generate_serials' => true,
            ])
            ->assertCreated();

        expect((float) pricingLatestBatch()->target_selling_price)->toBe(650000.0);
    });

    it('does not let a deleted batch set the inherited price', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();
        pricingLatestBatch()->delete();

        pricingReceiveStock($this, ['brand' => 'Nokia', 'model' => 'Never Stocked'])
            ->assertUnprocessable();
    });
});

describe('Price suggestion endpoint', function () {
    it('reports the last buying and selling price with its batch reference', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();
        $batch = pricingLatestBatch();

        $response = $this->actingAs($this->admin)->getJson(
            '/api/v1/admin/inventory/price-suggestion?'
            .http_build_query([
                'category_id' => $this->category->id,
                'brand' => 'Samsung',
                'model' => 'Galaxy S24',
                'branch_id' => $this->branch->id,
            ])
        );

        $response->assertSuccessful();

        expect($response->json('data.source'))->toBe('last_batch')
            ->and($response->json('data.batch_number'))->toBe($batch->batch_number)
            ->and((float) $response->json('data.buying_price'))->toBe(500000.0)
            ->and((float) $response->json('data.target_selling_price'))->toBe(650000.0);
    });

    it('reports the catalog base price when there is no prior stock', function () {
        $brand = Brand::create([
            'category_id' => $this->category->id,
            'name' => 'Infinix',
            'slug' => 'infinix',
            'is_active' => true,
        ]);
        ProductModel::create([
            'brand_id' => $brand->id,
            'name' => 'Hot 40',
            'slug' => 'hot-40',
            'base_price' => 275000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->getJson(
            '/api/v1/admin/inventory/price-suggestion?'
            .http_build_query([
                'category_id' => $this->category->id,
                'brand' => 'Infinix',
                'model' => 'Hot 40',
            ])
        );

        $response->assertSuccessful();

        expect($response->json('data.source'))->toBe('catalog_base_price')
            ->and($response->json('data.batch_id'))->toBeNull()
            ->and((float) $response->json('data.target_selling_price'))->toBe(275000.0);
    });

    it('returns a null price when nothing can be inherited', function () {
        $response = $this->actingAs($this->admin)->getJson(
            '/api/v1/admin/inventory/price-suggestion?'
            .http_build_query([
                'category_id' => $this->category->id,
                'brand' => 'Unknown',
                'model' => 'Unknown',
            ])
        );

        $response->assertSuccessful();

        expect($response->json('data.source'))->toBeNull()
            ->and($response->json('data.target_selling_price'))->toBeNull();
    });
});

describe('Inherited prices reach the devices', function () {
    it('attaches the inherited price to the devices in the new batch', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();
        pricingReceiveStock($this)->assertCreated();

        $batch = pricingLatestBatch();
        $devices = Device::query()->where('inventory_batch_id', $batch->id)->get();

        expect($devices)->toHaveCount(2)
            ->and($devices->every(fn (Device $device) => $device->inventory_status === InventoryStatus::IN_STOCK))->toBeTrue()
            ->and((float) $batch->target_selling_price)->toBe(650000.0);
    });
});

describe('Catalog link on batches', function () {
    it('stores the catalog model on the batch at intake', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();

        $batch = pricingLatestBatch();

        expect($batch->product_model_id)->not->toBeNull()
            ->and(ProductModel::find($batch->product_model_id)?->name)->toBe('Galaxy S24');
    });

    it('keeps inheriting the price after the catalog model is renamed', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();

        $batch = pricingLatestBatch();
        $model = ProductModel::findOrFail($batch->product_model_id);

        // The batch keeps the old text, so only the product_model_id link can
        // still find it. Text matching would miss it and fall through to 422.
        $model->update(['name' => 'Galaxy S24 Ultra']);

        pricingReceiveStock($this, ['model' => 'Galaxy S24 Ultra'])->assertCreated();

        expect((float) pricingLatestBatch()->target_selling_price)->toBe(650000.0);
    });
});

describe('Catalog price cascade', function () {
    it('reprices batches through the catalog link even after a rename', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();

        $batch = pricingLatestBatch();
        $model = ProductModel::findOrFail($batch->product_model_id);
        $model->update(['name' => 'Galaxy S24 Ultra']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/catalog/models/{$model->id}", ['base_price' => 700000])
            ->assertSuccessful();

        expect((float) $batch->fresh()->target_selling_price)->toBe(700000.0);
    });

    it('does not push a null price into the batch when the catalog price is cleared', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();

        $batch = pricingLatestBatch();
        $model = ProductModel::findOrFail($batch->product_model_id);
        $model->update(['base_price' => 650000]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/catalog/models/{$model->id}", ['base_price' => null])
            ->assertSuccessful();

        expect((float) $batch->fresh()->target_selling_price)->toBe(650000.0);
    });

    it('counts batches by catalog link in the deletion impact preview', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();

        $batch = pricingLatestBatch();
        $model = ProductModel::findOrFail($batch->product_model_id);
        $model->update(['name' => 'Galaxy S24 Ultra']);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/catalog/models/{$model->id}/deletion-impact");

        $response->assertSuccessful();

        // The text no longer matches after the rename; the preview must agree
        // with the cascade about which batches belong to this model.
        expect($response->json('data.related.inventory_batches'))->toBe(1);
    });

    it('counts batches by catalog link in the brand deletion impact preview', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();

        $batch = pricingLatestBatch();
        $brand = ProductModel::findOrFail($batch->product_model_id)->brand;

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/catalog/brands/{$brand->id}/deletion-impact");

        $response->assertSuccessful();

        expect($response->json('data.related.inventory_batches'))->toBe(1);
    });
});

describe('Price inheritance edge cases', function () {
    it('still inherits from a batch that has no catalog link', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();

        // Simulate a legacy row the backfill could not match to the catalog.
        pricingLatestBatch()->update(['product_model_id' => null]);

        pricingReceiveStock($this)->assertCreated();

        expect((float) pricingLatestBatch()->target_selling_price)->toBe(650000.0);
    });

    it('still inherits when the catalog model has been deleted', function () {
        pricingReceiveStock($this, ['target_selling_price' => 650000])->assertCreated();

        $batch = pricingLatestBatch();
        ProductModel::findOrFail($batch->product_model_id)->delete();

        pricingReceiveStock($this)->assertCreated();

        expect((float) pricingLatestBatch()->target_selling_price)->toBe(650000.0);
    });

    it('still requires a buying price', function () {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/inventory/receipts', [
            'branch_id' => $this->branch->id,
            'lines' => [[
                'category_id' => $this->category->id,
                'brand' => 'Samsung',
                'model' => 'Galaxy S24',
                'quantity' => 1,
                'target_selling_price' => 650000,
            ]],
        ]);

        // Cost is per-delivery reality and can never be inherited.
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.buying_price']);
    });

    it('does not expose the suggestion endpoint to customer service', function () {
        $agent = User::factory()->create();
        $agent->assignRole('customer_service');

        $this->actingAs($agent)
            ->getJson('/api/v1/admin/inventory/price-suggestion?'.http_build_query([
                'category_id' => $this->category->id,
                'brand' => 'Samsung',
                'model' => 'Galaxy S24',
            ]))
            ->assertForbidden();
    });
});
