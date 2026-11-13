<?php
declare(strict_types=1);

use PBreeze\Documents\DocumentFile;
use PBreeze\Documents\Documents;
use PBreeze\Request;
use PBreeze\Router;

/** @return array<string, DOMDocument> */
function documentParts(DocumentFile $file): array
{
    global $temp;
    $path = $temp . '/package-' . bin2hex(random_bytes(6)) . '.' . $file->extension;
    $file->save($path);
    $zip = new ZipArchive();
    same(true, $zip->open($path, ZipArchive::CHECKCONS));
    try {
        $parts = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            $xml = $zip->getFromIndex($index);
            check(is_string($name) && is_string($xml));
            check(!str_contains($xml, '<!DOCTYPE') && !str_contains($xml, '<!ENTITY'));
            $document = new DOMDocument();
            check($document->loadXML($xml, LIBXML_NONET));
            $parts[$name] = $document;
        }
        return $parts;
    } finally {
        $zip->close();
        unlink($path);
    }
}

test('JSON document export preserves data types, Unicode, and explicit encoding failures', static function (): void {
    $data = ['name' => 'Café 日本語', 'active' => true, 'price' => 1.0, 'empty' => null, 'rows' => [1, 2]];
    $file = Documents::json($data);
    same('json', $file->extension);
    same('application/json', $file->mimeType);
    same($data, json_decode($file->contents, true, 512, JSON_THROW_ON_ERROR));
    check(str_contains($file->contents, '日本語'));
    same("null\n", Documents::json(null)->contents);
    raises(static fn () => Documents::json(["\xff"]), JsonException::class);
    raises(static fn () => Documents::json(INF), JsonException::class);
    $cycle = [];
    $cycle['self'] = &$cycle;
    raises(static fn () => Documents::json($cycle), JsonException::class);
});

test('CSV export round trips delimiters, quotes, backslashes, newlines, and scalar cells', static function (): void {
    $rows = [['name', 'notes', 'number', 'active', 'empty'], ['Café 日本語', "a,\"b\"\\c\nline", -2.5, true, null]];
    foreach ([',', ';', "\t", '|'] as $delimiter) {
        $file = Documents::csv((static function () use ($rows): Generator { yield from $rows; })(), $delimiter);
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, $file->contents);
            rewind($stream);
            same($rows[0], fgetcsv($stream, null, $delimiter, '"', ''));
            same(['Café 日本語', "a,\"b\"\\c\nline", '-2.5', 'true', ''], fgetcsv($stream, null, $delimiter, '"', ''));
            same(false, fgetcsv($stream, null, $delimiter, '"', ''));
            check(str_ends_with($file->contents, "\r\n"));
        } finally {
            fclose($stream);
        }
    }
    same('', Documents::csv([])->contents);
    raises(static fn () => Documents::csv([['x']], ':'), InvalidArgumentException::class);
});

test('CSV formula protection covers formulas after whitespace and can be disabled explicitly', static function (): void {
    $row = ['=1+1', '+cmd', '-2', '@SUM(A1)', " \t=1", "\tvalue", "\rvalue", "\nvalue", 'normal', -2];
    $protected = Documents::csv([$row])->contents;
    $stream = fopen('php://temp', 'w+b');
    try {
        fwrite($stream, $protected);
        rewind($stream);
        $actual = fgetcsv($stream, null, ',', '"', '');
        foreach (array_slice($row, 0, 8) as $index => $value) {
            same("'" . $value, $actual[$index]);
        }
        same('normal', $actual[8]);
        same('-2', $actual[9]);
    } finally {
        fclose($stream);
    }
    same("=1+1\r\n", Documents::csv([['=1+1']], protectFormulas: false)->contents);
});

test('XML export provides escaped nested elements and item lists without DTDs or attributes', static function (): void {
    $file = Documents::xml([
        'name' => "Café 日本語 <&> \"'\r\n",
        'rows' => [['active' => true, 'count' => 2], ['active' => false, 'count' => null]],
    ], 'report');
    $xml = new DOMDocument();
    check($xml->loadXML($file->contents, LIBXML_NONET));
    same('report', $xml->documentElement->nodeName);
    same("Café 日本語 <&> \"'\r\n", $xml->getElementsByTagName('name')->item(0)->textContent);
    same(2, $xml->getElementsByTagName('item')->length);
    same('true', $xml->getElementsByTagName('active')->item(0)->textContent);
    same('', $xml->getElementsByTagName('count')->item(1)->textContent);
    check(!str_contains($file->contents, '<!DOCTYPE'));
    foreach (['bad name', 'ns:name', '1root', 'xmlRoot', '<node>'] as $name) {
        raises(static fn () => Documents::xml([], $name), InvalidArgumentException::class);
        raises(static fn () => Documents::xml([$name => 'value']), InvalidArgumentException::class);
    }
    $deep = [];
    for ($index = 0; $index < 66; $index++) {
        $deep = ['nested' => $deep];
    }
    raises(static fn () => Documents::xml($deep), InvalidArgumentException::class);
});

test('Document exporters reject invalid UTF-8, illegal XML characters, and unsupported cell values', static function (): void {
    foreach (["\xff", "\0", "\x01", "\u{FFFE}", new stdClass(), ['nested'], INF, NAN] as $value) {
        raises(static fn () => Documents::csv([[$value]]), InvalidArgumentException::class);
        if (!is_array($value)) {
            raises(static fn () => Documents::xml(['value' => $value]), InvalidArgumentException::class);
        }
        raises(static fn () => Documents::excel()->sheet('Data', [[$value]]), InvalidArgumentException::class);
        raises(static fn () => Documents::word()->table(['Value'], [[$value]]), InvalidArgumentException::class);
        raises(static fn () => Documents::pdf()->table(['Value'], [[$value]]), InvalidArgumentException::class);
    }
    foreach ([['named' => 'value'], 'not a row'] as $row) {
        raises(static fn () => Documents::csv([$row]), InvalidArgumentException::class);
        raises(static fn () => Documents::excel()->sheet('Data', [$row]), InvalidArgumentException::class);
    }
});

test('Excel packages contain valid relationships, multiple worksheets, typed cells, and safe literal text', static function (): void {
    $file = Documents::excel()
        ->sheet('People & sales', [
            ['Name', 'Total', 'Active', 'Empty', 'Literal'],
            ['Café 日本語', 12.5, true, null, '=HYPERLINK("https://example.test")'],
            ['_x0041_', -1, false, '', "a\rb\nc"],
        ])
        ->sheet('Other', [[0, 1.0]])
        ->build();
    same('xlsx', $file->extension);
    same('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $file->mimeType);
    check(str_starts_with($file->contents, "PK\x03\x04"));
    $parts = documentParts($file);
    same(6, count($parts));
    $workbook = $parts['xl/workbook.xml'];
    $sheets = $workbook->getElementsByTagName('sheet');
    same(2, $sheets->length);
    same('People & sales', $sheets->item(0)->getAttribute('name'));
    same('rId2', $sheets->item(1)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id'));
    $relations = $parts['xl/_rels/workbook.xml.rels']->getElementsByTagName('Relationship');
    same('worksheets/sheet2.xml', $relations->item(1)->getAttribute('Target'));
    $types = $parts['[Content_Types].xml']->getElementsByTagName('Override');
    same(3, $types->length);
    same('xl/workbook.xml', $parts['_rels/.rels']->getElementsByTagName('Relationship')->item(0)->getAttribute('Target'));
    $xpath = new DOMXPath($parts['xl/worksheets/sheet1.xml']);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    same('Café 日本語', $xpath->evaluate('string(//s:c[@r="A2"]/s:is/s:t)'));
    same('n', $xpath->evaluate('string(//s:c[@r="B2"]/@t)'));
    same('12.5', $xpath->evaluate('string(//s:c[@r="B2"]/s:v)'));
    same('b', $xpath->evaluate('string(//s:c[@r="C2"]/@t)'));
    same('1', $xpath->evaluate('string(//s:c[@r="C2"]/s:v)'));
    same(0, $xpath->query('//s:c[@r="D2"]/*')->length);
    same('=HYPERLINK("https://example.test")', $xpath->evaluate('string(//s:c[@r="E2"]/s:is/s:t)'));
    same('_x005F_x0041_', $xpath->evaluate('string(//s:c[@r="A3"]/s:is/s:t)'));
    same("a\rb\nc", $xpath->evaluate('string(//s:c[@r="E3"]/s:is/s:t)'));
    same(0, $xpath->query('//s:f')->length);
});

test('Excel validates names, dimensions, text lengths, column references, and failed sheet additions', static function (): void {
    raises(static fn () => Documents::excel()->build(), LogicException::class);
    foreach (['', str_repeat('a', 32), 'Bad/Name', 'Bad\\Name', 'Bad:Name', 'Bad[Name]', 'Bad*Name', 'Bad?Name', "'Bad", "Bad'", str_repeat('😀', 16)] as $name) {
        raises(static fn () => Documents::excel()->sheet($name, []), InvalidArgumentException::class);
    }
    $book = Documents::excel()->sheet('Éxport', []);
    raises(static fn () => $book->sheet('éXPORT', []), InvalidArgumentException::class);
    raises(static fn () => $book->sheet('Invalid', [[new stdClass()]]), InvalidArgumentException::class);
    $book->sheet('Invalid', [['now valid']]);
    raises(static fn () => Documents::excel()->sheet('Data', [[str_repeat('x', 32768)]]), InvalidArgumentException::class);
    raises(static fn () => Documents::excel()->sheet('Data', [[str_repeat('😀', 16384)]]), InvalidArgumentException::class);
    raises(static fn () => Documents::excel()->sheet('Data', [array_fill(0, 16385, 0)]), InvalidArgumentException::class);
    $row = array_fill(0, 16384, null);
    $row[25] = 'Z';
    $row[26] = 'AA';
    $row[16383] = 'XFD';
    $parts = documentParts(Documents::excel()->sheet('Data', [$row])->build());
    $xpath = new DOMXPath($parts['xl/worksheets/sheet1.xml']);
    $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    foreach (['Z', 'AA', 'XFD'] as $column) {
        same($column, $xpath->evaluate('string(//s:c[@r="' . $column . '1"]/s:is/s:t)'));
    }
    same(2, documentParts($book->build())['xl/workbook.xml']->getElementsByTagName('sheet')->length);
});

test('Word packages preserve Unicode, headings, paragraphs, tabs, line breaks, and tables', static function (): void {
    $file = Documents::word()->heading('Quarterly <report>', 1)->heading('Details', 3)
        ->paragraph("Café 日本語 & <text>\r\nNext\tcolumn")
        ->table(['Name', 'Amount'], [['Ada', 12.5], ['Lin', null]])->build();
    same('docx', $file->extension);
    same('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $file->mimeType);
    $parts = documentParts($file);
    same(3, count($parts));
    same('word/document.xml', $parts['_rels/.rels']->getElementsByTagName('Relationship')->item(0)->getAttribute('Target'));
    same('/word/document.xml', $parts['[Content_Types].xml']->getElementsByTagName('Override')->item(0)->getAttribute('PartName'));
    $xml = $parts['word/document.xml'];
    $namespace = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $xpath = new DOMXPath($xml);
    $xpath->registerNamespace('w', $namespace);
    same('Quarterly <report>', $xpath->evaluate('string(/w:document/w:body/w:p[1]/w:r/w:t)'));
    same('2', $xpath->evaluate('string(/w:document/w:body/w:p[2]/w:pPr/w:outlineLvl/@w:val)'));
    same('Café 日本語 & <text>', $xpath->evaluate('string(/w:document/w:body/w:p[3]/w:r[1]/w:t)'));
    same(1, $xpath->query('//w:br')->length);
    same(1, $xpath->query('//w:tab')->length);
    same(3, $xpath->query('//w:tbl/w:tr')->length);
    same(1, $xpath->query('//w:tblHeader')->length);
    same('12.5', $xpath->evaluate('string(//w:tbl/w:tr[2]/w:tc[2]/w:p/w:r/w:t)'));
    documentParts(Documents::word()->build());
    raises(static fn () => Documents::word()->heading('Bad', 0), InvalidArgumentException::class);
    raises(static fn () => Documents::word()->heading('Bad', 7), InvalidArgumentException::class);
    raises(static fn () => Documents::word()->table([], []), InvalidArgumentException::class);
    raises(static fn () => Documents::word()->table(['One'], [['a', 'b']]), InvalidArgumentException::class);
});

test('PDF export has correct xref offsets, stream lengths, pagination, escaping, and font encodings', static function (): void {
    $document = Documents::pdf()->heading('Report (2026)')->paragraph("Café € \\ <text>\r\nNext\tcolumn")
        ->table(['Name', 'Amount'], [['Ada', 12.5], ['Lin', null]]);
    for ($index = 0; $index < 100; $index++) {
        $document->paragraph('Row ' . $index . ' ' . str_repeat('long ', 30));
    }
    $file = $document->build();
    same('pdf', $file->extension);
    same('application/pdf', $file->mimeType);
    check(str_starts_with($file->contents, '%PDF-1.4'));
    check(str_ends_with($file->contents, "%%EOF\n"));
    check(str_contains($file->contents, 'Report \\(2026\\)'));
    check(str_contains($file->contents, "Caf\xe9 \x80 \\\\ <text>"));
    preg_match('/\/Type \/Pages \/Count (\d+)/', $file->contents, $pages);
    check((int) $pages[1] > 1);
    preg_match('/startxref\n(\d+)\n%%EOF/', $file->contents, $start);
    $xref = substr($file->contents, (int) $start[1]);
    check(str_starts_with($xref, "xref\n"));
    preg_match('/xref\n0 (\d+)\n/', $xref, $size);
    $entries = explode("\n", $xref);
    for ($id = 1; $id < (int) $size[1]; $id++) {
        $offset = (int) substr($entries[$id + 2], 0, 10);
        check(str_starts_with(substr($file->contents, $offset), $id . " 0 obj\n"));
    }
    preg_match_all('/\/Length (\d+) >>\nstream\n/', $file->contents, $streams, PREG_OFFSET_CAPTURE);
    foreach ($streams[0] as $index => [$match, $offset]) {
        $end = $offset + strlen($match) + (int) $streams[1][$index][0];
        check(str_starts_with(substr($file->contents, $end), 'endstream'));
    }
    same($file->contents, $document->build()->contents);
    check(str_contains(Documents::pdf()->build()->contents, '/Count 1'));
    raises(static fn () => Documents::pdf()->paragraph('日本語'), InvalidArgumentException::class);
    raises(static fn () => Documents::pdf()->paragraph("\x7f"), InvalidArgumentException::class);
    raises(static fn () => Documents::pdf()->heading('Bad', 4), InvalidArgumentException::class);
    raises(static fn () => Documents::pdf()->table([], []), InvalidArgumentException::class);
    raises(static fn () => Documents::pdf()->table(['One'], [['a', 'b']]), InvalidArgumentException::class);
});

test('Document files save exclusively with private permissions and reject unsafe local paths', static function () use ($temp): void {
    $file = Documents::json(['name' => 'Ada']);
    $path = $temp . '/private report.json';
    $file->save($path);
    same($file->contents, file_get_contents($path));
    if (PHP_OS_FAMILY !== 'Windows') {
        same(0600, fileperms($path) & 0777);
    }
    raises(static fn () => $file->save($path), RuntimeException::class);
    same($file->contents, file_get_contents($path));
    $file->save(str_replace('/', '\\', $temp . '/backslash report.json'));
    same($file->contents, file_get_contents($temp . '/backslash report.json'));
    foreach (['', "bad\0.json", 'php://memory', 'https://example.test/out.json', 'C:relative.json', $temp . '/CON.json', $temp . '/bad?.json', $temp . '/bad.'] as $invalid) {
        raises(static fn () => $file->save($invalid), InvalidArgumentException::class);
    }
    raises(static fn () => new DocumentFile('', "text/plain\r\nX-Bad: 1", 'txt'), InvalidArgumentException::class);
    raises(static fn () => new DocumentFile('', 'text/plain', 'txt"'), InvalidArgumentException::class);
});

test('Document downloads use safe attachment headers, correct byte lengths, and ordinary web routes', static function (): void {
    $files = [
        Documents::json(['name' => 'Ada']),
        Documents::csv([['name'], ['Ada']]),
        Documents::xml(['name' => 'Ada']),
        Documents::pdf()->paragraph('Ada')->build(),
        Documents::excel()->sheet('Names', [['Ada']])->build(),
        Documents::word()->paragraph('Ada')->build(),
    ];
    foreach ($files as $file) {
        $response = $file->download('Café "' . $file->extension . '".' . $file->extension);
        same($file->contents, $response->body);
        same($file->mimeType, $response->headers['Content-Type']);
        same((string) strlen($file->contents), $response->headers['Content-Length']);
        check(str_contains($response->headers['Content-Disposition'], "filename*=UTF-8''Caf%C3%A9%20%22"));
        same('no-store', $response->headers['Cache-Control']);
        $router = new Router();
        $router->get('/report', static fn () => $response);
        same($response, $router->dispatch(new Request('GET', '/report')));
        same($response, $router->dispatch(new Request('HEAD', '/report')));
        foreach (['', '../report', 'folder/report', 'folder\\report', "bad\r\nX-Bad: 1", "\xff", '.', '..'] as $invalid) {
            raises(static fn () => $file->download($invalid), InvalidArgumentException::class);
        }
    }
});

function documentHttp(string $base): void
{
    test('HTTP document downloads deliver all six file formats and omit bodies for HEAD', static function () use ($base): void {
        foreach (['json', 'csv', 'xml', 'pdf', 'xlsx', 'docx'] as $extension) {
            $response = http($base, '/__documents/' . $extension);
            same(200, $response['status']);
            $headers = implode("\n", $response['headers']);
            check(str_contains($headers, 'Content-Disposition: attachment;'));
            check(str_contains($headers, 'Content-Length: ' . strlen($response['body'])));
            check(str_contains($headers, 'X-Content-Type-Options: nosniff'));
            check(str_contains($headers, 'Cache-Control: no-store'));
            check(str_contains($headers, "filename*=UTF-8''report." . $extension));
            $head = http($base, '/__documents/' . $extension, 'HEAD');
            same(200, $head['status']);
            same('', $head['body']);
            match ($extension) {
                'json' => same(['name' => 'Ada'], json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)),
                'csv' => same("name\r\nAda\r\n", $response['body']),
                'xml' => check(str_contains($response['body'], '<name>Ada</name>')),
                'pdf' => check(str_starts_with($response['body'], '%PDF-1.4')),
                default => check(str_starts_with($response['body'], "PK\x03\x04")),
            };
        }
    });
}
