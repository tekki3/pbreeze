<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use PBreeze\Cache;

$driver = getenv('CACHE_TEST_DRIVER') ?: '';
if (!in_array($driver, ['redis', 'memcached'], true) || !extension_loaded($driver)) {
    throw new RuntimeException('Set CACHE_TEST_DRIVER=redis or memcached and install the matching native extension.');
}
$settings = [
    'driver' => $driver, 'prefix' => 'pbreeze-test-' . bin2hex(random_bytes(12)) . ':',
    $driver => [
        'host' => getenv('CACHE_TEST_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('CACHE_TEST_PORT') ?: ($driver === 'redis' ? 6379 : 11211)),
        'username' => getenv('CACHE_TEST_USERNAME') ?: '',
        'password' => getenv('CACHE_TEST_PASSWORD') ?: '',
        'tls' => filter_var(getenv('CACHE_TEST_TLS') ?: 'false', FILTER_VALIDATE_BOOL),
        'ca_file' => getenv('CACHE_TEST_CA_FILE') ?: '',
    ],
];
$cache = new Cache($settings);
if (($argv[1] ?? '') === '--increment-worker') {
    $prefix = $argv[2] ?? '';
    if (!preg_match('/^pbreeze-test-[a-f0-9]{24}:$/D', $prefix)) {
        throw new RuntimeException('Invalid integration-test worker prefix.');
    }
    $worker = new Cache(array_replace($settings, ['prefix' => $prefix]));
    for ($i = 0; $i < 25; $i++) {
        $worker->increment('counter');
    }
    exit(0);
}
$keys = ['value', 'counter', 'expires', 'long', 'forever', 'remember', 'counter-expiry', 'invalid-counter', 'boundary', 'corrupt'];
$assert = static function (bool $valid, string $message): void {
    if (!$valid) {
        throw new RuntimeException($message);
    }
};
try {
    $assert($cache->get('missing', 'default') === 'default', 'Missing value default failed.');
    $native = (new ReflectionProperty(Cache::class, 'client'))->getValue($cache);
    $nativeKey = $settings['prefix'] . hash('sha256', 'corrupt');
    $native->set($nativeKey, 'invalid-json');
    $produced = false;
    try {
        $cache->remember('corrupt', static function () use (&$produced): int { $produced = true; return 1; });
        throw new LogicException('Corrupt cache data was accepted.');
    } catch (RuntimeException) {
        $assert(!$produced, 'Corrupt data was treated as a cache miss.');
    }
    if ($native instanceof Redis) {
        $native->del($nativeKey);
        $native->lPush($nativeKey, 'list-not-string');
        try {
            $cache->get('corrupt', 'default');
            throw new LogicException('Redis WRONGTYPE was treated as a miss.');
        } catch (RuntimeException) {
            // Redis command failures must not become success-shaped misses.
        }
    } else {
        $native->set($nativeKey, false);
        try {
            $cache->get('corrupt', 'default');
            throw new LogicException('Non-JSON Memcached false was treated as a miss.');
        } catch (RuntimeException) {
            // Only NOTFOUND, not an unexpected false value, is a miss.
        }
    }
    $native->set($nativeKey, str_repeat('x', 524289));
    try {
        $cache->get('corrupt');
        throw new LogicException('Oversized cached data was accepted.');
    } catch (RuntimeException) {
        // Enforce the read bound even for data written outside this service.
    }
    $cache->delete('corrupt');
    foreach ([null, false, true, 0, 42, -1, 1.0, 1.25, '', '0', "Hello \u{2615}", ['nested' => [null, false, 1, 'text']]] as $value) {
        $cache->set('value', $value);
        $assert($cache->get('value', 'missing') === $value, 'JSON value round trip failed.');
    }
    $cache->set('boundary', str_repeat('a', 524286));
    $assert(strlen($cache->get('boundary')) === 524286, 'Exact 512 KiB JSON boundary failed.');
    $nested = 1;
    for ($i = 0; $i < 60; $i++) {
        $nested = [$nested];
    }
    $cache->set('value', $nested);
    $assert($cache->get('value') === $nested, 'Valid nested arrays failed.');
    $assert($cache->delete('value'), 'Existing deletion failed.');
    $assert(!$cache->delete('value'), 'Missing deletion failed.');
    $calls = 0;
    $producer = static function () use (&$calls): mixed { $calls++; return null; };
    $assert($cache->remember('remember', $producer) === null, 'Remember null failed.');
    $cache->remember('remember', $producer);
    $assert($calls === 1, 'Remember must not rerun for cached null.');
    try {
        $cache->remember('value', static fn () => throw new LogicException('producer failure'));
        throw new RuntimeException('Producer failure was swallowed.');
    } catch (LogicException $error) {
        $assert($error->getMessage() === 'producer failure', 'Producer exception changed.');
    }
    $assert($cache->get('value', 'missing') === 'missing', 'Producer failure cached a value.');
    $assert($cache->increment('counter', 2) === 2, 'Counter creation failed.');
    $assert($cache->increment('counter', 3) === 5, 'Counter increment failed.');
    $assert($cache->get('counter') === 5, 'Counter retrieval failed.');
    $cache->set('counter', 10);
    $assert($cache->increment('counter') === 11, 'Set integer cannot be incremented.');
    $cache->set('counter', 0);
    $processes = [];
    try {
        for ($i = 0; $i < 4; $i++) {
            $process = proc_open([PHP_BINARY, __FILE__, '--increment-worker', $settings['prefix']], [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes, null, null, ['bypass_shell' => true]);
            $assert(is_resource($process), 'Counter worker did not start.');
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $assert(proc_close($process) === 0, 'Counter worker failed: ' . $output);
        }
    } finally {
        foreach ($processes as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }
    $assert($cache->get('counter') === 100, 'Concurrent increments lost updates.');
    foreach (['12', -1, 1.0, false, ['value' => 1]] as $value) {
        $cache->set('invalid-counter', $value);
        try {
            $cache->increment('invalid-counter');
            throw new LogicException('Invalid counter was accepted.');
        } catch (RuntimeException) {
            // Invalid counter types must not be treated as cache misses.
        }
    }
    $cache->set('expires', 'short', 1);
    $cache->set('long', 'long', 2592001);
    $cache->set('forever', 'forever', 0);
    $assert($cache->increment('counter-expiry', ttl: 1) === 1, 'Expiring counter creation failed.');
    $assert($cache->increment('counter-expiry', ttl: 300) === 2, 'Expiring counter increment failed.');
    sleep(2);
    $assert($cache->get('expires', 'missing') === 'missing', 'Value did not expire.');
    $assert($cache->get('counter-expiry', 'missing') === 'missing', 'Increment refreshed counter expiration.');
    $assert($cache->get('long') === 'long', 'TTL above 30 days was misinterpreted.');
    $assert($cache->get('forever') === 'forever', 'No-expiration value was lost.');
    $other = new Cache(array_replace($settings, ['prefix' => $settings['prefix'] . 'other:']));
    $assert($other->get('forever', 'missing') === 'missing', 'Prefixes are not isolated.');
    echo "PASS $driver cache: round trips, misses, deletion, remember, counters, expiry, boundaries, and isolation.\n";
} finally {
    foreach ($keys as $key) {
        $cache->delete($key);
    }
}
