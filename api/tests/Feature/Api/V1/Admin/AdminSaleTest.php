<?php

use App\Enums\InventoryStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\CommunicationSettings;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\NewSaleNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->branch = Branch::create([
        'name' => 'Test Branch',
        'code' => 'TST',
        'is_active' => true,
    ]);

    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');

    $this->customer = User::factory()->create();
    $this->customer->assignRole('customer');

    $this->category = DeviceCategory::factory()->create(['name' => 'Television']);
});

function salePayload(Device $device, User $customer, array $overrides = []): array
{
    return array_replace_recursive([
        'customer_id' => $customer->id,
        'payment_method' => 'cash',
        'payment_amount' => 1500,
        'status' => 'completed',
        'items' => [[
            'category_id' => $device->category_id,
            'brand' => $device->brand,
            'model' => $device->model,
            'color' => $device->color,
            'quantity' => 1,
            'unit_price' => 1200,
            'discount' => 100,
            'tax' => 210,
        ]],
    ], $overrides);
}

describe('Admin Sales', function () {
    it('sends completed POS sale notifications to the approval email', function () {
        config(['inventory.approval_email' => 'approval@example.test']);
        CommunicationSettings::current()->update([
            'admin_emails' => [],
            'sale_notifications_enabled' => true,
        ]);
        Notification::fake();

        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer))
            ->assertCreated();

        Notification::assertSentOnDemand(NewSaleNotification::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'approval@example.test';
        });
    });

    it('renders the real staff and customer names in the sale notification mail', function () {
        $sale = Sale::create([
            'sale_number' => 'SALE-2026-09999',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'sold_by_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'subtotal' => 1000,
            'tax_amount' => 175,
            'discount_amount' => 0,
            'total_amount' => 1175,
            'sale_date' => now(),
        ]);

        $mail = (new NewSaleNotification($sale))->toMail($this->admin);
        $body = implode("\n", $mail->introLines);

        expect($body)
            ->toContain("- Staff: {$this->admin->name}")
            ->toContain("- Customer: {$this->customer->name}")
            ->not->toContain('Unknown Staff')
            ->not->toContain('Customer: Guest');
    });

    it('stores an in-app notification for super admins when a store admin makes a sale', function () {
        config(['inventory.approval_email' => 'approval@example.test']);
        CommunicationSettings::current()->update([
            'admin_emails' => [],
            'sale_notifications_enabled' => true,
        ]);
        Mail::fake();

        $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $storeAdmin->assignRole('store_admin');
        $secondSuperAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $secondSuperAdmin->assignRole('super_admin');
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $saleId = $this->actingAs($storeAdmin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer))
            ->assertCreated()
            ->json('data.id');

        foreach ([$this->admin, $secondSuperAdmin] as $superAdmin) {
            $notification = $superAdmin->notifications()
                ->where('data->type', 'sale_completed')
                ->where('data->sale_id', $saleId)
                ->first();

            expect($notification)->not->toBeNull()
                ->and($notification->data)->toMatchArray([
                    'type' => 'sale_completed',
                    'sale_id' => $saleId,
                    'sold_by_id' => $storeAdmin->id,
                    'branch_id' => $this->branch->id,
                ]);
        }

        expect($storeAdmin->notifications()->where('data->sale_id', $saleId)->exists())->toBeFalse();
    });

    it('stores an in-app notification for super admins when customer service completes a sale', function () {
        config(['inventory.approval_email' => 'approval@example.test']);
        CommunicationSettings::current()->update([
            'admin_emails' => [],
            'sale_notifications_enabled' => true,
        ]);
        Mail::fake();

        $customerService = User::factory()->create(['branch_id' => $this->branch->id]);
        $customerService->assignRole('customer_service');
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Silver',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $saleId = $this->actingAs($customerService)
            ->postJson('/api/v1/customer-service/sales', salePayload($device, $this->customer))
            ->assertCreated()
            ->json('data.id');

        $notification = $this->admin->notifications()
            ->where('data->type', 'sale_completed')
            ->where('data->sale_id', $saleId)
            ->first();

        expect($notification)->not->toBeNull()
            ->and($notification->data)->toMatchArray([
                'sale_id' => $saleId,
                'sold_by_id' => $customerService->id,
            ]);
    });

    it('creates a completed paid sale and snapshots profit', function () {
        $batch = InventoryBatch::create([
            'batch_number' => 'BATCH-TEST-SALE-PRICE',
            'branch_id' => $this->branch->id,
            'category_id' => $this->category->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'quantity_received' => 1,
            'buying_price' => 800,
            'target_selling_price' => 1200,
        ]);

        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'inventory_batch_id' => $batch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer));

        $response->assertCreated()
            ->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.payment_status.value', 'completed')
            ->assertJsonPath('data.payment_amount', '1500.00')
            ->assertJsonPath('data.change_due', '190.00')
            ->assertJsonPath('data.items.0.cost_price', '800.00')
            ->assertJsonPath('data.items.0.selling_price', '1200.00')
            ->assertJsonPath('data.items.0.profit_amount', '300.00');

        $device->refresh();
        expect($device->inventory_status)->toBe(InventoryStatus::SOLD);
        expect($device->owner_id)->toBe($this->customer->id);
        expect($device->sold_at)->not->toBeNull();
    });

    it('uses device purchase cost when no batch selling price is available', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_batch_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'items' => [[
                    'category_id' => $device->category_id,
                    'brand' => $device->brand,
                    'model' => $device->model,
                    'color' => $device->color,
                    'quantity' => 1,
                    'unit_price' => 0,
                    'discount' => 0,
                    'tax' => 0,
                ]],
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '800.00')
            ->assertJsonPath('data.items.0.selling_price', '800.00')
            ->assertJsonPath('data.items.0.profit_amount', '0.00');
    });

    it('rejects a sale when neither batch price nor purchase cost is available', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'purchase_price' => null,
            'inventory_batch_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'items' => [[
                    'category_id' => $device->category_id,
                    'brand' => $device->brand,
                    'model' => $device->model,
                    'color' => $device->color,
                    'quantity' => 1,
                    'unit_price' => 0,
                    'discount' => 0,
                    'tax' => 0,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK)
            ->and(Sale::count())->toBe(0);
    });

    it('creates a draft sale that reserves stock only', function () {
        config(['inventory.approval_email' => 'approval@example.test']);
        CommunicationSettings::current()->update([
            'admin_emails' => [],
            'sale_notifications_enabled' => true,
        ]);
        Notification::fake();

        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'White',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'status' => 'draft',
                'payment_amount' => null,
            ]));

        $response->assertCreated()
            ->assertJsonPath('data.status.value', 'draft')
            ->assertJsonPath('data.payment_status.value', 'pending');

        $device->refresh();
        expect($device->inventory_status)->toBe(InventoryStatus::RESERVED);
        expect($device->owner_id)->toBeNull();
        expect($device->sold_at)->toBeNull();
        Notification::assertSentOnDemandTimes(NewSaleNotification::class, 0);
    });

    it('completes a draft sale and marks reserved devices sold', function () {
        config(['inventory.approval_email' => 'approval@example.test']);
        CommunicationSettings::current()->update([
            'admin_emails' => [],
            'sale_notifications_enabled' => true,
        ]);
        Notification::fake();

        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Silver',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $saleId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'status' => 'draft',
                'payment_amount' => null,
            ]))
            ->json('data.id');

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/sales/{$saleId}/complete", [
                'payment_amount' => 1500,
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.payment_status.value', 'completed');

        Notification::assertSentOnDemand(NewSaleNotification::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'approval@example.test';
        });

        $device->refresh();
        expect($device->inventory_status)->toBe(InventoryStatus::SOLD);
        expect($device->owner_id)->toBe($this->customer->id);
    });

    it('requires payment amount when completing a cash draft sale', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Purple',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $saleId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'status' => 'draft',
                'payment_amount' => null,
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/sales/{$saleId}/complete")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_amount');

        expect(Sale::find($saleId)->status->value)->toBe('draft');
        expect(Sale::find($saleId)->payment_amount)->toBeNull();
        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::RESERVED);
    });

    it('rejects underpaid completed cash sales', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Blue',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'payment_amount' => 100,
            ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('payment_amount');
        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK);
    });

    it('rejects negative monetary values before creating a sale', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Blue',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $cases = [
            [
                'field' => 'payment_amount',
                'payload' => ['payment_amount' => -1],
                'message' => 'Payment amount cannot be negative.',
            ],
            [
                'field' => 'items.0.quantity',
                'payload' => ['items' => [['quantity' => -1]]],
                'message' => 'Quantity must be at least 1.',
            ],
            [
                'field' => 'items.0.unit_price',
                'payload' => ['items' => [['unit_price' => -1]]],
                'message' => 'Sale price cannot be negative.',
            ],
            [
                'field' => 'items.0.price_override',
                'payload' => ['items' => [['price_override' => -1, 'price_override_reason' => 'Negative price test']]],
                'message' => 'Sale price override cannot be negative.',
            ],
            [
                'field' => 'items.0.discount',
                'payload' => ['items' => [['discount' => -1]]],
                'message' => 'Discount cannot be negative.',
            ],
            [
                'field' => 'items.0.tax',
                'payload' => ['items' => [['tax' => -1]]],
                'message' => 'Tax cannot be negative.',
            ],
        ];

        foreach ($cases as $case) {
            $response = $this->actingAs($this->admin)
                ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, $case['payload']));

            $response->assertUnprocessable()->assertJsonValidationErrors($case['field']);
            expect($response->json('errors')[$case['field']][0])->toBe($case['message']);
        }

        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK)
            ->and(Sale::count())->toBe(0);
    });

    it('rejects a negative payment when completing a draft sale', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $saleId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'status' => 'draft',
                'payment_amount' => null,
            ]))
            ->assertCreated()
            ->json('data.id');

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/sales/{$saleId}/complete", ['payment_amount' => -1]);

        $response->assertUnprocessable()->assertJsonValidationErrors('payment_amount');
        expect($response->json('errors.payment_amount.0'))->toBe('Payment amount cannot be negative.');
        expect(Sale::find($saleId)->status->value)->toBe('draft')
            ->and($device->fresh()->inventory_status)->toBe(InventoryStatus::RESERVED);
    });

    it('rejects item discounts greater than unit price', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Blue',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'payment_amount' => 5000000,
                'items' => [[
                    'category_id' => $device->category_id,
                    'brand' => $device->brand,
                    'model' => $device->model,
                    'color' => $device->color,
                    'quantity' => 1,
                    'unit_price' => 1200,
                    'discount' => 1201,
                    'tax' => 0,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK);
    });

    it('does not allow a store admin to sell stock from another branch', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $storeAdmin->assignRole('store_admin');

        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $otherBranch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($storeAdmin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'items' => [[
                    'device_id' => $device->id,
                    'unit_price' => 1200,
                    'discount' => 0,
                    'tax' => 0,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK);
    });

    it('does not allow customer service to sell stock from another branch', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $customerService = User::factory()->create(['branch_id' => $this->branch->id]);
        $customerService->assignRole('customer_service');

        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $otherBranch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($customerService)
            ->postJson('/api/v1/customer-service/sales', salePayload($device, $this->customer, [
                'items' => [[
                    'device_id' => $device->id,
                    'unit_price' => 1200,
                    'discount' => 0,
                    'tax' => 0,
                ]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        expect(Sale::count())->toBe(0)
            ->and($device->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK);
    });

    it('scopes store admin sale listings to their branch', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $storeAdmin->assignRole('store_admin');

        Sale::create([
            'sale_number' => 'SALE-2026-00001',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'sold_by_id' => $storeAdmin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now(),
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);

        Sale::create([
            'sale_number' => 'SALE-2026-00002',
            'customer_id' => $this->customer->id,
            'branch_id' => $otherBranch->id,
            'sold_by_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now(),
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);

        $response = $this->actingAs($storeAdmin)
            ->getJson("/api/v1/admin/sales?branch_id={$otherBranch->id}");

        $response->assertSuccessful();
        expect($response->json('meta.total'))->toBe(1);
        expect($response->json('data.0.sale_number'))->toBe('SALE-2026-00001');
    });

    it('lets super admins filter sales by branch and explicitly view all branches', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        Sale::create([
            'sale_number' => 'SALE-2026-00004',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'sold_by_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now(),
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);

        Sale::create([
            'sale_number' => 'SALE-2026-00005',
            'customer_id' => $this->customer->id,
            'branch_id' => $otherBranch->id,
            'sold_by_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now(),
            'subtotal' => 200,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 200,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/sales?branch_id={$otherBranch->id}")
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.branch_id', $otherBranch->id)
            ->assertJsonPath('data.0.branch.name', 'Other Branch');

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/sales?all_branches=1')
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 2);
    });

    it('scopes sale listings to the branch managed by the store admin', function () {
        $manager = User::factory()->create(['branch_id' => null]);
        $manager->assignRole('store_admin');
        $this->branch->update(['manager_id' => $manager->id]);
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        foreach ([
            ['number' => 'SALE-2026-MANAGER-01', 'branch_id' => $this->branch->id],
            ['number' => 'SALE-2026-MANAGER-02', 'branch_id' => $otherBranch->id],
        ] as $saleData) {
            Sale::create([
                'sale_number' => $saleData['number'],
                'customer_id' => $this->customer->id,
                'branch_id' => $saleData['branch_id'],
                'sold_by_id' => $this->admin->id,
                'payment_method' => PaymentMethod::Cash,
                'payment_status' => PaymentStatus::Completed,
                'status' => SaleStatus::COMPLETED,
                'sale_date' => now(),
                'subtotal' => 100,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 100,
            ]);
        }

        $this->actingAs($manager)
            ->getJson('/api/v1/admin/sales?all_branches=1')
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.branch_id', $this->branch->id);
    });

    it('filters sales inclusively by the requested date range', function () {
        config(['app.local_timezone' => 'Africa/Blantyre']);
        $from = '2026-01-15';
        $to = '2026-01-16';

        foreach ([
            // Stored UTC instants immediately outside/inside the Malawi date range.
            ['number' => 'SALE-2026-DATE-01', 'date' => '2026-01-14 21:59:59'],
            ['number' => 'SALE-2026-DATE-02', 'date' => '2026-01-14 22:00:00'],
            ['number' => 'SALE-2026-DATE-03', 'date' => '2026-01-16 21:59:59'],
            ['number' => 'SALE-2026-DATE-04', 'date' => '2026-01-16 22:00:00'],
        ] as $saleData) {
            Sale::create([
                'sale_number' => $saleData['number'],
                'customer_id' => $this->customer->id,
                'branch_id' => $this->branch->id,
                'sold_by_id' => $this->admin->id,
                'payment_method' => PaymentMethod::Cash,
                'payment_status' => PaymentStatus::Completed,
                'status' => SaleStatus::COMPLETED,
                'sale_date' => $saleData['date'],
                'subtotal' => 100,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => 100,
            ]);
        }

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/sales?date_from={$from}&date_to={$to}")
            ->assertSuccessful()
            ->assertJsonPath('meta.total', 2);
    });

    it('summarizes all sales matching the branch and date filters', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $matchingDate = now()->subDay();
        foreach ([
            ['number' => 'SALE-2026-SUMMARY-01', 'branch_id' => $this->branch->id, 'amount' => 100],
            ['number' => 'SALE-2026-SUMMARY-02', 'branch_id' => $this->branch->id, 'amount' => 200],
            ['number' => 'SALE-2026-SUMMARY-03', 'branch_id' => $otherBranch->id, 'amount' => 500],
        ] as $saleData) {
            Sale::create([
                'sale_number' => $saleData['number'],
                'customer_id' => $this->customer->id,
                'branch_id' => $saleData['branch_id'],
                'sold_by_id' => $this->admin->id,
                'payment_method' => PaymentMethod::Cash,
                'payment_status' => PaymentStatus::Completed,
                'status' => SaleStatus::COMPLETED,
                'sale_date' => $matchingDate,
                'subtotal' => $saleData['amount'],
                'tax_amount' => 0,
                'discount_amount' => 0,
                'total_amount' => $saleData['amount'],
            ]);
        }

        InventoryMovement::create([
            'movement_number' => 'MOVE-2026-SUMMARY-ADMIN-01',
            'movement_type' => InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            'branch_id' => $this->branch->id,
            'quantity' => 1,
            'total_amount' => 75,
            'created_at' => $matchingDate,
            'updated_at' => $matchingDate,
        ]);

        InventoryMovement::create([
            'movement_number' => 'MOVE-2026-SUMMARY-ADMIN-02',
            'movement_type' => InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            'branch_id' => $otherBranch->id,
            'quantity' => 1,
            'total_amount' => 125,
            'created_at' => $matchingDate,
            'updated_at' => $matchingDate,
        ]);

        $date = $matchingDate->toDateString();
        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/sales/summary?branch_id={$this->branch->id}&date_from={$date}&date_to={$date}")
            ->assertSuccessful()
            ->assertJsonPath('data.sales_count', 2)
            ->assertJsonPath('data.total_amount', 300)
            ->assertJsonPath('data.average_amount', 150)
            ->assertJsonPath('data.administrative_sale_amount', 75)
            ->assertJsonPath('data.administrative_sale_transactions', 1)
            ->assertJsonPath('data.combined_total_amount', 375);
    });

    it('rejects a sales date range whose end precedes its start', function () {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/sales?date_from=2026-08-13&date_to=2026-08-12')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    });

    it('prevents store admins from viewing another branch sale directly', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $storeAdmin->assignRole('store_admin');

        $sale = Sale::create([
            'sale_number' => 'SALE-2026-00003',
            'customer_id' => $this->customer->id,
            'branch_id' => $otherBranch->id,
            'sold_by_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now(),
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);

        $this->actingAs($storeAdmin)
            ->getJson("/api/v1/admin/sales/{$sale->id}")
            ->assertForbidden();

        $this->actingAs($storeAdmin)
            ->getJson("/api/v1/admin/sales/{$sale->id}/receipt-url")
            ->assertForbidden();
    });

    it('renders Mufasah Electronics and its T-PIN on every receipt format', function () {
        $sale = Sale::create([
            'sale_number' => 'SALE-2026-TPIN-01',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'sold_by_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now(),
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);

        foreach (['a4', 'thermal'] as $format) {
            $this->actingAs($this->admin)
                ->get("/api/v1/admin/sales/{$sale->id}/receipt?format={$format}")
                ->assertOk()
                ->assertSeeText('Mufasah Electronics')
                ->assertSeeText('T-PIN: 70474293');
        }
    });

    it('supports labelled copies and guided printing on direct and signed receipt pages', function () {
        config(['receipt.logo' => null, 'receipt.thermal.width' => 80]);
        $sale = Sale::create([
            'sale_number' => 'SALE-2026-COPIES-01',
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'sold_by_id' => $this->admin->id,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => now(),
            'subtotal' => 100,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 100,
        ]);

        foreach (['a4', 'thermal'] as $format) {
            foreach (['customer', 'company'] as $copy) {
                $query = "format={$format}&copy={$copy}";
                $this->actingAs($this->admin)
                    ->get("/api/v1/admin/sales/{$sale->id}/receipt?{$query}")
                    ->assertOk()
                    ->assertSeeText(ucfirst($copy).' copy')
                    ->assertSee('id="receipt-print-controls"', false);

                $url = $this->actingAs($this->admin)
                    ->getJson("/api/v1/admin/sales/{$sale->id}/receipt-url?{$query}")
                    ->assertOk()
                    ->json('data.signed_url');

                $this->get($url)
                    ->assertOk()
                    ->assertSeeText(ucfirst($copy).' copy')
                    ->assertSee('id="receipt-print-controls"', false);

                $otherCopy = $copy === 'customer' ? 'company' : 'customer';
                $this->get(str_replace("copy={$copy}", "copy={$otherCopy}", $url))
                    ->assertForbidden();

                $pdf = $this->actingAs($this->admin)
                    ->get("/api/v1/admin/sales/{$sale->id}/receipt?{$query}&output=pdf")
                    ->assertOk()
                    ->assertHeader('Content-Type', 'application/pdf');

                expect($pdf->headers->get('Content-Disposition'))->toContain("_{$copy}.pdf")
                    ->and($pdf->getContent())->toStartWith('%PDF-');

                preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)/', $pdf->getContent(), $pageSize);
                expect((float) $pageSize[1])->toEqualWithDelta(($format === 'thermal' ? 80 : 210) * 72 / 25.4, 0.1);
            }
        }

        $this->actingAs($this->admin)
            ->get("/api/v1/admin/sales/{$sale->id}/receipt?copy=invalid")
            ->assertStatus(400);
        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/sales/{$sale->id}/receipt-url?copy=invalid")
            ->assertStatus(400);

        config(['receipt.thermal.width' => 58]);
        $narrowPdf = $this->actingAs($this->admin)
            ->get("/api/v1/admin/sales/{$sale->id}/receipt?format=thermal&output=pdf")
            ->assertOk();
        preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)/', $narrowPdf->getContent(), $pageSize);
        expect((float) $pageSize[1])->toEqualWithDelta(58 * 72 / 25.4, 0.1);
    });

    it('allocates product-spec sales only from the seller branch', function () {
        $otherBranch = Branch::create([
            'name' => 'Other Branch',
            'code' => 'OTH',
            'is_active' => true,
        ]);

        $storeAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $storeAdmin->assignRole('store_admin');

        Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $otherBranch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Black',
            'owner_id' => null,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $this->actingAs($storeAdmin)
            ->postJson('/api/v1/admin/sales', salePayload(Device::first(), $this->customer, [
                'payment_amount' => 1500,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    });

    it('rejects a second sale attempt for a device that is no longer in stock', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Gold',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $payload = salePayload($device, $this->customer);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', $payload)
            ->assertCreated();

        $saleCount = Sale::count();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        expect(Sale::count())->toBe($saleCount);
        expect($device->fresh()->inventory_status)->toBe(InventoryStatus::SOLD);
    });

    it('does not complete a draft sale when the reserved device changed state', function () {
        $device = Device::factory()->create([
            'category_id' => $this->category->id,
            'branch_id' => $this->branch->id,
            'brand' => 'Sony',
            'model' => 'Bravia X85L',
            'color' => 'Green',
            'owner_id' => null,
            'purchase_price' => 800,
            'inventory_status' => InventoryStatus::IN_STOCK,
        ]);

        $saleId = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/sales', salePayload($device, $this->customer, [
                'status' => 'draft',
                'payment_amount' => null,
            ]))
            ->assertCreated()
            ->json('data.id');

        $reservedDevice = Sale::find($saleId)->items()->first()->device;
        $reservedDevice->update([
            'inventory_status' => InventoryStatus::IN_STOCK,
            'sale_id' => null,
        ]);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/sales/{$saleId}/complete", [
                'payment_amount' => 1500,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        expect(Sale::find($saleId)->status->value)->toBe('draft');
        expect($reservedDevice->fresh()->inventory_status)->toBe(InventoryStatus::IN_STOCK);
    });
});
