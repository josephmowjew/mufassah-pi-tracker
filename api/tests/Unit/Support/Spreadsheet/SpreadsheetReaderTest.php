<?php

use App\Support\Spreadsheet\SpreadsheetReader;
use App\Support\Spreadsheet\XlsxWorkbook;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/spreadsheet_reader_'.bin2hex(random_bytes(6));
    mkdir($this->dir);
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->dir);
});

/** Write fixture bytes into the per-test temp directory. */
function writeSheet(string $dir, string $name, string $contents): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, $contents);

    return $path;
}

/**
 * A minimal but real .xlsx using a shared-strings table, which is what Excel
 * produces. {@see XlsxWorkbook} writes inline strings instead, so this fixture
 * is the only way to prove the `t="s"` branch is read.
 */
function sharedStringWorkbook(string $dir): string
{
    $sheet = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="s"><v>0</v></c>
      <c r="B1" t="s"><v>1</v></c>
      <c r="C1" t="s"><v>2</v></c>
    </row>
    <row r="2">
      <c r="A2" t="s"><v>3</v></c>
      <c r="B2" t="s"><v>4</v></c>
      <c r="C2"><v>7</v></c>
    </row>
  </sheetData>
</worksheet>
XML;

    $shared = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="5" uniqueCount="5">
  <si><t>Branch</t></si>
  <si><t>Brand</t></si>
  <si><t>Quantity</t></si>
  <si><t>Lilongwe</t></si>
  <si><r><t>Sams</t></r><r><t>ung</t></r></si>
</sst>
XML;

    // The first tab points at a part that is not sheet1.xml, which is exactly the
    // case a "just read sheet1.xml" implementation gets wrong.
    $workbook = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Cover" sheetId="1" r:id="rId9"/>
    <sheet name="Stock" sheetId="2" r:id="rId1"/>
  </sheets>
</workbook>
XML;

    $rels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/cover.xml"/>
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/stock.xml"/>
</Relationships>
XML;

    $cover = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Instructions</t></is></c></row></sheetData>
</worksheet>
XML;

    $path = $dir.'/shared.xlsx';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/sharedStrings.xml', $shared);
    $zip->addFromString('xl/worksheets/cover.xml', $cover);
    $zip->addFromString('xl/worksheets/stock.xml', $sheet);
    $zip->close();

    return $path;
}

it('reads a comma separated file with numbered rows', function () {
    $path = writeSheet($this->dir, 'stock.csv', "Branch,Brand,Quantity\nLilongwe,Samsung,5\nBlantyre,Tecno,3\n");

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['headers'])->toBe(['Branch', 'Brand', 'Quantity'])
        ->and($sheet['rows'])->toHaveCount(2)
        ->and($sheet['rows'][0]['number'])->toBe(2)
        ->and($sheet['rows'][0]['cells'])->toBe(['Lilongwe', 'Samsung', '5'])
        ->and($sheet['rows'][1]['number'])->toBe(3);
});

it('strips a utf-8 byte order mark from the first header', function () {
    $path = writeSheet($this->dir, 'bom.csv', "\xEF\xBB\xBFBranch,Brand\nLilongwe,Samsung\n");

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['headers'][0])->toBe('Branch');
});

it('detects a semicolon delimiter', function () {
    $path = writeSheet($this->dir, 'semi.csv', "Branch;Brand;Quantity\nLilongwe;Samsung;5\nBlantyre;Tecno;3\n");

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['headers'])->toBe(['Branch', 'Brand', 'Quantity'])
        ->and($sheet['rows'][0]['cells'])->toBe(['Lilongwe', 'Samsung', '5']);
});

it('detects a tab delimiter', function () {
    $path = writeSheet($this->dir, 'tabs.csv', "Branch\tBrand\tQuantity\nLilongwe\tSamsung\t5\nBlantyre\tTecno\t3\n");

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['headers'])->toBe(['Branch', 'Brand', 'Quantity']);
});

it('keeps commas inside quoted fields', function () {
    $path = writeSheet($this->dir, 'quoted.csv', "Brand,Notes\nSamsung,\"Dual SIM, 128GB\"\n");

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['rows'][0]['cells'][1])->toBe('Dual SIM, 128GB');
});

it('reads a windows-1252 file without mangling text', function () {
    // "Café" in Windows-1252 — not valid UTF-8, which is what Excel on Windows
    // writes for "CSV (Comma delimited)".
    $path = writeSheet($this->dir, 'latin.csv', "Brand,Notes\nSamsung,Caf\xE9\n");

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['rows'][0]['cells'][1])->toBe('Café');
});

it('skips a blank spacer row before the header', function () {
    $path = writeSheet($this->dir, 'spacer.csv', ",,\nBranch,Brand,Quantity\nLilongwe,Samsung,5\n");

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['headers'])->toBe(['Branch', 'Brand', 'Quantity'])
        ->and($sheet['rows'])->toHaveCount(1)
        ->and($sheet['rows'][0]['number'])->toBe(3);
});

it('pads short rows to the header width', function () {
    $path = writeSheet($this->dir, 'short.csv', "Branch,Brand,Quantity\nLilongwe,Samsung\n");

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['rows'][0]['cells'])->toBe(['Lilongwe', 'Samsung', null]);
});

it('drops trailing empty header columns', function () {
    $path = writeSheet($this->dir, 'trailing.csv', "Branch,Brand,,\nLilongwe,Samsung,,\n");

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['headers'])->toBe(['Branch', 'Brand'])
        ->and($sheet['rows'][0]['cells'])->toBe(['Lilongwe', 'Samsung']);
});

it('reads a workbook written by the xlsx writer', function () {
    $path = $this->dir.'/written.xlsx';

    $book = new XlsxWorkbook;
    $book->addSheet('Stock')
        ->addHeaderRow(['Branch', 'Brand', 'Quantity'])
        ->addRow(['Lilongwe', 'Samsung', 5])
        ->addRow(['Blantyre', 'Tecno', 3]);
    $book->save($path);

    $sheet = (new SpreadsheetReader)->read($path, 'xlsx');

    expect($sheet['headers'])->toBe(['Branch', 'Brand', 'Quantity'])
        ->and($sheet['rows'][0]['cells'])->toBe(['Lilongwe', 'Samsung', '5'])
        ->and($sheet['rows'][1]['cells'])->toBe(['Blantyre', 'Tecno', '3']);
});

it('resolves the first sheet through the relationships instead of guessing sheet1', function () {
    $sheet = (new SpreadsheetReader)->read(sharedStringWorkbook($this->dir), 'xlsx');

    // The first tab is "Cover" and its relationship points at cover.xml, so the
    // reader must follow it rather than defaulting to sheet1.xml.
    expect($sheet['headers'])->toBe(['Instructions'])
        ->and($sheet['rows'])->toBe([]);
});

it('reads shared strings and joins rich text runs', function () {
    $path = sharedStringWorkbook($this->dir);

    // Point the first tab at the stock part so the rich text run ("Sams" +
    // "ung") is actually read.
    $zip = new ZipArchive;
    $zip->open($path);
    $workbook = $zip->getFromName('xl/workbook.xml');
    $zip->addFromString('xl/workbook.xml', str_replace('rId9', 'rId1', (string) $workbook));
    $zip->close();

    $sheet = (new SpreadsheetReader)->read($path, 'xlsx');

    expect($sheet['headers'])->toBe(['Branch', 'Brand', 'Quantity'])
        ->and($sheet['rows'][0]['cells'])->toBe(['Lilongwe', 'Samsung', '7']);
});

it('rejects an unsupported extension', function () {
    $path = writeSheet($this->dir, 'legacy.xls', "Branch\nLilongwe\n");

    expect(fn () => (new SpreadsheetReader)->read($path, 'xls'))
        ->toThrow(RuntimeException::class, 'Unsupported file type');
});

it('reports whether an extension is supported', function () {
    expect(SpreadsheetReader::supports('csv'))->toBeTrue()
        ->and(SpreadsheetReader::supports('CSV'))->toBeTrue()
        ->and(SpreadsheetReader::supports('xlsx'))->toBeTrue()
        ->and(SpreadsheetReader::supports('xls'))->toBeFalse()
        ->and(SpreadsheetReader::supports(null))->toBeFalse();
});

/** Build a CSV with a header plus $dataRows identical stock rows. */
function bulkCsv(int $dataRows, string $prefix = ''): string
{
    $lines = [];

    if ($prefix !== '') {
        $lines[] = $prefix;
    }

    $lines[] = 'Branch,Category,Brand,Model,Quantity,Buying Price,Target Selling Price';

    for ($i = 1; $i <= $dataRows; $i++) {
        $lines[] = "Lilongwe,Smartphones,Samsung,Galaxy A{$i},1,320000,395000";
    }

    return implode("\n", $lines)."\n";
}

it('flags a file that was cut short at the row ceiling', function () {
    $path = writeSheet($this->dir, 'big.csv', bulkCsv(SpreadsheetReader::MAX_DATA_ROWS + 500));

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['truncated'])->toBeTrue()
        // One row past the ceiling is kept, so the caller can tell the limit was
        // exceeded rather than merely reached.
        ->and($sheet['rows'])->toHaveCount(SpreadsheetReader::MAX_DATA_ROWS + 1);
});

it('still flags truncation when a non-empty row sits above the header', function () {
    // Regression: the reader caps *matrix* rows while the caller validates
    // *data* rows. A whitespace-only spacer above the header shifts that
    // arithmetic by one, so the data-row count lands exactly on the ceiling and
    // the caller's "more than 2000 rows" check never fires — the surplus rows
    // were dropped and the import reported success.
    $path = writeSheet($this->dir, 'spacer.csv', bulkCsv(SpreadsheetReader::MAX_DATA_ROWS + 500, '   '));

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['truncated'])->toBeTrue()
        ->and($sheet['rows'])->toHaveCount(SpreadsheetReader::MAX_DATA_ROWS);
});

it('does not flag a file that exactly fills the row budget', function () {
    $path = writeSheet($this->dir, 'exact.csv', bulkCsv(SpreadsheetReader::MAX_DATA_ROWS));

    $sheet = (new SpreadsheetReader)->read($path, 'csv');

    expect($sheet['truncated'])->toBeFalse()
        ->and($sheet['rows'])->toHaveCount(SpreadsheetReader::MAX_DATA_ROWS);
});
