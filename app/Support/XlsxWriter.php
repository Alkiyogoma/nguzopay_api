<?php

namespace App\Support;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Minimal .xlsx writer (one sheet): text, numbers, a bold header row and
 * column widths. Enough for report exports without a spreadsheet library.
 */
class XlsxWriter
{
    /** @var list<array{cells: list<mixed>, style: int}> */
    private array $rows = [];

    /** @var list<float> */
    private array $widths = [];

    public const NORMAL = 0;

    public const BOLD = 1;

    public const TITLE = 2;

    public const MONEY = 3;

    public const MUTED = 4;

    public function __construct(private readonly string $sheetName = 'Report') {}

    /** @param  list<mixed>  $cells  strings, ints/floats, DateTimeInterface or null */
    public function row(array $cells, int $style = self::NORMAL, array $moneyColumns = []): self
    {
        $this->rows[] = ['cells' => $cells, 'style' => $style, 'money' => $moneyColumns];
        foreach ($cells as $i => $cell) {
            $len = mb_strlen($this->text($cell));
            $this->widths[$i] = max($this->widths[$i] ?? 8, min(60, $len + 2));
        }

        return $this;
    }

    public function save(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the export file.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$this->esc(mb_substr($this->sheetName, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        // Styles: 0 normal, 1 bold, 2 title (bold 14), 3 #,##0 number, 4 grey text, 5 bold #,##0
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="6"><xf fontId="0"/><xf fontId="1" applyFont="1"/><xf fontId="2" applyFont="1"/><xf fontId="0" numFmtId="3" applyNumberFormat="1"/><xf fontId="3" applyFont="1"/><xf fontId="1" numFmtId="3" applyFont="1" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();
    }

    private function sheet(): string
    {
        $cols = '';
        foreach ($this->widths as $i => $w) {
            $n = $i + 1;
            $cols .= "<col min=\"{$n}\" max=\"{$n}\" width=\"{$w}\" customWidth=\"1\"/>";
        }

        $xml = '';
        foreach ($this->rows as $r => $row) {
            $rn = $r + 1;
            $xml .= "<row r=\"{$rn}\">";
            foreach ($row['cells'] as $c => $value) {
                $ref = $this->col($c).$rn;
                $numeric = is_int($value) || is_float($value);
                $style = match (true) {
                    $numeric && $row['style'] === self::BOLD => 5,
                    $numeric && in_array($c, $row['money'], true) => 3,
                    default => $row['style'],
                };
                if ($value === null || $value === '') {
                    $xml .= "<c r=\"{$ref}\" s=\"{$style}\"/>";
                } elseif ($numeric) {
                    $xml .= "<c r=\"{$ref}\" s=\"{$style}\"><v>{$value}</v></c>";
                } else {
                    $xml .= "<c r=\"{$ref}\" s=\"{$style}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">".$this->esc($this->text($value)).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .($cols ? "<cols>{$cols}</cols>" : '').'<sheetData>'.$xml.'</sheetData></worksheet>';
    }

    private function text(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i') : (string) $value;
    }

    private function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private function esc(string $s): string
    {
        // Strip characters XML 1.0 forbids, then escape.
        return htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
