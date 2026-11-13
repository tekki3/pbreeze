<?php
declare(strict_types=1);

namespace PBreeze;

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class Cache
{
    private \Redis|\Memcached|null $client = null;
    private readonly string $driver;
    private readonly string $prefix;

    /** @param array<string, mixed> $config */
    public function __construct(#[\SensitiveParameter] private readonly array $config = [])
    {
        $this->driver = $config['driver'] ?? 'none';
        $this->prefix = $config['prefix'] ?? 'pbreeze:';
        if (!in_array($this->driver, ['none', 'redis', 'memcached'], true)) {
            throw new InvalidArgumentException('CACHE_DRIVER must be none, redis, or memcached.');
        }
        if (!is_string($this->prefix) || !preg_match('/^[a-zA-Z0-9:_-]{1,64}$/D', $this->prefix)) {
            throw new InvalidArgumentException('CACHE_PREFIX requires 1..64 ASCII letters, digits, colons, underscores, or hyphens.');
        }
        $timeout = $config['timeout'] ?? 2;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 30) {
            throw new InvalidArgumentException('CACHE_TIMEOUT must be 1..30 seconds.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $key = $this->key($key);
        $raw = $this->perform(function (\Redis|\Memcached $client) use ($key): string|int|false {
            $value = $client->get($key);
            if ($client instanceof \Memcached) {
                if ($client->getResultCode() === \Memcached::RES_NOTFOUND) {
                    return false;
                }
                if ($client->getResultCode() !== \Memcached::RES_SUCCESS || $value === false) {
                    throw new RuntimeException('Cache read failed or returned invalid data.');
                }
            }
            if ($value !== false && !is_string($value) && !is_int($value)) {
                throw new RuntimeException('Unexpected cache value type.');
            }
            return $value;
        });
        if ($raw === false) {
            return $default;
        }
        if (strlen((string) $raw) > 524288) {
            throw new RuntimeException('Cached value exceeds the 512 KiB limit.');
        }
        try {
            return json_decode((string) $raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Cached value is not valid PBreeze JSON.');
        }
    }

    public function set(string $key, #[\SensitiveParameter] mixed $value, int $ttl = 300): void
    {
        $key = $this->key($key);
        $this->ttl($ttl);
        $this->validateValue($value);
        try {
            $raw = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, 64);
        } catch (JsonException) {
            throw new InvalidArgumentException('Cache values require finite numbers and valid UTF-8 JSON data.');
        }
        if (strlen($raw) > 524288) {
            throw new InvalidArgumentException('Cache values must not exceed 512 KiB encoded.');
        }
        $this->perform(function (\Redis|\Memcached $client) use ($key, $raw, $ttl): void {
            $stored = $client instanceof \Redis
                ? ($ttl === 0 ? $client->set($key, $raw) : $client->setex($key, $ttl, $raw))
                : $client->set($key, $raw, $this->expiration($ttl));
            if ($stored !== true) {
                throw new RuntimeException('Cache write failed.');
            }
        });
    }

    public function delete(string $key): bool
    {
        $key = $this->key($key);
        return $this->perform(static function (\Redis|\Memcached $client) use ($key): bool {
            if ($client instanceof \Redis) {
                $deleted = $client->del($key);
                if ($deleted === false) {
                    throw new RuntimeException('Cache deletion failed.');
                }
                return $deleted > 0;
            }
            if ($client->delete($key)) {
                return true;
            }
            if ($client->getResultCode() !== \Memcached::RES_NOTFOUND) {
                throw new RuntimeException('Cache deletion failed.');
            }
            return false;
        });
    }

    public function remember(string $key, callable $produce, int $ttl = 300): mixed
    {
        $this->key($key);
        $this->ttl($ttl);
        $missing = new \stdClass();
        $value = $this->get($key, $missing);
        if ($value !== $missing) {
            return $value;
        }
        $value = $produce();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function increment(string $key, int $amount = 1, int $ttl = 300): int
    {
        $key = $this->key($key);
        $this->ttl($ttl);
        if ($amount < 1) {
            throw new InvalidArgumentException('Cache increment amount must be a positive integer.');
        }
        return $this->perform(function (\Redis|\Memcached $client) use ($key, $amount, $ttl): int {
            if ($client instanceof \Redis) {
                // Initial creation and expiry must be atomic, without refreshing existing counters.
                $script = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current and not string.match(current, '^%d+$') then
    return redis.error_reply('Counter requires a nonnegative integer')
end
redis.call('INCRBY', KEYS[1], ARGV[1])
if not current and tonumber(ARGV[2]) > 0 then
    redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return redis.call('GET', KEYS[1])
LUA;
                $value = $client->eval($script, [$key, (string) $amount, (string) $ttl], 1);
            } else {
                $value = $client->increment($key, $amount, $amount, $this->expiration($ttl));
            }
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($value === false || $integer === false) {
                throw new RuntimeException('Cache counter failed or exceeded the PHP integer range.');
            }
            return $integer;
        });
    }

    private function key(string $key): string
    {
        if ($key === '' || strlen($key) > 1024 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new InvalidArgumentException('Cache keys require 1..1024 bytes without spaces or control characters.');
        }
        return $this->prefix . hash('sha256', $key);
    }

    private function ttl(int $ttl): void
    {
        if ($ttl < 0 || $ttl > 31536000) {
            throw new InvalidArgumentException('Cache TTL must be 0..31536000 seconds; 0 means no expiration.');
        }
    }

    private function expiration(int $ttl): int
    {
        return $ttl > 2592000 ? time() + $ttl : $ttl;
    }

    private function validateValue(#[\SensitiveParameter] mixed $value, int $depth = 0): void
    {
        if ($depth > 60 || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Cache supports only scalars, null, and arrays up to 60 levels deep.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validateValue($item, $depth + 1);
            }
        }
    }

    private function perform(Closure $operation): mixed
    {
        if ($this->driver === 'none') {
            throw new RuntimeException('Cache is disabled. Configure CACHE_DRIVER=redis or memcached.');
        }
        if (!extension_loaded($this->driver)) {
            throw new RuntimeException('Cache requires the native ' . $this->driver . ' PHP extension.');
        }
        set_error_handler(static function (): never {
            throw new RuntimeException('Native cache operation failed.');
        });
        try {
            $client = $this->connection();
            if ($client instanceof \Redis) {
                $client->clearLastError();
            }
            $result = $operation($client);
            if ($client instanceof \Redis && $client->getLastError() !== null) {
                throw new RuntimeException('Redis command failed.');
            }
            return $result;
        } catch (Throwable) {
            throw new RuntimeException('Cache operation failed. Check the configured extension, server, credentials, permissions, and value type.');
        } finally {
            restore_error_handler();
        }
    }

    private function connection(): \Redis|\Memcached
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $settings = $this->config[$this->driver] ?? [];
        $host = $settings['host'] ?? '127.0.0.1';
        $port = $settings['port'] ?? ($this->driver === 'redis' ? 6379 : 11211);
        $timeout = $this->config['timeout'] ?? 2;
        if (!is_string($host) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/D', $host)
            || !is_int($port) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid cache server host or port.');
        }
        $username = $settings['username'] ?? '';
        $password = $settings['password'] ?? '';
        if ($this->driver === 'redis') {
            $database = $settings['database'] ?? 0;
            $tls = $settings['tls'] ?? false;
            $ca = $settings['ca_file'] ?? '';
            if (!is_int($database) || $database < 0 || $database > 65535 || !is_bool($tls)
                || !is_string($ca) || ($ca !== '' && (!is_file($ca) || !is_readable($ca)))) {
                throw new RuntimeException('Invalid Redis database or TLS settings.');
            }
            $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host];
            if ($ca !== '') {
                $ssl['cafile'] = $ca;
            }
            $client = new \Redis();
            if (!$client->connect(($tls ? 'tls://' : '') . $host, $port, $timeout, null, 0, $timeout, $tls ? ['stream' => $ssl] : null)
                || !$client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE)
                || !$client->setOption(\Redis::OPT_MAX_RETRIES, 0)) {
                throw new RuntimeException('Redis connection failed.');
            }
            if ($username !== '' && $password === '') {
                throw new RuntimeException('Redis ACL username requires a password.');
            }
            if ($password !== '' && !$client->auth($username === '' ? $password : [$username, $password])) {
                throw new RuntimeException('Redis authentication failed.');
            }
            if (!$client->select($database)) {
                throw new RuntimeException('Redis database selection failed.');
            }
        } else {
            $client = new \Memcached();
            if (!$client->setOptions([
                \Memcached::OPT_BINARY_PROTOCOL => true,
                \Memcached::OPT_SERIALIZER => \Memcached::SERIALIZER_JSON,
                \Memcached::OPT_COMPRESSION => false,
                \Memcached::OPT_CONNECT_TIMEOUT => $timeout * 1000,
                \Memcached::OPT_POLL_TIMEOUT => $timeout * 1000,
                \Memcached::OPT_SEND_TIMEOUT => $timeout * 1000000,
                \Memcached::OPT_RECV_TIMEOUT => $timeout * 1000000,
                \Memcached::OPT_RETRY_TIMEOUT => 0,
            ]) || !$client->addServer($host, $port)) {
                throw new RuntimeException('Memcached connection configuration failed.');
            }
            if (($username === '') !== ($password === '')) {
                throw new RuntimeException('Memcached SASL requires both a username and password.');
            }
            if ($username !== '' && !$client->setSaslAuthData($username, $password)) {
                throw new RuntimeException('Memcached SASL configuration failed.');
            }
        }
        return $this->client = $client;
    }
}
