<?php
declare(strict_types=1);

use PBreeze\Cache;

test('Cache is lazy and disabled by default without changing existing application services', static function () use ($app): void {
    check($app->cache instanceof Cache);
    raises(static fn () => $app->cache->get('key'), RuntimeException::class);
    foreach (['redis', 'memcached'] as $driver) {
        $cache = new Cache(['driver' => $driver]);
        if (!extension_loaded($driver)) {
            raises(static fn () => $cache->get('key'), RuntimeException::class);
        }
    }
});

test('Cache validates namespaces, timeouts, keys, and TTLs before opening a connection', static function (): void {
    foreach ([
        ['driver' => 'memcache'], ['prefix' => ''], ['prefix' => "bad\nprefix"],
        ['prefix' => str_repeat('a', 65)], ['timeout' => 0], ['timeout' => 31],
    ] as $config) {
        raises(static fn () => new Cache($config), InvalidArgumentException::class);
    }
    $cache = new Cache(['driver' => 'redis']);
    foreach (['', "bad key", "bad\0key", str_repeat('k', 1025)] as $key) {
        raises(static fn () => $cache->get($key), InvalidArgumentException::class);
        raises(static fn () => $cache->set($key, 'value'), InvalidArgumentException::class);
        raises(static fn () => $cache->delete($key), InvalidArgumentException::class);
        raises(static fn () => $cache->increment($key), InvalidArgumentException::class);
    }
    foreach ([-1, 31536001] as $ttl) {
        raises(static fn () => $cache->set('key', null, $ttl), InvalidArgumentException::class);
        raises(static fn () => $cache->remember('key', static fn () => 1, $ttl), InvalidArgumentException::class);
        raises(static fn () => $cache->increment('key', ttl: $ttl), InvalidArgumentException::class);
    }
    raises(static fn () => $cache->increment('key', 0), InvalidArgumentException::class);
    raises(static fn () => $cache->increment('key', -1), InvalidArgumentException::class);
});

test('Cache rejects objects, resources, recursive arrays, invalid UTF-8, and oversized JSON', static function (): void {
    $cache = new Cache(['driver' => 'redis']);
    $resource = fopen('php://memory', 'r+');
    $recursive = [];
    $recursive['self'] = &$recursive;
    try {
        foreach ([new stdClass(), ['object' => new stdClass()], $resource, $recursive, INF, NAN, "\xff", str_repeat('x', 524288)] as $value) {
            raises(static fn () => $cache->set('key', $value), InvalidArgumentException::class);
        }
    } finally {
        fclose($resource);
    }
});

test('Cache failures never include credentials or server-supplied error text', static function (): void {
    $cache = new Cache(['driver' => 'redis', 'redis' => ['host' => 'bad/secret-host', 'password' => 'secret-password']]);
    try {
        $cache->get('key');
        throw new LogicException('Expected cache failure.');
    } catch (RuntimeException $error) {
        check(!str_contains((string) $error, 'secret-password'));
        check(!str_contains((string) $error, 'secret-host'));
        same(null, $error->getPrevious());
    }
});

test('Doctor checks the selected cache extension without opening a connection', static function (): void {
    foreach (['none', 'redis', 'memcached'] as $driver) {
        $app = productionApp(['cache' => ['driver' => $driver, $driver => ['host' => 'invalid/no-connection']]]);
        $checks = array_column((new \PBreeze\Diagnostics($app))->check(), null, 'name');
        same($driver === 'none' ? 'pass' : (extension_loaded($driver) ? 'warn' : 'fail'), $checks['cache']['status']);
        check(!file_exists($app->root . '/storage'));
    }
});
