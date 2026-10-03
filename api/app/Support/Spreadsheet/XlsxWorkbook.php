<?php

namespace App\Support\Spreadsheet;

/**
 * A minimal, dependency-free `.xlsx` writer.
 *
 * Produces a SpreadsheetML (OOXML) package using PHP's zip extension — no
 * `phpoffice/phpspreadsheet` or `maatwebsite/excel` required. Strings are written
 * inline (`t="inlineStr"`), which removes the need for a shared-strings table and
 * keeps the part count low.
 *
 * Supported: multiple sheets, styled/frozen/filtered header rows, column widths,
 * inline text, real numbers, currency / integer / percent / date formats.
 */
final class XlsxWorkbook
{
    /** Header background, slate-700 — light enough to read white text on. */
    private const HEADER_FILL = 'FF334155';

    private const HEADER_BORDER = 'FF94A3B8';

    /** @var list<XlsxSheet> */
    private array $sheets = [];

    /** @var array<string, true> */
    private array $usedNames = [];

    public function __construct(
        private readonly string $creator = 'Mufassah Device Tracking',
    ) {}

    public function addSheet(string $name): XlsxSheet
    {
        $sheet = new XlsxSheet($name);
        $sheet->rename($this->uniqueName($sheet->name()));
        $this->usedNames[$sheet->name()] = true;
        $this->sheets[] = $sheet;

        return $sheet;
    }

    /**
     * @return list<XlsxSheet>
     */
    public function sheets(): array
    {
        return $this->sheets;
    }

    public function sheetCount(): int
    {
        return count($this->sheets);
    }

    public function save(string $path): void
    {
        $zip = new \ZipArchive;
        $opened = $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new \RuntimeException("Unable to create workbook at [{$path}].");
        }

        foreach ($this->parts() as $part => $contents) {
            $zip->addFromString($part, $contents);
        }

        $zip->close();
    }

    /**
     * Render the workbook and return the raw bytes.
     */
    public function toString(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_');

        if ($path === false) {
            throw new \RuntimeException('Unable to allocate a temporary file for the workbook.');
        }

        try {
            $this->save($path);
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new \RuntimeException('Unable to read the generated workbook.');
            }

            return $contents;
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function parts(): array
    {
        $parts = [
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => $this->rootRelationshipsXml(),
            'docProps/core.xml' => $this->corePropertiesXml(),
            'docProps/app.xml' => $this->appPropertiesXml(),
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationshipsXml(),
            'xl/styles.xml' => $this->stylesXml(),
        ];

        foreach ($this->sheets as $index => $sheet) {
            $parts['xl/worksheets/sheet'.($index + 1).'.xml'] = $sheet->toXml();
        }

        return $parts;
    }

    private function contentTypesXml(): string
    {
        $xml = $this->header();
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

        foreach (array_keys($this->sheets) as $index) {
            $xml .= sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $index + 1
            );
        }

        return $xml.'</Types>';
    }

    private function rootRelationshipsXml(): string
    {
        $xml = $this->header();
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $xml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $xml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>';
        $xml .= '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>';

        return $xml.'</Relationships>';
    }

    private function workbookXml(): string
    {
        $xml = $this->header();
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';

        foreach ($this->sheets as $index => $sheet) {
            $xml .= sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                $this->escape($sheet->name()),
                $index + 1,
                $index + 1
            );
        }

        return $xml.'</sheets></workbook>';
    }

    private function workbookRelationshipsXml(): string
    {
        $xml = $this->header();
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach (array_keys($this->sheets) as $index) {
            $xml .= sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $index + 1,
                $index + 1
            );
        }

        $xml .= sprintf(
            '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>',
            count($this->sheets) + 1
        );

        return $xml.'</Relationships>';
    }

    /**
     * Style slots are addressed by index from {@see Cell} — keep the two in step.
     *
     * fills[0] and fills[1] must stay `none` and `gray125`; Excel treats those two
     * positions as reserved and refuses the file if they are reordered.
     */
    private function stylesXml(): string
    {
        $xml = $this->header();
        $xml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<numFmts count="4">'
            .'<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            .'<numFmt numFmtId="165" formatCode="#,##0"/>'
            .'<numFmt numFmtId="166" formatCode="yyyy\-mm\-dd"/>'
            .'<numFmt numFmtId="167" formatCode="0.00&quot;%&quot;"/>'
            .'</numFmts>';
        $xml .= '<fonts count="4">'
            .'<font><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="13"/><color rgb="FF1F2937"/><name val="Calibri"/></font>'
            .'</fonts>';
        $xml .= '<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid">'
            .'<fgColor rgb="'.self::HEADER_FILL.'"/><bgColor indexed="64"/>'
            .'</patternFill></fill>'
            .'</fills>';
        $xml .= '<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top/>'
            .'<bottom style="thin"><color rgb="'.self::HEADER_BORDER.'"/></bottom>'
            .'<diagonal/></border>'
            .'</borders>';
        $xml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $xml .= '<cellXfs count="10">';
        $xml .= '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        $xml .= '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>';
        $xml .= '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        $xml .= '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        $xml .= '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>';
        $xml .= '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>';
        $xml .= '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        $xml .= '<xf numFmtId="167" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        $xml .= '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>';
        $xml .= '<xf numFmtId="165" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>';
        $xml .= '</cellXfs>';
        $xml .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';

        return $xml.'</styleSheet>';
    }

    private function corePropertiesXml(): string
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');

        $xml = $this->header();
        $xml .= '<cp:coreProperties'
            .' xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            .' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            .' xmlns:dcterms="http://purl.org/dc/terms/"'
            .' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
        $xml .= '<dc:creator>'.$this->escape($this->creator).'</dc:creator>';
        $xml .= '<cp:lastModifiedBy>'.$this->escape($this->creator).'</cp:lastModifiedBy>';
        $xml .= '<dcterms:created xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:created>';
        $xml .= '<dcterms:modified xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:modified>';

        return $xml.'</cp:coreProperties>';
    }

    private function appPropertiesXml(): string
    {
        $xml = $this->header();
        $xml .= '<Properties'
            .' xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            .' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">';
        $xml .= '<Application>'.$this->escape($this->creator).'</Application>';

        return $xml.'</Properties>';
    }

    private function header(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    }

    private function escape(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function uniqueName(string $name): string
    {
        if (! isset($this->usedNames[$name])) {
            return $name;
        }

        for ($suffix = 2; $suffix < 100; $suffix++) {
            $suffixText = " ({$suffix})";
            $candidate = mb_substr($name, 0, 31 - mb_strlen($suffixText)).$suffixText;

            if (! isset($this->usedNames[$candidate])) {
                return $candidate;
            }
        }

        return mb_substr($name, 0, 26).'-'.mb_substr((string) microtime(true), -4);
    }
}
