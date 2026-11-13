<?php
declare(strict_types=1);

namespace PBreeze\Documents;

use InvalidArgumentException;
use LogicException;

final class Spreadsheet
{
    /** @var list<array{name: string, xml: string}> */
    private array $sheets = [];

    /** @param iterable<list<string|int|float|bool|null>> $rows */
    public function sheet(string $name, iterable $rows): self
    {
        Documents::text($name);
        $length = preg_match_all('/./us', $name) + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $name);
        if ($length < 1 || $length > 31 || preg_match('~[\\\\/?*\[\]:\x00-\x1f]~', $name)
            || str_starts_with($name, "'") || str_ends_with($name, "'")) {
            throw new InvalidArgumentException('Worksheet names must contain 1 to 31 UTF-16 code units without Excel-reserved characters.');
        }
        foreach ($this->sheets as $sheet) {
            if (preg_match('/\A' . preg_quote($sheet['name'], '/') . '\z/iu', $name)) {
                throw new InvalidArgumentException('Worksheet names must be unique without case differences.');
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $number = 0;
        foreach ($rows as $row) {
            Documents::row($row);
            $number++;
            if ($number > 1048576 || count($row) > 16384) {
                throw new InvalidArgumentException('Worksheet data exceeds Excel row or column limits.');
            }
            $xml .= '<row r="' . $number . '">';
            foreach ($row as $index => $value) {
                $text = Documents::cell($value);
                $reference = self::column($index + 1) . $number;
                if ($value === null) {
                    $xml .= '<c r="' . $reference . '"/>';
                } elseif (is_bool($value)) {
                    $xml .= '<c r="' . $reference . '" t="b"><v>' . ($value ? '1' : '0') . '</v></c>';
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $reference . '" t="n"><v>' . $text . '</v></c>';
                } else {
                    // Excel stores text lengths as UTF-16 code units.
                    $units = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text) + preg_match_all('/./us', $text);
                    if ($units > 32767) {
                        throw new InvalidArgumentException('Excel text cells must not exceed 32767 UTF-16 code units.');
                    }
                    $xml .= '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">'
                        . self::escape($text) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $this->sheets[] = ['name' => $name, 'xml' => $xml];
        return $this;
    }

    public function build(): DocumentFile
    {
        if ($this->sheets === []) {
            throw new LogicException('An Excel workbook requires at least one worksheet.');
        }
        $types = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $workbook = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $relations = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $parts = [];
        foreach ($this->sheets as $index => $sheet) {
            $id = $index + 1;
            $types .= '<Override PartName="/xl/worksheets/sheet' . $id
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbook .= '<sheet name="' . self::escape($sheet['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $relations .= '<Relationship Id="rId' . $id
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            $parts['xl/worksheets/sheet' . $id . '.xml'] = $sheet['xml'];
        }
        $parts['[Content_Types].xml'] = $types . '</Types>';
        $parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $parts['xl/workbook.xml'] = $workbook . '</sheets></workbook>';
        $parts['xl/_rels/workbook.xml.rels'] = $relations . '</Relationships>';
        return Documents::package($parts, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx');
    }

    private static function column(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + $number % 26) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }

    private static function escape(string $text): string
    {
        // OOXML reserves _xHHHH_ sequences even inside ordinary string values.
        return Documents::escape(preg_replace('/_(?=x[0-9a-f]{4}_)/i', '_x005F_', $text));
    }
}
