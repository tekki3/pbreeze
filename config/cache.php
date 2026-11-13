<?php
declare(strict_types=1);

use PBreeze\Env;

$integer = static function (string $name, int $default, int $min, int $max): int {
    $value = filter_var(Env::get($name, (string) $default), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max],
    ]);
    if ($value === false) {
        throw new RuntimeException("$name must be between $min and $max.");
    }
    return $value;
};

return [
    'driver' => Env::get('CACHE_DRIVER', 'none'),
    'prefix' => Env::get('CACHE_PREFIX', 'pbreeze:'),
    'timeout' => $integer('CACHE_TIMEOUT', 2, 1, 30),
    'redis' => [
        'host' => Env::get('REDIS_HOST', '127.0.0.1'),
        'port' => $integer('REDIS_PORT', 6379, 1, 65535),
        'username' => Env::get('REDIS_USERNAME'),
        'password' => Env::get('REDIS_PASSWORD'),
        'database' => $integer('REDIS_DATABASE', 0, 0, 65535),
        'tls' => Env::bool('REDIS_TLS'),
        'ca_file' => Env::get('REDIS_CA_FILE'),
    ],
    'memcached' => [
        'host' => Env::get('MEMCACHED_HOST', '127.0.0.1'),
        'port' => $integer('MEMCACHED_PORT', 11211, 1, 65535),
        'username' => Env::get('MEMCACHED_USERNAME'),
        'password' => Env::get('MEMCACHED_PASSWORD'),
    ],
];
