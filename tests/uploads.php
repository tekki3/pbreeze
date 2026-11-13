<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Documents\Documents;
use PBreeze\HttpException;
use PBreeze\Request;
use PBreeze\UploadedFile;
use PBreeze\Uploads;

test('Upload limits load from the environment and propagate into the application service', static function () use ($root, $temp, $config): void {
    $previous = getenv('UPLOAD_MAX_BYTES');
    try {
        putenv('UPLOAD_MAX_BYTES=1024');
        $loaded = require $root . '/config/app.php';
        same(1024, $loaded['upload_max_bytes']);
        $configured = new Application($temp, array_replace($config, ['upload_max_bytes' => $loaded['upload_max_bytes']]));
        same(1024, $configured->uploads->maxBytes);
        foreach (['0', '-1', '', '2M', '1.5', 'not-a-size', str_repeat('9', 30)] as $invalid) {
            putenv('UPLOAD_MAX_BYTES=' . $invalid);
            raises(static fn () => require $root . '/config/app.php', RuntimeException::class);
        }
    } finally {
        putenv($previous === false ? 'UPLOAD_MAX_BYTES' : 'UPLOAD_MAX_BYTES=' . $previous);
    }
    same(2097152, (new Uploads($temp))->maxBytes);
    raises(static fn () => new Uploads($temp, 0), InvalidArgumentException::class);
});

/** @param array<string, mixed> $replace @return array<string, mixed> */
function uploadMetadata(array $replace = []): array
{
    return array_replace([
        'name' => 'report.txt', 'type' => 'text/plain', 'tmp_name' => '/not-an-upload',
        'error' => UPLOAD_ERR_OK, 'size' => 5,
    ], $replace);
}

test('Request exposes separate single and multiple upload fields and handles absent selections', static function (): void {
    $files = UploadedFile::capture([
        'document' => uploadMetadata(['name' => 'C:\\fakepath\\Café.txt', 'type' => 'client/untrusted']),
        'empty' => uploadMetadata(['error' => UPLOAD_ERR_NO_FILE, 'name' => '']),
        'documents' => [
            'name' => ['first' => 'one.txt', 'second' => 'two.txt'],
            'type' => ['first' => 'text/plain', 'second' => 'text/plain'],
            'tmp_name' => ['first' => '/tmp/one', 'second' => '/tmp/two'],
            'size' => ['first' => 1, 'second' => 2],
            'error' => ['first' => 0, 'second' => 0],
        ],
    ]);
    $request = new Request('POST', '/upload', body: ['name' => 'form'], uploads: $files);
    same('Café.txt', $request->file('document')->originalName);
    same('client/untrusted', $request->file('document')->clientMediaType);
    same(null, $request->file('missing'));
    same(null, $request->file('empty'));
    same([], $request->files('empty'));
    same([], $request->files('missing'));
    same(2, count($request->files('documents')));
    raises(static fn () => $request->file('documents'), HttpException::class, 400);
    same(['name' => 'form'], $request->all());
});

test('Upload metadata rejects malformed shapes, nested fields, unknown errors, and invalid names', static function (): void {
    foreach ([
        ['file' => 'invalid'], ['file' => []],
        ['file' => uploadMetadata(['error' => 99])],
        ['file' => uploadMetadata(['size' => -1])],
        ['file' => uploadMetadata(['error' => '0'])],
        ['file' => uploadMetadata(['name' => ['x.txt']])],
        ['file' => ['name' => [['x.txt']], 'type' => [['text/plain']], 'tmp_name' => [['x']], 'error' => [[0]], 'size' => [[1]]]],
    ] as $files) {
        raises(static fn () => UploadedFile::capture($files), HttpException::class, 400);
    }
    foreach (["bad\0.txt", "bad\r\n.txt", "\xff.txt", '.', '..', str_repeat('a', 256)] as $name) {
        raises(static fn () => UploadedFile::capture(['file' => uploadMetadata(['name' => $name])]), HttpException::class, 422);
    }
});

test('Upload validation requires explicit supported types and real HTTP upload provenance', static function () use ($temp): void {
    $path = $temp . '/not-an-http-upload.txt';
    file_put_contents($path, 'Private server data');
    $file = UploadedFile::capture(['file' => uploadMetadata(['tmp_name' => $path])])['file'][0];
    foreach ([[], ['php'], ['svg'], ['TXT'], ['text/plain'], ['type' => 'txt']] as $allowed) {
        raises(static fn () => $file->validate($allowed), InvalidArgumentException::class);
    }
    raises(static fn () => $file->validate(['txt'], 0), InvalidArgumentException::class);
    raises(static fn () => $file->validate(['txt']), HttpException::class, 422);
    raises(static fn () => (new Uploads($temp))->store($file, ['txt']), HttpException::class, 422);
    check(!is_dir($temp . '/storage/uploads'));
    same('Private server data', file_get_contents($path));
});

test('PHP upload failures produce explicit size, partial-upload, and server-side errors', static function (): void {
    foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $error) {
        $file = UploadedFile::capture(['file' => uploadMetadata(['error' => $error])])['file'][0];
        raises(static fn () => $file->validate(['txt']), HttpException::class, 413);
    }
    $file = UploadedFile::capture(['file' => uploadMetadata(['error' => UPLOAD_ERR_PARTIAL])])['file'][0];
    raises(static fn () => $file->validate(['txt']), HttpException::class, 422);
    foreach ([UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION] as $error) {
        $file = UploadedFile::capture(['file' => uploadMetadata(['error' => $error])])['file'][0];
        try {
            $file->validate(['txt']);
            throw new LogicException('Expected an upload receive error.');
        } catch (RuntimeException $exception) {
            check(!$exception instanceof HttpException);
        }
    }
});

test('Private upload downloads and deletion reject traversal, missing files, and symlinks', static function () use ($temp, $config): void {
    $app = new Application($temp, $config);
    check($app->uploads instanceof Uploads);
    foreach (['../.env', '../../public/index.php', 'php://filter', '', str_repeat('a', 64) . '.php', str_repeat('a', 64) . '.txt'] as $key) {
        raises(static fn () => $app->uploads->download($key), HttpException::class, 404);
        raises(static fn () => $app->uploads->delete($key), HttpException::class, 404);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        mkdir($temp . '/storage/uploads', 0700);
        $id = str_repeat('b', 64);
        $target = $temp . '/storage/uploads/' . $id;
        mkdir($target, 0700);
        symlink($temp . '/not-an-http-upload.txt', $target . '/file.txt');
        raises(static fn () => $app->uploads->download($id . '.txt'), HttpException::class, 404);
        raises(static fn () => $app->uploads->delete($id . '.txt'), HttpException::class, 404);
        unlink($target . '/file.txt');
        rmdir($target);
        rmdir($temp . '/storage/uploads');
        symlink($temp, $temp . '/storage/uploads');
        raises(static fn () => $app->uploads->download($id . '.txt'), RuntimeException::class);
        unlink($temp . '/storage/uploads');
    }
});

/**
 * @param list<array{field: string, name: string, contents: string, type?: string}> $files
 * @param array<string, string> $fields
 * @return array{string, list<string>}
 */
function uploadBody(array $files, array $fields = []): array
{
    $boundary = 'pbreeze-' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($fields as $field => $value) {
        $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"$field\"\r\n\r\n$value\r\n";
    }
    foreach ($files as $file) {
        $name = $file['name'];
        $field = $file['field'];
        $type = $file['type'] ?? 'application/octet-stream';
        $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"$field\"; filename=\"$name\"\r\n"
            . "Content-Type: $type\r\n\r\n" . $file['contents'] . "\r\n";
    }
    $body .= '--' . $boundary . "--\r\n";
    return [$body, ['Content-Type: multipart/form-data; boundary=' . $boundary]];
}

function uploadHttp(string $base): void
{
    global $temp;
    $cookie = '';
    $csrf = '';
    test('HTTP multipart uploads require CSRF and save exact bytes with random names in private storage', static function () use ($base, $temp, &$cookie, &$csrf): void {
        $tokenResponse = http($base, '/__uploads/token');
        foreach ($tokenResponse['headers'] as $header) {
            if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) {
                $cookie = $match[1];
            }
        }
        check($cookie !== '');
        $csrf = json_decode($tokenResponse['body'], true, 32, JSON_THROW_ON_ERROR)['token'];
        [$body, $headers] = uploadBody([['field' => 'document', 'name' => 'Café.txt', 'contents' => 'A private text upload.', 'type' => 'image/png']]);
        same(419, http($base, '/__uploads', 'POST', $body, $headers)['status']);
        same(419, http($base, '/__uploads', 'POST', $body, [...$headers, 'Cookie: ' . $cookie])['status']);
        $response = http($base, '/__uploads', 'POST', $body, [...$headers, 'Cookie: ' . $cookie, 'X-CSRF-Token: ' . $csrf]);
        same(201, $response['status']);
        $file = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR)['files'][0];
        check(preg_match('/^[a-f0-9]{64}\.txt$/D', $file['key']) === 1);
        same('Café.txt', $file['name']);
        same('text/plain', $file['mime']);
        same(22, $file['size']);
        $path = $temp . '/storage/uploads/' . substr($file['key'], 0, 64) . '/file.txt';
        same('A private text upload.', file_get_contents($path));
        if (PHP_OS_FAMILY !== 'Windows') {
            same(0600, fileperms($path) & 0777);
            same(0700, fileperms(dirname($path)) & 0777);
        }
        same(403, http($base, '/__uploads/' . $file['key'])['status']);
        $download = http($base, '/__uploads/' . $file['key'], 'GET', '', ['Cookie: ' . $cookie]);
        same(200, $download['status']);
        same('A private text upload.', $download['body']);
        check(str_contains(implode("\n", $download['headers']), "filename*=UTF-8''Caf%C3%A9.txt"));
        check(str_contains(implode("\n", $download['headers']), 'X-Content-Type-Options: nosniff'));
        same('', http($base, '/__uploads/' . $file['key'], 'HEAD', '', ['Cookie: ' . $cookie])['body']);
        same(404, http($base, '/storage/uploads/' . $file['key'])['status']);
        same(419, http($base, '/__uploads/' . $file['key'], 'DELETE', '', ['Cookie: ' . $cookie])['status']);
        same(204, http($base, '/__uploads/' . $file['key'], 'DELETE', '', ['Cookie: ' . $cookie, 'X-CSRF-Token: ' . $csrf])['status']);
        same(404, http($base, '/__uploads/' . $file['key'], 'GET', '', ['Cookie: ' . $cookie])['status']);
        check(!file_exists($path));
    });

    test('HTTP uploads validate actual size, empty files, extension, MIME, and missing selections', static function () use ($base, &$cookie, &$csrf): void {
        $auth = ['Cookie: ' . $cookie, 'X-CSRF-Token: ' . $csrf];
        foreach ([
            ['small.txt', str_repeat('a', 17), '?small=1', 413],
            ['configured.txt', str_repeat('a', 17), '?configured=1', 413],
            ['empty.txt', '', '', 422],
            ['payload.php', '<?php echo "payload";', '', 422],
            ['payload.txt', '<?php echo "payload";', '', 422],
            ['fake.png', 'not an image', '?kind=image', 422],
            ['broken.gif', 'GIF89a', '?kind=image', 422],
            ['text.pdf', 'This is not a PDF.', '?kind=pdf', 422],
        ] as [$name, $contents, $query, $status]) {
            [$body, $headers] = uploadBody([['field' => 'document', 'name' => $name, 'contents' => $contents]]);
            same($status, http($base, '/__uploads' . $query, 'POST', $body, [...$headers, ...$auth])['status']);
        }
        [$body, $headers] = uploadBody([['field' => 'document', 'name' => 'configured.txt', 'contents' => str_repeat('a', 16)]]);
        same(201, http($base, '/__uploads?configured=1', 'POST', $body, [...$headers, ...$auth])['status']);
        [$body, $headers] = uploadBody([], ['_token' => $csrf]);
        same(422, http($base, '/__uploads', 'POST', $body, [...$headers, 'Cookie: ' . $cookie])['status']);
        [$body, $headers] = uploadBody([['field' => 'document', 'name' => '', 'contents' => '']]);
        same(422, http($base, '/__uploads', 'POST', $body, [...$headers, ...$auth])['status']);
        [$body, $headers] = uploadBody([['field' => 'document', 'name' => 'large.txt', 'contents' => str_repeat('a', 2097153)]]);
        same(413, http($base, '/__uploads', 'POST', $body, [...$headers, ...$auth])['status']);
    });

    test('HTTP uploads accept the exact 2 MiB limit, enforce PHP limits, and support multiple fields', static function () use ($base, &$cookie, &$csrf): void {
        $auth = ['Cookie: ' . $cookie, 'X-CSRF-Token: ' . $csrf];
        [$body, $headers] = uploadBody([['field' => 'document', 'name' => 'exact.txt', 'contents' => str_repeat('a', 2097152)]]);
        $response = http($base, '/__uploads', 'POST', $body, [...$headers, ...$auth]);
        same(201, $response['status']);
        same(2097152, json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR)['files'][0]['size']);
        [$body, $headers] = uploadBody([['field' => 'document', 'name' => 'php-limit.txt', 'contents' => str_repeat('a', 3 * 1048576 + 1)]]);
        same(413, http($base, '/__uploads', 'POST', $body, [...$headers, ...$auth])['status']);
        same(413, http($base, '/__uploads', 'POST', str_repeat('a', 4 * 1048576 + 1),
            ['Content-Type: application/octet-stream', ...$auth])['status']);
        [$body, $headers] = uploadBody([
            ['field' => 'documents[]', 'name' => 'one.txt', 'contents' => 'First upload.'],
            ['field' => 'documents[]', 'name' => 'two.csv', 'contents' => "name,total\nAda,12.5\n"],
            ['field' => 'documents[]', 'name' => 'one.txt', 'contents' => 'Same name, different content.'],
        ], ['_token' => $csrf]);
        $response = http($base, '/__uploads?multiple=1', 'POST', $body, [...$headers, 'Cookie: ' . $cookie]);
        same(201, $response['status']);
        $files = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR)['files'];
        same(3, count($files));
        check($files[0]['key'] !== $files[1]['key']);
        check($files[0]['key'] !== $files[2]['key']);
        same('text/csv', $files[1]['mime']);
        same('First upload.', http($base, '/__uploads/' . $files[0]['key'], 'GET', '', ['Cookie: ' . $cookie])['body']);
        same('Same name, different content.', http($base, '/__uploads/' . $files[2]['key'], 'GET', '', ['Cookie: ' . $cookie])['body']);
        [$body, $headers] = uploadBody([
            ['field' => 'document[]', 'name' => 'one.txt', 'contents' => 'First upload.'],
            ['field' => 'document[]', 'name' => 'two.txt', 'contents' => 'Second upload.'],
        ]);
        same(400, http($base, '/__uploads', 'POST', $body, [...$headers, ...$auth])['status']);
        [$body, $headers] = uploadBody([['field' => 'document[nested][]', 'name' => 'one.txt', 'contents' => 'Nested.']]);
        same(400, http($base, '/__uploads', 'POST', $body, [...$headers, ...$auth])['status']);
    });

    test('HTTP uploads accept native images and generated PDF/Office files but reject disguised packages', static function () use ($base, &$cookie, &$csrf): void {
        $auth = ['Cookie: ' . $cookie, 'X-CSRF-Token: ' . $csrf];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true);
        foreach ([
            ['pixel.PNG', $png, '?kind=image'],
            ['report.pdf', Documents::pdf()->paragraph('Native upload test.')->build()->contents, '?kind=pdf'],
            ['report.docx', Documents::word()->paragraph('Native upload test.')->build()->contents, '?kind=office'],
            ['report.xlsx', Documents::excel()->sheet('Data', [['Native upload test.']])->build()->contents, '?kind=office'],
        ] as [$name, $contents, $query]) {
            [$body, $headers] = uploadBody([['field' => 'document', 'name' => $name, 'contents' => $contents]]);
            same(201, http($base, '/__uploads' . $query, 'POST', $body, [...$headers, ...$auth])['status']);
        }
        [$body, $headers] = uploadBody([[
            'field' => 'document', 'name' => 'disguised.xlsx',
            'contents' => Documents::word()->paragraph('Wrong package type.')->build()->contents,
        ]]);
        same(422, http($base, '/__uploads?kind=office', 'POST', $body, [...$headers, ...$auth])['status']);
        [$body, $headers] = uploadBody([['field' => 'document', 'name' => 'pixel.svg', 'contents' => '<svg xmlns="http://www.w3.org/2000/svg"/>']]);
        same(422, http($base, '/__uploads?kind=image', 'POST', $body, [...$headers, ...$auth])['status']);
        [$body, $headers] = uploadBody([['field' => 'document', 'name' => 'report.txt', 'contents' => 'Not API JSON.']]);
        same(415, http($base, '/api/me', 'POST', $body, $headers)['status']);
    });

    test('HTTP Office uploads reject macro entries, path traversal, oversized expansion, and excessive entries', static function () use ($base, &$cookie, &$csrf): void {
        $parts = [
            '[Content_Types].xml' => '<Types/>', '_rels/.rels' => '<Relationships/>',
            'word/document.xml' => '<document/>',
        ];
        $many = [];
        for ($index = 0; $index < 1000; $index++) {
            $many['extra/' . $index . '.txt'] = 'x';
        }
        foreach ([
            ['word/vbaProject.bin' => 'macro'],
            ['../outside.txt' => 'traversal'],
            ['word/huge.xml' => str_repeat('x', 10485761)],
            $many,
        ] as $extra) {
            $contents = Documents::package($parts + $extra, 'application/zip', 'docx')->contents;
            [$body, $headers] = uploadBody([['field' => 'document', 'name' => 'unsafe.docx', 'contents' => $contents]]);
            same(422, http($base, '/__uploads?kind=office', 'POST', $body,
                [...$headers, 'Cookie: ' . $cookie, 'X-CSRF-Token: ' . $csrf])['status']);
        }
    });
}
