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
use App\Notifications\InventoryActionApprovalPinNotification;
use App\Notifications\InventoryActionPinAvailableNotification;
use App\Services\InventoryMovementService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    config(['inventory.approval_email' => 'elijahpchitedze@gmail.com']);

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
    Notification::fake();

    $request = $this->actingAs($this->admin)->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
        'selling_price' => 150000,
        'reason' => 'Sold directly to a staff member',
    ])->assertCreated();

    $challengeId = $request->json('data.challenge_id');
    expect($request->json('data.masked_email'))->toBe('e**************@gmail.com');
    Notification::assertSentOnDemand(InventoryActionApprovalPinNotification::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'elijahpchitedze@gmail.com';
    });
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

it('delivers metadata-only in-app PIN notifications to the initiating administrator', function () {
    $device = dispositionDevice();
    Notification::fake();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
            'selling_price' => 150000,
            'reason' => 'In-app delivery test',
        ])
        ->assertCreated()
        ->assertJsonMissing(['pin']);

    $challengeId = $response->json('data.challenge_id');
    $plainPin = null;
    Notification::assertSentOnDemand(InventoryActionApprovalPinNotification::class, function ($notification) use (&$plainPin) {
        $plainPin = $notification->pin;

        return true;
    });
    Notification::assertSentTo($this->admin, InventoryActionPinAvailableNotification::class, function ($notification) use ($challengeId) {
        $data = $notification->toArray($this->admin);

        return $data['challenge_id'] === $challengeId
            && $data['type'] === 'inventory_action_pin'
            && ! array_key_exists('pin', $data);
    });

    $challenge = InventoryActionChallenge::findOrFail($challengeId);
    $cached = Cache::get("inventory-action-pin:{$challengeId}");
    expect($plainPin)->toMatch('/^\d{6}$/')
        ->and($challenge->toArray())->not->toHaveKey('pin')
        ->and($challenge->pin_hash)->not->toContain($plainPin)
        ->and($cached)->toBeString()
        ->and($cached)->not->toContain($plainPin);
});

it('reveals the emailed PIN after password confirmation without consuming a verification attempt', function () {
    $device = dispositionDevice();
    Notification::fake();
    $emailedPin = null;

    $challengeId = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/devices/{$device->id}/delete/request", [
            'reason' => 'Secure reveal test',
        ])
        ->assertCreated()
        ->json('data.challenge_id');

    Notification::assertSentOnDemand(InventoryActionApprovalPinNotification::class, function ($notification) use (&$emailedPin) {
        $emailedPin = $notification->pin;

        return true;
    });

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/reveal", ['password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
    expect(InventoryActionChallenge::findOrFail($challengeId)->attempts)->toBe(0);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/reveal", ['password' => 'password'])
        ->assertSuccessful()
        ->assertJsonPath('data.pin', $emailedPin);

    expect($response->json('data.pin'))->toBe($emailedPin)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(InventoryActionChallenge::findOrFail($challengeId)->attempts)->toBe(0);
});

it('does not allow another administrator to reveal an initiators PIN', function () {
    $device = dispositionDevice();
    Notification::fake();
    $challengeId = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/devices/{$device->id}/delete/request", ['reason' => 'Ownership test'])
        ->assertCreated()
        ->json('data.challenge_id');

    $otherAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
    $otherAdmin->assignRole('super_admin');

    $this->actingAs($otherAdmin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/reveal", ['password' => 'password'])
        ->assertForbidden();
});

it('marks the persisted in-app notification read on reveal and completed on verification', function () {
    $device = dispositionDevice();
    $challengeId = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/devices/{$device->id}/delete/request", ['reason' => 'Notification lifecycle test'])
        ->assertCreated()
        ->json('data.challenge_id');

    $notification = $this->admin->notifications()
        ->where('data->type', 'inventory_action_pin')
        ->where('data->challenge_id', $challengeId)
        ->firstOrFail();
    expect($notification->data['status'])->toBe('active')
        ->and($notification->data)->not->toHaveKey('pin')
        ->and($notification->read_at)->toBeNull();

    $pin = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/reveal", ['password' => 'password'])
        ->assertSuccessful()
        ->json('data.pin');
    expect($notification->fresh()->read_at)->not->toBeNull();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/verify", ['pin' => $pin])
        ->assertSuccessful();
    expect($notification->fresh()->data['status'])->toBe('completed')
        ->and(Cache::get("inventory-action-pin:{$challengeId}"))->toBeNull();
});

it('replaces the in-app PIN on resend and allows resending an expired challenge', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $device = dispositionDevice();
    Notification::fake();
    $challengeId = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/devices/{$device->id}/delete/request", ['reason' => 'Resend test'])
        ->assertCreated()
        ->json('data.challenge_id');

    $firstPin = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/reveal", ['password' => 'password'])
        ->assertSuccessful()
        ->json('data.pin');

    InventoryActionChallenge::whereKey($challengeId)->update([
        'last_sent_at' => now()->subMinutes(2),
        'expires_at' => now()->subMinute(),
    ]);
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/resend")
        ->assertSuccessful();

    $secondPin = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/reveal", ['password' => 'password'])
        ->assertSuccessful()
        ->json('data.pin');

    expect($secondPin)->not->toBe($firstPin);
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/verify", ['pin' => $firstPin])
        ->assertUnprocessable();
    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/action-challenges/{$challengeId}/verify", ['pin' => $secondPin])
        ->assertSuccessful();
    expect(Cache::get("inventory-action-pin:{$challengeId}"))->toBeNull();
});

it('defaults an administrative sale to purchase cost when no batch target price exists', function () {
    $device = dispositionDevice();

    $request = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
            'reason' => 'Fallback pricing test',
        ])
        ->assertCreated();

    expect($request->json('data.financial.unit_price'))->toBe(100000)
        ->and($request->json('data.financial.pricing_source'))->toBe('device.purchase_price');
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

it('rejects a negative administrative sale amount', function () {
    $device = dispositionDevice();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/inventory/{$device->id}/administrative-sale/request", [
            'selling_price' => -1,
            'reason' => 'Invalid negative amount',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('selling_price');

    expect($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK)
        ->and(InventoryActionChallenge::count())->toBe(0)
        ->and(InventoryMovement::where('movement_type', InventoryMovement::TYPE_ADMINISTRATIVE_SALE)->count())->toBe(0);
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

it('deletes multiple devices with one PIN challenge and returns the complete result', function () {
    $devices = [
        dispositionDevice(['inventory_batch_id' => null]),
        dispositionDevice(['inventory_batch_id' => null]),
    ];
    Notification::fake();

    $challenge = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/devices/bulk-delete/request', [
            'device_ids' => collect($devices)->pluck('id')->all(),
            'reason' => 'OVERSTOCK',
            'notes' => 'Bulk cleanup',
        ])
        ->assertCreated()
        ->assertJsonPath('data.device_count', 2)
        ->json('data.challenge_id');

    $result = verifyDispositionChallenge($challenge, $this->admin)
        ->assertSuccessful()
        ->assertJsonPath('data.deleted_count', 2);

    expect($result->json('data.deleted_device_ids'))
        ->toEqualCanonicalizing(collect($devices)->pluck('id')->all());

    foreach ($devices as $device) {
        expect(Device::withTrashed()->find($device->id)->deleted_at)->not->toBeNull();
    }
});

it('warns for tracked devices in a bulk deletion and then deletes all after explicit confirmation', function () {
    $batch = InventoryBatch::create([
        'batch_number' => 'BATCH-DSP-BULK-DELETE-00001',
        'branch_id' => $this->branch->id,
        'category_id' => $this->category->id,
        'brand' => 'Tracked',
        'model' => 'Bulk Device',
        'quantity_received' => 1,
        'buying_price' => 100000,
        'target_selling_price' => 150000,
    ]);
    $tracked = dispositionDevice(['inventory_batch_id' => $batch->id]);
    app(InventoryMovementService::class)->recordReceipt($tracked, $batch, null, $this->admin);
    $clean = dispositionDevice(['inventory_batch_id' => null]);
    $deviceIds = [$tracked->id, $clean->id];

    $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/devices/bulk-delete/request', [
            'device_ids' => $deviceIds,
            'reason' => 'OVERSTOCK',
        ])
        ->assertStatus(409)
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('errors.device_ids.0', 'Some selected devices have inventory history. Proceeding will soft-delete them while preserving the existing audit movements.');

    $challenge = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/devices/bulk-delete/request', [
            'device_ids' => $deviceIds,
            'reason' => 'OVERSTOCK',
            'confirm_history_loss' => true,
        ])
        ->assertCreated()
        ->json('data.challenge_id');

    verifyDispositionChallenge($challenge, $this->admin)->assertSuccessful();

    expect(Device::withTrashed()->find($tracked->id)->deleted_at)->not->toBeNull()
        ->and(Device::withTrashed()->find($clean->id)->deleted_at)->not->toBeNull();
});

it('keeps a bulk deletion atomic when a selected device becomes protected before PIN verification', function () {
    $first = dispositionDevice(['inventory_batch_id' => null]);
    $second = dispositionDevice(['inventory_batch_id' => null]);
    $challenge = $this->actingAs($this->admin)
        ->postJson('/api/v1/admin/devices/bulk-delete/request', [
            'device_ids' => [$first->id, $second->id],
            'reason' => 'Atomicity test',
        ])
        ->assertCreated()
        ->json('data.challenge_id');

    $second->update(['owner_id' => $this->admin->id]);

    verifyDispositionChallenge($challenge, $this->admin)
        ->assertStatus(422)
        ->assertJsonValidationErrors('device');

    expect(Device::find($first->id))->not->toBeNull()
        ->and(Device::find($second->id))->not->toBeNull()
        ->and(InventoryActionChallenge::find($challenge)->consumed_at)->toBeNull();
});

it('blocks legacy deletion and requires a PIN for a clean device deletion', function () {
    $device = dispositionDevice(['inventory_batch_id' => null]);
    Notification::fake();

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/admin/devices/{$device->id}")
        ->assertStatus(403);

    $challenge = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/devices/{$device->id}/delete/request", [
            'reason' => 'OVERSTOCK',
            'notes' => 'admin',
        ])
        ->assertCreated()
        ->json('data.challenge_id');

    Notification::assertSentOnDemand(InventoryActionApprovalPinNotification::class, function ($notification, $channels, $notifiable) {
        return $notifiable->routes['mail'] === 'elijahpchitedze@gmail.com';
    });

    verifyDispositionChallenge($challenge, $this->admin)->assertSuccessful();

    expect(Device::withTrashed()->find($device->id)->deleted_at)->not->toBeNull();
});

it('warns before deleting tracked inventory and allows explicit PIN-confirmed override', function () {
    $batch = InventoryBatch::create([
        'batch_number' => 'BATCH-DSP-DELETE-00001',
        'branch_id' => $this->branch->id,
        'category_id' => $this->category->id,
        'brand' => 'Tracked',
        'model' => 'Inventory Device',
        'quantity_received' => 1,
        'buying_price' => 100000,
        'target_selling_price' => 150000,
    ]);
    $device = dispositionDevice(['inventory_batch_id' => $batch->id]);
    app(InventoryMovementService::class)->recordReceipt($device, $batch, null, $this->admin);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/devices/{$device->id}/delete/request", [
            'reason' => 'OVERSTOCK',
            'notes' => 'admin',
        ])
        ->assertStatus(409)
        ->assertJsonPath('requires_confirmation', true)
        ->assertJsonPath('errors.device.0', 'This device has inventory history. Proceeding will soft-delete the device while preserving the existing audit movements.');

    $challenge = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/devices/{$device->id}/delete/request", [
            'reason' => 'OVERSTOCK',
            'notes' => 'admin',
            'confirm_history_loss' => true,
        ])
        ->assertCreated()
        ->json('data.challenge_id');

    verifyDispositionChallenge($challenge, $this->admin)->assertSuccessful();
    expect(Device::withTrashed()->find($device->id)->deleted_at)->not->toBeNull()
        ->and(InventoryMovement::where('movement_type', InventoryMovement::TYPE_RECEIPT)->where('device_id', $device->id)->exists())->toBeTrue();
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
