<?php
declare(strict_types=1);

use PBreeze\FileReader;
use PBreeze\Readers\CsvReader;
use PBreeze\Readers\JsonReader;
use PBreeze\Readers\XmlReader;
use PBreeze\Readers\YamlReader;

function readerFixture(string $contents, string $extension): string
{
    global $temp;
    $path = $temp . '/reader-' . bin2hex(random_bytes(6)) . '.' . $extension;
    file_put_contents($path, $contents);
    return $path;
}

test('File readers detect case-insensitive extensions, explicit formats, and trusted custom readers', static function (): void {
    $path = readerFixture('{"nested":{"list":[1,true,null,{"name":"value"}]},"big":92233720368547758080}', 'JSON');
    $expected = ['nested' => ['list' => [1, true, null, ['name' => 'value']]], 'big' => '92233720368547758080'];
    same($expected, FileReader::read($path));
    same($expected, (new JsonReader())->read($path));
    same($expected, FileReader::read(readerFixture(file_get_contents($path), 'dat'), ['format' => 'json']));
    FileReader::register('fixture', new JsonReader());
    same($expected, FileReader::read(readerFixture(file_get_contents($path), 'fixture')));
    raises(static fn () => FileReader::read($path, ['format' => 1]), InvalidArgumentException::class);
    raises(static fn () => FileReader::read($path, ['format' => null]), InvalidArgumentException::class);
    raises(static fn () => FileReader::read($path, ['typo' => true]), InvalidArgumentException::class);
    raises(static fn () => FileReader::register('../bad', new JsonReader()), InvalidArgumentException::class);
});

test('File readers explicitly reject missing files, nonfiles, wrappers, unsupported formats and unsafe limits', static function (): void {
    global $temp;
    raises(static fn () => FileReader::read($temp . '/missing.json'), RuntimeException::class);
    raises(static fn () => FileReader::read($temp, ['format' => 'json']), RuntimeException::class);
    raises(static fn () => FileReader::read('php://filter/resource=/etc/passwd', ['format' => 'json']), InvalidArgumentException::class);
    raises(static fn () => FileReader::read("bad\0.json"), InvalidArgumentException::class);
    raises(static fn () => FileReader::read(readerFixture('{}', 'txt')), InvalidArgumentException::class);
    $path = readerFixture('{"a":1}', 'json');
    raises(static fn () => FileReader::read($path, ['max_bytes' => 6]), RuntimeException::class);
    same(['a' => 1], FileReader::read($path, ['max_bytes' => 7]));
    same(['a' => 1], FileReader::read($path, ['max_bytes' => PHP_INT_MAX]));
    foreach ([['max_bytes' => 0], ['max_depth' => 0], ['max_depth' => 257], ['max_bytes' => '12']] as $options) {
        raises(static fn () => FileReader::read($path, $options), InvalidArgumentException::class);
    }
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        chmod($path, 0000);
        try {
            raises(static fn () => FileReader::read($path), RuntimeException::class);
        } finally {
            chmod($path, 0600);
        }
    }
});

test('JSON readers support BOM, empty containers and nested data with safe descriptive parse errors', static function (): void {
    same([], FileReader::read(readerFixture("\xef\xbb\xbf{}", 'json')));
    same([], FileReader::read(readerFixture('[]', 'json')));
    foreach (['', '{"secret":}', '{"x":1,}', '[1', 'null', 'true', '"string"', '{"x":1e9999}', '{"x":"' . "\xff" . '"}'] as $contents) {
        raises(static fn () => FileReader::read(readerFixture($contents, 'json')), RuntimeException::class);
    }
    raises(static fn () => FileReader::read(readerFixture('{"a":{"b":[]}}', 'json'), ['max_depth' => 1]), RuntimeException::class);
    try {
        FileReader::read(readerFixture('{"secret-value":}', 'json'));
        throw new LogicException('Expected JSON failure.');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'JSON'));
        check(!str_contains($error->getMessage(), 'secret-value'));
        check($error->getPrevious() instanceof JsonException);
    }
    raises(static fn () => FileReader::iterate(readerFixture('[]', 'json')), RuntimeException::class);
});

test('CSV readers handle headers, Unicode BOM, multiline values, escapes, delimiters and custom names', static function (): void {
    $path = readerFixture("\xef\xbb\xbfname,notes\r\n\"Caf\xc3\xa9\",\"first\nsecond \"\"quoted\"\"\"\r\n", 'csv');
    same([['name' => "Caf\xc3\xa9", 'notes' => "first\nsecond \"quoted\""]], FileReader::read($path));
    same([['a' => 'x', 'b' => 'y']], FileReader::read(readerFixture("a;b\n'x';'y'\n", 'csv'), [
        'delimiter' => ';', 'enclosure' => "'",
    ]));
    same([['key' => 'a', 'value' => 'b']], FileReader::read(readerFixture("a\tb", 'csv'), [
        'delimiter' => "\t", 'headers' => ['key', 'value'],
    ]));
    same([['a', 'b'], ['1', '2']], (new CsvReader())->read(readerFixture("a|b\n1|2\n", 'csv'), [
        'headers' => false, 'delimiter' => '|',
    ]));
    same([['x' => 'a\\"b']], FileReader::read(readerFixture("x\n\"a\\\"b\"\n", 'csv'), ['escape' => '\\']));
    same([], FileReader::read(readerFixture('', 'csv')));
    same([], FileReader::read(readerFixture('only-header', 'csv')));
    same([['']], FileReader::read(readerFixture("\n", 'csv'), ['headers' => false, 'skip_empty_lines' => false]));
    same([], FileReader::read(readerFixture("\n\n", 'csv'), ['headers' => false]));
    $rows = [['one', 'a,"quoted"', "with\nnewline", 'back\\slash', '']];
    same($rows, FileReader::read(readerFixture(\PBreeze\Documents\Documents::csv($rows, protectFormulas: false)->contents, 'csv'), ['headers' => false]));
});

test('CSV rejects malformed records, duplicate headers, inconsistent widths and invalid reader options', static function (): void {
    foreach (["a,b\n1", "a,b\n1,2,3", "a,a\n1,2", ",b\n1,2", "a\n\"unclosed", "a\n\"value\"junk", "a\nbare\"quote",
        "\xff\xfea\0", "\xfe\xff\0a", "a\n\xff"] as $contents) {
        raises(static fn () => FileReader::read(readerFixture($contents, 'csv')), RuntimeException::class);
    }
    $path = readerFixture("a,b\n1,2\n", 'csv');
    foreach ([['delimiter' => 'xx'], ['enclosure' => ''], ['escape' => 'xx'], ['delimiter' => "\n"],
        ['delimiter' => '"'], ['headers' => 1], ['headers' => ['a', 'a']], ['headers' => []],
        ['skip_empty_lines' => 1]] as $options) {
        raises(static fn () => FileReader::read($path, $options), InvalidArgumentException::class);
    }
});

test('CSV iteration streams large inputs without retaining all rows and closes abandoned generators', static function (): void {
    $path = readerFixture('', 'csv');
    $stream = fopen($path, 'wb');
    fwrite($stream, "id,value\n");
    for ($index = 0; $index < 30000; $index++) {
        fwrite($stream, "$index," . str_repeat('x', 80) . "\n");
    }
    fclose($stream);
    $start = memory_get_usage();
    $count = 0;
    foreach (FileReader::iterate($path) as $row) {
        same((string) $count, $row['id']);
        $count++;
    }
    same(30000, $count);
    check(memory_get_usage() - $start < 1048576, 'CSV iteration retained too much data.');
    $iterator = FileReader::iterate($path);
    foreach ($iterator as $row) {
        same('0', $row['id']);
        break;
    }
    unset($iterator);
    check(unlink($path));
    $invalid = FileReader::iterate(readerFixture("a,b\n1,2\n3\n", 'csv'));
    $invalid->rewind();
    same(['a' => '1', 'b' => '2'], $invalid->current());
    raises(static fn () => $invalid->next(), RuntimeException::class);
    unset($invalid);
});

test('XML readers preserve nested attributes, repeated children, namespaces, empty nodes and direct text', static function (): void {
    $path = readerFixture('<root xmlns="urn:root" xmlns:x="urn:extra" id="1" x:flag="yes">'
        . '<item a="b">one &amp; two</item><item><![CDATA[three]]></item><x:item/><mixed>before<b>bold</b>after</mixed></root>', 'xml');
    $root = FileReader::read($path)['{urn:root}root'];
    same(['id' => '1', '{urn:extra}flag' => 'yes'], $root['@attributes']);
    same('one & two', $root['@children']['{urn:root}item'][0]['@text']);
    same('three', $root['@children']['{urn:root}item'][1]['@text']);
    same(['@attributes' => [], '@text' => '', '@children' => []], $root['@children']['{urn:extra}item'][0]);
    same('beforeafter', $root['@children']['{urn:root}mixed'][0]['@text']);
    same('bold', $root['@children']['{urn:root}mixed'][0]['@children']['{urn:root}b'][0]['@text']);
    same(['r' => ['@attributes' => [], '@text' => '', '@children' => []]], (new XmlReader())->read(readerFixture('<r/>', 'xml')));
});

test('XML rejects malformed input, DTDs, external entities, entity bombs and excessive nesting', static function (): void {
    $secret = readerFixture('private-file-content', 'txt');
    foreach (['', '<r>', '<r/><s/>', '<r>&undefined;</r>', '<!DOCTYPE r [<!ENTITY x "expanded">]><r>&x;</r>',
        '<!DOCTYPE r SYSTEM "http://127.0.0.1:1/never"><r/>',
        '<!DOCTYPE r [<!ENTITY x SYSTEM "file://' . $secret . '">]><r>&x;</r>',
        '<!DOCTYPE r [<!ENTITY a "a"><!ENTITY b "&a;&a;&a;">]><r>&b;</r>',
        "<r>\xff</r>", '<?xml version="1.0" encoding="ISO-8859-1"?><r/>',
        iconv('UTF-8', 'UTF-16LE', '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE r SYSTEM "file://' . $secret . '"><r/>')] as $contents) {
        raises(static fn () => FileReader::read(readerFixture($contents, 'xml')), RuntimeException::class);
    }
    $loader = libxml_get_external_entity_loader();
    $calls = 0;
    libxml_set_external_entity_loader(static function () use (&$calls) {
        $calls++;
        return null;
    });
    try {
        raises(static fn () => FileReader::read(readerFixture(
            '<!DOCTYPE r [<!ENTITY x SYSTEM "file://' . $secret . '">]><r>&x;</r>', 'xml',
        )), RuntimeException::class);
        raises(static fn () => FileReader::read(readerFixture(
            '<!DOCTYPE r SYSTEM "http://127.0.0.1:1/never"><r/>', 'xml',
        )), RuntimeException::class);
        same(0, $calls);
    } finally {
        libxml_set_external_entity_loader($loader);
    }
    raises(static fn () => FileReader::read(readerFixture('<r><c/></r>', 'xml'), ['max_depth' => 1]), RuntimeException::class);
    $before = libxml_use_internal_errors();
    raises(static fn () => FileReader::read(readerFixture('<r>', 'xml')), RuntimeException::class);
    same($before, libxml_use_internal_errors());
    raises(static fn () => FileReader::iterate(readerFixture('<r/>', 'xml')), RuntimeException::class);
});

test('YAML and YML require the native extension and never silently fall back to another parser', static function (): void {
    global $root;
    $path = readerFixture('name: test', 'yaml');
    if (!function_exists('yaml_parse')) {
        foreach (['yaml', 'yml'] as $format) {
            try {
                FileReader::read($path, ['format' => $format]);
                throw new LogicException('Expected missing YAML extension failure.');
            } catch (RuntimeException $error) {
                check(str_contains($error->getMessage(), 'ext-yaml'));
            }
        }
    } else {
        same(['name' => 'test'], FileReader::read($path));
    }
    raises(static fn () => FileReader::iterate($path), RuntimeException::class);
    $source = 'require ' . var_export($root . '/framework/FileReader.php', true) . ';'
        . 'try { \\PBreeze\\FileReader::read(' . var_export($path, true) . '); exit(1); }'
        . 'catch (RuntimeException $error) { echo $error->getMessage(); }';
    $process = proc_open([PHP_BINARY, '-d', 'disable_functions=yaml_parse', '-r', $source], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    check(is_resource($process));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    same(0, proc_close($process));
    same('', $errors);
    check(str_contains($output, 'ext-yaml'));
});

if (function_exists('yaml_parse')) {
    test('Native YAML handles nested collections, aliases, block text and both extensions', static function (): void {
        $contents = "name: example\nactive: true\nitems:\n  - id: 1\n    tags: [one, two]\nbase: &base {value: 2}\ncopy: *base\ntext: |\n  first\n  second\n";
        $expected = ['name' => 'example', 'active' => true, 'items' => [['id' => 1, 'tags' => ['one', 'two']]],
            'base' => ['value' => 2], 'copy' => ['value' => 2], 'text' => "first\nsecond\n"];
        foreach (['yaml', 'yml'] as $extension) {
            same($expected, FileReader::read(readerFixture($contents, $extension)));
        }
        same([], (new YamlReader())->read(readerFixture('{}', 'yaml')));
        same([], FileReader::read(readerFixture('[]', 'yml')));
        same(['name' => 'example'], FileReader::read(readerFixture("\xef\xbb\xbfname: example", 'yaml')));
        same(['base' => ['a' => 1], 'copy' => ['a' => 1, 'b' => 2]], FileReader::read(readerFixture(
            "base: &base {a: 1}\ncopy: {<<: *base, b: 2}\n", 'yml',
        )));
    });

    test('Native YAML rejects invalid documents, scalar roots, cyclic aliases, objects and unsafe configuration', static function (): void {
        foreach (['', 'value', 'null', "x: [unterminated\n", "a: 1\n---\nb: 2\n", 'x: &x [*x]', 'x: .inf', "x: \xff",
            'x: !php/object \'O:8:"stdClass":0:{}\''] as $contents) {
            raises(static fn () => FileReader::read(readerFixture($contents, 'yaml')), RuntimeException::class);
        }
        raises(static fn () => FileReader::read(readerFixture('a: {b: {c: 1}}', 'yaml'), ['max_depth' => 1]), RuntimeException::class);
        $aliases = "a: &a [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]\n";
        $prior = 'a';
        foreach (['b', 'c', 'd', 'e', 'f'] as $anchor) {
            $aliases .= "$anchor: &$anchor [" . implode(', ', array_fill(0, 10, '*' . $prior)) . "]\n";
            $prior = $anchor;
        }
        raises(static fn () => FileReader::read(readerFixture($aliases, 'yaml')), RuntimeException::class);
        $previous = ini_get('yaml.decode_php');
        ini_set('yaml.decode_php', '1');
        try {
            raises(static fn () => FileReader::read(readerFixture('{}', 'yaml')), RuntimeException::class);
        } finally {
            ini_set('yaml.decode_php', $previous);
        }
    });
}
