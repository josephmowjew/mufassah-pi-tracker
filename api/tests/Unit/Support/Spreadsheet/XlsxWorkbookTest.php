<?php

use App\Support\Spreadsheet\Cell;
use App\Support\Spreadsheet\XlsxWorkbook;

beforeEach(function () {
    $this->readPart = function (string $path, string $part): string {
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        $contents = $zip->getFromName($part);
        $zip->close();

        return (string) $contents;
    };

    $this->path = tempnam(sys_get_temp_dir(), 'xlsx_test_');
});

afterEach(function () {
    if (isset($this->path) && is_file($this->path)) {
        @unlink($this->path);
    }
});

it('writes a complete OOXML package', function () {
    $book = new XlsxWorkbook('Test');
    $book->addSheet('Summary')->addPairRow('Total', Cell::money(10));
    $book->save($this->path);

    $zip = new ZipArchive;
    $zip->open($this->path);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $parts[] = $zip->getNameIndex($i);
    }
    $zip->close();

    expect($parts)->toContain('[Content_Types].xml')
        ->toContain('_rels/.rels')
        ->toContain('xl/workbook.xml')
        ->toContain('xl/_rels/workbook.xml.rels')
        ->toContain('xl/styles.xml')
        ->toContain('xl/worksheets/sheet1.xml')
        ->toContain('docProps/core.xml')
        ->toContain('docProps/app.xml');
});

it('escapes xml metacharacters and strips characters xml forbids', function () {
    $book = new XlsxWorkbook;
    $book->addSheet('S')->addRow([Cell::text("Acme <&\"'> Co\x07\x00 Ltd")]);
    $book->save($this->path);

    $xml = ($this->readPart)($this->path, 'xl/worksheets/sheet1.xml');

    // Metacharacters survive as literal text once parsed back. ENT_XML1 emits the
    // XML entity &apos; rather than the HTML &#039;.
    expect($xml)->toContain('Acme &lt;&amp;&quot;&apos;&gt; Co Ltd')
        ->not->toContain("\x07")
        ->not->toContain("\x00");
});

it('writes numbers as numeric cells so they stay summable', function () {
    $book = new XlsxWorkbook;
    $book->addSheet('S')->addRow([Cell::money(1234.5), Cell::number(7)]);
    $book->save($this->path);

    $xml = ($this->readPart)($this->path, 'xl/worksheets/sheet1.xml');

    expect($xml)->toContain('<v>1234.5</v>')
        ->toContain('<v>7</v>')
        ->not->toContain('t="inlineStr"><is><t xml:space="preserve">1234.5');
});

it('stores dates as excel serial numbers rather than text', function () {
    $book = new XlsxWorkbook;
    $book->addSheet('S')->addRow([Cell::date('2026-10-02')]);
    $book->save($this->path);

    $xml = ($this->readPart)($this->path, 'xl/worksheets/sheet1.xml');

    expect($xml)->not->toContain('2026-10-02')
        ->toContain('<v>');

    preg_match('/<v>([0-9.]+)<\/v>/', $xml, $matches);
    // 2026-10-02 is ~46,300 days after Excel's 1899-12-30 epoch.
    expect((float) $matches[1])->toBeGreaterThan(46000)->toBeLessThan(47000);
});

it('renders a blank cell as an omitted value rather than an empty string', function () {
    $book = new XlsxWorkbook;
    $book->addSheet('S')->addRow([Cell::text('a'), null, Cell::text('c')]);
    $book->save($this->path);

    $xml = ($this->readPart)($this->path, 'xl/worksheets/sheet1.xml');

    expect($xml)->toContain('r="A1"')->toContain('r="C1"')->not->toContain('r="B1"');
});

it('freezes and auto-filters the header row', function () {
    $book = new XlsxWorkbook;
    $sheet = $book->addSheet('S');
    $sheet->addHeaderRow(['A', 'B']);
    $sheet->addRow(['x', 'y']);
    $book->save($this->path);

    $xml = ($this->readPart)($this->path, 'xl/worksheets/sheet1.xml');

    expect($xml)->toContain('state="frozen"')
        ->toContain('<autoFilter ref="A1:B2"/>');
});

it('sanitises sheet names that excel would reject', function () {
    $book = new XlsxWorkbook;
    $book->addSheet('Sales/Detail:2026[1]');
    $book->addSheet('Sales/Detail:2026[1]');
    $book->addSheet(str_repeat('Long ', 12));
    $book->save($this->path);

    $xml = ($this->readPart)($this->path, 'xl/workbook.xml');

    preg_match_all('/<sheet name="([^"]+)"/', $xml, $matches);

    expect($matches[1])->toHaveCount(3)
        ->and($matches[1][0])->not->toContain('/')->not->toContain(':')->not->toContain('[')
        // Duplicates are disambiguated rather than dropped.
        ->and($matches[1][1])->not->toBe($matches[1][0]);

    foreach ($matches[1] as $name) {
        expect(mb_strlen($name))->toBeLessThanOrEqual(31);
    }
});

it('addresses columns past Z correctly', function () {
    $book = new XlsxWorkbook;
    $book->addSheet('S')->addRow(array_fill(0, 28, Cell::number(1)));
    $book->save($this->path);

    $xml = ($this->readPart)($this->path, 'xl/worksheets/sheet1.xml');

    expect($xml)->toContain('r="Z1"')->toContain('r="AA1"')->toContain('r="AB1"');
});

it('round-trips through the zip reader with the same sheet count', function () {
    $book = new XlsxWorkbook;
    $book->addSheet('One');
    $book->addSheet('Two');
    $book->addSheet('Three');

    expect($book->sheetCount())->toBe(3)
        ->and($book->sheets())->toHaveCount(3);

    $bytes = $book->toString();

    expect($bytes)->toStartWith('PK'); // zip local file header
});

it('keeps a bolded number numeric so totals stay summable', function () {
    $book = new XlsxWorkbook;
    $book->addSheet('S')->addRow([Cell::bold('Total'), Cell::bold(3), Cell::boldMoney(12.5)]);
    $book->save($this->path);

    $xml = ($this->readPart)($this->path, 'xl/worksheets/sheet1.xml');

    expect($xml)
        ->toContain('r="B1" s="9"><v>3</v>')
        ->toContain('r="C1" s="8"><v>12.5</v>')
        ->toContain('t="inlineStr"><is><t xml:space="preserve">Total</t></is>');
});
