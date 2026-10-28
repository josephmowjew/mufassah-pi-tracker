<?php

namespace App\Services;

use App\Support\Spreadsheet\Cell;
use App\Support\Spreadsheet\XlsxWorkbook;

/**
 * Builds the downloadable stock-import sample.
 *
 * The sample is the contract made concrete: whatever headers appear here are the
 * headers {@see InventoryImportService} recognises, in an order that reads
 * naturally. Two formats are offered because the two audiences differ — `.xlsx`
 * carries a second "Instructions" sheet and is the one to hand to someone who
 * has never done this before, while `.csv` is what the same file has to become
 * before a browser upload in some environments.
 *
 * The example rows are deliberately valid stock data rather than placeholders
 * like "e.g. Samsung": a sample that cannot be imported cannot prove the format
 * works. The trade-off is that an operator who uploads the file untouched would
 * create three example batches, so the preview step in the UI exists precisely
 * to show that before anything is written, and the instructions say to delete
 * the examples first.
 */
class InventoryImportTemplate
{
    /** Column order in the sample. Matches {@see InventoryImportService::COLUMNS}. */
    public const HEADERS = [
        'Branch',
        'Category',
        'Brand',
        'Model',
        'Color',
        'Quantity',
        'Buying Price',
        'Target Selling Price',
        'Serial Numbers',
        'Supplier',
        'Reference Number',
        'Purchase Date',
        'Purchase Location',
        'Description',
        'Notes',
    ];

    /**
     * Example rows covering both branches named in the requirement, one row with
     * explicit serial numbers and one with the price left blank to show
     * inheritance.
     *
     * @return list<list<string|int|null>>
     */
    public static function exampleRows(): array
    {
        // Relative dates, not fixed ones: a sample dated in the past would fail
        // its own future-date rule the moment an operator downloads it months
        // later.
        $firstDelivery = now()->subDays(8)->toDateString();
        $secondDelivery = now()->subDays(6)->toDateString();

        return [
            ['Lilongwe', 'Smartphones', 'Samsung', 'Galaxy A15', 'Blue', 5, 320000, 395000, '', 'Mufasah Imports', 'INV-2026-0104', $firstDelivery, 'Lilongwe Warehouse', 'Dual SIM', 'Opening stock'],
            ['Lilongwe', 'Smartphones', 'Tecno', 'Spark 20', 'Black', 3, 185000, 235000, '', 'Mufasah Imports', 'INV-2026-0104', $firstDelivery, 'Lilongwe Warehouse', '', ''],
            // Two units, two serials: shows that a serialised line lists one
            // serial per unit.
            ['Blantyre', 'Smartphones', 'Samsung', 'Galaxy A15', 'Blue', 2, 325000, 400000, 'SM-A155-0001, SM-A155-0002', 'Mufasah Imports', 'INV-2026-0105', $secondDelivery, 'Blantyre Warehouse', '', 'Serialised delivery'],
            // Every row carries a selling price so the sample imports cleanly on
            // a brand-new installation; leaving the column blank is documented in
            // the Instructions sheet instead of demonstrated here, because
            // inheritance needs earlier stock to inherit *from*.
            ['Blantyre', 'Accessories', 'Anker', 'PowerCore 10000', 'Black', 10, 42000, 58000, '', 'Mufasah Imports', 'INV-2026-0105', $secondDelivery, 'Blantyre Warehouse', 'Power bank', 'Bulk accessories'],
        ];
    }

    /**
     * Column documentation used by the "Instructions" sheet.
     *
     * @return list<list<string>>
     */
    public static function columnGuide(): array
    {
        return [
            ['Branch', 'Recommended', 'Branch name or code', 'Lilongwe', 'Must match a branch in the system. Leave blank only if you pick a default branch in the upload form.'],
            ['Category', 'Yes', 'Existing category name', 'Smartphones', 'Must already exist. New categories are created in Product Catalog, never by an upload.'],
            ['Brand', 'Yes', 'Text', 'Samsung', 'A new brand is added to the catalog automatically.'],
            ['Model', 'Yes', 'Text', 'Galaxy A15', 'A new model is added to the catalog automatically.'],
            ['Color', 'No', 'Text', 'Blue', 'Optional. Used for display and grouping.'],
            ['Quantity', 'Yes', 'Whole number 1-100', '5', 'One device is created per unit. Use another row to receive more than 100 of one product.'],
            ['Buying Price', 'Yes', 'Number in MWK', '320000', 'Cost per unit for this delivery. Currency symbols and thousands separators are accepted.'],
            ['Target Selling Price', 'No', 'Number in MWK', '395000', 'Selling price for this batch. Leave blank to reuse the price already in use for the product.'],
            ['Serial Numbers', 'No', 'List separated by commas', 'SM-A155-0001, SM-A155-0002', 'One serial per unit. Leave blank to have serials generated.'],
            ['Supplier', 'No', 'Text', 'Mufasah Imports', 'Copied onto the receipt for the branch.'],
            ['Reference Number', 'No', 'Text', 'INV-2026-0104', 'Invoice or delivery note number, copied onto the receipt.'],
            ['Purchase Date', 'No', 'YYYY-MM-DD', '2026-10-20', 'Cannot be in the future.'],
            ['Purchase Location', 'No', 'Text', 'Lilongwe Warehouse', 'Where the stock was collected or delivered from.'],
            ['Description', 'No', 'Text', 'Dual SIM', 'Stored on each device of this line.'],
            ['Notes', 'No', 'Text', 'Opening stock', 'Stored on the batch and each device.'],
        ];
    }

    /**
     * A UTF-8 CSV with a byte-order mark.
     *
     * The BOM is not decoration: without it Excel on Windows opens the file as
     * Windows-1252 and mangles any non-ASCII text. The reader strips it again on
     * the way back in, so a round trip is lossless.
     */
    public function csv(): string
    {
        $stream = fopen('php://temp', 'r+');

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::HEADERS, ',', '"', '');

        foreach (self::exampleRows() as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return (string) $contents;
    }

    /**
     * A workbook with the stock sheet first and the instructions second.
     *
     * Order matters: the importer reads the *first* worksheet, so a workbook
     * that opened on the instructions would import prose.
     */
    public function xlsx(): string
    {
        $workbook = new XlsxWorkbook('Mufassah Device Tracking');

        $stock = $workbook->addSheet('Stock');
        $stock->addHeaderRow(self::HEADERS);
        $stock->addRows(self::exampleRows());
        $stock->setColumnWidths([14, 16, 14, 22, 10, 10, 14, 20, 26, 18, 18, 14, 20, 16, 26]);

        $instructions = $workbook->addSheet('Instructions');
        $instructions->addTitleRow('How to fill in the Stock sheet');
        $instructions->addPairRow('Required columns', 'Branch (or a default branch in the form), Category, Brand, Model, Quantity, Buying Price');
        $instructions->addPairRow('Optional columns', 'Color, Target Selling Price, Serial Numbers, Supplier, Reference Number, Purchase Date, Purchase Location, Description, Notes');
        $instructions->addPairRow('Column order', 'Any order. Columns are matched by header name, not position.');
        $instructions->addPairRow('Headers', 'Do not rename or add columns — an unknown header stops the upload so nothing is imported by accident.');
        $instructions->addPairRow('Branches', 'One row per product per branch. A file may mix branches; each branch gets its own receipt.');
        $instructions->addPairRow('Leaving a price blank', 'If Target Selling Price is empty the batch inherits the price already in use for that product. It fails only when there is no earlier price to inherit. This sample fills every price in so it imports on a new installation.');
        $instructions->addBlankRow();
        $instructions->addPairRow('Before uploading', 'Delete the example rows on the Stock sheet, then save the file.');
        $instructions->addPairRow('Uploading', 'Inventory → Import from Excel/CSV → choose the file → review the preview → confirm.');
        $instructions->addPairRow('If a row fails', 'Nothing is imported. Fix the rows marked in red and upload again.');
        $instructions->addBlankRow();
        // A plain bold row rather than addHeaderRow(): that would freeze and
        // autofilter the instruction paragraphs above it, which reads as a
        // broken table in Excel. This sheet is documentation, not data.
        $instructions->addRow([
            Cell::bold('Column'),
            Cell::bold('Required'),
            Cell::bold('Format'),
            Cell::bold('Example'),
            Cell::bold('Notes'),
        ]);
        $instructions->addRows(self::columnGuide());
        $instructions->setColumnWidths([20, 12, 24, 26, 70]);

        return $workbook->toString();
    }

    public function filename(string $format): string
    {
        $date = now()->format('Y-m-d');

        return "mufassah-stock-import-sample-{$date}.".($format === 'xlsx' ? 'xlsx' : 'csv');
    }

    public function mimeType(string $format): string
    {
        return $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=UTF-8';
    }
}
