<?php

use App\Enums\InventoryStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryActionChallenge;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->branch = Branch::create([
        'name' => 'Disposition Branch',
        'code' => 'DSP',
        'is_active' => true,
    ]);
    $this->category = DeviceCategory::factory()->create();
    $this->admin = User::factory()->create(['email' => 'admin@example.test', 'branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');
});

function dispositionDevice(array $overrides = []): Device
{
    return Device::factory()->create(array_merge([
        'category_id' => test()->category->id,
        'branch_id' => test()->branch->id,
        'owner_id' => null,
        'inventory_status' => InventoryStatus::IN_STOCK,
        'purchase_price' => 100000,
    ], $overrides));
}

function verifyDispositionChallenge(int $challengeId, User $admin, string $pin = '123456')
{
    InventoryActionChallenge::whereKey($challengeId)->update(['pin_hash' => Hash::make($pin)]);

    return test()->actingAs($admin)->postJson("/api/v1/admin/action-challenges/{$challengeId}/verify", ['pin' => $pin]);
}

it('records an administrative sale with financial snapshots and no POS sale', function () {
    $device = dispositionDevice();

    $request = $this->actingAs($this->admin)->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
        'selling_price' => 150000,
        'reason' => 'Sold directly to a staff member',
    ])->assertCreated();

    $challengeId = $request->json('data.challenge_id');
    expect($request->json('data.masked_email'))->toBe('a****@example.test');
    expect($request->json('data.financial'))->toMatchArray([
        'unit_cost' => 100000,
        'unit_price' => 150000,
        'total_cost' => 100000,
        'total_amount' => 150000,
        'profit_amount' => 50000,
    ]);

    $response = verifyDispositionChallenge($challengeId, $this->admin);
    $response->assertSuccessful()
        ->assertJsonPath('data.movement.movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)
        ->assertJsonPath('data.movement.total_cost', '100000.00')
        ->assertJsonPath('data.movement.total_amount', '150000.00')
        ->assertJsonPath('data.movement.profit_amount', '50000.00');

    $device->refresh();
    $movement = InventoryMovement::where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)->firstOrFail();

    expect($device->inventory_status)->toBe(InventoryStatus::SOLD)
        ->and($device->sale_id)->toBeNull()
        ->and($device->owner_id)->toBeNull()
        ->and($device->sold_by_id)->toBe($this->admin->id)
        ->and((float) $movement->unit_cost)->toBe(100000.0)
        ->and((float) $movement->unit_price)->toBe(150000.0)
        ->and((float) $movement->total_cost)->toBe(100000.0)
        ->and((float) $movement->total_amount)->toBe(150000.0)
        ->and((float) $movement->profit_amount)->toBe(50000.0)
        ->and($movement->sale_id)->toBeNull()
        ->and(Sale::count())->toBe(0);
});

it('records negative administrative profit as a loss', function () {
    $device = dispositionDevice(['purchase_price' => 100000]);

    $challenge = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
            'selling_price' => 75000,
            'reason' => 'Approved clearance sale',
        ])
        ->assertCreated()
        ->json('data.challenge_id');

    verifyDispositionChallenge($challenge, $this->admin)->assertSuccessful();

    expect((float) InventoryMovement::where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)->value('profit_amount'))->toBe(-25000.0);
});

it('rejects an administrative sale when cost cannot be resolved', function () {
    $device = dispositionDevice(['purchase_price' => null, 'inventory_batch_id' => null]);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
            'selling_price' => 75000,
            'reason' => 'No cost record',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('device');
});

it('invalidates the previous PIN challenge when the amount is changed', function () {
    $device = dispositionDevice();

    $first = $this->actingAs($this->admin)->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
        'selling_price' => 150000,
        'reason' => 'First amount',
    ])->assertCreated()->json('data.challenge_id');
    $second = $this->actingAs($this->admin)->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
        'selling_price' => 125000,
        'reason' => 'Changed amount',
    ])->assertCreated()->json('data.challenge_id');

    expect(InventoryActionChallenge::find($first)->invalidated_at)->not->toBeNull();

    InventoryActionChallenge::whereKey($first)->update(['pin_hash' => Hash::make('123456')]);
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$first}/verify", ['pin' => '123456'])
        ->assertStatus(422);

    expect(InventoryActionChallenge::find($second)->invalidated_at)->toBeNull();
});

it('rejects sold through generic inventory status and adjustment endpoints', function () {
    $device = dispositionDevice();

    $this->actingAs($this->admin)
        ->putJson("/api/v1/admin/inventory/{$device->id}/status", ['inventory_status' => 'sold'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('inventory_status');

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/stock-movements/adjustments', [
            'device_ids' => [$device->id],
            'inventory_status' => 'sold',
            'reason' => 'Should not bypass sale flow',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('inventory_status');
});

it('uses the inventory batch buying price when the device cost is absent', function () {
    $batch = InventoryBatch::create([
        'batch_number' => 'BATCH-DSP-00001',
        'branch_id' => $this->branch->id,
        'category_id' => $this->category->id,
        'brand' => 'Test',
        'model' => 'Batch Device',
        'quantity_received' => 1,
        'buying_price' => 80000,
        'target_selling_price' => 120000,
    ]);
    $device = dispositionDevice([
        'inventory_batch_id' => $batch->id,
        'purchase_price' => null,
    ]);

    $challenge = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
            'reason' => 'Batch pricing source',
        ])
        ->assertCreated();

    expect($challenge->json('data.financial.pricing_source'))->toBe('inventory_batch.buying_price')
        ->and($challenge->json('data.financial.unit_price'))->toBe(120000);

    verifyDispositionChallenge($challenge->json('data.challenge_id'), $this->admin)->assertSuccessful();
    $summary = $batch->fresh()->summary();
    expect($summary['pos_revenue'])->toBe(0.0)
        ->and($summary['administrative_sale_value'])->toBe(120000.0)
        ->and($summary['administrative_sale_profit'])->toBe(40000.0)
        ->and($summary['combined_inventory_realized_value'])->toBe(120000.0)
        ->and($summary['combined_gross_profit_loss'])->toBe(40000.0);

    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/reports/stock')
        ->assertSuccessful()
        ->assertJsonPath('data.summary.administrative_sale_value', 120000)
        ->assertJsonPath('data.summary.combined_inventory_realized_value', 120000);
});

it('blocks legacy deletion and requires a PIN for a clean device deletion', function () {
    $device = dispositionDevice(['inventory_batch_id' => null]);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/devices/{$device->id}")
        ->assertStatus(403);

    $challenge = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/devices/{$device->id}/delete/request", [
            'reason' => 'Duplicate device record',
        ])
        ->assertCreated()
        ->json('data.challenge_id');

    verifyDispositionChallenge($challenge, $this->admin)->assertSuccessful();

    expect(Device::withTrashed()->find($device->id)->deleted_at)->not->toBeNull();
});

it('enforces expiry, failed-attempt limits, and one-time replay protection', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $device = dispositionDevice();
    $challenge = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
            'selling_price' => 150000,
            'reason' => 'Security test',
        ])
        ->assertCreated()
        ->json('data.challenge_id');

    InventoryActionChallenge::whereKey($challenge)->update(['pin_hash' => Hash::make('123456')]);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/action-challenges/{$challenge}/verify", ['pin' => '000000'])
            ->assertStatus(422);
    }
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challenge}/verify", ['pin' => '123456'])
        ->assertStatus(422);
    expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)->count())->toBe(0);

    $replayDevice = dispositionDevice();
    $replayChallenge = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/inventory/{$replayDevice->id}/administrative-sale/request", [
            'selling_price' => 150000,
            'reason' => 'Replay test',
        ])
        ->assertCreated()
        ->json('data.challenge_id');
    verifyDispositionChallenge($replayChallenge, $this->admin)->assertSuccessful();
    verifyDispositionChallenge($replayChallenge, $this->admin)->assertStatus(422);
    expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)->count())->toBe(1);

    $expiredDevice = dispositionDevice();
    $expiredChallenge = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/inventory/{$expiredDevice->id}/administrative-sale/request", [
            'selling_price' => 150000,
            'reason' => 'Expiry test',
        ])
        ->assertCreated()
        ->json('data.challenge_id');
    InventoryActionChallenge::whereKey($expiredChallenge)->update([
        'pin_hash' => Hash::make('123456'),
        'expires_at' => now()->subMinute(),
    ]);
    verifyDispositionChallenge($expiredChallenge, $this->admin)->assertStatus(422);
    expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)->count())->toBe(1);
});
