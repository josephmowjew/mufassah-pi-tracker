<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Http\Resources\SaleResource;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\TheftReport;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Reports\ReportFilters;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['app.local_timezone' => 'Africa/Blantyre']);
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->branch = Branch::create(['name' => 'Timezone Branch', 'code' => 'TZ', 'is_active' => true]);
    $this->admin = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->admin->assignRole('super_admin');
    $this->makeSale = fn (string $utcDate, float $amount = 100) => Sale::create([
        'sale_number' => 'TZ-'.Sale::count(), 'customer_id' => $this->admin->id,
        'sold_by_id' => $this->admin->id, 'branch_id' => $this->branch->id,
        'payment_method' => PaymentMethod::Cash, 'payment_status' => PaymentStatus::Completed,
        'status' => SaleStatus::COMPLETED, 'sale_date' => $utcDate,
        'subtotal' => $amount, 'total_amount' => $amount,
    ]);
    $this->boundaryTimes = ['2026-01-14 21:59:59', '2026-01-14 22:00:00', '2026-01-15 21:59:59', '2026-01-15 22:00:00'];
});

it('formats mutable and immutable instants without changing their UTC values', function (string $class) {
    $date = $class::parse('2026-01-15 22:30:00', 'UTC');
    expect(config('app.timezone'))->toBe('UTC')
        ->and($date->toLocalIso8601String())->toBe('2026-01-16T00:30:00+02:00')
        ->and($date->toLocalDateString())->toBe('2026-01-16')
        ->and($date->toLocalDateTimeString())->toBe('2026-01-16 00:30:00')
        ->and($date->toLocalFormat('Y-m-d H:i'))->toBe('2026-01-16 00:30')
        ->and($date->toIso8601String())->toBe('2026-01-15T22:30:00+00:00');
})->with(['mutable' => [Carbon::class], 'immutable' => [CarbonImmutable::class]]);

it('converts local filter days to UTC while preserving filter labels', function (string $input) {
    $request = Request::create('/', 'GET', ['from' => $input, 'to' => $input]);
    $request->setUserResolver(fn () => $this->admin);
    $filters = ReportFilters::fromRequest($request);
    expect($filters->from->toIso8601String())->toBe('2026-01-14T22:00:00+00:00')
        ->and($filters->to->toIso8601String())->toBe('2026-01-15T21:59:59+00:00')
        ->and($filters->fromDate())->toBe('2026-01-15')
        ->and($filters->toDate())->toBe('2026-01-15')
        ->and($filters->periodLabel())->toBe('2026-01-15 to 2026-01-15');
})->with([
    'calendar date' => ['2026-01-15'],
    'UTC midnight' => ['2026-01-15T00:00:00Z'],
    'Malawi midnight' => ['2026-01-15T00:00:00+02:00'],
    'previous UTC day' => ['2026-01-14T23:30:00Z'],
    'positive offset' => ['2026-01-15T04:00:00+05:00'],
    'negative offset' => ['2026-01-14T21:00:00-05:00'],
]);

it('keeps dashboard totals, daily buckets and sale lists on the same local day', function () {
    foreach ($this->boundaryTimes as $index => $utcDate) {
        ($this->makeSale)($utcDate);
        InventoryMovement::create([
            'movement_number' => 'TZ-MOV-'.$index,
            'movement_type' => InventoryMovement::TYPE_ADMINISTRATIVE_SALE,
            'branch_id' => $this->branch->id, 'quantity' => 1, 'total_amount' => 50,
            'created_at' => $utcDate, 'updated_at' => $utcDate,
        ]);
    }
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/dashboard?date_from=2026-01-15&date_to=2026-01-15')
        ->assertSuccessful()
        ->assertJsonPath('data.filters.date_from', '2026-01-15')
        ->assertJsonPath('data.filters.date_to', '2026-01-15')
        ->assertJsonPath('data.sales.total_transactions', 2)
        ->assertJsonPath('data.sales.total_amount', 200)
        ->assertJsonPath('data.sales.previous_total_amount', 100)
        ->assertJsonPath('data.sales.administrative_sale_amount', 100)
        ->assertJsonPath('data.sales.previous_administrative_sale_amount', 50)
        ->assertJsonPath('data.sales.daily_breakdown.0.date', '2026-01-15')
        ->assertJsonPath('data.sales.daily_breakdown.0.combined_total_amount', 300)
        ->assertJsonPath('data.sales.combined_trend.2026-01-15', 300);
    expect(collect($response->json('data.sales.daily_breakdown'))->sum('combined_total_amount'))
        ->toEqual($response->json('data.sales.combined_total_amount'));
    $this->getJson('/api/v1/admin/sales?date_from=2026-01-15&date_to=2026-01-15')
        ->assertSuccessful()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/admin/sales/summary?date_from=2026-01-15&date_to=2026-01-15')
        ->assertSuccessful()->assertJsonPath('data.sales_count', 2);
    $this->getJson('/api/v1/admin/stock-movements?date_from=2026-01-15&date_to=2026-01-15')
        ->assertSuccessful()->assertJsonPath('meta.total', 2);
    $this->admin->assignRole('customer_service');
    $this->getJson('/api/v1/customer-service/sales?date_from=2026-01-15&date_to=2026-01-15')
        ->assertSuccessful()->assertJsonPath('meta.total', 2);
});

it('uses the Malawi year when defaulting the dashboard range before UTC new year', function () {
    $this->travelTo(Carbon::parse('2025-12-31 22:30:00', 'UTC'));
    ($this->makeSale)('2025-12-31 22:15:00');
    ($this->makeSale)('2025-12-31 21:59:59');
    $this->actingAs($this->admin)->getJson('/api/v1/admin/dashboard')
        ->assertSuccessful()
        ->assertJsonPath('data.filters.date_from', '2026-01-01')
        ->assertJsonPath('data.filters.date_to', '2026-01-01')
        ->assertJsonPath('data.sales.total_transactions', 1);
});

it('filters revenue reports by local midnight and returns only local day buckets', function () {
    foreach ($this->boundaryTimes as $utcDate) {
        Payment::factory()->create(['user_id' => $this->admin->id, 'paid_at' => $utcDate, 'amount' => 100]);
    }
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/reports/revenue?from=2026-01-15&to=2026-01-15&group_by=day')
        ->assertSuccessful()
        ->assertJsonPath('data.filters.from', '2026-01-15')
        ->assertJsonPath('data.filters.to', '2026-01-15')
        ->assertJsonPath('data.summary.total_transactions', 2)
        ->assertJsonPath('data.summary.total_revenue', 200)
        ->assertJsonCount(1, 'data.by_period')
        ->assertJsonPath('data.by_period.0.period', '2026-01-15')
        ->assertJsonPath('data.by_period.0.revenue', 200);
});

it('keeps calendar-only purchase date filters unchanged', function () {
    InventoryBatch::create([
        'batch_number' => 'TZ-BATCH', 'branch_id' => $this->branch->id,
        'category_id' => DeviceCategory::factory()->create()->id,
        'brand' => 'Test', 'model' => 'Test', 'quantity_received' => 1,
        'buying_price' => 100, 'target_selling_price' => 150, 'purchase_date' => '2026-01-15',
    ]);
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/reports/stock?from=2026-01-15&to=2026-01-15')
        ->assertSuccessful()->assertJsonPath('data.summary.received_in_range', 1);
});

it('uses the same local day for shift lists and staff time reports', function () {
    foreach ($this->boundaryTimes as $utcDate) {
        Shift::create([
            'user_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'started_at' => $utcDate, 'ended_at' => Carbon::parse($utcDate, 'UTC')->addHour(),
            'status' => 'completed',
        ]);
    }
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/shifts?date_from=2026-01-15&date_to=2026-01-15')
        ->assertSuccessful()->assertJsonPath('meta.total', 2);
    $this->getJson('/api/v1/admin/reports/staff-time?from=2026-01-15&to=2026-01-15')
        ->assertSuccessful()->assertJsonPath('data.summary.total_shifts', 2)
        ->assertJsonPath('data.recent_shifts.0.date', '2026-01-15');
    $this->admin->assignRole('customer_service');
    $this->getJson('/api/v1/customer-service/shifts/history?date_from=2026-01-15&date_to=2026-01-15')
        ->assertSuccessful()->assertJsonPath('meta.total', 2);
});

it('renders API and receipt times locally without changing stored timestamps on save', function () {
    $sale = ($this->makeSale)('2026-01-15 22:30:00');
    expect((new SaleResource($sale))->resolve()['sale_date'])->toBe('2026-01-16T00:30:00+02:00');
    $html = view('receipts.thermal_receipt', [
        'sale' => $sale, 'paperWidth' => 80,
        'company' => ['name' => 'Test', 'address' => null, 'phone' => null, 'tpin' => null, 'email' => null],
        'customer' => null, 'soldBy' => null, 'items' => collect(), 'barcode' => '',
    ])->render();
    expect($html)->toContain('2026-01-16 00:30');
    $sale->update(['notes' => 'Saved after display']);
    expect(DB::table('sales')->where('id', $sale->id)->value('sale_date'))->toBe('2026-01-15 22:30:00')
        ->and($sale->fresh()->sale_date->timezoneName)->toBe('UTC')
        ->and(LocalTime::startOfDayUtc('2026-01-16')->timezoneName)->toBe('UTC');
});

it('counts today sales using the Malawi day before UTC midnight', function () {
    $this->travelTo(Carbon::parse('2026-01-14 22:30:00', 'UTC'));
    $this->admin->assignRole('customer_service');
    foreach ($this->boundaryTimes as $utcDate) {
        ($this->makeSale)($utcDate)->forceFill(['created_at' => $utcDate])->save();
    }
    $this->actingAs($this->admin)->getJson('/api/v1/customer-service/dashboard')
        ->assertSuccessful()->assertJsonPath('data.todaySales', 2);
});

it('uses the same Malawi day for timezone-qualified dashboard and report filters', function (string $input) {
    foreach ($this->boundaryTimes as $utcDate) {
        ($this->makeSale)($utcDate);
    }
    $query = http_build_query(['date_from' => $input, 'date_to' => $input]);
    $this->actingAs($this->admin)->getJson('/api/v1/admin/dashboard?'.$query)
        ->assertSuccessful()
        ->assertJsonPath('data.filters.date_from', '2026-01-15')
        ->assertJsonPath('data.filters.date_to', '2026-01-15')
        ->assertJsonPath('data.sales.total_transactions', 2)
        ->assertJsonPath('data.sales.daily_breakdown.0.pos_transactions', 2)
        ->assertJsonCount(1, 'data.sales.daily_breakdown');
    $query = http_build_query(['from' => $input, 'to' => $input]);
    $this->getJson('/api/v1/admin/reports/sales?'.$query)
        ->assertSuccessful()
        ->assertJsonPath('data.filters.period_label', '2026-01-15 to 2026-01-15')
        ->assertJsonPath('data.summary.pos_sales_count', 2)
        ->assertJsonPath('data.by_day.0.date', '2026-01-15')
        ->assertJsonCount(1, 'data.by_day');
})->with(['2026-01-15T00:00:00Z', '2026-01-14T23:30:00Z', '2026-01-15T00:00:00+02:00']);

it('accepts today purchase dates at Malawi midnight and keeps timestamps UTC', function () {
    $this->travelTo(Carbon::parse('2026-01-14 22:30:00', 'UTC'));
    $category = DeviceCategory::factory()->create();
    $payload = [
        'branch_id' => $this->branch->id, 'purchase_date' => '2026-01-15',
        'lines' => [[
            'category_id' => $category->id, 'brand' => 'Test', 'model' => 'Test',
            'quantity' => 1, 'buying_price' => 100, 'target_selling_price' => 150,
            'auto_generate_serials' => true,
        ]],
    ];
    $this->actingAs($this->admin)->postJson('/api/v1/admin/inventory/receipts', $payload)
        ->assertCreated()->assertJsonPath('data.receipt.purchase_date', '2026-01-15');
    expect(DB::table('inventory_receipts')->value('created_at'))->toBe('2026-01-14 22:30:00')
        ->and(DB::table('inventory_receipts')->value('received_at'))->toBe('2026-01-14 22:30:00');
    $payload['purchase_date'] = '2026-01-16';
    $this->postJson('/api/v1/admin/inventory/receipts', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('purchase_date');
});

it('validates purchase dates against the Malawi calendar in every device request', function (string $requestClass) {
    $this->travelTo(Carbon::parse('2026-01-14 22:30:00', 'UTC'));
    $rules = (new $requestClass)->rules()['purchase_date'];
    expect(validator(['purchase_date' => '2026-01-15'], ['purchase_date' => $rules])->passes())->toBeTrue()
        ->and(validator(['purchase_date' => '2026-01-16'], ['purchase_date' => $rules])->fails())->toBeTrue();
})->with([
    [\App\Http\Requests\Api\V1\Device\StoreDeviceRequest::class],
    [\App\Http\Requests\Api\V1\Device\UpdateDeviceRequest::class],
    [\App\Http\Requests\Api\V1\Admin\BulkStoreDeviceRequest::class],
]);

it('stores date-only and timezone-qualified theft dates as UTC instants at Malawi midnight', function (string $input, string $stored, string $local) {
    $this->travelTo(Carbon::parse('2026-01-14 22:30:00', 'UTC'));
    $device = Device::factory()->create(['owner_id' => $this->admin->id]);
    $response = $this->actingAs($this->admin)->postJson('/api/v1/theft-reports', [
        'device_id' => $device->id, 'theft_date' => $input,
        'theft_location' => 'Blantyre', 'description' => 'Device was stolen.',
    ])->assertCreated()->assertJsonPath('data.theft_date', $local);
    $id = $response->json('data.id');
    expect(DB::table('theft_reports')->where('id', $id)->value('theft_date'))->toBe($stored)
        ->and(DB::table('theft_reports')->where('id', $id)->value('created_at'))->toBe('2026-01-14 22:30:00');
    // Updating uses validated(), so normalization must reach both persistence paths.
    $this->putJson('/api/v1/theft-reports/'.$id, ['theft_date' => $input])
        ->assertSuccessful()->assertJsonPath('data.theft_date', $local);
    expect(DB::table('theft_reports')->where('id', $id)->value('theft_date'))->toBe($stored);
    $this->putJson('/api/v1/theft-reports/'.$id, ['theft_date' => '2026-01-15T00:31:00+02:00'])
        ->assertUnprocessable()->assertJsonValidationErrors('theft_date');
    $this->putJson('/api/v1/theft-reports/'.$id, ['theft_date' => '2026-01-16'])
        ->assertUnprocessable()->assertJsonValidationErrors('theft_date');
})->with([
    'calendar date' => ['2026-01-15', '2026-01-14 22:00:00', '2026-01-15T00:00:00+02:00'],
    'alternate calendar format' => ['15 January 2026', '2026-01-14 22:00:00', '2026-01-15T00:00:00+02:00'],
    'explicit offset' => ['2026-01-15T00:15:00+02:00', '2026-01-14 22:15:00', '2026-01-15T00:15:00+02:00'],
    'UTC instant' => ['2026-01-14T22:15:00Z', '2026-01-14 22:15:00', '2026-01-15T00:15:00+02:00'],
    'legacy naive UTC' => ['2026-01-14 22:15:00', '2026-01-14 22:15:00', '2026-01-15T00:15:00+02:00'],
]);

it('keeps malformed and overflowing theft dates invalid', function (mixed $input) {
    $this->travelTo(Carbon::parse('2026-01-14 22:30:00', 'UTC'));
    $device = Device::factory()->create(['owner_id' => $this->admin->id]);
    $this->actingAs($this->admin)->postJson('/api/v1/theft-reports', [
        'device_id' => $device->id, 'theft_date' => $input,
        'theft_location' => 'Blantyre', 'description' => 'Device was stolen.',
    ])->assertUnprocessable()->assertJsonValidationErrors('theft_date');
    expect(TheftReport::count())->toBe(0);
})->with([['not-a-date'], ['2026-02-31'], [['2026-01-15']]]);

it('groups revenue by the Malawi ISO week year across New Year', function () {
    foreach (['2025-12-28 21:59:59', '2025-12-28 22:00:00', '2025-12-31 10:00:00', '2026-01-01 10:00:00'] as $utcDate) {
        Payment::factory()->create(['user_id' => $this->admin->id, 'paid_at' => $utcDate, 'amount' => 100]);
    }
    $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/reports/revenue?from=2025-12-28&to=2026-01-02&group_by=week')
        ->assertSuccessful()
        ->assertJsonCount(2, 'data.by_period')
        ->assertJsonPath('data.by_period.0.period', '2025-W52')
        ->assertJsonPath('data.by_period.0.revenue', 100)
        ->assertJsonPath('data.by_period.1.period', '2026-W01')
        ->assertJsonPath('data.by_period.1.revenue', 300);
    $this->assertDatabaseHas('payments', ['paid_at' => '2025-12-28 22:00:00']);
});
