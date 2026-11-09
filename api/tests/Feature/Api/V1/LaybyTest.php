<?php

use App\Enums\InventoryStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\LaybyEntry;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(now()->setDate(2026, 11, 9)->setTime(8, 0));
    $this->branch = Branch::create(['name' => 'Layby pilot', 'code' => 'LB1', 'is_active' => true]);
    $this->otherBranch = Branch::create(['name' => 'Other shop', 'code' => 'LB2', 'is_active' => true]);
    $this->manager = User::factory()->create(['branch_id' => $this->branch->id])->assignRole('store_admin');
    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->cashier = User::factory()->create(['branch_id' => $this->branch->id])->assignRole('customer_service');
    $this->otherCashier = User::factory()->create(['branch_id' => $this->otherBranch->id])->assignRole('customer_service');
    $this->customer = User::factory()->create()->assignRole('customer');
    $this->shift = Shift::create(['branch_id' => $this->branch->id, 'user_id' => $this->cashier->id, 'started_at' => now(), 'status' => 'active']);
    $this->device = Device::factory()->create([
        'branch_id' => $this->branch->id, 'owner_id' => null, 'inventory_status' => InventoryStatus::IN_STOCK,
        'status' => 'active', 'purchase_price' => 500,
    ]);
    $batch = InventoryBatch::create([
        'batch_number' => 'LB-BATCH', 'branch_id' => $this->branch->id, 'category_id' => $this->device->category_id,
        'brand' => $this->device->brand, 'model' => $this->device->model, 'quantity_received' => 1,
        'buying_price' => 500, 'target_selling_price' => 1000, 'received_by_id' => $this->admin->id,
    ]);
    $this->device->update(['inventory_batch_id' => $batch->id]);
    $this->policy = [
        'enabled' => true, 'deposit_percent' => 20, 'term_days' => 30, 'vat_basis_points' => 1750,
        'review_reference' => 'Approved pilot review', 'merchant_channels' => ['mobile_money' => 'LAYBY-PILOT-01'],
    ];
});

function laybyPost($test, User $actor, string $path, array $payload, ?string $key = null)
{
    return $test->actingAs($actor)->postJson('/api/v1/laybys'.$path, $payload, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
}

function enableLayby($test): void
{
    $test->actingAs($test->admin)->putJson('/api/v1/laybys/policy/'.$test->branch->id, $test->policy)->assertOk();
}

function laybyQuote($test): array
{
    return laybyPost($test, $test->cashier, '/quote', ['device_id' => $test->device->id, 'customer_id' => $test->customer->id])
        ->assertOk()->assertJsonPath('total_minor', 117500)->assertJsonPath('minimum_deposit_minor', 23500)->json();
}

function laybyOpen($test): array
{
    enableLayby($test);

    return laybyPost($test, $test->cashier, '', [
        'quote' => laybyQuote($test)['quote'], 'amount_minor' => 23500, 'terms_accepted' => true, 'cash_received' => true,
    ])->assertCreated()->json();
}

it('gates opening, freezes the quote, and returns the original response on retry', function () {
    laybyPost($this, $this->cashier, '/quote', ['device_id' => $this->device->id, 'customer_id' => $this->customer->id])->assertUnprocessable();
    $this->actingAs($this->admin)->putJson('/api/v1/laybys/policy/'.$this->branch->id, $this->policy + ['unused' => true])
        ->assertOk();
    $key = (string) Str::uuid();
    $payload = ['quote' => laybyQuote($this)['quote'], 'amount_minor' => 23500, 'terms_accepted' => true, 'cash_received' => true];
    $open = laybyPost($this, $this->cashier, '', $payload, $key)->assertCreated()
        ->assertJsonPath('due_date', '2026-12-09')->assertJsonPath('balance_minor', 94000)->json();
    laybyPost($this, $this->cashier, '', $payload, $key)->assertCreated()->assertExactJson($open);
    laybyPost($this, $this->cashier, '', array_replace($payload, ['amount_minor' => 30000]), $key)->assertConflict();
    laybyPost($this, $this->cashier, '', $payload)->assertUnprocessable();
    expect($this->device->fresh()->reserved_layby_id)->toBe($open['id'])
        ->and($this->device->fresh()->inventory_status)->toBe(InventoryStatus::RESERVED)
        ->and(Sale::count())->toBe(0)->and(LaybyEntry::where('kind', 'collection')->count())->toBe(1);
});

it('keeps layby reservations out of inventory mutation and ordinary sales paths', function () {
    $open = laybyOpen($this);
    $id = $this->device->id;
    $this->actingAs($this->admin)->putJson("/api/v1/admin/inventory/{$id}/status", ['inventory_status' => 'in_stock'])->assertUnprocessable();
    $this->putJson("/api/v1/admin/devices/{$id}", ['serial_number' => 'SUBSTITUTE'])->assertUnprocessable();
    $this->putJson("/api/v1/devices/{$id}", ['serial_number' => 'SUBSTITUTE'])->assertUnprocessable();
    $this->postJson('/api/v1/admin/stock-movements/adjustments', ['device_ids' => [$id], 'inventory_status' => 'in_stock', 'reason' => 'Test'])->assertUnprocessable();
    $this->postJson('/api/v1/admin/sales', ['customer_id' => $this->customer->id, 'payment_method' => 'cash',
        'payment_amount' => 1200, 'items' => [['device_id' => $id]]])->assertUnprocessable();
    $this->actingAs($this->otherCashier)->getJson('/api/v1/laybys/'.$open['id'])->assertForbidden();
    $this->getJson('/api/v1/laybys')->assertJsonCount(0, 'data');
    expect($this->device->fresh()->reserved_layby_id)->toBe($open['id']);
});

it('settles across shifts, records pickup once, and posts a dated return separately', function () {
    $open = laybyOpen($this);
    $path = '/'.$open['id'];
    $pickup = ['serial_number' => $this->device->serial_number, 'evidence' => 'Customer and serial checked', 'verified_pickup' => true];
    laybyPost($this, $this->cashier, $path.'/handover', $pickup)->assertUnprocessable();
    laybyPost($this, $this->cashier, $path.'/collect', ['tender' => 'cash', 'amount_minor' => 94001, 'cash_received' => true])->assertUnprocessable();
    laybyPost($this, $this->cashier, $path.'/collect', ['tender' => 'cash', 'amount_minor' => 20000, 'cash_received' => true])
        ->assertOk()->assertJsonPath('balance_minor', 74000);
    $this->shift->update(['status' => 'completed', 'ended_at' => now()]);
    $this->travel(1)->day();
    $pickupShift = Shift::create(['branch_id' => $this->branch->id, 'user_id' => $this->cashier->id, 'started_at' => now(), 'status' => 'active']);
    $this->actingAs($this->admin)->putJson('/api/v1/laybys/policy/'.$this->branch->id, array_replace($this->policy, ['enabled' => false]))->assertOk();
    laybyPost($this, $this->cashier, $path.'/collect', ['tender' => 'cash', 'amount_minor' => 74000, 'cash_received' => true])
        ->assertOk()->assertJsonPath('status', 'ready');
    expect(Sale::count())->toBe(0)->and($this->device->fresh()->owner_id)->toBeNull();
    laybyPost($this, $this->cashier, $path.'/handover', array_replace($pickup, ['serial_number' => 'WRONG']))->assertUnprocessable();
    $key = (string) Str::uuid();
    $sale = laybyPost($this, $this->cashier, $path.'/handover', $pickup, $key)->assertOk()->assertJsonPath('status', 'fulfilled')->json('sale_id');
    laybyPost($this, $this->cashier, $path.'/handover', $pickup, $key)->assertOk()->assertJsonPath('sale_id', $sale);
    expect(Sale::count())->toBe(1)->and($this->device->fresh()->owner_id)->toBe($this->customer->id)
        ->and($this->shift->laybyTenderTotals()['cash']['collection'])->toBe(43500)
        ->and($pickupShift->laybyTenderTotals()['cash']['collection'])->toBe(74000)
        ->and((float) $pickupShift->cash_sales)->toBe(0.0);
    $this->actingAs($this->admin)->getJson('/api/v1/admin/sales/'.$sale)
        ->assertOk()->assertJsonPath('data.tender_breakdown_minor.cash', 117500);
    $this->postJson('/api/v1/admin/sales/'.$sale.'/refund', ['reason' => 'Wrong endpoint'])->assertUnprocessable();
    $this->travel(31)->days();
    laybyPost($this, $this->manager, $path.'/return', [
        'faulty' => true, 'evidence' => 'Returned and inspected in branch', 'physically_received' => true,
        'serial_number' => $this->device->serial_number,
    ])->assertOk()->assertJsonPath('refund_due_minor', 117500);
    expect($this->device->fresh()->inventory_status)->toBe(InventoryStatus::FAULTY)
        ->and(Sale::completedForReporting()->count())->toBe(1)
        ->and(InventoryMovement::where('sale_id', $sale)->where('movement_type', InventoryMovement::TYPE_REFUND)->first()->new_status)->toBe('faulty');
    $this->getJson('/api/v1/laybys/report?from=2026-12-01&to=2026-12-31')
        ->assertOk()->assertJsonPath('returns.data.0.sale_id', $sale)->assertJsonPath('refunds_due_minor', 117500);
});

it('requires independent merchant verification and caps outstanding payment submissions', function () {
    $open = laybyOpen($this);
    $path = '/'.$open['id'];
    $collect = ['tender' => 'mobile_money', 'reference' => 'MM-00010', 'amount_minor' => 94000];
    $entry = laybyPost($this, $this->cashier, $path.'/collect', $collect)->assertOk()
        ->assertJsonPath('balance_minor', 94000)->assertJsonPath('entries.1.kind', 'submitted')->json('entries.1.id');
    laybyPost($this, $this->cashier, $path.'/collect', ['tender' => 'cash', 'amount_minor' => 1000, 'cash_received' => true])->assertUnprocessable();
    laybyPost($this, $this->manager, $path.'/cancel', ['faulty' => false, 'evidence' => 'Customer request'])->assertUnprocessable();
    $verify = ['entry_id' => $entry, 'evidence' => 'Matched merchant statement'];
    laybyPost($this, $this->cashier, $path.'/verify', $verify)->assertForbidden();
    laybyPost($this, $this->manager, $path.'/verify', $verify)->assertOk()->assertJsonPath('status', 'ready');
    laybyPost($this, $this->manager, $path.'/verify', $verify)->assertUnprocessable();
});

it('blocks late collections, allows an agreed extension, and keeps refund and correction histories separate', function () {
    $open = laybyOpen($this);
    $path = '/'.$open['id'];
    $this->shift->update(['status' => 'completed', 'ended_at' => now()]);
    $this->travel(31)->days();
    Shift::create(['branch_id' => $this->branch->id, 'user_id' => $this->cashier->id, 'started_at' => now(), 'status' => 'active']);
    $payment = ['tender' => 'cash', 'amount_minor' => 1000, 'cash_received' => true];
    laybyPost($this, $this->cashier, $path.'/collect', $payment)->assertUnprocessable();
    laybyPost($this, $this->manager, $path.'/extend', ['due_date' => '2026-12-31', 'evidence' => 'Customer agreed in branch'])->assertOk();
    $entry = laybyPost($this, $this->cashier, $path.'/collect', $payment)->assertOk()->json('entries.2.id');
    laybyPost($this, $this->manager, $path.'/correct', ['entry_id' => $entry, 'reason' => 'erroneous_posting', 'evidence' => 'Till log confirms no cash was taken'])
        ->assertOk()->assertJsonPath('received_minor', 23500);
    laybyPost($this, $this->manager, $path.'/cancel', ['faulty' => false, 'evidence' => 'Customer requested cancellation'])
        ->assertOk()->assertJsonPath('refund_due_minor', 23500);
    laybyPost($this, $this->manager, $path.'/refund-approve', ['entry_id' => $entry, 'evidence' => 'Would pay false deposit'])->assertUnprocessable();
    $source = ['entry_id' => $open['entries'][0]['id'], 'evidence' => 'Approved cash refund'];
    laybyPost($this, $this->manager, $path.'/refund-approve', $source)->assertOk();
    laybyPost($this, $this->manager, $path.'/refund-claim', $source)->assertForbidden();
    $entries = laybyPost($this, $this->cashier, $path.'/refund-claim', $source)->assertOk()->json('entries');
    $claim = end($entries)['id'];
    $result = $source + ['claim_id' => $claim, 'outcome' => 'uncertain'];
    laybyPost($this, $this->cashier, $path.'/refund-result', $result)->assertOk()->assertJsonPath('refund_due_minor', 23500);
    laybyPost($this, $this->cashier, $path.'/refund-claim', $source)->assertUnprocessable();
    laybyPost($this, $this->admin, $path.'/refund-result', array_replace($result, ['outcome' => 'paid', 'reference' => 'CASH-REF-022']))
        ->assertOk()->assertJsonPath('refund_due_minor', 0);
    expect($this->device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK)
        ->and(LaybyEntry::where('kind', 'refund')->count())->toBe(1);
});

it('uses a merchant reference once per channel and records external fiscal documents independently', function () {
    $first = laybyOpen($this);
    $anotherDevice = Device::factory()->create(['branch_id' => $this->branch->id, 'owner_id' => null,
        'status' => 'active', 'inventory_status' => InventoryStatus::IN_STOCK, 'inventory_batch_id' => $this->device->inventory_batch_id]);
    $quote = laybyPost($this, $this->cashier, '/quote', ['device_id' => $anotherDevice->id, 'customer_id' => $this->customer->id])
        ->assertOk()->json('quote');
    $second = laybyPost($this, $this->cashier, '', [
        'quote' => $quote, 'amount_minor' => 23500, 'terms_accepted' => true, 'cash_received' => true,
    ])->assertCreated()->json('id');

    $payment = ['tender' => 'mobile_money', 'reference' => 'MM 001', 'amount_minor' => 5000];
    $submitted = laybyPost($this, $this->cashier, '/'.$first['id'].'/collect', $payment)->assertOk()->json('entries.1.id');
    laybyPost($this, $this->manager, '/'.$first['id'].'/reject', ['entry_id' => $submitted, 'evidence' => 'Wrong agreement'])->assertOk();
    $resubmitted = laybyPost($this, $this->cashier, '/'.$second.'/collect', array_replace($payment, ['reference' => 'mm001']))
        ->assertOk()->json('entries.1.id');
    laybyPost($this, $this->manager, '/'.$second.'/verify', ['entry_id' => $resubmitted, 'evidence' => 'Matched actual settlement'])->assertOk();
    laybyPost($this, $this->cashier, '/'.$first['id'].'/collect', $payment)->assertUnprocessable();
    $fiscal = ['entry_id' => $first['entries'][0]['id'], 'document_type' => 'tax_invoice',
        'reference' => 'EIS-123', 'evidence' => 'Matched external fiscal document'];
    laybyPost($this, $this->manager, '/'.$first['id'].'/fiscal-reference', $fiscal)->assertOk()
        ->assertJsonPath('entries.3.kind', 'fiscal_reference')->assertJsonPath('received_minor', 23500);
});

it('keeps an ordinary sale number sequence after a layby pickup', function () {
    $open = laybyOpen($this);
    $path = '/'.$open['id'];
    laybyPost($this, $this->cashier, $path.'/collect', ['tender' => 'cash', 'amount_minor' => 94000, 'cash_received' => true])->assertOk();
    $saleId = laybyPost($this, $this->cashier, $path.'/handover', [
        'serial_number' => $this->device->serial_number, 'evidence' => 'Customer and serial checked', 'verified_pickup' => true,
    ])->assertOk()->json('sale_id');
    expect(Sale::findOrFail($saleId)->sale_number)->toBe($open['reference'])
        ->and(Sale::generateSaleNumber())->toBe('SALE-2026-00001');
    $this->actingAs($this->admin)->get('/api/v1/admin/sales/'.$saleId.'/receipt?format=thermal')->assertOk()->assertSee('Layby');
});
