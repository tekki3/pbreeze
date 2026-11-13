<?php
declare(strict_types=1);

namespace PBreeze\Documents;

use InvalidArgumentException;

final class WordDocument
{
    private string $body = '';

    public function heading(string $text, int $level = 1): self
    {
        if ($level < 1 || $level > 6) {
            throw new InvalidArgumentException('Word heading levels must be between 1 and 6.');
        }
        $size = [1 => 36, 2 => 30, 3 => 26, 4 => 24, 5 => 22, 6 => 22][$level];
        $this->body .= self::paragraphXml($text, '<w:pPr><w:keepNext/><w:outlineLvl w:val="' . ($level - 1)
            . '"/></w:pPr>', '<w:rPr><w:b/><w:sz w:val="' . $size . '"/></w:rPr>');
        return $this;
    }

    public function paragraph(string $text): self
    {
        $this->body .= self::paragraphXml($text);
        return $this;
    }

    /** @param list<string> $headers @param iterable<list<string|int|float|bool|null>> $rows */
    public function table(array $headers, iterable $rows): self
    {
        Documents::row($headers);
        if ($headers === [] || count($headers) > 63) {
            throw new InvalidArgumentException('Word tables require 1 to 63 columns.');
        }
        $xml = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) {
            $xml .= '<w:' . $side . ' w:val="single" w:sz="4" w:color="B8C2CC"/>';
        }
        $xml .= '</w:tblBorders></w:tblPr><w:tblGrid>';
        $width = intdiv(9360, count($headers));
        foreach ($headers as $header) {
            if (!is_string($header)) {
                throw new InvalidArgumentException('Table headers must be strings.');
            }
            $xml .= '<w:gridCol w:w="' . $width . '"/>';
        }
        $xml .= '</w:tblGrid>' . self::tableRow($headers, true);
        foreach ($rows as $row) {
            Documents::row($row);
            if (count($row) !== count($headers)) {
                throw new InvalidArgumentException('Table rows must match the number of headers.');
            }
            $xml .= self::tableRow($row, false);
        }
        $this->body .= $xml . '</w:tbl><w:p/>';
        return $this;
    }

    public function build(): DocumentFile
    {
        return Documents::package([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '</Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                . ($this->body === '' ? '<w:p/>' : $this->body)
                . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
                . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
                . '</w:body></w:document>',
        ], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx');
    }

    private static function paragraphXml(string $text, string $properties = '', string $runProperties = ''): string
    {
        Documents::text($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $runs = '';
        foreach (preg_split('/(\n|\t)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            $content = match ($part) {
                "\n" => '<w:br/>',
                "\t" => '<w:tab/>',
                default => '<w:t xml:space="preserve">' . Documents::escape($part) . '</w:t>',
            };
            $runs .= '<w:r>' . $runProperties . $content . '</w:r>';
        }
        return '<w:p>' . $properties . $runs . '</w:p>';
    }

    /** @param list<string|int|float|bool|null> $row */
    private static function tableRow(array $row, bool $header): string
    {
        $xml = '<w:tr>' . ($header ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
        foreach ($row as $value) {
            $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . intdiv(9360, count($row)) . '" w:type="dxa"/></w:tcPr>'
                . self::paragraphXml(Documents::cell($value), '', $header ? '<w:rPr><w:b/></w:rPr>' : '') . '</w:tc>';
        }
        return $xml . '</w:tr>';
    }
}
