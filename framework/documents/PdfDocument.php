<?php
declare(strict_types=1);

namespace PBreeze\Documents;

use InvalidArgumentException;
use RuntimeException;

final class PdfDocument
{
    /** @var list<array{text: string, size: int, bold: bool}> */
    private array $lines = [];

    public function heading(string $text, int $level = 1): self
    {
        if ($level < 1 || $level > 3) {
            throw new InvalidArgumentException('PDF heading levels must be between 1 and 3.');
        }
        $this->append($text, [1 => 18, 2 => 15, 3 => 12][$level], true);
        return $this;
    }

    public function paragraph(string $text): self
    {
        $this->append($text, 11, false);
        return $this;
    }

    /** @param list<string> $headers @param iterable<list<string|int|float|bool|null>> $rows */
    public function table(array $headers, iterable $rows): self
    {
        Documents::row($headers);
        if ($headers === [] || count($headers) > 10) {
            throw new InvalidArgumentException('PDF tables require 1 to 10 columns.');
        }
        foreach ($headers as $header) {
            if (!is_string($header)) {
                throw new InvalidArgumentException('Table headers must be strings.');
            }
        }
        // Courier has fixed-width glyphs, so tables need no external font metrics.
        $width = intdiv(85 - (count($headers) - 1) * 3, count($headers));
        $lines = self::tableLines($headers, $width, true);
        foreach ($rows as $row) {
            Documents::row($row);
            if (count($row) !== count($headers)) {
                throw new InvalidArgumentException('Table rows must match the number of headers.');
            }
            array_push($lines, ...self::tableLines($row, $width, false));
        }
        array_push($this->lines, ...$lines);
        $this->lines[] = ['text' => '', 'size' => 10, 'bold' => false];
        return $this;
    }

    public function build(): DocumentFile
    {
        $pages = [];
        $stream = '';
        $y = 800;
        foreach ($this->lines as $line) {
            $height = $line['size'] + 5;
            if ($y - $height < 42) {
                $pages[] = $stream;
                $stream = '';
                $y = 800;
            }
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line['text']);
            $stream .= 'BT /' . ($line['bold'] ? 'F2' : 'F1') . ' ' . $line['size']
                . ' Tf 1 0 0 1 42 ' . $y . ' Tm (' . $escaped . ") Tj ET\n";
            $y -= $height;
        }
        $pages[] = $stream;
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($pages as $index => $content) {
            $id = 5 + $index * 2;
            $kids[] = $id . ' 0 R';
            $objects[$id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89]'
                . ' /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ($id + 1) . ' 0 R >>';
            $objects[$id + 1] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
        }
        $objects[2] = '<< /Type /Pages /Count ' . count($pages) . ' /Kids [' . implode(' ', $kids) . '] >>';
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
        for ($id = 1; $id < $count; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return new DocumentFile($pdf, 'application/pdf', 'pdf');
    }

    private function append(string $text, int $size, bool $bold): void
    {
        $text = self::encode($text);
        $width = (int) floor(511 / ($size * 0.6));
        foreach (self::wrap($text, $width) as $line) {
            $this->lines[] = ['text' => $line, 'size' => $size, 'bold' => $bold];
        }
        $this->lines[] = ['text' => '', 'size' => 5, 'bold' => false];
    }

    private static function encode(string $text): string
    {
        Documents::text($text);
        if (str_contains($text, "\x7f")) {
            throw new InvalidArgumentException('PDF text must not contain control characters other than tabs and line breaks.');
        }
        if (!function_exists('iconv')) {
            throw new RuntimeException('PDF creation requires the native iconv extension.');
        }
        // Reject unrepresentable characters before iconv can emit a warning or replace data.
        if (preg_match('/[^\x{0}-\x{7F}\x{A0}-\x{FF}\x{20AC}\x{201A}\x{192}\x{201E}\x{2026}\x{2020}\x{2021}\x{2C6}\x{2030}\x{160}\x{2039}\x{152}\x{17D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2013}\x{2014}\x{2DC}\x{2122}\x{161}\x{203A}\x{153}\x{17E}\x{178}]/u', $text)) {
            throw new InvalidArgumentException('The native PDF font supports Windows-1252 text only; use Word for other Unicode characters.');
        }
        $encoded = iconv('UTF-8', 'Windows-1252', $text);
        if ($encoded === false) {
            throw new RuntimeException('Cannot encode PDF text.');
        }
        return str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $encoded);
    }

    /** @return list<string> */
    private static function wrap(string $text, int $width): array
    {
        return explode("\n", wordwrap($text, $width, "\n", true));
    }

    /** @param list<string|int|float|bool|null> $row @return list<array{text: string, size: int, bold: bool}> */
    private static function tableLines(array $row, int $width, bool $bold): array
    {
        $cells = [];
        foreach ($row as $value) {
            $cells[] = self::wrap(self::encode(Documents::cell($value)), $width);
        }
        $height = max(array_map('count', $cells));
        $lines = [];
        for ($index = 0; $index < $height; $index++) {
            $parts = [];
            foreach ($cells as $cell) {
                $parts[] = str_pad($cell[$index] ?? '', $width);
            }
            $lines[] = ['text' => implode(' | ', $parts), 'size' => 10, 'bold' => $bold];
        }
        return $lines;
    }
}
