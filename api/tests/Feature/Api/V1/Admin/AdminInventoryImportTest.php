<?php

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\User;
use App\Services\InventoryImportTemplate;
use App\Support\Spreadsheet\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');

    $this->storeAdmin = User::factory()->create();
    $this->storeAdmin->assignRole('store_admin');

    $this->customerService = User::factory()->create();
    $this->customerService->assignRole('customer_service');

    $this->lilongwe = Branch::create(['name' => 'Lilongwe', 'code' => 'LLW', 'is_active' => true]);
    $this->blantyre = Branch::create(['name' => 'Blantyre', 'code' => 'BTY', 'is_active' => true]);

    $this->phones = DeviceCategory::factory()->create(['name' => 'Smartphones']);
    $this->accessories = DeviceCategory::factory()->create(['name' => 'Accessories']);
});

/**
 * Post a spreadsheet to an import endpoint as an authenticated admin.
 *
 * @param  array<string, mixed>  $extra
 */
function postImport(string $endpoint, string $contents, string $filename = 'stock.csv', array $extra = [], ?User $user = null): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($user ?? test()->admin)
        ->withHeaders(['Accept' => 'application/json'])
        ->post($endpoint, [
            'file' => UploadedFile::fake()->createWithContent($filename, $contents),
            ...$extra,
        ]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function importCsv(string $contents, string $filename = 'stock.csv', array $extra = [], ?User $user = null): \Illuminate\Testing\TestResponse
{
    return postImport('/api/v1/admin/inventory/import', $contents, $filename, $extra, $user);
}

/**
 * @param  array<string, mixed>  $extra
 */
function previewCsv(string $contents, string $filename = 'stock.csv', array $extra = [], ?User $user = null): \Illuminate\Testing\TestResponse
{
    return postImport('/api/v1/admin/inventory/import/preview', $contents, $filename, $extra, $user);
}

function stockCsv(array $rows): string
{
    $lines = ['Branch,Category,Brand,Model,Quantity,Buying Price,Target Selling Price'];

    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }

    return implode("\n", $lines)."\n";
}

describe('Import preview', function () {
    it('describes what the file would create without writing anything', function () {
        $csv = stockCsv([
            ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 2, 320000, 395000],
            ['Blantyre', 'Smartphones', 'Samsung', 'Galaxy A15', 3, 325000, 400000],
        ]);

        $response = $this->actingAs($this->admin)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/admin/inventory/import/preview', [
                'file' => UploadedFile::fake()->createWithContent('stock.csv', $csv),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.can_import', true)
            ->assertJsonPath('data.summary.rows', 2)
            ->assertJsonPath('data.summary.valid_rows', 2)
            ->assertJsonPath('data.summary.units', 5)
            ->assertJsonPath('data.summary.total_cost', 1615000);

        expect(Device::count())->toBe(0)
            ->and(InventoryBatch::count())->toBe(0)
            ->and(InventoryReceipt::count())->toBe(0);
    });

    it('lists the branches the file would fill', function () {
        $csv = stockCsv([
            ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 2, 320000, 395000],
            ['Blantyre', 'Smartphones', 'Samsung', 'Galaxy A15', 3, 325000, 400000],
        ]);

        $response = $this->actingAs($this->admin)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/admin/inventory/import/preview', [
                'file' => UploadedFile::fake()->createWithContent('stock.csv', $csv),
            ]);

        $branches = collect($response->json('data.branches'))->keyBy('branch');

        expect($branches)->toHaveCount(2)
            ->and($branches['Lilongwe']['units'])->toBe(2)
            ->and($branches['Blantyre']['units'])->toBe(3);
    });
});

describe('Import commit', function () {
    it('imports rows for several branches and files one receipt per branch', function () {
        $csv = stockCsv([
            ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 2, 320000, 395000],
            ['Blantyre', 'Smartphones', 'Samsung', 'Galaxy A15', 3, 325000, 400000],
        ]);

        $response = $this->actingAs($this->admin)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/admin/inventory/import', [
                'file' => UploadedFile::fake()->createWithContent('stock.csv', $csv),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.units', 5)
            ->assertJsonPath('data.batches', 2);

        // The confirmation panel lists the receipt each branch got, by name.
        $branches = collect($response->json('data.branches'))->keyBy('branch');

        expect($branches)->toHaveCount(2)
            ->and($branches['Lilongwe']['receipt_number'])->toStartWith('REC-')
            ->and($branches['Blantyre']['receipt_number'])->toStartWith('REC-');

        expect(InventoryReceipt::count())->toBe(2)
            ->and(InventoryBatch::count())->toBe(2)
            ->and(Device::count())->toBe(5)
            ->and(Device::where('branch_id', $this->lilongwe->id)->count())->toBe(2)
            ->and(Device::where('branch_id', $this->blantyre->id)->count())->toBe(3);

        // Each branch keeps its own cost, which is the whole point of a batch.
        $lilongweBatch = InventoryBatch::where('branch_id', $this->lilongwe->id)->first();
        $blantyreBatch = InventoryBatch::where('branch_id', $this->blantyre->id)->first();

        expect((float) $lilongweBatch->buying_price)->toBe(320000.0)
            ->and((float) $lilongweBatch->target_selling_price)->toBe(395000.0)
            ->and((float) $blantyreBatch->buying_price)->toBe(325000.0)
            ->and((float) $blantyreBatch->target_selling_price)->toBe(400000.0);

        // Devices must be auditable from the moment they exist.
        expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_RECEIPT)->count())->toBe(5);
    });

    it('records the upload on the receipt and in the activity log', function () {
        $csv = stockCsv([['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000]]);

        importCsv($csv, 'october-delivery.csv');

        expect(InventoryReceipt::first()->notes)->toContain('october-delivery.csv')
            ->and(ActivityLog::where('event', 'inventory_import')->count())->toBe(1);
    });

    it('matches a branch by its code as well as its name', function () {
        $csv = stockCsv([['LLW', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000]]);

        importCsv($csv)->assertCreated();

        expect(Device::first()->branch_id)->toBe($this->lilongwe->id);
    });

    it('accepts a branch name with the word branch appended', function () {
        $csv = stockCsv([['Blantyre Branch', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000]]);

        importCsv($csv)->assertCreated();

        expect(Device::first()->branch_id)->toBe($this->blantyre->id);
    });

    it('falls back to the chosen branch when the column is missing', function () {
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price\n"
            ."Smartphones,Samsung,Galaxy A15,2,320000,395000\n";

        importCsv($csv, 'stock.csv', ['branch_id' => $this->blantyre->id])->assertCreated();

        expect(Device::where('branch_id', $this->blantyre->id)->count())->toBe(2);
    });

    it('inherits the selling price already in use when the column is blank', function () {
        $first = stockCsv([['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000]]);
        importCsv($first)->assertCreated();

        $second = stockCsv([['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 310000, '']]);
        $response = importCsv($second)->assertCreated();

        $latest = InventoryBatch::orderByDesc('id')->first();

        expect((float) $latest->buying_price)->toBe(310000.0)
            ->and((float) $latest->target_selling_price)->toBe(395000.0)
            ->and($response->json('data.batches'))->toBe(1);
    });

    it('reports the inherited price in the preview', function () {
        importCsv(stockCsv([['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000]]));

        $response = previewCsv(stockCsv([['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 310000, '']]));

        expect($response->json('data.rows.0.price_source'))->toBe('inherited')
            ->and($response->json('data.rows.0.target_selling_price'))->toBe(395000);
    });

    it('parses prices with currency symbols and thousands separators', function () {
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price\n"
            ."Smartphones,Samsung,Galaxy A15,2,\"MWK 320,000\",\"1,250,000.50\"\n";

        importCsv($csv, 'stock.csv', ['branch_id' => $this->lilongwe->id])->assertCreated();

        $batch = InventoryBatch::first();

        expect((float) $batch->buying_price)->toBe(320000.0)
            ->and((float) $batch->target_selling_price)->toBe(1250000.5);
    });

    it('reads a decimal comma price', function () {
        // A decimal comma has to be quoted in a comma-separated file, which is
        // exactly what Excel writes when the machine's locale uses one.
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price\n"
            ."Smartphones,Samsung,Galaxy A15,1,\"1250,50\",\"1899,99\"\n";

        importCsv($csv, 'stock.csv', ['branch_id' => $this->lilongwe->id])->assertCreated();

        $batch = InventoryBatch::first();

        expect((float) $batch->buying_price)->toBe(1250.5)
            ->and((float) $batch->target_selling_price)->toBe(1899.99);
    });

    it('uses the serial numbers supplied in the file', function () {
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price,Serial Numbers\n"
            ."Smartphones,Samsung,Galaxy A15,2,320000,395000,\"SM-001, SM-002\"\n";

        importCsv($csv, 'stock.csv', ['branch_id' => $this->lilongwe->id])->assertCreated();

        expect(Device::orderBy('id')->pluck('serial_number')->all())->toBe(['SM-001', 'SM-002']);
    });

    it('generates serial numbers when the column is blank', function () {
        $csv = stockCsv([['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 2, 320000, 395000]]);

        importCsv($csv)->assertCreated();

        $serials = Device::pluck('serial_number');

        expect($serials)->toHaveCount(2)
            ->and($serials->filter()->count())->toBe(2)
            ->and($serials->unique()->count())->toBe(2);
    });

    it('adds unknown brands and models to the catalog', function () {
        $csv = stockCsv([['Lilongwe', 'Smartphones', 'Itel', 'A70', 1, 90000, 130000]]);

        importCsv($csv)->assertCreated();

        expect(Device::first()->brand)->toBe('Itel')
            ->and(Device::first()->product_model_id)->not->toBeNull();
    });
});

describe('Import validation', function () {
    it('rejects a file with an unrecognised column header', function () {
        $csv = "Branch,Category,Brand,Model,Quantity,Buying Price,Wholesale Price\n"
            ."Lilongwe,Smartphones,Samsung,Galaxy A15,2,320000,395000\n";

        $response = importCsv($csv);

        $response->assertStatus(422)
            ->assertJsonPath('data.can_import', false);

        expect($response->json('data.errors.0'))->toContain('Unrecognised column header')
            ->and(Device::count())->toBe(0);
    });

    it('rejects a negative buying price', function () {
        $response = importCsv(stockCsv([['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, -320000, 395000]]));

        $response->assertStatus(422)->assertJsonPath('data.can_import', false);

        expect(implode(' ', $response->json('data.rows.0.errors')))->toContain('cannot be negative')
            ->and(Device::count())->toBe(0);
    });

    it('rejects a negative target selling price', function () {
        // Parity with the manual receipt form, whose rule is
        // `target_selling_price => nullable|numeric|min:0`. Without this the
        // spreadsheet was a way to store a negative price the form refuses, and
        // every revenue and margin figure downstream inherits the sign error.
        $response = importCsv(stockCsv([['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, -395000]]));

        $response->assertStatus(422)->assertJsonPath('data.can_import', false);

        expect(implode(' ', $response->json('data.rows.0.errors')))->toContain('cannot be negative')
            ->and(Device::count())->toBe(0);
    });

    it('rejects a file missing a required column', function () {
        $csv = "Branch,Category,Brand,Quantity,Buying Price\n"
            ."Lilongwe,Smartphones,Samsung,2,320000\n";

        $response = importCsv($csv);

        $response->assertStatus(422);

        expect($response->json('data.errors.0'))->toContain('Missing required column')
            ->and($response->json('data.errors.0'))->toContain('Model');
    });

    it('rejects two columns that map to the same field', function () {
        $csv = "Branch,Category,Brand,Model,Quantity,Cost,Buying Price\n"
            ."Lilongwe,Smartphones,Samsung,Galaxy A15,2,320000,330000\n";

        $response = importCsv($csv);

        $response->assertStatus(422);

        expect($response->json('data.errors.0'))->toContain('More than one column maps to the same field');
    });

    it('refuses the whole file when one row is invalid', function () {
        $csv = stockCsv([
            ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 2, 320000, 395000],
            ['Lilongwe', 'Tablets', 'Samsung', 'Galaxy Tab', 1, 420000, 520000],
        ]);

        $response = importCsv($csv);

        $response->assertStatus(422)
            ->assertJsonPath('data.summary.valid_rows', 1)
            ->assertJsonPath('data.summary.invalid_rows', 1);

        expect($response->json('data.rows.1.errors.0'))->toContain('Unknown category')
            ->and(Device::count())->toBe(0)
            ->and(InventoryReceipt::count())->toBe(0);
    });

    it('names the spreadsheet row that failed', function () {
        $csv = stockCsv([
            ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 2, 320000, 395000],
            ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 0, 320000, 395000],
        ]);

        $response = importCsv($csv);

        // Header is row 1, so the second data row is spreadsheet row 3.
        expect($response->json('data.rows.1.row'))->toBe(3)
            ->and($response->json('data.rows.1.errors.0'))->toContain('at least 1');
    });

    it('rejects an unknown branch and lists the known ones', function () {
        $csv = stockCsv([['Mzuzu', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000]]);

        $response = importCsv($csv);

        expect($response->json('data.rows.0.errors.0'))->toContain('Unknown branch')
            ->and($response->json('data.rows.0.errors.0'))->toContain('Lilongwe');
    });

    it('rejects an inactive branch', function () {
        $this->lilongwe->update(['is_active' => false]);

        $response = importCsv(stockCsv([['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000]]));

        expect($response->json('data.rows.0.errors.0'))->toContain('inactive');
    });

    it('requires a branch when the file has none and no default was chosen', function () {
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price\n"
            ."Smartphones,Samsung,Galaxy A15,1,320000,395000\n";

        $response = importCsv($csv);

        expect($response->json('data.rows.0.errors.0'))->toContain('Branch is required');
    });

    it('rejects a serial count that does not match the quantity', function () {
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price,Serial Numbers\n"
            ."Smartphones,Samsung,Galaxy A15,3,320000,395000,\"SM-001, SM-002\"\n";

        $response = importCsv($csv, 'stock.csv', ['branch_id' => $this->lilongwe->id]);

        expect($response->json('data.rows.0.errors.0'))->toContain('Serial Numbers lists 2 value(s) but Quantity is 3');
    });

    it('rejects a serial number that already exists', function () {
        importCsv("Category,Brand,Model,Quantity,Buying Price,Target Selling Price,Serial Numbers\n"
            ."Smartphones,Samsung,Galaxy A15,1,320000,395000,SM-001\n", 'stock.csv', ['branch_id' => $this->lilongwe->id])
            ->assertCreated();

        $response = importCsv("Category,Brand,Model,Quantity,Buying Price,Target Selling Price,Serial Numbers\n"
            ."Smartphones,Tecno,Spark 20,1,180000,240000,SM-001\n", 'stock.csv', ['branch_id' => $this->lilongwe->id]);

        expect($response->json('data.rows.0.errors.0'))->toContain('already in the system');
    });

    it('rejects a purchase date in the future', function () {
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price,Purchase Date\n"
            .'Smartphones,Samsung,Galaxy A15,1,320000,395000,'.now()->addDays(3)->toDateString()."\n";

        $response = importCsv($csv, 'stock.csv', ['branch_id' => $this->lilongwe->id]);

        expect($response->json('data.rows.0.errors.0'))->toContain('future');
    });

    it('rejects a non-numeric quantity', function () {
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price\n"
            ."Smartphones,Samsung,Galaxy A15,two,320000,395000\n";

        $response = importCsv($csv, 'stock.csv', ['branch_id' => $this->lilongwe->id]);

        expect($response->json('data.rows.0.errors.0'))->toContain('not a whole number');
    });

    it('rejects a missing buying price', function () {
        $csv = "Category,Brand,Model,Quantity,Target Selling Price\n"
            ."Smartphones,Samsung,Galaxy A15,1,395000\n";

        $response = importCsv($csv, 'stock.csv', ['branch_id' => $this->lilongwe->id]);

        // The column is absent, so the file-level check catches it first.
        expect($response->json('data.errors.0'))->toContain('Missing required column');
    });

    it('rejects an empty file with only headers', function () {
        $response = importCsv("Branch,Category,Brand,Model,Quantity,Buying Price\n");

        expect($response->json('data.errors.0'))->toContain('no stock rows');
    });

    it('rejects an unsupported file type', function () {
        $response = postImport('/api/v1/admin/inventory/import', 'not a spreadsheet', 'stock.xls');

        $response->assertStatus(422)
            ->assertJsonValidationErrors('file');
    });

    it('reports a corrupt workbook as a file error rather than a server error', function () {
        // A .xlsx that is not a zip: a renamed .xls, a truncated download, or a
        // file the browser mangled. This used to escape as an uncaught
        // RuntimeException and render a 500.
        $response = postImport('/api/v1/admin/inventory/import', 'this is not a zip archive', 'stock.xlsx');

        $response->assertStatus(422)
            ->assertJsonPath('data.can_import', false);

        expect($response->json('data.errors.0'))->toContain('could not be opened')
            ->and(Device::count())->toBe(0);
    });

    it('reports an empty file as a file error', function () {
        $response = postImport('/api/v1/admin/inventory/import', '', 'stock.csv');

        $response->assertStatus(422);

        expect($response->json('data.errors.0'))->toContain('empty');
    });

    it('reports an unparseable date on its row instead of failing the upload', function () {
        // Carbon 3 throws on a malformed format; that must not escape as a 500.
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price,Purchase Date\n"
            ."Smartphones,Samsung,Galaxy A15,1,320000,395000,not-a-date\n";

        $response = importCsv($csv, 'stock.csv', ['branch_id' => $this->lilongwe->id]);

        $response->assertStatus(422);

        expect($response->json('data.rows.0.errors.0'))->toContain('is not a date')
            ->and(Device::count())->toBe(0);
    });

    it('rejects an overflowing date instead of rolling it into the next month', function () {
        // PHP parses 31/02 as 3 March; the round-trip check must refuse it.
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price,Purchase Date\n"
            ."Smartphones,Samsung,Galaxy A15,1,320000,395000,31/02/2026\n";

        $response = importCsv($csv, 'stock.csv', ['branch_id' => $this->lilongwe->id]);

        $response->assertStatus(422);

        expect($response->json('data.rows.0.errors.0'))->toContain('is not a date');
    });

    it('accepts the date formats an operator is likely to type', function (string $date) {
        $csv = "Category,Brand,Model,Quantity,Buying Price,Target Selling Price,Purchase Date\n"
            ."Smartphones,Samsung,Galaxy A15,1,320000,395000,{$date}\n";

        importCsv($csv, 'stock.csv', ['branch_id' => $this->lilongwe->id])->assertCreated();

        expect(InventoryBatch::first()->purchase_date?->toDateString())->toBe('2026-10-20');
    })->with([
        'iso' => ['2026-10-20'],
        'slashes' => ['2026/10/20'],
        'day first' => ['20/10/2026'],
        'month name' => ['20 Oct 2026'],
    ]);

    it('refuses a file with more rows than the reader keeps', function () {
        // The reader stops at its cap, so a silently truncated file must be
        // refused rather than imported with rows missing.
        $lines = ['Category,Brand,Model,Quantity,Buying Price,Target Selling Price'];

        for ($row = 0; $row < 2001; $row++) {
            $lines[] = 'Smartphones,Samsung,Galaxy A15,1,320000,395000';
        }

        $response = importCsv(implode("\n", $lines)."\n", 'stock.csv', ['branch_id' => $this->lilongwe->id]);

        $response->assertStatus(422);

        expect($response->json('data.errors.0'))->toContain('more than 2000 rows')
            ->and(Device::count())->toBe(0);
    });
});

describe('Import permissions', function () {
    it('forbids customer service from importing stock', function () {
        $response = $this->actingAs($this->customerService)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/admin/inventory/import', [
                'file' => UploadedFile::fake()->createWithContent('stock.csv', stockCsv([
                    ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000],
                ])),
            ])
            ->assertForbidden();

        // A 403 the operator cannot act on is a dead end; the body must still
        // explain itself, because the modal shows `message` verbatim. This one
        // is Spatie's raw string rather than the generic "You do not have
        // permission…" from bootstrap/app.php, because Spatie throws its own
        // exception type. Pinned here so a future change is a deliberate one.
        expect($response->json('message'))->toBe('User does not have the right roles.');
    });

    it('scopes a store admin to their own branch', function () {
        $this->storeAdmin->update(['branch_id' => $this->lilongwe->id]);

        $response = $this->actingAs($this->storeAdmin)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/admin/inventory/import', [
                'file' => UploadedFile::fake()->createWithContent('stock.csv', stockCsv([
                    ['Blantyre', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000],
                ])),
            ]);

        expect($response->json('data.rows.0.errors.0'))->toContain('only import stock for your own branch')
            ->and(Device::count())->toBe(0);
    });

    it('lets a store admin import into their own branch', function () {
        $this->storeAdmin->update(['branch_id' => $this->lilongwe->id]);

        $this->actingAs($this->storeAdmin)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/admin/inventory/import', [
                'file' => UploadedFile::fake()->createWithContent('stock.csv', stockCsv([
                    ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000],
                ])),
            ])
            ->assertCreated();

        expect(Device::first()->branch_id)->toBe($this->lilongwe->id);
    });

    it('refuses a store admin with no branch', function () {
        $response = $this->actingAs($this->storeAdmin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/admin/inventory/import', [
                'file' => UploadedFile::fake()->createWithContent('stock.csv', stockCsv([
                    ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 1, 320000, 395000],
                ])),
            ])
            ->assertForbidden();

        // The specific message matters here: "you do not have permission" sends
        // the operator to their manager, while "your account must be assigned to
        // a branch" tells them exactly what to fix.
        expect($response->json('message'))->toBe('Your account must be assigned to a branch before managing inventory.');
    });
});

describe('Import sample file', function () {
    it('downloads a csv sample with the documented headers', function () {
        $response = $this->actingAs($this->admin)->get('/api/v1/admin/inventory/import/template?format=csv');

        $response->assertOk();

        $contents = $response->streamedContent();

        // fputcsv quotes any field containing a space, which is valid CSV, so
        // compare the parsed header row rather than the raw bytes.
        $firstLine = strtok(ltrim($contents, "\xEF\xBB\xBF"), "\n");

        expect(str_getcsv((string) $firstLine, ',', '"', ''))->toBe(InventoryImportTemplate::HEADERS)
            ->and($contents)->toContain('Lilongwe')
            ->and($contents)->toContain('Blantyre')
            ->and($response->headers->get('content-disposition'))->toContain('.csv');
    });

    it('downloads an xlsx sample that imports without editing', function () {
        $response = $this->actingAs($this->admin)->get('/api/v1/admin/inventory/import/template');

        $response->assertOk();

        $contents = $response->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'sample_');
        file_put_contents($path, $contents);

        $import = $this->actingAs($this->admin)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/admin/inventory/import', [
                'file' => new UploadedFile($path, 'sample.xlsx', null, null, true),
            ]);

        $import->assertCreated()
            ->assertJsonPath('data.units', 20)
            ->assertJsonPath('data.batches', 4);

        expect(InventoryReceipt::count())->toBe(2);

        @unlink($path);
    });

    it('builds the sample from the catalog that actually exists', function () {
        // The seeders create "Smartphone", "Lilongwe Branch" and "Blantyre
        // Branch" — not the plural "Smartphones" and bare city names a fixture
        // can accidentally invent. A hardcoded sample failed 4/4 rows on a real
        // installation, so the sample must read the catalog at download time.
        $this->lilongwe->update(['name' => 'Lilongwe Branch']);
        $this->blantyre->update(['name' => 'Blantyre Limbe']);
        $this->phones->update(['name' => 'Smartphone']);
        $this->accessories->update(['name' => 'Home Appliance']);

        $csv = $this->actingAs($this->admin)
            ->get('/api/v1/admin/inventory/import/template?format=csv')
            ->streamedContent();

        expect($csv)->toContain('Lilongwe Branch')
            ->and($csv)->toContain('Blantyre Limbe')
            ->and($csv)->toContain('Smartphone')
            ->and($csv)->not->toContain('Smartphones');

        previewCsv($csv, 'sample.csv')
            ->assertOk()
            ->assertJsonPath('data.can_import', true)
            ->assertJsonPath('data.summary.valid_rows', 4)
            ->assertJsonPath('data.summary.units', 20);
    });

    it('puts the instructions on the second sheet so the first sheet is the stock', function () {
        $response = $this->actingAs($this->admin)->get('/api/v1/admin/inventory/import/template');

        $path = tempnam(sys_get_temp_dir(), 'sample_');
        file_put_contents($path, $response->streamedContent());

        $zip = new ZipArchive;
        $zip->open($path);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();

        expect($workbook)->toContain('name="Stock"')
            ->and($workbook)->toContain('name="Instructions"')
            ->and(strpos($workbook, 'name="Stock"'))->toBeLessThan(strpos($workbook, 'name="Instructions"'));

        @unlink($path);
    });

    it('forbids customer service from downloading the sample', function () {
        $this->actingAs($this->customerService)
            ->get('/api/v1/admin/inventory/import/template')
            ->assertForbidden();
    });
});

describe('Import transaction safety', function () {
    it('rolls back every branch when a later write fails', function () {
        $csv = stockCsv([
            ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 2, 320000, 395000],
            ['Blantyre', 'Smartphones', 'Samsung', 'Galaxy A15', 3, 325000, 400000],
        ]);

        // Fail on the second branch's batch insert, after the first branch has
        // already been written inside the transaction.
        $batches = 0;
        DB::listen(function ($query) use (&$batches) {
            if (str_contains($query->sql, 'insert into "inventory_batches"')) {
                $batches++;

                if ($batches === 2) {
                    throw new RuntimeException('Simulated failure');
                }
            }
        });

        $response = importCsv($csv);

        $response->assertStatus(500);

        expect(InventoryReceipt::count())->toBe(0)
            ->and(InventoryBatch::count())->toBe(0)
            ->and(Device::count())->toBe(0);
    });
});

describe('Import row ceiling', function () {
    /** A header plus $dataRows single-unit rows, optionally behind a spacer line. */
    $bulk = function (int $dataRows, ?string $spacer = null): string {
        $lines = [];

        if ($spacer !== null) {
            $lines[] = $spacer;
        }

        $lines[] = 'Branch,Category,Brand,Model,Quantity,Buying Price,Target Selling Price';

        for ($i = 1; $i <= $dataRows; $i++) {
            $lines[] = "Lilongwe,Smartphones,Samsung,Galaxy A{$i},1,320000,395000";
        }

        return implode("\n", $lines)."\n";
    };

    it('refuses a file with more rows than one upload may carry', function () use ($bulk) {
        $response = importCsv($bulk(SpreadsheetReader::MAX_DATA_ROWS + 5));

        $response->assertStatus(422)->assertJsonPath('data.can_import', false);

        expect(implode(' ', $response->json('data.errors')))
            ->toContain('more than '.SpreadsheetReader::MAX_DATA_ROWS.' rows')
            ->and(Device::count())->toBe(0);
    });

    it('refuses an oversized file even when a spacer row hides the overflow', function () use ($bulk) {
        // Regression: the reader caps matrix rows while the caller validates
        // data rows. With a whitespace-only row above the header the data-row
        // count landed exactly on the ceiling, so the guard stayed silent, the
        // file was cut to 2000 rows and imported — losing the rest in silence.
        $response = importCsv($bulk(SpreadsheetReader::MAX_DATA_ROWS + 5, '   '));

        $response->assertStatus(422)->assertJsonPath('data.can_import', false);

        expect(implode(' ', $response->json('data.errors')))
            ->toContain('more than '.SpreadsheetReader::MAX_DATA_ROWS.' rows')
            ->and(Device::count())->toBe(0);
    });

    it('accepts a file that exactly fills the row budget', function () use ($bulk) {
        importCsv($bulk(SpreadsheetReader::MAX_DATA_ROWS))->assertCreated();

        expect(Device::count())->toBe(SpreadsheetReader::MAX_DATA_ROWS);
    });
});
