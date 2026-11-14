<?php
declare(strict_types=1);

use PBreeze\Storage;

/** @return array<string, mixed> */
function storageSettings(string $driver, array $overrides = []): array
{
    return array_replace_recursive([
        'driver' => $driver, 'max_bytes' => 1024, 'timeout' => 3,
        's3' => ['endpoint' => 'https://s3.example.test', 'bucket' => 'test-bucket', 'region' => 'us-east-1',
            'access_key' => 'test-access', 'secret_key' => 'test-secret', 'session_token' => 'test-token'],
        'azure' => ['account' => 'testaccount', 'container' => 'documents', 'key' => base64_encode(str_repeat('k', 64))],
        'sftp' => ['host' => 'sftp.example.test', 'username' => 'test', 'password' => 'test',
            'root' => '/remote', 'fingerprint' => str_repeat('a', 40)],
    ], $overrides);
}

test('Local storage supports binary and empty content, overwrite, missing keys and lazy private directories', static function (): void {
    global $temp, $config;
    $root = $temp . '/storage-contract';
    mkdir($root, 0700);
    $app = new \PBreeze\Application($root, $config);
    check(!is_dir($root . '/storage'));
    same(false, $app->storage->exists('documents/test.pdf'));
    same(false, $app->storage->delete('documents/test.pdf'));
    raises(static fn () => $app->storage->get('documents/test.pdf'), RuntimeException::class);
    check(!is_dir($root . '/storage'));
    $content = "%PDF-\0\xff\r\n";
    $app->storage->put('documents/test.pdf', $content);
    same($content, $app->storage->get('documents/test.pdf'));
    same(true, $app->storage->exists('documents/test.pdf'));
    $app->storage->put('documents/test.pdf', '');
    same('', $app->storage->get('documents/test.pdf'));
    same(true, $app->storage->exists('documents/test.pdf'));
    $app->storage->put('documents/Café 日本語.json', '{"nested":{"name":"Ada"}}');
    same(['nested' => ['name' => 'Ada']], json_decode($app->storage->get('documents/Café 日本語.json'), true, 32, JSON_THROW_ON_ERROR));
    if (PHP_OS_FAMILY !== 'Windows') {
        same(0600, fileperms($root . '/storage/files/documents/test.pdf') & 0777);
        same(0700, fileperms($root . '/storage/files/documents') & 0777);
    }
    same(true, $app->storage->delete('documents/test.pdf'));
    same(false, $app->storage->delete('documents/test.pdf'));
    same(false, $app->storage->exists('documents/test.pdf'));
    same([], glob($root . '/storage/files/documents/.write-*'));
});

test('Storage validates portable keys and byte bounds consistently across drivers before I/O', static function (): void {
    global $temp;
    foreach (['local', 'sftp', 's3', 'azure'] as $driver) {
        $calls = 0;
        $storage = new Storage($temp, storageSettings($driver), transport: static function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => ''];
        });
        foreach (['', '/absolute', 'a//b', '../secret', 'a/../b', './a', 'a/', 'a\\b', 'a:stream',
            '.hidden', 'a/.hidden', 'a%2fb', 'a#fragment', "bad\0name", "\xff", 'a?', 'a*', 'a.', 'a ',
            'NUL.txt', 'CON', 'a/LPT1.csv', str_repeat('a', 256), str_repeat('a/', 512) . 'b'] as $key) {
            raises(static fn () => $storage->put($key, ''), InvalidArgumentException::class);
            raises(static fn () => $storage->get($key), InvalidArgumentException::class);
            raises(static fn () => $storage->exists($key), InvalidArgumentException::class);
            raises(static fn () => $storage->delete($key), InvalidArgumentException::class);
        }
        raises(static fn () => $storage->put('large.bin', str_repeat('x', 1025)), InvalidArgumentException::class);
        same(0, $calls);
    }
    $root = $temp . '/storage-bounds';
    mkdir($root, 0700);
    $storage = new Storage($root, ['max_bytes' => 4]);
    $storage->put('exact.bin', '1234');
    same('1234', $storage->get('exact.bin'));
    file_put_contents($root . '/storage/files/exact.bin', '12345');
    raises(static fn () => $storage->get('exact.bin'), RuntimeException::class);
    foreach ([['driver' => 'unknown'], ['max_bytes' => 0], ['max_bytes' => 268435457], ['timeout' => 0], ['timeout' => 301]] as $bad) {
        raises(static fn () => new Storage($root, $bad), InvalidArgumentException::class);
    }
});

test('Local storage refuses public roots, incompatible paths and symlink escapes', static function (): void {
    global $temp;
    $root = $temp . '/storage-confinement';
    mkdir($root, 0700);
    mkdir($root . '/public', 0700);
    $storage = new Storage($root);
    $storage->put('documents/one.txt', 'one');
    $public = new Storage($root, storagePath: 'public');
    raises(static fn () => $public->put('secret.txt', 'secret'), RuntimeException::class);
    $badRoot = new Storage($root, storagePath: '../outside');
    raises(static fn () => $badRoot->exists('secret.txt'), InvalidArgumentException::class);
    mkdir($root . '/storage/files/directory', 0700);
    raises(static fn () => $storage->exists('directory'), RuntimeException::class);
    raises(static fn () => $storage->put('documents/one.txt/child', ''), RuntimeException::class);
    if (PHP_OS_FAMILY !== 'Windows') {
        file_put_contents($root . '/outside.txt', 'keep');
        symlink($root . '/outside.txt', $root . '/storage/files/linked.txt');
        symlink($root, $root . '/storage/files/escape');
        try {
            foreach (['linked.txt', 'escape/outside.txt'] as $key) {
                raises(static fn () => $storage->get($key), RuntimeException::class);
                raises(static fn () => $storage->exists($key), RuntimeException::class);
                raises(static fn () => $storage->put($key, 'changed'), RuntimeException::class);
                raises(static fn () => $storage->delete($key), RuntimeException::class);
            }
            same('keep', file_get_contents($root . '/outside.txt'));
        } finally {
            unlink($root . '/storage/files/linked.txt');
            unlink($root . '/storage/files/escape');
        }
    }
});

test('S3 and Azure drivers share the storage contract and sign each operation including empty content', static function (): void {
    global $temp;
    foreach (['s3', 'azure'] as $driver) {
        $objects = [];
        $methods = [];
        $config = storageSettings($driver);
        $storage = new Storage($temp, $config, transport: static function ($method, $url, $headers, $content, $limit, $timeout) use (&$objects, &$methods, $driver, $config): array {
            same(1024, $limit);
            same(3, $timeout);
            $path = parse_url($url, PHP_URL_PATH);
            $methods[] = $method;
            check(str_contains($path, '/Caf%C3%A9%20%E6%97%A5%E6%9C%AC%E8%AA%9E.bin'));
            if ($driver === 's3') {
                same(hash('sha256', $content), $headers['x-amz-content-sha256']);
                same('test-token', $headers['x-amz-security-token']);
                $date = $headers['x-amz-date'];
                $scope = substr($date, 0, 8) . '/us-east-1/s3/aws4_request';
                $canonical = implode("\n", [$method, $path, '',
                    'host:s3.example.test' . "\n" . 'x-amz-content-sha256:' . hash('sha256', $content) . "\n"
                        . 'x-amz-date:' . $date . "\n" . 'x-amz-security-token:test-token' . "\n",
                    'host;x-amz-content-sha256;x-amz-date;x-amz-security-token', hash('sha256', $content)]);
                $key = hash_hmac('sha256', substr($date, 0, 8), 'AWS4test-secret', true);
                foreach (['us-east-1', 's3', 'aws4_request'] as $part) {
                    $key = hash_hmac('sha256', $part, $key, true);
                }
                $signature = hash_hmac('sha256', "AWS4-HMAC-SHA256\n$date\n$scope\n" . hash('sha256', $canonical), $key);
                same('AWS4-HMAC-SHA256 Credential=test-access/' . $scope
                    . ', SignedHeaders=host;x-amz-content-sha256;x-amz-date;x-amz-security-token, Signature=' . $signature, $headers['Authorization']);
            } else {
                $canonical = $method . "\n\n\n" . ($content !== '' && $method === 'PUT' ? strlen($content) : '')
                    . "\n\n" . ($method === 'PUT' ? 'application/octet-stream' : '') . "\n\n\n\n\n\n\n";
                if ($method === 'PUT') {
                    same('BlockBlob', $headers['x-ms-blob-type']);
                    $canonical .= "x-ms-blob-type:BlockBlob\n";
                }
                $canonical .= 'x-ms-date:' . $headers['x-ms-date'] . "\n";
                if ($method === 'DELETE') {
                    same('include', $headers['x-ms-delete-snapshots']);
                    $canonical .= "x-ms-delete-snapshots:include\n";
                }
                $canonical .= "x-ms-version:2023-11-03\n/testaccount" . $path;
                same('SharedKey testaccount:' . base64_encode(hash_hmac('sha256', $canonical, str_repeat('k', 64), true)), $headers['Authorization']);
            }
            if ($method === 'PUT') {
                same((string) strlen($content), $headers['Content-Length']);
                same('application/octet-stream', $headers['Content-Type']);
                $objects[$url] = $content;
                return ['status' => $driver === 's3' ? 200 : 201, 'body' => ''];
            }
            if (!array_key_exists($url, $objects)) {
                return ['status' => 404, 'body' => 'not found'];
            }
            if ($method === 'DELETE') {
                unset($objects[$url]);
                return ['status' => $driver === 's3' ? 204 : 202, 'body' => ''];
            }
            return ['status' => 200, 'body' => $method === 'HEAD' ? '' : $objects[$url]];
        });
        $key = 'documents/Café 日本語.bin';
        same(false, $storage->exists($key));
        same(false, $storage->delete($key));
        raises(static fn () => $storage->get($key), RuntimeException::class);
        $storage->put($key, "\0\xff\r\n");
        same("\0\xff\r\n", $storage->get($key));
        same(true, $storage->exists($key));
        $storage->put($key, '');
        same('', $storage->get($key));
        same(true, $storage->delete($key));
        same(false, $storage->delete($key));
        check(in_array('HEAD', $methods, true) && in_array('DELETE', $methods, true));
    }
});

test('Remote storage rejects bad configuration, non-404 failures and oversized responses without leaking provider details', static function (): void {
    global $temp;
    foreach (['s3', 'azure'] as $driver) {
        foreach (['http://example.test', 'https://user:pass@example.test', 'https://example.test/path', 'https://example.test?secret=1'] as $endpoint) {
            raises(static fn () => new Storage($temp, storageSettings($driver, [$driver => ['endpoint' => $endpoint]])), InvalidArgumentException::class);
        }
        foreach ([301, 403, 409, 429, 500] as $status) {
            $storage = new Storage($temp, storageSettings($driver), transport: static fn () => ['status' => $status, 'body' => 'provider-secret']);
            foreach (['get', 'exists', 'delete'] as $method) {
                try {
                    $storage->$method('a.bin');
                    throw new LogicException('Expected remote failure.');
                } catch (RuntimeException $error) {
                    check(str_contains($error->getMessage(), 'HTTP ' . $status));
                    check(!str_contains($error->getMessage(), 'provider-secret'));
                }
            }
            raises(static fn () => $storage->put('a.bin', ''), RuntimeException::class);
        }
        $large = new Storage($temp, storageSettings($driver), transport: static fn () => ['status' => 200, 'body' => str_repeat('a', 1025)]);
        raises(static fn () => $large->get('a.bin'), RuntimeException::class);
    }
    raises(static fn () => new Storage($temp, storageSettings('s3', ['s3' => ['secret_key' => '']])), InvalidArgumentException::class);
    raises(static fn () => new Storage($temp, storageSettings('azure', ['azure' => ['key' => 'invalid']])), InvalidArgumentException::class);
    raises(static fn () => new Storage($temp, storageSettings('sftp', ['sftp' => ['fingerprint' => '']])), InvalidArgumentException::class);
    raises(static fn () => new Storage($temp, storageSettings('sftp', ['sftp' => ['root' => '/']])), InvalidArgumentException::class);
    foreach (['s3', 'azure', 'sftp'] as $driver) {
        raises(static fn () => new Storage($temp, ['driver' => $driver, $driver => 'invalid']), InvalidArgumentException::class);
    }
    raises(static fn () => new Storage($temp, storageSettings('s3', ['s3' => ['bucket' => []]])), InvalidArgumentException::class);
    raises(static fn () => new Storage($temp, storageSettings('azure', ['azure' => ['key' => []]])), InvalidArgumentException::class);
    raises(static fn () => new Storage($temp, storageSettings('sftp', ['sftp' => ['root' => []]])), InvalidArgumentException::class);
    if (!extension_loaded('ssh2')) {
        $sftp = new Storage($temp, storageSettings('sftp'));
        try {
            $sftp->exists('a.bin');
            throw new LogicException('Expected native extension requirement.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'native ssh2 extension'));
        }
    }
});

test('Object storage uses native verified HTTPS for signed PUT, GET, HEAD and DELETE', static function (): void {
    global $temp;
    foreach (['s3', 'azure'] as $driver) {
        foreach (['PUT', 'GET', 'HEAD', 'DELETE'] as $method) {
            $status = $method === 'PUT' ? ($driver === 's3' ? 200 : 201)
                : ($method === 'DELETE' ? ($driver === 's3' ? 204 : 202) : 200);
            $transcript = aiHttpFixture(['status' => $status, 'body_base64' => base64_encode($method === 'GET' ? "binary\0\xff" : '')], static function ($url, $certificate) use ($temp, $driver, $method): void {
                $endpoint = substr($url, 0, strpos($url, '/v1/test'));
                $config = storageSettings($driver, ['ca_file' => $certificate, $driver => ['endpoint' => $endpoint]]);
                $client = new \PBreeze\Storage\HttpClient(3, 1024, $certificate);
                $transport = $method === 'DELETE' && $driver === 's3'
                    ? static fn ($verb, $target, $headers, $body): array => $verb === 'HEAD'
                        ? ['status' => 200, 'body' => ''] : $client->request($verb, $target, $headers, $body)
                    : null;
                $storage = new Storage($temp, $config, transport: $transport);
                match ($method) {
                    'PUT' => $storage->put('documents/report.bin', "payload\0"),
                    'GET' => same("binary\0\xff", $storage->get('documents/report.bin')),
                    'HEAD' => same(true, $storage->exists('documents/report.bin')),
                    'DELETE' => same(true, $storage->delete('documents/report.bin')),
                };
            });
            check(str_contains($transcript['headers'], $method . ' /'));
            check(str_contains($transcript['headers'], 'Authorization:'));
            same($method === 'PUT' ? "payload\0" : '', $transcript['body']);
        }
    }
});

test('Storage native HTTPS refuses untrusted TLS, redirects, oversized and incomplete transfers', static function (): void {
    foreach ([['untrusted' => true], ['status' => 302, 'location' => 'https://127.0.0.1:1/secret'],
        ['body' => str_repeat('x', 1025)], ['truncate' => true]] as $options) {
        aiHttpFixture($options, static function ($url, $certificate) use ($options): void {
            $client = new \PBreeze\Storage\HttpClient(3, 1024, isset($options['untrusted']) ? '' : $certificate);
            if (isset($options['location'])) {
                same(302, $client->request('GET', $url, [])['status']);
            } else {
                raises(static fn () => $client->request('GET', $url, []), RuntimeException::class);
            }
            $client = new \PBreeze\Storage\HttpClient(3, 1024, transport: static fn () => ['status' => 200, 'body' => '']);
            raises(static fn () => $client->request('GET', 'http://example.test', []), InvalidArgumentException::class);
            raises(static fn () => $client->request('GET', 'https://example.test', ['X-Test' => "bad\r\nheader"]), InvalidArgumentException::class);
            raises(static fn () => $client->request('PUT', 'https://example.test', [], str_repeat('x', 1025)), InvalidArgumentException::class);
        });
    }
});

test('Storage environment config propagates into lazy application services without touching real storage', static function (): void {
    global $root, $temp, $config;
    $previous = getenv('STORAGE_DRIVER');
    $bytes = getenv('STORAGE_MAX_BYTES');
    try {
        putenv('STORAGE_DRIVER=local');
        putenv('STORAGE_MAX_BYTES=8');
        $loaded = require $root . '/config/storage.php';
        same('local', $loaded['driver']);
        same(8, $loaded['max_bytes']);
        $directory = $temp . '/storage-config';
        mkdir($directory, 0700);
        $app = new \PBreeze\Application($directory, array_replace($config, ['storage' => $loaded]));
        check(!is_dir($directory . '/storage'));
        raises(static fn () => $app->storage->put('file.bin', '123456789'), InvalidArgumentException::class);
        console($app, ['storage:prepare']);
        check(is_dir($directory . '/storage/files'));
        foreach (['0', '-1', '1.5', '268435457', 'bad'] as $invalid) {
            putenv('STORAGE_MAX_BYTES=' . $invalid);
            raises(static fn () => require $root . '/config/storage.php', RuntimeException::class);
        }
    } finally {
        putenv($previous === false ? 'STORAGE_DRIVER' : 'STORAGE_DRIVER=' . $previous);
        putenv($bytes === false ? 'STORAGE_MAX_BYTES' : 'STORAGE_MAX_BYTES=' . $bytes);
    }
});

require __DIR__ . '/storage-sftp.php';
