<?php

use App\Enums\InventoryStatus;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\InventoryActionApprovalPinNotification;
use App\Notifications\InvoiceEmailNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->branch = Branch::create([
        'name' => 'Invoice Branch',
        'code' => 'INV',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');

    $this->customer = User::factory()->create(['email' => 'buyer@example.test']);
    $this->customer->assignRole('customer');

    $this->category = DeviceCategory::factory()->create(['name' => 'Television']);
});

function invoiceDevice(InventoryBatch $batch, Branch $branch, DeviceCategory $category): Device
{
    return Device::factory()->create([
        'category_id' => $category->id,
        'branch_id' => $branch->id,
        'inventory_batch_id' => $batch->id,
        'brand' => 'Sony',
        'model' => 'Bravia X85L',
        'color' => 'Black',
        'owner_id' => null,
        'purchase_price' => 800,
        'inventory_status' => InventoryStatus::IN_STOCK,
    ]);
}

function invoiceBatch(Branch $branch, DeviceCategory $category, string $batchNumber, float $price = 1200): InventoryBatch
{
    return InventoryBatch::create([
        'batch_number' => $batchNumber,
        'branch_id' => $branch->id,
        'category_id' => $category->id,
        'brand' => 'Sony',
        'model' => 'Bravia X85L',
        'color' => 'Black',
        'quantity_received' => 2,
        'buying_price' => 800,
        'target_selling_price' => $price,
    ]);
}

function invoicePayload(Device $device, User $customer, array $overrides = []): array
{
    return array_replace_recursive([
        'customer_id' => $customer->id,
        'branch_id' => $device->branch_id,
        'recipient_email' => $customer->email,
        'vat_applicable' => true,
        'tax_rate' => 17.5,
        'items' => [[
            'category_id' => $device->category_id,
            'brand' => $device->brand,
            'model' => $device->model,
            'color' => $device->color,
            'quantity' => 1,
            'discount' => 100,
        ]],
    ], $overrides);
}

describe('Admin invoices', function () {
    it('issues an email-backed price locked invoice and reserves the exact device', function () {
        Notification::fake();
        $batch = invoiceBatch($this->branch, $this->category, 'INV-BATCH-ISSUE', 1200);
        $device = invoiceDevice($batch, $this->branch, $this->category);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/invoices', invoicePayload($device, $this->customer));

        $response->assertCreated()
            ->assertJsonPath('data.status.value', 'sent')
            ->assertJsonPath('data.vat_applicable', true)
            ->assertJsonPath('data.tax_rate', '17.50')
            ->assertJsonPath('data.tax_amount', '192.50')
            ->assertJsonPath('data.total_amount', '1292.50')
            ->assertJsonPath('data.items.0.tax', '192.50')
            ->assertJsonPath('data.items.0.pricing_source', 'batch_target_selling_price');

        $invoice = Invoice::firstOrFail();
        expect($invoice->invoice_number)->toStartWith('INV-')
            ->and((int) $invoice->issued_at->diffInSeconds($invoice->expires_at))->toBe(24 * 60 * 60)
            ->and($invoice->items)->toHaveCount(1)
            ->and($device->fresh()->inventory_status)->toBe(InventoryStatus::RESERVED)
            ->and($device->fresh()->reserved_invoice_id)->toBe($invoice->id);

        Notification::assertSentOnDemand(
            InvoiceEmailNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $this->customer->email,
        );
        expect(app(\App\Services\InvoiceDocumentService::class)->generatePdfData($invoice))->toStartWith('%PDF');
    });

    it('can issue an invoice without VAT and makes the tax treatment explicit', function () {
        Notification::fake();
        $batch = invoiceBatch($this->branch, $this->category, 'INV-BATCH-NO-VAT', 1200);
        $device = invoiceDevice($batch, $this->branch, $this->category);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/invoices', invoicePayload($device, $this->customer, [
                'vat_applicable' => false,
                'tax_rate' => 17.5,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.vat_applicable', false)
            ->assertJsonPath('data.tax_amount', '0.00')
            ->assertJsonPath('data.total_amount', '1100.00')
            ->assertJsonPath('data.tax_display', 'Not applicable')
            ->assertJsonPath('data.items.0.tax', '0.00');
    });

    it('redeems the frozen snapshots after catalog and batch prices change', function () {
        Notification::fake();
        $batch = invoiceBatch($this->branch, $this->category, 'INV-BATCH-REDEEM', 1200);
        $device = invoiceDevice($batch, $this->branch, $this->category);

        $invoiceId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/invoices', invoicePayload($device, $this->customer, [
                'items' => [[
                    'category_id' => $device->category_id,
                    'brand' => $device->brand,
                    'model' => $device->model,
                    'color' => $device->color,
                    'quantity' => 1,
                    'discount' => 0,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $batch->update(['target_selling_price' => 2000]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/invoices/{$invoiceId}/redeem", [
                'payment_method' => 'cash',
                'payment_amount' => 1410,
            ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'converted')
            ->assertJsonPath('data.converted_sale.total_amount', '1410.00');

        $sale = Sale::firstOrFail();
        expect($sale->status)->toBe(SaleStatus::COMPLETED)
            ->and($sale->items->first()->unit_price)->toBe('1200.00')
            ->and($sale->items->first()->tax)->toBe('210.00')
            ->and($device->fresh()->inventory_status)->toBe(InventoryStatus::SOLD)
            ->and($device->fresh()->reserved_invoice_id)->toBeNull();
    });

    it('expires a timed out invoice and releases its inventory reservation', function () {
        Notification::fake();
        $batch = invoiceBatch($this->branch, $this->category, 'INV-BATCH-EXPIRE', 1200);
        $device = invoiceDevice($batch, $this->branch, $this->category);

        $invoice = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/invoices', invoicePayload($device, $this->customer))
            ->assertCreated()
            ->json('data');

        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::RESERVED);

        $this->travelTo(now()->addHours(25));

        $this->artisan('invoices:expire')->assertSuccessful();

        $expired = Invoice::findOrFail($invoice['id']);
        expect($expired->status->value)->toBe('expired')
            ->and($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK)
            ->and($device->fresh()->reserved_invoice_id)->toBeNull()
            ->and(InventoryMovement::where('reference_type', Invoice::class)
                ->where('reference_id', $invoice['id'])
                ->where('movement_type', InventoryMovement::TYPE_RESERVATION_CANCELLED)
                ->exists())->toBeTrue();
    });

    it('exposes a tokenized customer view without leaking cost snapshots', function () {
        Notification::fake();
        $batch = invoiceBatch($this->branch, $this->category, 'INV-BATCH-PUBLIC', 1200);
        $device = invoiceDevice($batch, $this->branch, $this->category);

        $invoice = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/invoices', invoicePayload($device, $this->customer, [
                'vat_applicable' => false,
            ]))
            ->assertCreated()
            ->json('data');

        $stored = Invoice::findOrFail($invoice['id']);
        auth()->forgetGuards();
        $this->getJson('/api/v1/invoices/public/'.$stored->public_token)
            ->assertOk()
            ->assertJsonPath('data.invoice_number', $stored->invoice_number)
            ->assertJsonPath('data.tax_display', 'Not applicable')
            ->assertJsonMissingPath('data.items.0.cost_price');

        $this->actingAs($this->admin)
            ->get('/api/v1/admin/invoices/'.$stored->id.'/document?format=html')
            ->assertOk()
            ->assertSee('VAT is not applicable')
            ->assertSee($stored->invoice_number);
    });

    it('requires and consumes a PIN before cancelling an active invoice', function () {
        Notification::fake();
        $batch = invoiceBatch($this->branch, $this->category, 'INV-BATCH-CANCEL-PIN', 1200);
        $device = invoiceDevice($batch, $this->branch, $this->category);

        $invoiceId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/invoices', invoicePayload($device, $this->customer))
            ->assertCreated()
            ->json('data.id');

        $challenge = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/invoices/{$invoiceId}/cancel", [
                'reason' => 'Customer requested a different product',
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.action', 'invoice_cancellation')
            ->json('data');

        $pin = null;
        Notification::assertSentOnDemand(
            InventoryActionApprovalPinNotification::class,
            function ($notification) use (&$pin) {
                $pin = $notification->pin;

                return true;
            },
        );

        expect($pin)->toBeString()->toHaveLength(6);
        expect(Invoice::findOrFail($invoiceId)->status->value)->toBe('sent');

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/action-challenges/'.$challenge['challenge_id'].'/verify', [
                'pin' => $pin,
            ])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cancelled');

        expect(Invoice::findOrFail($invoiceId)->status->value)->toBe('cancelled')
            ->and($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK)
            ->and($device->fresh()->reserved_invoice_id)->toBeNull();
    });

    it('redeems an invoice only once and remains idempotent', function () {
        Notification::fake();
        $batch = invoiceBatch($this->branch, $this->category, 'INV-BATCH-IDEMPOTENT', 1200);
        $device = invoiceDevice($batch, $this->branch, $this->category);

        $invoiceId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/invoices', invoicePayload($device, $this->customer, [
                'items' => [[
                    'category_id' => $device->category_id,
                    'brand' => $device->brand,
                    'model' => $device->model,
                    'color' => $device->color,
                    'quantity' => 1,
                    'discount' => 0,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $payload = ['payment_method' => 'mobile_money', 'payment_reference' => 'MP-001'];
        $this->actingAs($this->admin)->postJson("/api/v1/admin/invoices/{$invoiceId}/redeem", $payload)->assertOk();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/invoices/{$invoiceId}/redeem", $payload)->assertOk();

        expect(Sale::count())->toBe(1)
            ->and(Invoice::findOrFail($invoiceId)->converted_sale_id)->toBe(Sale::firstOrFail()->id);
    });
});
