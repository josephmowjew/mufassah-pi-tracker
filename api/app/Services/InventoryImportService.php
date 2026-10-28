<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCategory;
use App\Models\InventoryReceipt;
use App\Models\User;
use App\Support\Spreadsheet\SpreadsheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Imports a stock delivery from an uploaded spreadsheet.
 *
 * The operator fills in one row per product/branch line, uploads the file, and
 * the rows are turned into the same receipts, batches, devices and ledger
 * movements the manual "Receive Stock" form produces — by delegating to
 * {@see InventoryIntakeService}, never by writing stock itself. That is the
 * whole point of this class: it is a *translator* from spreadsheet to the intake
 * contract, not a second implementation of intake.
 *
 * Two phases, both driven from the same code path:
 *   1. {@see analyse()} parses and validates everything and returns a report.
 *      Nothing is written. The UI shows it before asking for confirmation.
 *   2. {@see commit()} takes that report and writes one receipt per branch.
 *
 * All-or-nothing: if any row is invalid, `analyse()` reports it and `commit()`
 * refuses. A half-imported delivery is worse than a rejected one, because the
 * missing units are invisible until someone counts the shelf.
 *
 * Header contract — the columns are matched by name, not position, so the
 * operator can reorder them. Matching is token-based (see {@see resolveColumns()})
 * so "Buying Price", "buying_price" and "Cost (MWK)" all land on the same field,
 * while a genuinely unknown column is refused rather than silently ignored: a
 * typo that drops a price column must fail loudly.
 */
class InventoryImportService
{
    /**
     * Ceiling on units in one upload.
     *
     * Matches the worst case the manual receipt form already allows — 50 lines
     * of 100 units — so an upload cannot create more stock in one request than
     * an operator could enter by hand. Beyond this the file is a data migration,
     * not a delivery.
     */
    public const MAX_UNITS = 5000;

    /** Units a single row may declare, mirroring the manual receipt form. */
    public const MAX_ROW_QUANTITY = 100;

    /**
     * Canonical column => accepted header spellings.
     *
     * Order matters only for tie-breaks: the first column declared wins when two
     * aliases of the same length match one header. Longer aliases always win, so
     * "Purchase Price" resolves to `buying_price` rather than to the shorter
     * `price` alias of `target_selling_price`.
     */
    public const COLUMNS = [
        'branch' => ['branch', 'branch name', 'branch code', 'store', 'location'],
        'category' => ['category', 'device category', 'product category'],
        'brand' => ['brand', 'manufacturer', 'make'],
        'model' => ['model', 'product model', 'product'],
        'color' => ['color', 'colour'],
        'quantity' => ['quantity', 'qty', 'units', 'unit count', 'stock quantity', 'stock'],
        'buying_price' => ['buying price', 'cost', 'unit cost', 'purchase price', 'buying cost', 'cost price'],
        // No bare `price` alias on purpose: "Wholesale Price" or "Price (USD)"
        // would then be silently treated as the retail price, which is exactly
        // the wrong-money mistake the unknown-header check exists to prevent.
        'target_selling_price' => ['target selling price', 'selling price', 'retail price'],
        'serial_numbers' => ['serial numbers', 'serial number', 'serials', 'serial', 'imei'],
        'supplier' => ['supplier', 'vendor'],
        'reference_number' => ['reference number', 'invoice number', 'reference'],
        'purchase_date' => ['purchase date', 'date received', 'date'],
        'purchase_location' => ['purchase location', 'source'],
        'description' => ['description'],
        'notes' => ['notes', 'note', 'comment', 'comments'],
    ];

    /**
     * Columns that must be present in every upload.
     *
     * `branch` is not here because a single-branch operator can pick the branch
     * in the form instead; `target_selling_price` is not here because intake
     * inherits the price already in use for the product when it is blank.
     */
    public const REQUIRED_COLUMNS = ['category', 'brand', 'model', 'quantity', 'buying_price'];

    /**
     * Human labels used in error messages and in the template.
     */
    public const COLUMN_LABELS = [
        'branch' => 'Branch',
        'category' => 'Category',
        'brand' => 'Brand',
        'model' => 'Model',
        'color' => 'Color',
        'quantity' => 'Quantity',
        'buying_price' => 'Buying Price',
        'target_selling_price' => 'Target Selling Price',
        'serial_numbers' => 'Serial Numbers',
        'supplier' => 'Supplier',
        'reference_number' => 'Reference Number',
        'purchase_date' => 'Purchase Date',
        'purchase_location' => 'Purchase Location',
        'description' => 'Description',
        'notes' => 'Notes',
    ];

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly InventoryIntakeService $intake,
        private readonly InventoryPriceResolver $priceResolver,
    ) {}

    /**
     * Parse and validate an upload without writing anything.
     *
     * @return array{
     *     filename: string,
     *     columns: array{recognised: list<array{column: string, label: string}>, unknown: list<string>},
     *     summary: array{rows: int, valid_rows: int, invalid_rows: int, units: int, total_cost: float, expected_revenue: float},
     *     branches: list<array{branch_id: int, branch: string, rows: int, units: int, total_cost: float}>,
     *     rows: list<array<string, mixed>>,
     *     warnings: list<string>,
     *     errors: list<string>,
     *     can_import: bool,
     * }
     */
    public function analyse(string $path, string $filename, ?int $fallbackBranchId, User $user): array
    {
        $extension = SpreadsheetReader::extensionOf($filename);
        $errors = [];
        $warnings = [];

        // A file the reader cannot open at all (renamed .xls, truncated zip,
        // empty upload) is an operator mistake, not a server fault. It must come
        // back as a readable file-level error rather than an uncaught exception
        // rendered as a 500.
        try {
            $sheet = $this->reader->read($path, $extension);
        } catch (RuntimeException $exception) {
            return $this->emptyReport($filename, [$exception->getMessage()]);
        }

        $headers = $sheet['headers'];

        if ($headers === []) {
            return $this->emptyReport($filename, ['The file has no header row. Download the sample file and keep row 1 as the column headers.']);
        }

        [$mapping, $unknownHeaders, $duplicateHeaders] = $this->resolveColumns($headers);

        if ($unknownHeaders !== []) {
            $recognised = implode(', ', array_map(
                fn (string $column) => self::COLUMN_LABELS[$column] ?? $column,
                array_values($mapping)
            ));

            $errors[] = 'Unrecognised column header(s): '.implode(', ', $unknownHeaders)
                .'. The system matches columns by name, so every header must be one it knows. '
                .'Recognised headers in this file: '.($recognised !== '' ? $recognised : 'none').'. '
                .'Download the sample file for the exact list.';
        }

        if ($duplicateHeaders !== []) {
            $errors[] = 'More than one column maps to the same field: '.implode('; ', $duplicateHeaders)
                .'. Keep a single column per field so the system cannot import the wrong value.';
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_values($mapping)));

        if ($missing !== []) {
            $errors[] = 'Missing required column(s): '.implode(', ', array_map(
                fn (string $column) => self::COLUMN_LABELS[$column] ?? $column,
                $missing
            )).'.';
        }

        if ($sheet['rows'] === []) {
            $errors[] = 'The file has no stock rows below the header.';
        }

        // The reader stops at its own row ceiling, so this has to come from the
        // reader's flag rather than from counting what came back: a file cut
        // short at 2000 rows is indistinguishable from one that legitimately had
        // 2000. Counting is kept as a second opinion, but it is the flag that
        // catches the case where a spacer or title row above the header shifts
        // the arithmetic and the surplus would otherwise be dropped in silence.
        if (($sheet['truncated'] ?? false) || count($sheet['rows']) > SpreadsheetReader::MAX_DATA_ROWS) {
            $errors[] = 'The file has more than '.SpreadsheetReader::MAX_DATA_ROWS.' rows. Split it into smaller files.';
        }

        // Structural problems make per-row validation meaningless (every row
        // would report the same missing column), so stop here and let the
        // operator fix the sheet's shape first.
        if ($errors !== []) {
            return $this->report($filename, $mapping, $unknownHeaders, [], $warnings, $errors);
        }

        $branches = Branch::query()->orderBy('name')->get(['id', 'name', 'code', 'is_active']);
        $categories = DeviceCategory::query()->get(['id', 'name', 'slug']);

        $allowedBranchId = $this->allowedBranchId($user);
        $rows = [];
        $serialCandidates = [];

        foreach ($sheet['rows'] as $sheetRow) {
            $row = $this->analyseRow(
                $sheetRow,
                $mapping,
                $branches,
                $categories,
                $fallbackBranchId,
                $allowedBranchId,
            );

            if ($row['serial_numbers'] !== []) {
                $serialCandidates[$row['row']] = $row['serial_numbers'];
            }

            $rows[] = $row;
        }

        $this->flagExistingSerials($rows, $serialCandidates);

        $units = array_sum(array_map(
            static fn (array $row) => $row['errors'] === [] ? (int) $row['quantity'] : 0,
            $rows
        ));

        if ($units > self::MAX_UNITS) {
            $errors[] = 'This file declares '.number_format($units).' units, more than the '.number_format(self::MAX_UNITS).'-unit limit for one upload. Split it into several files.';
        }

        foreach ($this->branchConflicts($rows) as $warning) {
            $warnings[] = $warning;
        }

        return $this->report($filename, $mapping, $unknownHeaders, $rows, $warnings, $errors);
    }

    /**
     * Write the analysed rows as one receipt per branch.
     *
     * @param  array<string, mixed>  $report  The result of {@see analyse()}.
     * @return array{receipts: list<InventoryReceipt>, units: int, batches: int, devices: int}
     *
     * @throws RuntimeException when the report is not importable.
     */
    public function commit(array $report, User $user): array
    {
        if (! ($report['can_import'] ?? false)) {
            throw new RuntimeException('This file cannot be imported while it has errors.');
        }

        $rows = $report['rows'];

        $groups = [];
        foreach ($rows as $row) {
            $groups[(int) $row['branch_id']][] = $row;
        }

        return DB::transaction(function () use ($groups, $user, $report) {
            $receipts = [];
            $batchCount = 0;
            $deviceCount = 0;
            $unitCount = 0;

            foreach ($groups as $branchId => $branchRows) {
                $first = $branchRows[0];

                $lines = array_map(static fn (array $row): array => [
                    'category_id' => $row['category_id'],
                    'brand' => $row['brand'],
                    'model' => $row['model'],
                    'color' => $row['color'],
                    'description' => $row['description'],
                    'quantity' => $row['quantity'],
                    'buying_price' => $row['buying_price'],
                    // Always a concrete number: analyse() resolved the inherited
                    // price already, so intake never has to fall back here.
                    'target_selling_price' => $row['target_selling_price'],
                    'notes' => $row['notes'],
                    'auto_generate_serials' => $row['serial_numbers'] === [],
                    'serial_numbers' => $row['serial_numbers'],
                    'purchase_date' => $row['purchase_date'],
                    'purchase_location' => $row['purchase_location'],
                ], $branchRows);

                $result = $this->intake->receiveReceipt(
                    [
                        'branch_id' => $branchId,
                        'supplier' => $first['supplier'],
                        'reference_number' => $first['reference_number'],
                        'purchase_date' => $first['purchase_date'],
                        'purchase_location' => $first['purchase_location'],
                        'status' => 'received',
                        'notes' => $this->receiptNotes($first['notes'], $report['filename']),
                    ],
                    $lines,
                    $user,
                    'rows.%d.target_selling_price',
                );

                $receipts[] = $result['receipt'];
                $batchCount += count($result['batches']);
                $deviceCount += count($result['devices']);
                $unitCount += array_sum(array_map(static fn (array $row) => (int) $row['quantity'], $branchRows));
            }

            return [
                'receipts' => $receipts,
                'units' => $unitCount,
                'batches' => $batchCount,
                'devices' => $deviceCount,
            ];
        });
    }

    /**
     * Map header cells onto canonical columns.
     *
     * A header matches a column when the column's alias appears as a contiguous
     * run of words inside it — so "Buying Price (MWK)" matches `buying price`
     * and "Cost" matches `cost`. The longest matching alias wins, which is what
     * keeps "Purchase Price" away from the bare `price` alias of the selling
     * price. Two headers landing on one column is an error, not a last-wins
     * guess: silently dropping the second price column would import wrong money.
     *
     * @param  list<string>  $headers
     * @return array{0: array<int, string>, 1: list<string>, 2: list<string>}
     */
    private function resolveColumns(array $headers): array
    {
        $mapping = [];
        $unknown = [];
        $duplicates = [];
        $claimedBy = [];

        foreach ($headers as $index => $header) {
            $tokens = $this->tokens($header);

            if ($tokens === []) {
                // A trailing blank header is not an error worth blocking on; the
                // reader already trims the common case.
                continue;
            }

            $best = null;
            $bestLength = 0;

            foreach (self::COLUMNS as $column => $aliases) {
                foreach ($aliases as $alias) {
                    $aliasTokens = $this->tokens($alias);

                    if ($aliasTokens === [] || count($aliasTokens) < $bestLength) {
                        continue;
                    }

                    if (! $this->containsRun($tokens, $aliasTokens)) {
                        continue;
                    }

                    if (count($aliasTokens) === $bestLength && $best !== null) {
                        continue;
                    }

                    $best = $column;
                    $bestLength = count($aliasTokens);
                }
            }

            if ($best === null) {
                $unknown[] = $header;

                continue;
            }

            if (isset($claimedBy[$best])) {
                $duplicates[] = sprintf(
                    '"%s" and "%s" both look like %s',
                    $claimedBy[$best],
                    $header,
                    self::COLUMN_LABELS[$best] ?? $best
                );

                continue;
            }

            $claimedBy[$best] = $header;
            $mapping[$index] = $best;
        }

        return [$mapping, $unknown, $duplicates];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/u', ' ', $value);

        return array_values(array_filter(explode(' ', $value), static fn (string $token) => $token !== ''));
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needle
     */
    private function containsRun(array $haystack, array $needle): bool
    {
        $needleCount = count($needle);

        if ($needleCount === 0 || $needleCount > count($haystack)) {
            return false;
        }

        for ($start = 0; $start <= count($haystack) - $needleCount; $start++) {
            if (array_slice($haystack, $start, $needleCount) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{number: int, cells: list<string|null>}  $sheetRow
     * @param  array<int, string>  $mapping
     * @return array<string, mixed>
     */
    private function analyseRow(
        array $sheetRow,
        array $mapping,
        $branches,
        $categories,
        ?int $fallbackBranchId,
        ?int $allowedBranchId,
    ): array {
        $values = [];

        foreach ($mapping as $index => $column) {
            $raw = $sheetRow['cells'][$index] ?? null;
            $values[$column] = ($raw === null || trim((string) $raw) === '') ? null : trim((string) $raw);
        }

        $errors = [];
        $rowNumber = $sheetRow['number'];

        [$branchId, $branchLabel, $branchError] = $this->resolveBranch(
            $values['branch'] ?? null,
            $branches,
            $fallbackBranchId,
            $allowedBranchId,
        );

        if ($branchError !== null) {
            $errors[] = $branchError;
        }

        [$categoryId, $categoryError] = $this->resolveCategory($values['category'] ?? null, $categories);

        if ($categoryError !== null) {
            $errors[] = $categoryError;
        }

        $brand = $values['brand'] ?? null;
        $model = $values['model'] ?? null;

        if ($brand === null) {
            $errors[] = 'Brand is required.';
        } elseif (mb_strlen($brand) > 100) {
            $errors[] = 'Brand must be 100 characters or fewer.';
        }

        if ($model === null) {
            $errors[] = 'Model is required.';
        } elseif (mb_strlen($model) > 100) {
            $errors[] = 'Model must be 100 characters or fewer.';
        }

        $quantity = null;
        $quantityRaw = $values['quantity'] ?? null;

        if ($quantityRaw === null) {
            $errors[] = 'Quantity is required.';
        } elseif (! preg_match('/^\d+$/', (string) preg_replace('/[\s,]/', '', $quantityRaw))) {
            $errors[] = "Quantity \"{$quantityRaw}\" is not a whole number.";
        } else {
            $quantity = (int) preg_replace('/[\s,]/', '', $quantityRaw);

            if ($quantity < 1) {
                $errors[] = 'Quantity must be at least 1.';
                $quantity = null;
            } elseif ($quantity > self::MAX_ROW_QUANTITY) {
                $errors[] = 'Quantity '.$quantity.' is above the '.self::MAX_ROW_QUANTITY.'-unit limit per row. Split the line or raise the limit in the intake rules.';
                $quantity = null;
            }
        }

        $buyingPrice = $this->parseMoney($values['buying_price'] ?? null, 'Buying Price', $errors);

        if ($buyingPrice !== null && $buyingPrice < 0) {
            $errors[] = 'Buying Price cannot be negative.';
            $buyingPrice = null;
        }

        if ($buyingPrice === null && ($values['buying_price'] ?? null) === null) {
            $errors[] = 'Buying Price is required.';
        }

        $targetSellingPrice = $this->parseMoney($values['target_selling_price'] ?? null, 'Target Selling Price', $errors);

        if ($targetSellingPrice !== null && $targetSellingPrice < 0) {
            // Parity with the manual form, whose rule is `min:0`. A negative
            // price is not a blank price: it fails the row and must not fall
            // through to inheritance, or the preview would show a plausible
            // number beside an error and imply the row is fine.
            $errors[] = 'Target Selling Price cannot be negative.';
            $targetSellingPrice = null;
        }

        $priceSource = $targetSellingPrice !== null ? 'sheet' : null;

        // Blank selling price inherits the price already in use for the product,
        // exactly as the manual form does. Resolving it here means the preview
        // shows the number that will actually be used, instead of the operator
        // discovering it after the fact.
        //
        // Only a genuinely blank cell inherits: a cell that held text or a
        // negative number is already a row error, and inventing a price for it
        // would make the preview describe a row that cannot be imported.
        if ($targetSellingPrice === null
            && ($values['target_selling_price'] ?? null) === null
            && $categoryId !== null
            && $brand !== null
            && $model !== null) {
            $inherited = $this->priceResolver->fallbackSellingPrice($categoryId, $brand, $model, $branchId);

            if ($inherited === null) {
                $errors[] = 'No Target Selling Price and none could be inherited from earlier stock for this product. Enter a selling price.';
            } else {
                $targetSellingPrice = $inherited;
                $priceSource = 'inherited';
            }
        }

        $serialNumbers = $this->parseSerials($values['serial_numbers'] ?? null);

        if ($serialNumbers !== [] && $quantity !== null && count($serialNumbers) !== $quantity) {
            $errors[] = sprintf(
                'Serial Numbers lists %d value(s) but Quantity is %d. Provide exactly one serial per unit, or leave the column blank to generate them.',
                count($serialNumbers),
                $quantity
            );
        }

        $purchaseDate = null;
        $purchaseDateRaw = $values['purchase_date'] ?? null;

        if ($purchaseDateRaw !== null) {
            [$purchaseDate, $dateError] = $this->parseDate($purchaseDateRaw);

            if ($dateError !== null) {
                $errors[] = $dateError;
            } elseif ($purchaseDate !== null && $purchaseDate->isAfter(CarbonImmutable::today())) {
                $errors[] = 'Purchase Date '.$purchaseDate->toDateString().' is in the future.';
                $purchaseDate = null;
            }
        }

        $color = $this->limit($values['color'] ?? null, 50, 'Color', $errors);
        $description = $this->limit($values['description'] ?? null, 1000, 'Description', $errors);
        $notes = $this->limit($values['notes'] ?? null, 1000, 'Notes', $errors);
        $supplier = $this->limit($values['supplier'] ?? null, 255, 'Supplier', $errors);
        $reference = $this->limit($values['reference_number'] ?? null, 255, 'Reference Number', $errors);
        $purchaseLocation = $this->limit($values['purchase_location'] ?? null, 255, 'Purchase Location', $errors);

        return [
            'row' => $rowNumber,
            'status' => $errors === [] ? 'ok' : 'error',
            'branch_id' => $branchId,
            'branch' => $branchLabel,
            'category_id' => $categoryId,
            'category' => $values['category'] ?? null,
            'brand' => $brand,
            'model' => $model,
            'product' => trim(($brand ?? '').' '.($model ?? '')),
            'color' => $color,
            'quantity' => $quantity,
            'buying_price' => $buyingPrice,
            'target_selling_price' => $targetSellingPrice,
            'price_source' => $priceSource,
            'serial_numbers' => $serialNumbers,
            'supplier' => $supplier,
            'reference_number' => $reference,
            'purchase_date' => $purchaseDate?->toDateString(),
            'purchase_location' => $purchaseLocation,
            'description' => $description,
            'notes' => $notes,
            'line_total' => ($buyingPrice !== null && $quantity !== null) ? round($buyingPrice * $quantity, 2) : null,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{0: int|null, 1: string|null, 2: string|null}
     */
    private function resolveBranch(?string $value, $branches, ?int $fallbackBranchId, ?int $allowedBranchId): array
    {
        if ($value === null) {
            if ($fallbackBranchId === null) {
                return [null, null, 'Branch is required. Add a Branch column, or choose a default branch for rows that leave it blank.'];
            }

            $branch = $branches->firstWhere('id', $fallbackBranchId);

            if (! $branch) {
                return [null, null, 'The selected default branch no longer exists.'];
            }

            return [(int) $branch->id, $branch->name, $this->branchPermissionError($branch, $allowedBranchId)];
        }

        $needle = $this->branchKey($value);
        $branch = $branches->first(
            fn (Branch $candidate) => $this->branchKey((string) $candidate->name) === $needle
                || $this->branchKey((string) ($candidate->code ?? '')) === $needle
        );

        if (! $branch) {
            $known = $branches->pluck('name')->implode(', ');

            return [null, $value, "Unknown branch \"{$value}\". Known branches: {$known}."];
        }

        if (! $branch->is_active) {
            return [null, $branch->name, "Branch \"{$branch->name}\" is inactive and cannot receive stock."];
        }

        return [(int) $branch->id, $branch->name, $this->branchPermissionError($branch, $allowedBranchId)];
    }

    private function branchPermissionError(Branch $branch, ?int $allowedBranchId): ?string
    {
        if ($allowedBranchId === null || (int) $branch->id === $allowedBranchId) {
            return null;
        }

        return "You can only import stock for your own branch; row targets \"{$branch->name}\".";
    }

    /**
     * Branch names are typed by hand, so match on a normalised key and tolerate
     * a trailing "branch"/"store" ("Lilongwe" ≈ "Lilongwe Branch").
     */
    private function branchKey(string $value): string
    {
        $key = (string) preg_replace('/[^a-z0-9]+/u', ' ', mb_strtolower(trim($value)));
        $key = trim((string) preg_replace('/\s+/', ' ', $key));
        $key = (string) preg_replace('/\s+(branch|store)$/', '', $key);

        return $key;
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function resolveCategory(?string $value, $categories): array
    {
        if ($value === null) {
            return [null, 'Category is required.'];
        }

        $needle = (string) preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($value));

        $category = $categories->first(function (DeviceCategory $candidate) use ($needle) {
            return (string) preg_replace('/[^a-z0-9]+/u', '', mb_strtolower((string) $candidate->name)) === $needle
                || (string) preg_replace('/[^a-z0-9]+/u', '', mb_strtolower((string) $candidate->slug)) === $needle;
        });

        if (! $category) {
            $known = $categories->pluck('name')->implode(', ');

            // Categories are not auto-created on import: a typo would silently
            // fork the catalog ("Phone" beside "Phones") and split every report.
            return [null, "Unknown category \"{$value}\". Known categories: {$known}. Create the category first if it is genuinely new."];
        }

        return [(int) $category->id, null];
    }

    /**
     * Parse a money cell into a float.
     *
     * Operators paste values from Excel, email and invoices, so the parser
     * tolerates currency symbols, spaces and thousands separators. The comma
     * rule is deliberately conservative: a single trailing group of three
     * digits is read as thousands ("1,250" → 1250), while one or two trailing
     * digits is read as a decimal comma ("1250,50" → 1250.5). Guessing the
     * other way round would silently shift a price by three orders of magnitude.
     *
     * @param  list<string>  $errors
     */
    private function parseMoney(?string $value, string $label, array &$errors): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $clean = (string) preg_replace('/[^0-9,.\-]/', '', $value);

        if ($clean === '' || $clean === '-' || ! preg_match('/\d/', $clean)) {
            $errors[] = "{$label} \"{$value}\" is not a number.";

            return null;
        }

        $hasComma = str_contains($clean, ',');
        $hasDot = str_contains($clean, '.');

        if ($hasComma && $hasDot) {
            // Whichever separator comes last is the decimal one.
            $clean = strrpos($clean, ',') > strrpos($clean, '.')
                ? str_replace(['.', ','], ['', '.'], $clean)
                : str_replace(',', '', $clean);
        } elseif ($hasComma) {
            $clean = $this->normaliseSingleSeparator($clean, ',');
        } elseif ($hasDot) {
            $clean = $this->normaliseSingleSeparator($clean, '.');
        }

        if (! is_numeric($clean)) {
            $errors[] = "{$label} \"{$value}\" is not a number.";

            return null;
        }

        return round((float) $clean, 2);
    }

    private function normaliseSingleSeparator(string $value, string $separator): string
    {
        $groups = explode($separator, $value);

        if (count($groups) === 1) {
            return $value;
        }

        $head = array_shift($groups);

        // Every group after the first being exactly three digits is the
        // signature of a thousands separator, however many groups there are.
        $allThousands = true;
        foreach ($groups as $group) {
            if (strlen($group) !== 3) {
                $allThousands = false;
                break;
            }
        }

        if ($allThousands) {
            return $head.implode('', $groups);
        }

        // Decimal separator: keep it, drop any further occurrences.
        return $head.'.'.implode('', $groups);
    }

    /**
     * @return list<string>
     */
    private function parseSerials(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        $parts = preg_split('/[\r\n,;|]+/', $value) ?: [];

        return array_values(array_unique(array_filter(
            array_map(static fn (string $part) => trim($part), $parts),
            static fn (string $part) => $part !== ''
        )));
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: string|null}
     */
    private function parseDate(string $value): array
    {
        $value = trim($value);

        // An .xlsx date cell arrives as a serial number, not text.
        if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
            $serial = (int) $value;

            if ($serial >= 20000 && $serial <= 80000) {
                return [CarbonImmutable::create(1899, 12, 30)->addDays($serial)->startOfDay(), null];
            }
        }

        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j M Y', 'j F Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value);
            } catch (Throwable) {
                // Carbon 3 throws InvalidFormatException instead of returning
                // false. Without this, one cell reading "not-a-date" would escape
                // this method and fail the entire upload with a 500 — a bad date
                // is a row problem, not a server problem.
                continue;
            }

            // The round-trip check rejects PHP's lenient overflow: "31/02/2026"
            // parses as 3 March, and formatting it back yields a different string.
            if ($date !== false && $date->format($format) === $value) {
                return [$date->startOfDay(), null];
            }
        }

        try {
            return [CarbonImmutable::parse($value)->startOfDay(), null];
        } catch (Throwable) {
            return [null, "Purchase Date \"{$value}\" is not a date. Use YYYY-MM-DD (for example 2026-10-28)."];
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function limit(?string $value, int $max, string $label, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }

        if (mb_strlen($value) > $max) {
            $errors[] = "{$label} must be {$max} characters or fewer.";

            return null;
        }

        return $value;
    }

    /**
     * Mark rows whose serial numbers already exist, in one query for the file.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, list<string>>  $candidates  sheet row number => serials
     */
    private function flagExistingSerials(array &$rows, array $candidates): void
    {
        if ($candidates === []) {
            return;
        }

        $all = array_merge(...array_values($candidates));
        $existing = Device::query()
            ->whereIn('serial_number', $all)
            ->pluck('serial_number')
            ->all();

        if ($existing === []) {
            return;
        }

        $existingLookup = array_flip($existing);

        foreach ($rows as $index => $row) {
            $serials = $row['serial_numbers'];

            if ($serials === []) {
                continue;
            }

            $duplicates = array_values(array_filter(
                $serials,
                static fn (string $serial) => isset($existingLookup[$serial])
            ));

            if ($duplicates === []) {
                continue;
            }

            $rows[$index]['errors'][] = 'Serial number(s) already in the system: '.implode(', ', array_slice($duplicates, 0, 5))
                .(count($duplicates) > 5 ? '…' : '').'.';
            $rows[$index]['status'] = 'error';
        }
    }

    /**
     * Delivery-level columns (supplier, reference, date, location) are copied
     * from the first row of each branch group onto that branch's receipt. If
     * the sheet disagrees with itself, say so instead of silently picking one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function branchConflicts(array $rows): array
    {
        $warnings = [];
        $fields = ['supplier' => 'Supplier', 'reference_number' => 'Reference Number', 'purchase_date' => 'Purchase Date'];

        $groups = [];
        foreach ($rows as $row) {
            if ($row['branch_id'] === null) {
                continue;
            }

            $groups[$row['branch_id']][] = $row;
        }

        foreach ($groups as $branchRows) {
            $branch = $branchRows[0]['branch'];

            foreach ($fields as $field => $label) {
                $distinct = [];

                foreach ($branchRows as $row) {
                    if ($row[$field] !== null) {
                        $distinct[$row[$field]] = true;
                    }
                }

                if (count($distinct) > 1) {
                    $warnings[] = "Branch \"{$branch}\" has more than one {$label} value. The receipt will use \"".array_key_first($distinct).'", the first one in the file.';
                }
            }
        }

        return $warnings;
    }

    /**
     * Null means "any branch" (super admin). Otherwise the single branch the
     * user may write to.
     */
    private function allowedBranchId(User $user): ?int
    {
        if ($user->hasRole('super_admin')) {
            return null;
        }

        return $user->branchScopeId() !== null ? (int) $user->branchScopeId() : -1;
    }

    private function receiptNotes(?string $notes, string $filename): string
    {
        $prefix = "Imported from {$filename}";

        return $notes === null || $notes === '' ? $prefix : $prefix.' — '.$notes;
    }

    /**
     * @param  array<int, string>  $mapping
     * @param  list<string>  $unknownHeaders
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function report(
        string $filename,
        array $mapping,
        array $unknownHeaders,
        array $rows,
        array $warnings,
        array $errors,
    ): array {
        $validRows = array_values(array_filter($rows, static fn (array $row) => $row['errors'] === []));
        $invalidRows = count($rows) - count($validRows);

        $units = array_sum(array_map(static fn (array $row) => (int) $row['quantity'], $validRows));
        $totalCost = round(array_sum(array_map(
            static fn (array $row) => (float) $row['buying_price'] * (int) $row['quantity'],
            $validRows
        )), 2);
        $expectedRevenue = round(array_sum(array_map(
            static fn (array $row) => (float) $row['target_selling_price'] * (int) $row['quantity'],
            $validRows
        )), 2);

        $branchGroups = [];
        foreach ($validRows as $row) {
            $key = (int) $row['branch_id'];
            $branchGroups[$key] ??= [
                'branch_id' => $key,
                'branch' => $row['branch'],
                'rows' => 0,
                'units' => 0,
                'total_cost' => 0.0,
            ];

            $branchGroups[$key]['rows']++;
            $branchGroups[$key]['units'] += (int) $row['quantity'];
            $branchGroups[$key]['total_cost'] = round(
                $branchGroups[$key]['total_cost'] + ((float) $row['buying_price'] * (int) $row['quantity']),
                2
            );
        }

        $recognised = [];
        foreach ($mapping as $index => $column) {
            $recognised[] = [
                'column' => $column,
                'label' => self::COLUMN_LABELS[$column] ?? $column,
            ];
        }

        return [
            'filename' => $filename,
            'columns' => [
                'recognised' => $recognised,
                'unknown' => $unknownHeaders,
            ],
            'summary' => [
                'rows' => count($rows),
                'valid_rows' => count($validRows),
                'invalid_rows' => $invalidRows,
                'units' => (int) $units,
                'total_cost' => $totalCost,
                'expected_revenue' => $expectedRevenue,
            ],
            'branches' => array_values($branchGroups),
            'rows' => $rows,
            'warnings' => $warnings,
            'errors' => $errors,
            'can_import' => $errors === [] && $invalidRows === 0 && $validRows !== [],
        ];
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function emptyReport(string $filename, array $errors): array
    {
        return [
            'filename' => $filename,
            'columns' => ['recognised' => [], 'unknown' => []],
            'summary' => [
                'rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'units' => 0,
                'total_cost' => 0.0,
                'expected_revenue' => 0.0,
            ],
            'branches' => [],
            'rows' => [],
            'warnings' => [],
            'errors' => $errors,
            'can_import' => false,
        ];
    }
}
