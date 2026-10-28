<?php

use App\Enums\InventoryStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Reports\ReportExportService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    foreach (['customer', 'super_admin', 'store_admin'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
    }

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    $this->customer = User::factory()->create();
    $this->customer->assignRole('customer');

    $this->category = DeviceCategory::factory()->create();

    $this->lilongwe = Branch::create(['name' => 'Lilongwe', 'code' => 'LLW', 'is_active' => true]);
    $this->blantyre = Branch::create(['name' => 'Blantyre', 'code' => 'BT', 'is_active' => true]);

    /** Create a completed POS sale of one device. */
    $this->makeSale = function (Branch $branch, string $date, float $price, float $cost = 0.0): Sale {
        $device = Device::factory()->create([
            'branch_id' => $branch->id,
            'category_id' => $this->category->id,
            'brand' => 'Samsung',
            'model' => 'Crystal UHD',
            // Pinned so two devices describe the same product and group together.
            'color' => 'Black',
            'inventory_status' => InventoryStatus::SOLD,
            'sold_at' => $date,
            'purchase_price' => $cost,
        ]);

        $sale = Sale::create([
            'sale_number' => 'SALE-'.strtoupper(uniqid()),
            'branch_id' => $branch->id,
            'customer_id' => $this->customer->id,
            'sold_by_id' => $this->admin->id,
            'subtotal' => $price,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $price,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Completed,
            'status' => SaleStatus::COMPLETED,
            'sale_date' => $date,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'device_id' => $device->id,
            'item_description' => 'Samsung Crystal UHD',
            'unit_price' => $price,
            'discount' => 0,
            'tax' => 0,
            'total_price' => $price,
            'cost_price' => $cost,
            'selling_price' => $price,
            'profit_amount' => $price - $cost,
        ]);

        return $sale;
    };

    /** Write a downloaded binary to disk and read every worksheet part back. */
    $this->sheetXml = function (string $binary): string {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_resp_');
        file_put_contents($path, $binary);

        $zip = new ZipArchive;
        $zip->open($path);

        $xml = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/worksheets/')) {
                $xml .= (string) $zip->getFromName($name);
            }
        }

        $zip->close();
        @unlink($path);

        return $xml;
    };

    $this->sheetNames = function (string $binary): array {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_resp_');
        file_put_contents($path, $binary);

        $zip = new ZipArchive;
        $zip->open($path);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();
        @unlink($path);

        preg_match_all('/<sheet name="([^"]+)"/', $workbook, $matches);

        return $matches[1];
    };
});

describe('Sales report', function () {
    it('summarises POS sales for a period', function () {
        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000, 100000);
        ($this->makeSale)($this->lilongwe, '2026-09-11 10:00:00', 50000, 30000);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/sales?from=2026-09-01&to=2026-09-30');

        $response->assertSuccessful()
            ->assertJsonPath('data.summary.pos_sales_count', 2)
            ->assertJsonPath('data.summary.pos_revenue', 200000)
            ->assertJsonPath('data.summary.pos_gross_profit', 70000)
            ->assertJsonPath('data.filters.scope_label', 'All branches')
            ->assertJsonPath('data.filters.period_label', '2026-09-01 to 2026-09-30');
    });

    it('excludes sales outside the requested period', function () {
        ($this->makeSale)($this->lilongwe, '2026-08-31 10:00:00', 999999);
        ($this->makeSale)($this->lilongwe, '2026-09-15 10:00:00', 1000);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/sales?from=2026-09-01&to=2026-09-30')
            ->assertSuccessful()
            ->assertJsonPath('data.summary.pos_revenue', 1000);
    });

    it('includes the whole of the final day in the range', function () {
        // Regression: `to` used to be compared as a bare date, so MySQL read it as
        // midnight and every sale after 00:00 on the last day was silently dropped.
        ($this->makeSale)($this->lilongwe, '2026-09-30 23:45:00', 75000);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/sales?from=2026-09-01&to=2026-09-30')
            ->assertSuccessful()
            ->assertJsonPath('data.summary.pos_sales_count', 1)
            ->assertJsonPath('data.summary.pos_revenue', 75000);
    });

    it('breaks sales down by branch, product and day', function () {
        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000, 100000);
        ($this->makeSale)($this->blantyre, '2026-09-10 11:00:00', 250000, 200000);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/sales?from=2026-09-01&to=2026-09-30');

        $response->assertSuccessful();

        expect($response->json('data.by_branch'))->toHaveCount(2)
            ->and($response->json('data.by_product'))->toHaveCount(1)
            ->and($response->json('data.by_product.0.units_sold'))->toBe(2)
            ->and($response->json('data.by_product.0.revenue'))->toBe(400000)
            ->and($response->json('data.by_day'))->toHaveCount(1)
            ->and($response->json('data.by_day.0.combined_revenue'))->toBe(400000);
    });
});

describe('Report scoping', function () {
    it('pins a super admin report to a single branch', function () {
        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000);
        ($this->makeSale)($this->blantyre, '2026-09-10 10:00:00', 250000);

        $this->actingAs($this->admin)
            ->getJson("/api/v1/admin/reports/sales?from=2026-09-01&to=2026-09-30&branch_id={$this->lilongwe->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.summary.pos_revenue', 150000)
            ->assertJsonPath('data.filters.scope_label', 'Lilongwe')
            ->assertJsonPath('data.filters.all_branches', false);
    });

    it('spans every branch when all_branches is requested', function () {
        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000);
        ($this->makeSale)($this->blantyre, '2026-09-10 10:00:00', 250000);

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/sales?from=2026-09-01&to=2026-09-30&all_branches=1')
            ->assertSuccessful()
            ->assertJsonPath('data.summary.pos_revenue', 400000)
            ->assertJsonPath('data.filters.all_branches', true)
            ->assertJsonPath('data.filters.branch_id', null);
    });

    it('keeps a store admin on their own branch even when another is requested', function () {
        $storeAdmin = User::factory()->create(['branch_id' => $this->lilongwe->id]);
        $storeAdmin->assignRole('store_admin');

        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000);
        ($this->makeSale)($this->blantyre, '2026-09-10 10:00:00', 250000);

        $this->actingAs($storeAdmin)
            ->getJson("/api/v1/admin/reports/sales?branch_id={$this->blantyre->id}&all_branches=1")
            ->assertSuccessful()
            ->assertJsonPath('data.summary.pos_revenue', 150000)
            ->assertJsonPath('data.filters.branch_id', $this->lilongwe->id);
    });

    it('applies branch scoping to every report endpoint, not just sales', function () {
        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000);
        ($this->makeSale)($this->blantyre, '2026-09-10 10:00:00', 250000);

        foreach (['sales', 'stock', 'devices', 'theft', 'subscriptions', 'users', 'revenue', 'staff-time'] as $report) {
            $this->actingAs($this->admin)
                ->getJson("/api/v1/admin/reports/{$report}?branch_id={$this->lilongwe->id}")
                ->assertSuccessful()
                ->assertJsonPath('data.filters.branch_id', $this->lilongwe->id);
        }
    });

    it('rejects a branch that does not exist', function () {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/sales?branch_id=999999')
            ->assertStatus(422);
    });

    it('rejects a date range whose end precedes its start', function () {
        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/reports/sales?from=2026-09-30&to=2026-09-01')
            ->assertStatus(422);
    });
});

describe('Excel export', function () {
    it('streams a workbook with one sheet per section', function () {
        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000, 100000);

        $response = $this->actingAs($this->admin)
            ->get('/api/v1/admin/reports/export?report=sales&from=2026-09-01&to=2026-09-30');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        expect($response->headers->get('Content-Disposition'))
            ->toContain('attachment')
            ->toContain('mufassah-sales-report')
            ->toContain('.xlsx');

        $names = ($this->sheetNames)($response->streamedContent());

        expect($names)->toBe([
            'Summary',
            'By branch',
            'By product',
            'By day',
            'Sales detail',
            'Administrative sales',
        ]);
    });

    it('writes the sale rows into the detail sheet', function () {
        $sale = ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000, 100000);

        $response = $this->actingAs($this->admin)
            ->get('/api/v1/admin/reports/export?report=sales&from=2026-09-01&to=2026-09-30');

        expect(($this->sheetXml)($response->streamedContent()))
            ->toContain($sale->sale_number)
            ->toContain('Lilongwe');
    });

    it('honours the branch filter in the exported rows', function () {
        $kept = ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000);
        $dropped = ($this->makeSale)($this->blantyre, '2026-09-10 10:00:00', 250000);

        $response = $this->actingAs($this->admin)
            ->get("/api/v1/admin/reports/export?report=sales&branch_id={$this->lilongwe->id}");

        $xml = ($this->sheetXml)($response->streamedContent());

        expect($xml)->toContain($kept->sale_number)
            ->not->toContain($dropped->sale_number);
    });

    it('records the scope and period on the summary sheet', function () {
        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000);

        $response = $this->actingAs($this->admin)
            ->get("/api/v1/admin/reports/export?report=sales&from=2026-09-01&to=2026-09-30&branch_id={$this->lilongwe->id}");

        expect(($this->sheetXml)($response->streamedContent()))
            ->toContain('2026-09-01 to 2026-09-30')
            ->toContain('Lilongwe');
    });

    it('exports money as numbers rather than pre-formatted text', function () {
        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000, 100000);

        $response = $this->actingAs($this->admin)
            ->get('/api/v1/admin/reports/export?report=sales&from=2026-09-01&to=2026-09-30');

        $xml = ($this->sheetXml)($response->streamedContent());

        // Real numeric cells, so Excel can total them.
        expect($xml)->toContain('<v>150000</v>')
            ->not->toContain('t="inlineStr"><is><t xml:space="preserve">MWK 150,000');
    });

    it('never writes a bare number as text', function () {
        // Regression: totals rows used a bold-text style that stringified counts,
        // so Excel showed "3" as text and refused to sum it.
        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000, 100000);
        ($this->makeSale)($this->lilongwe, '2026-09-11 10:00:00', 250000, 200000);

        $response = $this->actingAs($this->admin)
            ->get('/api/v1/admin/reports/export?report=sales&from=2026-09-01&to=2026-09-30');

        $xml = ($this->sheetXml)($response->streamedContent());

        expect($xml)->not->toMatch('/<t xml:space="preserve">-?[0-9]+(\.[0-9]+)?<\/t>/');
    });

    it('exports every supported report type', function () {
        ($this->makeSale)($this->lilongwe, '2026-09-10 10:00:00', 150000);

        foreach (ReportExportService::reportTypes() as $report) {
            $response = $this->actingAs($this->admin)
                ->get("/api/v1/admin/reports/export?report={$report}&from=2026-01-01&to=2026-12-31");

            $response->assertOk();

            expect(($this->sheetNames)($response->streamedContent()))
                ->toContain('Summary');
        }
    });

    it('rejects an unknown report type', function () {
        $this->actingAs($this->admin)
            ->get('/api/v1/admin/reports/export?report=payroll')
            ->assertStatus(422)
            ->assertJsonValidationErrors('report');
    });

    it('requires a report type', function () {
        $this->actingAs($this->admin)
            ->get('/api/v1/admin/reports/export')
            ->assertStatus(422)
            ->assertJsonValidationErrors('report');
    });

    it('refuses a customer', function () {
        $this->actingAs($this->customer)
            ->get('/api/v1/admin/reports/export?report=sales')
            ->assertForbidden();
    });

    it('refuses an unauthenticated request', function () {
        $this->getJson('/api/v1/admin/reports/export?report=sales')
            ->assertUnauthorized();
    });
});
