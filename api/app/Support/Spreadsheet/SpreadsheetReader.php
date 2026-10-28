<?php

namespace App\Support\Spreadsheet;

use RuntimeException;

/**
 * Reads the first worksheet of a `.csv` or `.xlsx` upload into plain rows.
 *
 * Counterpart to {@see XlsxWorkbook}: like the writer, this is deliberately
 * dependency-free (PHP's zip extension plus SimpleXML) so the app does not gain
 * `phpoffice/phpspreadsheet` just to read an operator's stock sheet.
 *
 * Contract — the caller gets exactly two things and nothing else:
 *   - `headers`: the first non-empty row, as trimmed strings, in column order;
 *   - `rows`:    every later non-empty row, padded to the header width, each
 *                tagged with the *spreadsheet* row number so an error message
 *                can say "row 14" and the operator can find it in Excel.
 *
 * Deliberately not supported: formulas are read as their cached value (what the
 * operator sees), dates arrive as Excel serial numbers and are converted by the
 * caller that knows the column is a date, and every worksheet after the first is
 * ignored. Anything richer belongs in a real spreadsheet library.
 */
final class SpreadsheetReader
{
    /**
     * Hard ceiling on data rows. A stock delivery larger than this is a
     * mistake or a database migration, not a spreadsheet upload; refusing is
     * kinder than tying up a request for minutes.
     */
    public const MAX_DATA_ROWS = 2000;

    /** Compressed size cap for a single worksheet part, in bytes (32 MB). */
    private const MAX_PART_BYTES = 33_554_432;

    /**
     * Whether the last {@see read()} stopped early at {@see MAX_DATA_ROWS}.
     *
     * This is state rather than a value threaded out of the private parsers
     * because both of them hit the same ceiling, and the caller must be told the
     * file was *cut short* rather than merely that it had many rows. Counting
     * the returned rows cannot tell "exactly at the limit" from "truncated" —
     * and the difference is a silent loss of stock, because the reader caps
     * *matrix* rows while the caller validates *data* rows, so the arithmetic
     * only lines up when the header is the very first row of the sheet.
     */
    private bool $truncated = false;

    private const EXTENSIONS = ['csv', 'txt', 'xlsx'];

    private const SPREADSHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELATIONSHIP_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PACKAGE_RELATIONSHIP_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * Whether an uploaded file extension is one this reader understands.
     */
    public static function supports(?string $extension): bool
    {
        return in_array(strtolower((string) $extension), self::EXTENSIONS, true);
    }

    /**
     * Lower-cased extension of an original upload name.
     */
    public static function extensionOf(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * @return array{headers: list<string>, rows: list<array{number: int, cells: list<string|null>}>, truncated: bool}
     *
     * @throws RuntimeException when the file cannot be parsed at all. Row-level
     *                          problems are the caller's business, not this
     *                          class's — it only reports structure.
     */
    public function read(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        // Reset per call: this object may be reused, and a stale `true` would
        // reject an unrelated, perfectly small file.
        $this->truncated = false;

        $matrix = match ($extension) {
            'csv', 'txt' => $this->readDelimited($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new RuntimeException(
                'Unsupported file type. Upload a .csv or .xlsx file.'
            ),
        };

        return $this->splitHeader($matrix);
    }

    /**
     * Turn a raw positional matrix into headers plus numbered, padded rows.
     *
     * @param  list<array{number: int, cells: list<string|null>}>  $matrix
     * @return array{headers: list<string>, rows: list<array{number: int, cells: list<string|null>}>, truncated: bool}
     */
    private function splitHeader(array $matrix): array
    {
        // A spacer row above the header is common in hand-made sheets. Blank
        // rows are skipped; anything with text in it *is* the header, so a title
        // row becomes a header the caller will reject by name rather than being
        // silently swallowed.
        $headerIndex = null;
        foreach ($matrix as $index => $row) {
            if ($this->rowHasContent($row['cells'])) {
                $headerIndex = $index;
                break;
            }
        }

        if ($headerIndex === null) {
            return ['headers' => [], 'rows' => [], 'truncated' => $this->truncated];
        }

        $headers = array_map(
            static fn ($cell) => trim((string) $cell),
            $matrix[$headerIndex]['cells']
        );

        // A trailing empty header cell means the sheet has stray columns; drop
        // them so they are not reported as "unknown header ''".
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        $width = count($headers);
        $rows = [];

        foreach (array_slice($matrix, $headerIndex + 1) as $row) {
            if (! $this->rowHasContent($row['cells'])) {
                continue;
            }

            $cells = array_slice(array_pad($row['cells'], $width, null), 0, $width);

            $rows[] = [
                'number' => $row['number'],
                'cells' => array_map(
                    static fn ($cell) => $cell === null ? null : trim((string) $cell),
                    $cells
                ),
            ];
        }

        return ['headers' => array_values($headers), 'rows' => $rows, 'truncated' => $this->truncated];
    }

    /**
     * @param  list<string|null>  $cells
     */
    private function rowHasContent(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{number: int, cells: list<string|null>}>
     */
    private function readDelimited(string $path): array
    {
        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            throw new RuntimeException('The uploaded file is empty.');
        }

        $raw = $this->toUtf8($raw);

        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        $sample = array_values(array_filter(
            array_slice($lines, 0, 25),
            static fn (string $line) => trim($line) !== ''
        ));

        $delimiter = $this->detectDelimiter($sample);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);

        $matrix = [];
        $rowNumber = 0;

        // The explicit empty escape keeps PHP 8.4 from treating a backslash as
        // an escape character, which mangles Windows paths in a notes column.
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $rowNumber++;

            if ($cells === [null]) {
                continue;
            }

            $matrix[] = [
                'number' => $rowNumber,
                'cells' => array_map(
                    static fn ($cell) => $cell === null ? null : (string) $cell,
                    $cells
                ),
            ];

            if (count($matrix) > self::MAX_DATA_ROWS + 1) {
                $this->truncated = true;

                break;
            }
        }

        fclose($stream);

        return $matrix;
    }

    /**
     * Excel writes CSV in whatever the operator's locale uses, so the delimiter
     * is sniffed rather than assumed. Each candidate is scored on how
     * *consistently* it produces the same column count across the first rows —
     * a wrong delimiter usually yields a ragged or single-column parse.
     *
     * @param  list<string>  $lines
     */
    private function detectDelimiter(array $lines): string
    {
        $best = ',';
        $bestScore = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts = [];

            foreach ($lines as $line) {
                $counts[] = count(str_getcsv($line, $candidate, '"', ''));
            }

            if ($counts === []) {
                continue;
            }

            $tally = array_count_values($counts);
            arsort($tally);
            $modal = (int) array_key_first($tally);

            if ($modal < 2) {
                continue;
            }

            $consistency = $tally[$modal] / count($counts);
            $score = ($consistency * 100) + $modal;

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Normalise the upload to UTF-8 before parsing.
     *
     * Excel offers "CSV UTF-8", "CSV (Comma delimited)" — which on Windows is
     * Windows-1252 — and "Unicode Text" (.txt, UTF-16LE). All three arrive at
     * this method, so the byte-order mark decides first and a validity check
     * catches the rest. Without this, a Malawian operator's "Blantyre" survives
     * but any non-ASCII note turns into mojibake in the audit log.
     */
    private function toUtf8(string $raw): string
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return substr($raw, 3);
        }

        if (str_starts_with($raw, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($raw, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            return (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return $raw;
    }

    /**
     * @return list<array{number: int, cells: list<string|null>}>
     */
    private function readXlsx(string $path): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Reading .xlsx files requires the PHP zip extension. Save the sheet as CSV instead.');
        }

        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The workbook could not be opened. Re-save it as .xlsx or .csv and try again.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $sheetPart = $this->firstSheetPart($zip);
            $xml = $this->readPart($zip, $sheetPart);
        } finally {
            $zip->close();
        }

        return $this->parseSheetXml($xml, $sharedStrings);
    }

    /**
     * Resolve the *first* worksheet through `workbook.xml` and its relationships
     * rather than guessing `xl/worksheets/sheet1.xml`.
     *
     * The part name and the tab order are independent: a workbook whose tabs
     * were reordered, or one written by a tool that numbers parts differently,
     * would otherwise be read in the wrong order and the operator would import
     * the instructions sheet instead of the stock sheet.
     */
    private function firstSheetPart(\ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relationships = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $relationships === false) {
            throw new RuntimeException('This file is not a valid .xlsx workbook.');
        }

        $workbookDocument = $this->loadXml($workbook);
        $relationshipsDocument = $this->loadXml($relationships);

        $workbookXPath = new \DOMXPath($workbookDocument);
        $workbookXPath->registerNamespace('m', self::SPREADSHEET_NS);

        $firstSheet = $workbookXPath->query('//m:sheets/m:sheet')->item(0);

        if (! $firstSheet instanceof \DOMElement) {
            throw new RuntimeException('The workbook has no worksheets.');
        }

        $relationshipId = $firstSheet->getAttributeNS(self::RELATIONSHIP_NS, 'id');

        $relationshipsXPath = new \DOMXPath($relationshipsDocument);
        $relationshipsXPath->registerNamespace('p', self::PACKAGE_RELATIONSHIP_NS);

        foreach ($relationshipsXPath->query('//p:Relationship') as $relationship) {
            if (! $relationship instanceof \DOMElement || $relationship->getAttribute('Id') !== $relationshipId) {
                continue;
            }

            $target = ltrim($relationship->getAttribute('Target'), '/');

            // Targets are relative to xl/ unless the producer wrote an absolute
            // package path.
            if (! str_starts_with($target, 'xl/')) {
                $target = 'xl/'.$target;
            }

            return $this->normalisePartPath($target);
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * Collapse `xl/worksheets/../worksheets/sheet1.xml` style targets.
     */
    private function normalisePartPath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            if ($segment === '.' || $segment === '') {
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(\ZipArchive $zip): array
    {
        $contents = $zip->getFromName('xl/sharedStrings.xml');

        if ($contents === false) {
            return [];
        }

        $document = $this->loadXml($contents);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('m', self::SPREADSHEET_NS);

        $strings = [];

        foreach ($xpath->query('//m:sst/m:si') as $item) {
            // `<t>` directly, or one `<t>` per rich-text run. Excluding `rPh`
            // (phonetic hints) keeps a Japanese-annotated cell from gaining
            // duplicate text.
            $text = '';
            foreach ($xpath->query('m:t | m:r/m:t', $item) as $part) {
                $text .= $part->textContent;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<array{number: int, cells: list<string|null>}>
     */
    private function parseSheetXml(string $xml, array $sharedStrings): array
    {
        $document = $this->loadXml($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('m', self::SPREADSHEET_NS);

        $matrix = [];

        foreach ($xpath->query('//m:sheetData/m:row') as $row) {
            if (! $row instanceof \DOMElement) {
                continue;
            }

            $rowNumber = (int) $row->getAttribute('r');
            $cells = [];

            foreach ($xpath->query('m:c', $row) as $cell) {
                if (! $cell instanceof \DOMElement) {
                    continue;
                }

                $cells[$this->columnIndex($cell->getAttribute('r'))] = $this->cellValue($xpath, $cell, $sharedStrings);
            }

            $fallbackNumber = count($matrix) + 1;

            if ($cells === []) {
                $matrix[] = ['number' => $rowNumber > 0 ? $rowNumber : $fallbackNumber, 'cells' => []];

                continue;
            }

            // Cells are sparse: a row with only C and E still needs A/B blank
            // so column positions keep their meaning.
            $positional = array_fill(0, max(array_keys($cells)) + 1, null);

            foreach ($cells as $index => $value) {
                $positional[$index] = $value;
            }

            $matrix[] = [
                'number' => $rowNumber > 0 ? $rowNumber : $fallbackNumber,
                'cells' => $positional,
            ];

            if (count($matrix) > self::MAX_DATA_ROWS + 1) {
                $this->truncated = true;

                break;
            }
        }

        return $matrix;
    }

    /**
     * @param  list<string>  $sharedStrings
     */
    private function cellValue(\DOMXPath $xpath, \DOMElement $cell, array $sharedStrings): ?string
    {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            $text = '';
            foreach ($xpath->query('m:is//m:t', $cell) as $part) {
                $text .= $part->textContent;
            }

            return $text === '' ? null : $text;
        }

        $valueNode = $xpath->query('m:v', $cell)->item(0);

        if (! $valueNode instanceof \DOMNode) {
            return null;
        }

        $value = $valueNode->textContent;

        return match ($type) {
            's' => $sharedStrings[(int) $value] ?? null,
            'b' => $value === '1' ? 'TRUE' : 'FALSE',
            // A cell holding an error (#N/A, #REF!) is not data.
            'e' => null,
            default => $value,
        };
    }

    /**
     * Parse a part with entity expansion disabled.
     *
     * `LIBXML_NONET` blocks network fetches; `LIBXML_NOENT` is deliberately not
     * passed, so an uploaded file cannot expand entities into memory.
     */
    private function loadXml(string $contents): \DOMDocument
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($contents, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException('This file is not a valid .xlsx workbook.');
        }

        return $document;
    }

    /**
     * `A1` → 0, `AA7` → 26.
     */
    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?? '';

        if ($letters === '') {
            return 0;
        }

        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * Read one zip part with a size guard: a small .xlsx can expand to
     * gigabytes, and `getFromName` would happily try to hold it all in memory.
     */
    private function readPart(\ZipArchive $zip, string $part): string
    {
        $stat = $zip->statName($part);

        if ($stat === false) {
            throw new RuntimeException('The workbook is missing its first worksheet.');
        }

        if (($stat['size'] ?? 0) > self::MAX_PART_BYTES) {
            throw new RuntimeException('The worksheet is too large to read. Split it into smaller files.');
        }

        $contents = $zip->getFromName($part);

        if ($contents === false) {
            throw new RuntimeException('The first worksheet could not be read.');
        }

        return $contents;
    }
}
