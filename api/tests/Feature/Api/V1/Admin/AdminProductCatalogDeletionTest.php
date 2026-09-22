<?php

use App\Enums\InventoryStatus;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\DeviceLocation;
use App\Models\InventoryActionChallenge;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\ProductModel;
use App\Models\User;
use App\Notifications\InventoryActionApprovalPinNotification;
use App\Services\InventoryMovementService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    config(['inventory.approval_email' => 'elijahpchitedze@gmail.com']);
    Notification::fake();
    $this->withoutMiddleware(ThrottleRequests::class);

    $this->branch = Branch::create(['name' => 'Catalog Branch', 'code' => 'CAT', 'is_active' => true]);
    $this->category = DeviceCategory::factory()->create();
    $this->admin = User::factory()->create(['email' => 'catalog-admin@example.test', 'branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');

    $this->brand = Brand::create(['category_id' => $this->category->id, 'name' => 'Anker', 'is_active' => true]);
    $this->model = ProductModel::create(['brand_id' => $this->brand->id, 'name' => 'PowerCore 26800', 'is_active' => true]);
});

function catalogDeleteDevice(array $overrides = []): Device
{
    return Device::factory()->create(array_merge([
        'category_id' => test()->category->id,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'product_model_id' => test()->model->id,
        'brand' => 'Anker',
        'model' => 'PowerCore 26800',
        'owner_id' => null,
        'inventory_status' => InventoryStatus::IN_STOCK,
        'purchase_price' => 100000,
    ], $overrides));
}

function verifyCatalogDeleteChallenge(int $challengeId, User $admin, string $pin = '123456')
{
    InventoryActionChallenge::whereKey($challengeId)->update(['pin_hash' => Hash::make($pin)]);

    return test()->actingAs($admin)->postJson("/api/v1/admin/action-challenges/{$challengeId}/verify", ['pin' => $pin]);
}

it('deletes a model with no devices outright and opens no challenge', function () {
    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}")
        ->assertOk();

    expect(ProductModel::find($this->model->id))->toBeNull()
        ->and(InventoryActionChallenge::count())->toBe(0);
});

it('deletes a brand with no devices outright', function () {
    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/brands/{$this->brand->id}")
        ->assertOk();

    expect(Brand::find($this->brand->id))->toBeNull()
        ->and(ProductModel::find($this->model->id))->toBeNull();
});

it('exposes a read-only deletion impact preview', function () {
    $device = catalogDeleteDevice();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/catalog/models/{$this->model->id}/deletion-impact")
        ->assertOk()
        ->assertJsonPath('data.subject.type', 'product_model')
        ->assertJsonPath('data.subject.name', 'PowerCore 26800')
        ->assertJsonPath('data.devices.total', 1)
        ->assertJsonPath('data.devices.will_be_written_off', 1)
        ->assertJsonPath('data.detail.0.id', $device->id)
        ->assertJsonPath('data.detail.0.will_be_written_off', true)
        ->assertJsonPath('data.detail.0.unit_cost', 100000)
        ->assertJsonPath('data.stock_impact.units_leaving_stock', 1)
        ->assertJsonPath('data.stock_impact.value_at_cost', 100000);
});

it('returns the impact and requires confirmation before overriding', function () {
    catalogDeleteDevice();
    catalogDeleteDevice(['inventory_status' => InventoryStatus::SOLD]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}")
        ->assertStatus(409)
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('impact.devices.total', 2)
        ->assertJsonPath('impact.devices.will_be_written_off', 1)
        ->assertJsonPath('impact.devices.already_out_of_stock', 1)
        ->assertJsonPath('impact.stock_impact.units_leaving_stock', 1);

    expect(ProductModel::find($this->model->id))->not->toBeNull()
        ->and(Device::count())->toBe(2);
});

it('blocks non super admins from the override and the preview', function () {
    catalogDeleteDevice();
    $storeAdmin = User::factory()->create();
    $storeAdmin->assignRole('store_admin');

    $this->actingAs($storeAdmin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Trying to bypass',
        ])
        ->assertForbidden();

    $this->actingAs($storeAdmin)
        ->getJson("/api/v1/admin/catalog/models/{$this->model->id}/deletion-impact")
        ->assertForbidden();

    expect(ProductModel::find($this->model->id))->not->toBeNull();
});

it('requires a reason to override a deletion', function () {
    catalogDeleteDevice();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", ['confirm_impact' => true])
        ->assertStatus(422);
});

it('creates a PIN challenge once the impact is confirmed', function () {
    catalogDeleteDevice();

    $response = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Model discontinued by the supplier',
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.device_count', 1);

    $challenge = InventoryActionChallenge::findOrFail($response->json('data.challenge_id'));

    expect($challenge->action)->toBe(InventoryActionChallenge::ACTION_PRODUCT_MODEL_DELETION)
        ->and($challenge->payload['product_model_id'])->toBe($this->model->id)
        ->and($challenge->payload['device_ids'])->toBe([Device::firstOrFail()->id]);
});

it('cascades the deletion on PIN verification and writes stock out of the ledger', function () {
    $inStock = catalogDeleteDevice();
    $sold = catalogDeleteDevice(['inventory_status' => InventoryStatus::SOLD]);

    $challengeId = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Discontinued',
        ])
        ->assertStatus(202)
        ->json('data.challenge_id');

    verifyCatalogDeleteChallenge($challengeId, $this->admin)
        ->assertOk()
        ->assertJsonPath('data.deleted_count', 2)
        ->assertJsonPath('data.written_off_count', 1)
        ->assertJsonPath('data.catalog_entry_id', $this->model->id);

    expect(ProductModel::find($this->model->id))->toBeNull()
        ->and(Device::count())->toBe(0)
        ->and(Device::withTrashed()->count())->toBe(2);

    $writeOffs = InventoryMovement::where('movement_type', InventoryMovement::TYPE_WRITE_OFF)->get();

    expect($writeOffs)->toHaveCount(1)
        ->and($writeOffs->first()->device_id)->toBe($inStock->id)
        ->and($writeOffs->first()->previous_status)->toBe(InventoryStatus::IN_STOCK->value)
        ->and($writeOffs->first()->new_status)->toBeNull()
        ->and((float) $writeOffs->first()->unit_cost)->toBe(100000.0)
        ->and($writeOffs->first()->reason)->toBe('Discontinued');

    // A sold unit already left stock on its sale movement; writing it off
    // again would double-count the reduction.
    expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_WRITE_OFF)
        ->where('device_id', $sold->id)
        ->exists())->toBeFalse();
});

it('rejects a challenge when the linked devices changed after the PIN was requested', function () {
    catalogDeleteDevice();

    $challengeId = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Discontinued',
        ])
        ->assertStatus(202)
        ->json('data.challenge_id');

    catalogDeleteDevice();

    verifyCatalogDeleteChallenge($challengeId, $this->admin)->assertStatus(422);

    expect(ProductModel::find($this->model->id))->not->toBeNull()
        ->and(Device::count())->toBe(2)
        ->and(InventoryActionChallenge::findOrFail($challengeId)->consumed_at)->toBeNull();
});

it('cascades a brand deletion across its models and devices', function () {
    catalogDeleteDevice();

    $challengeId = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/brands/{$this->brand->id}", [
            'confirm_impact' => true,
            'reason' => 'Brand withdrawn from the market',
        ])
        ->assertStatus(202)
        ->json('data.challenge_id');

    verifyCatalogDeleteChallenge($challengeId, $this->admin)
        ->assertOk()
        ->assertJsonPath('data.action', InventoryActionChallenge::ACTION_BRAND_DELETION)
        ->assertJsonPath('data.written_off_count', 1);

    expect(Brand::find($this->brand->id))->toBeNull()
        ->and(ProductModel::find($this->model->id))->toBeNull()
        ->and(Device::count())->toBe(0)
        ->and(InventoryMovement::where('movement_type', InventoryMovement::TYPE_WRITE_OFF)->count())->toBe(1);
});

it('warns when devices reference the model by text but are not linked to it', function () {
    catalogDeleteDevice();

    Device::factory()->create([
        'category_id' => $this->category->id,
        'brand' => 'Anker',
        'model' => 'PowerCore 26800',
        'brand_id' => null,
        'product_model_id' => null,
        'owner_id' => null,
    ]);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/catalog/models/{$this->model->id}/deletion-impact")
        ->assertOk()
        ->assertJsonPath('data.devices.total', 1)
        ->assertJsonPath('data.devices.unlinked_text_matches', 1);
});

it('flags devices with sale, ownership and warranty history as protected', function () {
    catalogDeleteDevice(['inventory_status' => InventoryStatus::SOLD]);
    catalogDeleteDevice(['owner_id' => User::factory()->create()->id]);

    $this->actingAs($this->admin)
        ->getJson("/api/v1/admin/catalog/models/{$this->model->id}/deletion-impact")
        ->assertOk()
        ->assertJsonPath('data.devices.with_protected_history', 2)
        ->assertJsonPath('data.detail.0.protected', true)
        ->assertJsonPath('data.detail.1.protected', true);
});

it('keeps batch stock consistent with the ledger after the cascade', function () {
    $batch = InventoryBatch::create([
        'batch_number' => InventoryBatch::generateBatchNumber(),
        'branch_id' => $this->branch->id,
        'category_id' => $this->category->id,
        'brand' => 'Anker',
        'model' => 'PowerCore 26800',
        'quantity_received' => 3,
        'buying_price' => 90000,
        'target_selling_price' => 150000,
        'purchase_date' => now()->toDateString(),
    ]);

    // No device-level purchase price, so the write-off cost has to fall back
    // to the batch buying price.
    catalogDeleteDevice(['inventory_batch_id' => $batch->id, 'purchase_price' => null]);
    catalogDeleteDevice(['inventory_batch_id' => $batch->id, 'purchase_price' => null]);
    catalogDeleteDevice([
        'inventory_batch_id' => $batch->id,
        'inventory_status' => InventoryStatus::SOLD,
        'purchase_price' => null,
    ]);

    expect($batch->fresh()->summary()['in_stock'])->toBe(2);

    $challengeId = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Discontinued',
        ])
        ->assertStatus(202)
        ->json('data.challenge_id');

    verifyCatalogDeleteChallenge($challengeId, $this->admin)->assertOk();

    $writeOffs = InventoryMovement::where('movement_type', InventoryMovement::TYPE_WRITE_OFF)->get();

    // Batch totals follow the devices, and the ledger explains the movement.
    expect($batch->fresh()->summary()['in_stock'])->toBe(0)
        ->and($writeOffs)->toHaveCount(2)
        ->and((float) $writeOffs->first()->unit_cost)->toBe(90000.0);
});

it('resends a catalog deletion PIN naming the catalog entry', function () {
    catalogDeleteDevice();

    $challengeId = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Discontinued',
        ])
        ->assertStatus(202)
        ->json('data.challenge_id');

    Notification::fake();
    $this->travel(2)->minutes();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/resend")
        ->assertOk()
        ->assertJsonPath('data.challenge_id', $challengeId)
        ->assertJsonPath('data.device_count', 1);

    Notification::assertSentOnDemand(
        InventoryActionApprovalPinNotification::class,
        fn (InventoryActionApprovalPinNotification $notification) => $notification->deviceLabel === 'Anker PowerCore 26800 (1 device)'
            && $notification->actionLabel === 'product model deletion',
    );
});

it('blocks a non super admin from verifying a catalog deletion challenge', function () {
    catalogDeleteDevice();

    $challengeId = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Discontinued',
        ])
        ->assertStatus(202)
        ->json('data.challenge_id');

    InventoryActionChallenge::whereKey($challengeId)->update(['pin_hash' => Hash::make('123456')]);

    $storeAdmin = User::factory()->create();
    $storeAdmin->assignRole('store_admin');

    \Illuminate\Support\Facades\Auth::forgetGuards();

    $this->actingAs($storeAdmin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/verify", ['pin' => '123456'])
        ->assertForbidden();

    expect(ProductModel::find($this->model->id))->not->toBeNull()
        ->and(Device::count())->toBe(1)
        ->and(InventoryActionChallenge::findOrFail($challengeId)->consumed_at)->toBeNull();
});

it('never reduces stock twice for the same device', function () {
    $movementService = app(InventoryMovementService::class);

    $inStock = catalogDeleteDevice(['inventory_status' => InventoryStatus::IN_STOCK]);
    $reserved = catalogDeleteDevice(['inventory_status' => InventoryStatus::RESERVED]);
    $faulty = catalogDeleteDevice(['inventory_status' => InventoryStatus::FAULTY]);
    $sold = catalogDeleteDevice(['inventory_status' => InventoryStatus::SOLD]);

    // The sold unit already left stock, and the sale flow recorded that outflow
    // on the ledger. Writing it off again would double-count the reduction.
    $movementService->recordDeviceMovement($sold, [
        'movement_type' => InventoryMovement::TYPE_SALE,
        'previous_status' => InventoryStatus::IN_STOCK->value,
        'new_status' => InventoryStatus::SOLD->value,
    ]);

    $challengeId = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Discontinued',
        ])
        ->assertStatus(202)
        ->json('data.challenge_id');

    verifyCatalogDeleteChallenge($challengeId, $this->admin)->assertOk();

    $writeOffs = InventoryMovement::where('movement_type', InventoryMovement::TYPE_WRITE_OFF)->get();

    // Exactly one write-off per on-hand unit, and none for the sold unit.
    expect($writeOffs)->toHaveCount(3)
        ->and($writeOffs->pluck('device_id')->sort()->values()->all())
        ->toBe(collect([$inStock->id, $reserved->id, $faulty->id])->sort()->values()->all())
        ->and($writeOffs->sum('quantity'))->toBe(3)
        ->and($writeOffs->every(fn (InventoryMovement $movement) => $movement->quantity === 1))->toBeTrue();

    // The guard stated directly: no device is reduced by a sale AND a write-off.
    $reducedBySale = InventoryMovement::where('movement_type', InventoryMovement::TYPE_SALE)->pluck('device_id');

    expect($reducedBySale->intersect($writeOffs->pluck('device_id')))->toBeEmpty();

    // And the whole population is reduced exactly once each — four devices,
    // four reducing movements, never five.
    expect(InventoryMovement::whereIn('movement_type', [
        InventoryMovement::TYPE_SALE,
        InventoryMovement::TYPE_WRITE_OFF,
    ])->count())->toBe(4);
});

it('counts every device in exactly one impact bucket', function () {
    foreach ([
        InventoryStatus::IN_STOCK,
        InventoryStatus::RESERVED,
        InventoryStatus::FAULTY,
        InventoryStatus::RETURNED,
    ] as $status) {
        catalogDeleteDevice(['inventory_status' => $status]);
    }
    catalogDeleteDevice(['inventory_status' => InventoryStatus::SOLD]);

    $impact = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}")
        ->assertStatus(409)
        ->json('impact');

    expect($impact['devices']['total'])->toBe(5)
        ->and($impact['devices']['will_be_written_off'])->toBe(4)
        ->and($impact['devices']['already_out_of_stock'])->toBe(1)
        // The two buckets partition the device set: no unit is counted twice.
        ->and($impact['devices']['will_be_written_off'] + $impact['devices']['already_out_of_stock'])
        ->toBe($impact['devices']['total'])
        // Units leaving stock equal the write-offs, never more.
        ->and($impact['stock_impact']['units_leaving_stock'])->toBe(4)
        ->and($impact['stock_impact']['units_already_out_of_stock'])->toBe(1);
});

it('removes exactly as many units from stock as it writes off', function () {
    $batch = InventoryBatch::create([
        'batch_number' => InventoryBatch::generateBatchNumber(),
        'branch_id' => $this->branch->id,
        'category_id' => $this->category->id,
        'brand' => 'Anker',
        'model' => 'PowerCore 26800',
        'quantity_received' => 5,
        'buying_price' => 90000,
        'target_selling_price' => 150000,
        'purchase_date' => now()->toDateString(),
    ]);

    $soldDevice = catalogDeleteDevice([
        'inventory_batch_id' => $batch->id,
        'inventory_status' => InventoryStatus::SOLD,
    ]);

    foreach ([
        InventoryStatus::IN_STOCK,
        InventoryStatus::IN_STOCK,
        InventoryStatus::IN_STOCK,
        InventoryStatus::RESERVED,
    ] as $status) {
        catalogDeleteDevice(['inventory_batch_id' => $batch->id, 'inventory_status' => $status]);
    }

    $before = $batch->fresh()->summary();
    $onHandBefore = $before['in_stock'] + $before['reserved'] + $before['returned'] + $before['faulty'];

    expect($onHandBefore)->toBe(4);

    $challengeId = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Discontinued',
        ])
        ->assertStatus(202)
        ->json('data.challenge_id');

    verifyCatalogDeleteChallenge($challengeId, $this->admin)->assertOk();

    $after = $batch->fresh()->summary();
    $onHandAfter = $after['in_stock'] + $after['reserved'] + $after['returned'] + $after['faulty'];
    $writeOffs = InventoryMovement::where('movement_type', InventoryMovement::TYPE_WRITE_OFF)->get();
    $writtenOffUnits = (int) $writeOffs->sum('quantity');

    // Conservation: the units that left stock equal the units the ledger says
    // were written off. A double-count makes these two figures disagree.
    expect($onHandBefore - $onHandAfter)->toBe($writtenOffUnits)
        ->and($onHandAfter)->toBe(0)
        ->and($writtenOffUnits)->toBe(4);

    // The sold unit is deleted along with the rest of the model, but it is
    // never written off — its outflow is already on the sale movement.
    expect($writeOffs->where('device_id', $soldDevice->id))->toBeEmpty()
        ->and(Device::withTrashed()->count())->toBe(5)
        // Consequence worth pinning down: the soft-deleted sold unit drops out
        // of the batch's device breakdown, so the batch shows sold: 0 while its
        // sale record still carries the revenue.
        ->and($after['sold'])->toBe(0);
});

it('does not double-apply the write-off when a verified PIN is replayed', function () {
    $device = catalogDeleteDevice();

    $challengeId = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Discontinued',
        ])
        ->assertStatus(202)
        ->json('data.challenge_id');

    verifyCatalogDeleteChallenge($challengeId, $this->admin)->assertOk();

    $writeOffQuery = fn () => InventoryMovement::where('movement_type', InventoryMovement::TYPE_WRITE_OFF);

    expect($writeOffQuery()->count())->toBe(1)
        ->and((float) $writeOffQuery()->sum('total_cost'))->toBe(100000.0);

    // Replay the exact same challenge with the same PIN.
    \Illuminate\Support\Facades\Auth::forgetGuards();

    verifyCatalogDeleteChallenge($challengeId, $this->admin)
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    // The reduction is not applied a second time, and no second cost is booked.
    expect($writeOffQuery()->count())->toBe(1)
        ->and((float) $writeOffQuery()->sum('total_cost'))->toBe(100000.0)
        ->and(Device::withTrashed()->find($device->id))->not->toBeNull();
});

it('keeps a device location history when the catalog entry is deleted', function () {
    $device = catalogDeleteDevice();
    $location = DeviceLocation::factory()->create(['device_id' => $device->id]);

    $challengeId = $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/catalog/models/{$this->model->id}", [
            'confirm_impact' => true,
            'reason' => 'Discontinued',
        ])
        ->assertStatus(202)
        ->json('data.challenge_id');

    verifyCatalogDeleteChallenge($challengeId, $this->admin)->assertOk();

    // Telemetry is not collateral damage: the device is soft-deleted, so its
    // trail survives instead of being destroyed with it.
    expect(DeviceLocation::find($location->id))->not->toBeNull()
        ->and(Device::find($device->id))->toBeNull()
        ->and(Device::withTrashed()->find($device->id))->not->toBeNull();
});
