<?php
declare(strict_types=1);

use PBreeze\Data;

final class DataTestRules
{
    public static function rules(): array
    {
        return ['name' => 'required|string', 'email' => 'required|email'];
    }
}

final class DataTestDto
{
    private function __construct(public readonly string $name, public readonly string $email)
    {
    }

    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['email']);
    }
}

final class DataTestInvalidRules
{
    public static function rules(): string { return 'invalid'; }
}

final class DataTestMapper
{
    public static function fromArray(array $data): array
    {
        return ['customers' => $data['users']];
    }
}

test('Data immutable entry filter/map preserve associative keys and normalize filtered lists', static function (): void {
    $data = Data::from([['name' => 'Ada', 'active' => true], ['name' => 'Grace', 'active' => false], ['name' => 'Linus', 'active' => true]]);
    $keys = [];
    $result = $data->filter(static fn ($row): bool => $row['active'])
        ->map(static function ($row, $key) use (&$keys): string {
            $keys[] = $key;
            return strtoupper($row['name']);
        });
    same(['ADA', 'LINUS'], $result->toArray());
    same([0, 1], $keys);
    same(3, count($data->toArray()));
    same(['b' => 4], Data::from(['a' => 1, 'b' => 2])->filter(static fn ($value, $key): bool => $key === 'b')->map(static fn ($value): int => $value * 2)->toArray());
    same([], Data::from([])->map(static fn ($value) => $value)->toArray());
    raises(static fn () => $data->filter(static fn () => 1), InvalidArgumentException::class);
    raises(static fn () => $data->transform(static fn () => 'not an array'), InvalidArgumentException::class);
    $error = new LogicException('Mapper failure');
    try {
        $data->map(static function () use ($error): never { throw $error; });
        throw new RuntimeException('Expected mapper failure.');
    } catch (LogicException $caught) {
        check($caught === $error);
    }
    same(['customers' => [['name' => 'Ada']]],
        Data::from(['users' => [['name' => 'Ada']]])->transform(DataTestMapper::class)->toArray());
});

test('Data validation uses whole-array rules, safe validated output and explicit DTO factories', static function (): void {
    $request = new \PBreeze\Request('POST', '/users', body: ['name' => 'Ada', 'email' => 'ada@example.com', 'admin' => true],
        headers: ['Content-Type' => 'application/json']);
    $input = Data::from($request->json());
    $validated = $input->validate(DataTestRules::class);
    same(['name' => 'Ada', 'email' => 'ada@example.com'], $validated->toArray());
    same(true, $input->toArray()['admin']);
    $dtos = Data::from([$validated->toArray()])->map(DataTestDto::class)->toArray();
    check($dtos[0] instanceof DataTestDto);
    same('Ada', $dtos[0]->name);
    $rows = Data::from([['email' => 'a@example.com', 'admin' => true], ['email' => 'b@example.com']])
        ->validate(['*.email' => 'required|email'])->toArray();
    same([['email' => 'a@example.com'], ['email' => 'b@example.com']], $rows);
    try {
        Data::from([['email' => 'good@example.com'], ['email' => 'bad']])->validate(['*.email' => 'required|email']);
        throw new LogicException('Expected validation failure.');
    } catch (\PBreeze\ValidationException $error) {
        check($error->errors->has('1.email'));
    }
    try {
        Data::from([])->validate(['name' => 'required'], ['name.required' => 'Name is needed.']);
        throw new LogicException('Expected validation failure.');
    } catch (\PBreeze\ValidationException $error) {
        same('Name is needed.', $error->errors->first());
    }
    raises(static fn () => $input->validate('NotLoadedRules'), InvalidArgumentException::class);
    raises(static fn () => $input->validate(DataTestDto::class), InvalidArgumentException::class);
    raises(static fn () => $input->validate(DataTestInvalidRules::class), InvalidArgumentException::class);
    raises(static fn () => $input->map(DataTestDto::class), InvalidArgumentException::class);
    raises(static fn () => $input->map('NotLoadedDto'), InvalidArgumentException::class);
    raises(static fn () => $input->map(DataTestRules::class), InvalidArgumentException::class);
});

test('Data reads CSV then filters, normalizes, validates and writes JSON without mutating the source', static function (): void {
    global $temp;
    $directory = $temp . '/data-csv';
    mkdir($directory, 0700);
    $source = $directory . '/customers.csv';
    file_put_contents($source, "\xef\xbb\xbfname,email,active\r\nAda,ada@example.com,1\r\nGrace,grace@example.com,0\r\n");
    $input = Data::read($source);
    $result = $input->filter(static fn ($row): bool => $row['active'] === '1')
        ->map(static fn ($row): array => ['name' => strtoupper($row['name']), 'email' => $row['email']])
        ->validate(['*.name' => 'required|string', '*.email' => 'required|email']);
    same([['name' => 'ADA', 'email' => 'ada@example.com']], $result->toArray());
    same($result, $result->write($directory . '/output.json'));
    same($result->toArray(), Data::read($directory . '/output.json')->toArray());
    same(2, count($input->toArray()));
    raises(static fn () => $result->write($directory . '/output.json'), RuntimeException::class);
    raises(static fn () => Data::read($directory . '/missing.json'), RuntimeException::class);
    file_put_contents($directory . '/invalid.json', '{broken}');
    raises(static fn () => Data::read($directory . '/invalid.json'), RuntimeException::class);
    file_put_contents($directory . '/input.data', '{"name":"Ada"}');
    same(['name' => 'Ada'], Data::read($directory . '/input.data', ['format' => 'json'])->toArray());
});

test('Data whole-document XML mapper can flatten secure reader nodes and write JSON', static function (): void {
    global $temp;
    $directory = $temp . '/data-xml';
    mkdir($directory, 0700);
    file_put_contents($directory . '/input.xml', '<customers><customer id="1"><name>Ada</name></customer><customer id="2"><name>Grace</name></customer></customers>');
    $mapper = static fn (array $document): array => array_map(
        static fn ($node): array => ['id' => $node['@attributes']['id'], 'name' => $node['@children']['name'][0]['@text']],
        $document['customers']['@children']['customer'],
    );
    Data::read($directory . '/input.xml')->transform($mapper)->write($directory . '/output.json');
    same([['id' => '1', 'name' => 'Ada'], ['id' => '2', 'name' => 'Grace']], Data::read($directory . '/output.json')->toArray());
    Data::from(['name' => '<Ada&>', 'active' => true])->write($directory . '/output.xml', ['root' => 'customer']);
    $xml = Data::read($directory . '/output.xml')->toArray();
    same('<Ada&>', $xml['customer']['@children']['name'][0]['@text']);
    file_put_contents($directory . '/unsafe.xml', '<!DOCTYPE x [<!ENTITY external SYSTEM "file:///etc/passwd">]><x>&external;</x>');
    raises(static fn () => Data::read($directory . '/unsafe.xml'), RuntimeException::class);
});

test('Data CSV export enforces columns and retains formula protection and explicit positional rows', static function (): void {
    global $temp;
    $directory = $temp . '/data-write-csv';
    mkdir($directory, 0700);
    Data::from([['name' => '=danger', 'age' => 30], ['name' => 'Ada', 'age' => 31]])
        ->write($directory . '/output.csv', ['delimiter' => ';']);
    same([['name' => "'=danger", 'age' => '30'], ['name' => 'Ada', 'age' => '31']],
        Data::read($directory . '/output.csv', ['delimiter' => ';'])->toArray());
    Data::from([['Ada', 30]])->write($directory . '/positional.csv', ['headers' => false]);
    same([['Ada', '30']], Data::read($directory . '/positional.csv', ['headers' => false])->toArray());
    raises(static fn () => Data::from([['Ada', 30]])->write($directory . '/bad.csv'), InvalidArgumentException::class);
    raises(static fn () => Data::from([['name' => 'Ada'], ['other' => 'Grace']])->write($directory . '/bad.csv'), InvalidArgumentException::class);
    raises(static fn () => Data::from([['name' => ['nested']]])->write($directory . '/bad.csv'), InvalidArgumentException::class);
    check(!file_exists($directory . '/bad.csv'));
});

test('Data writers reject invalid formats, options, cycles, DTOs and output beyond byte limits before publication', static function (): void {
    global $temp;
    $path = $temp . '/data-output.json';
    $data = Data::from(['name' => 'Ada']);
    raises(static fn () => $data->write($temp . '/output.pdf'), InvalidArgumentException::class);
    raises(static fn () => $data->write($path, ['unknown' => true]), InvalidArgumentException::class);
    raises(static fn () => $data->write($path, ['max_bytes' => 0]), InvalidArgumentException::class);
    raises(static fn () => $data->write($path, ['max_bytes' => 1]), RuntimeException::class);
    check(!file_exists($path));
    $encoded = \PBreeze\Documents\Documents::json($data->toArray())->contents;
    raises(static fn () => $data->write($path, ['max_bytes' => strlen($encoded) - 1]), RuntimeException::class);
    check(!file_exists($path));
    $data->write($path, ['max_bytes' => strlen($encoded)]);
    same($encoded, file_get_contents($path));
    unlink($path);
    $cycle = [];
    $cycle['self'] = &$cycle;
    raises(static fn () => Data::from($cycle)->write($path), InvalidArgumentException::class);
    raises(static fn () => Data::from(['x' => INF])->write($path), InvalidArgumentException::class);
    raises(static fn () => Data::from(['x' => "\xff"])->write($path), InvalidArgumentException::class);
    raises(static fn () => Data::from([['name' => 'Ada', 'email' => 'ada@example.com']])->map(DataTestDto::class)->write($path), InvalidArgumentException::class);
    $data->write($temp . '/data-output.bytes', ['format' => 'json']);
    same($data->toArray(), Data::read($temp . '/data-output.bytes', ['format' => 'json'])->toArray());
});

test('Data YAML/YML output uses native extension or fails explicitly without publishing a file', static function (): void {
    global $temp;
    $data = Data::from(['customer' => ['name' => 'Café', 'active' => true], 'values' => [1, null, 2.5]]);
    foreach (['yaml', 'yml'] as $format) {
        $path = $temp . '/data-output.' . $format;
        if (extension_loaded('yaml')) {
            $data->write($path);
            same($data->toArray(), Data::read($path)->toArray());
            Data::read($path)->transform(static fn ($document): array => $document['customer'])
                ->validate(['name' => 'required|string', 'active' => 'required|boolean'])
                ->write($temp . '/data-yaml-converted-' . $format . '.json');
            same(['name' => 'Café', 'active' => true],
                Data::read($temp . '/data-yaml-converted-' . $format . '.json')->toArray());
        } else {
            raises(static fn () => $data->write($path), RuntimeException::class);
            check(!file_exists($path));
        }
    }
});

test('Data loads independently and does not discover mapper classes or read application configuration', static function (): void {
    global $root;
    $source = 'require ' . var_export($root . '/framework/Data.php', true) . ';'
        . 'spl_autoload_register(static function () { throw new LogicException("No discovery"); });'
        . '$data = \\PBreeze\\Data::from([1,2,3])->filter(static fn($value):bool=>$value>1)->map(static fn($value):int=>$value*2);'
        . 'echo json_encode($data->toArray());'
        . 'try { $data->validate("MissingRules"); exit(2); } catch(InvalidArgumentException) {}'
        . 'try { $data->map("MissingDto"); exit(3); } catch(InvalidArgumentException) {}';
    $process = proc_open([PHP_BINARY, '-r', $source], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    same(0, proc_close($process));
    same('', $errors);
    same('[4,6]', $output);
});
