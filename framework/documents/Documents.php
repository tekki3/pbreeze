<?php
declare(strict_types=1);

namespace PBreeze\Documents;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

final class Documents
{
    public static function json(mixed $data): DocumentFile
    {
        return new DocumentFile(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n",
            'application/json',
            'json',
        );
    }

    /** @param iterable<list<string|int|float|bool|null>> $rows */
    public static function csv(iterable $rows, string $delimiter = ',', bool $protectFormulas = true): DocumentFile
    {
        if (!in_array($delimiter, [',', ';', "\t", '|'], true)) {
            throw new InvalidArgumentException('Use a comma, semicolon, tab, or pipe CSV delimiter.');
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot open the CSV buffer.');
        }
        try {
            foreach ($rows as $row) {
                self::row($row);
                $cells = [];
                foreach ($row as $value) {
                    $text = self::cell($value);
                    if ($protectFormulas && is_string($value) && preg_match('/^(?:\s*[=+@-]|[\t\r\n])/u', $text)) {
                        $text = "'" . $text;
                    }
                    $cells[] = $text;
                }
                if (fputcsv($stream, $cells, $delimiter, '"', '', "\r\n") === false) {
                    throw new RuntimeException('Cannot write the CSV buffer.');
                }
            }
            if (!rewind($stream)) {
                throw new RuntimeException('Cannot rewind the CSV buffer.');
            }
            $contents = stream_get_contents($stream);
            if ($contents === false) {
                throw new RuntimeException('Cannot read the CSV buffer.');
            }
        } finally {
            fclose($stream);
        }
        return new DocumentFile($contents, 'text/csv', 'csv');
    }

    /**
     * String keys become elements; list entries become <item> elements.
     * @param array<mixed> $data
     */
    public static function xml(array $data, string $root = 'document'): DocumentFile
    {
        self::elementName($root);
        return new DocumentFile(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n<" . $root . '>'
                . self::xmlValues($data, 0) . '</' . $root . ">\n",
            'application/xml',
            'xml',
        );
    }

    public static function pdf(): PdfDocument
    {
        return new PdfDocument();
    }

    public static function excel(): Spreadsheet
    {
        return new Spreadsheet();
    }

    public static function word(): WordDocument
    {
        return new WordDocument();
    }

    /** @internal */
    public static function text(string $text): string
    {
        if (preg_match('//u', $text) !== 1
            || preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $text)) {
            throw new InvalidArgumentException('Document text must contain valid UTF-8 and XML 1.0 characters.');
        }
        return $text;
    }

    /** @internal */
    public static function escape(string $text): string
    {
        return str_replace("\r", '&#13;', htmlspecialchars(self::text($text), ENT_XML1 | ENT_QUOTES, 'UTF-8'));
    }

    /** @internal */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        if (is_string($value)) {
            return self::text($value);
        }
        throw new InvalidArgumentException('Document cells must be strings, finite numbers, booleans, or null.');
    }

    /** @internal */
    public static function row(mixed $row): void
    {
        if (!is_array($row) || !array_is_list($row)) {
            throw new InvalidArgumentException('Document rows must be lists of cells.');
        }
    }

    /** @internal @param array<string, string> $parts */
    public static function package(array $parts, string $mimeType, string $extension): DocumentFile
    {
        if (!class_exists(ZipArchive::class, false)) {
            throw new RuntimeException('Excel and Word creation require the native zip extension.');
        }
        $path = tempnam(sys_get_temp_dir(), 'pbreeze-document-');
        if ($path === false) {
            throw new RuntimeException('Cannot create the Office package buffer.');
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot open the Office package buffer.');
            }
            $opened = true;
            foreach ($parts as $name => $contents) {
                if (!$zip->addFromString($name, $contents)) {
                    throw new RuntimeException('Cannot add an Office package part.');
                }
            }
            $closed = $zip->close();
            $opened = false;
            if (!$closed) {
                throw new RuntimeException('Cannot finalize the Office package.');
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Cannot read the Office package.');
            }
            return new DocumentFile($contents, $mimeType, $extension);
        } finally {
            try {
                if ($opened && !$zip->close()) {
                    throw new RuntimeException('Cannot close the Office package.');
                }
            } finally {
                if (!unlink($path)) {
                    throw new RuntimeException('Cannot remove the Office package buffer.');
                }
            }
        }
    }

    private static function elementName(string $name): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $name) || str_starts_with(strtolower($name), 'xml')) {
            throw new InvalidArgumentException('Use unprefixed XML element names that do not start with xml.');
        }
    }

    /** @param array<mixed> $data */
    private static function xmlValues(array $data, int $depth): string
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('XML data must not exceed 64 nested levels.');
        }
        $xml = '';
        foreach ($data as $key => $value) {
            $name = is_int($key) ? 'item' : $key;
            self::elementName($name);
            $content = is_array($value) ? self::xmlValues($value, $depth + 1) : self::escape(self::cell($value));
            $xml .= '<' . $name . '>' . $content . '</' . $name . '>';
        }
        return $xml;
    }
}
