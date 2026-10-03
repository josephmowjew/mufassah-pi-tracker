<?php

namespace App\Support\Spreadsheet;

/**
 * One worksheet in a {@see XlsxWorkbook}.
 *
 * Rows are accumulated in memory and serialised to OOXML when the workbook is
 * written. Cells may be passed as {@see Cell} instances or as bare scalars, in
 * which case a sensible default style is inferred.
 */
final class XlsxSheet
{
    private const MAX_NAME_LENGTH = 31;

    /** @var list<list<Cell>> */
    private array $rows = [];

    /** @var list<float> */
    private array $widths = [];

    private bool $freezeHeader = false;

    private bool $autoFilter = false;

    /** Number of leading rows that make up the header block (frozen + filtered). */
    private int $headerRowCount = 0;

    public function __construct(
        private string $name,
    ) {
        $this->name = self::sanitiseName($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = self::sanitiseName($name);
    }

    /**
     * Set the rendered width (in characters) for each column, in order.
     *
     * @param  list<int|float>  $widths
     */
    public function setColumnWidths(array $widths): self
    {
        $this->widths = array_map(static fn ($w) => (float) $w, $widths);

        return $this;
    }

    /**
     * Append a styled header row and mark the sheet as having a header block.
     *
     * @param  list<string>  $labels
     */
    public function addHeaderRow(array $labels): self
    {
        $this->rows[] = array_map(
            static fn ($label) => Cell::header((string) $label),
            $labels
        );
        $this->headerRowCount = count($this->rows);
        $this->freezeHeader = true;
        $this->autoFilter = true;

        return $this;
    }

    /**
     * A prominent single-cell title row, used to open summary sheets.
     */
    public function addTitleRow(string $title): self
    {
        $this->rows[] = [Cell::title($title)];

        return $this;
    }

    /**
     * A `label | value` pair row, the building block of summary sheets.
     */
    public function addPairRow(string $label, Cell|string|int|float|null $value): self
    {
        $this->rows[] = [
            Cell::bold($label),
            $value instanceof Cell ? $value : self::infer($value),
        ];

        return $this;
    }

    /**
     * @param  array<string, Cell|string|int|float|null>  $pairs
     */
    public function addPairRows(array $pairs): self
    {
        foreach ($pairs as $label => $value) {
            $this->addPairRow((string) $label, $value);
        }

        return $this;
    }

    /**
     * @param  list<Cell|string|int|float|null>  $cells
     */
    public function addRow(array $cells): self
    {
        $this->rows[] = array_map(
            static fn ($cell) => $cell instanceof Cell ? $cell : self::infer($cell),
            array_values($cells)
        );

        return $this;
    }

    /**
     * @param  iterable<array<array-key, Cell|string|int|float|null>>  $rows
     */
    public function addRows(iterable $rows): self
    {
        foreach ($rows as $row) {
            $this->addRow(is_array($row) ? $row : [$row]);
        }

        return $this;
    }

    public function addBlankRow(): self
    {
        $this->rows[] = [];

        return $this;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function hasContent(): bool
    {
        foreach ($this->rows as $row) {
            foreach ($row as $cell) {
                if (! $cell->isEmpty()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Render the `xl/worksheets/sheetN.xml` part.
     */
    public function toXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= sprintf('<dimension ref="A1:%s"/>', $this->lastCellReference());
        $xml .= $this->sheetViewsXml();
        $xml .= '<sheetFormatPr defaultRowHeight="15"/>';
        $xml .= $this->columnsXml();
        $xml .= $this->sheetDataXml();
        if ($this->autoFilter && $this->headerRowCount > 0) {
            $xml .= sprintf('<autoFilter ref="A%d:%s"/>', $this->headerRowCount, $this->lastCellReference());
        }
        $xml .= '</worksheet>';

        return $xml;
    }

    private function sheetViewsXml(): string
    {
        if (! $this->freezeHeader || $this->headerRowCount === 0) {
            return '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
        }

        $topRow = $this->headerRowCount + 1;

        return '<sheetViews><sheetView workbookViewId="0">'
            .sprintf(
                '<pane ySplit="%d" topLeftCell="A%d" activePane="bottomLeft" state="frozen"/>',
                $this->headerRowCount,
                $topRow
            )
            .sprintf('<selection pane="bottomLeft" activeCell="A%d" sqref="A%d"/>', $topRow, $topRow)
            .'</sheetView></sheetViews>';
    }

    private function columnsXml(): string
    {
        if ($this->widths === []) {
            return '';
        }

        $xml = '<cols>';
        foreach ($this->widths as $index => $width) {
            $xml .= sprintf(
                '<col min="%d" max="%d" width="%s" customWidth="1"/>',
                $index + 1,
                $index + 1,
                rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.')
            );
        }

        return $xml.'</cols>';
    }

    private function sheetDataXml(): string
    {
        $xml = '<sheetData>';
        foreach ($this->rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $cells = '';
            foreach (array_values($row) as $columnIndex => $cell) {
                if ($cell->isEmpty()) {
                    continue;
                }
                $cells .= $this->cellXml($cell, $columnIndex, $rowNumber);
            }
            $xml .= $cells === ''
                ? sprintf('<row r="%d"/>', $rowNumber)
                : sprintf('<row r="%d">%s</row>', $rowNumber, $cells);
        }

        return $xml.'</sheetData>';
    }

    private function cellXml(Cell $cell, int $columnIndex, int $rowNumber): string
    {
        $reference = self::columnLetter($columnIndex).$rowNumber;
        $style = $cell->style;

        if ($cell->isNumeric()) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $reference, $style, self::numberLiteral($cell->value));
        }

        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $reference,
            $style,
            self::escape((string) $cell->value)
        );
    }

    private function lastCellReference(): string
    {
        $maxColumns = 1;
        foreach ($this->rows as $row) {
            $maxColumns = max($maxColumns, count($row));
        }

        return self::columnLetter($maxColumns - 1).max(count($this->rows), 1);
    }

    /**
     * Bare scalars get a default style: numbers stay numeric, text stays text.
     */
    private static function infer(string|int|float|null $value): Cell
    {
        if (is_int($value) || is_float($value)) {
            return Cell::number($value);
        }

        return Cell::text($value);
    }

    /**
     * Excel wants a plain decimal literal, never scientific notation or a locale
     * separator, so trailing zeros are trimmed by hand.
     */
    private static function numberLiteral(int|float|null $value): string
    {
        if ($value === null) {
            return '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_finite($value)) {
            return '0';
        }

        $literal = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return ($literal === '' || $literal === '-0') ? '0' : $literal;
    }

    /**
     * XML 1.0 forbids most control characters outright; Excel rejects the whole
     * workbook if they survive, so strip them rather than escaping them.
     */
    private static function escape(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * 0-based column index to spreadsheet letters (0 => A, 26 => AA).
     */
    private static function columnLetter(int $index): string
    {
        $letter = '';
        $index++;

        while ($index > 0) {
            $letter = chr(65 + (($index - 1) % 26)).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }

    private static function sanitiseName(string $name): string
    {
        $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);

        return $name === '' ? 'Sheet' : $name;
    }
}
