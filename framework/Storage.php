<?php
declare(strict_types=1);

namespace PBreeze;

use Closure;
use InvalidArgumentException;
use PBreeze\Storage\DriverInterface;
use PBreeze\Storage\LocalDriver;
use PBreeze\Storage\SftpDriver;
use PBreeze\Storage\S3Driver;
use PBreeze\Storage\AzureDriver;
use PBreeze\Storage\HttpClient;

require_once __DIR__ . '/Filesystem.php';
require_once __DIR__ . '/storage/DriverInterface.php';
require_once __DIR__ . '/storage/LocalDriver.php';
require_once __DIR__ . '/storage/SftpDriver.php';
require_once __DIR__ . '/storage/HttpClient.php';
require_once __DIR__ . '/storage/S3Driver.php';
require_once __DIR__ . '/storage/AzureDriver.php';

final class Storage
{
    private readonly DriverInterface $driver;
    private readonly int $maxBytes;

    /**
     * @param array<string, mixed> $config
     * @param Closure(string, string, array<string, string>, string, int, int): array{status: int, body: string}|null $transport Trusted HTTPS replacement for isolated tests.
     */
    public function __construct(
        string $root,
        #[\SensitiveParameter] array $config = [],
        ?string $storagePath = null,
        ?Closure $transport = null,
    ) {
        $this->maxBytes = $config['max_bytes'] ?? 16777216;
        $timeout = $config['timeout'] ?? 30;
        if (!is_int($this->maxBytes) || $this->maxBytes < 1 || $this->maxBytes > 268435456
            || !is_int($timeout) || $timeout < 1 || $timeout > 300) {
            throw new InvalidArgumentException('Storage max_bytes must be 1..268435456 and timeout 1..300 seconds.');
        }
        $driver = $config['driver'] ?? 'local';
        if (in_array($driver, ['sftp', 's3', 'azure'], true) && !is_array($config[$driver] ?? [])) {
            throw new InvalidArgumentException('Storage driver configuration must be an array.');
        }
        if (!is_string($config['ca_file'] ?? '')) {
            throw new InvalidArgumentException('Storage ca_file must be a local path string.');
        }
        $this->driver = match ($driver) {
            'local' => new LocalDriver($root, $storagePath ?? 'storage', $this->maxBytes),
            'sftp' => new SftpDriver($config['sftp'] ?? [], $this->maxBytes),
            's3' => new S3Driver($config['s3'] ?? [], new HttpClient($timeout, $this->maxBytes, $config['ca_file'] ?? '', $transport)),
            'azure' => new AzureDriver($config['azure'] ?? [], new HttpClient($timeout, $this->maxBytes, $config['ca_file'] ?? '', $transport)),
            default => throw new InvalidArgumentException('STORAGE_DRIVER must be local, sftp, s3, or azure.'),
        };
    }

    public function put(string $key, #[\SensitiveParameter] string $content): void
    {
        self::key($key);
        if (strlen($content) > $this->maxBytes) {
            throw new InvalidArgumentException('Storage content exceeds the configured maximum byte size.');
        }
        $this->perform(fn () => $this->driver->put($key, $content));
    }

    public function get(string $key): string
    {
        self::key($key);
        return $this->perform(fn () => $this->driver->get($key));
    }

    public function exists(string $key): bool
    {
        self::key($key);
        return $this->perform(fn () => $this->driver->exists($key));
    }

    public function delete(string $key): bool
    {
        self::key($key);
        return $this->perform(fn () => $this->driver->delete($key));
    }

    private function perform(Closure $operation): mixed
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException('Native storage operation failed; check permissions, connectivity, and configuration.');
        });
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /** @internal */
    public static function key(string $key): void
    {
        if ($key === '' || strlen($key) > 1024 || preg_match('//u', $key) !== 1
            || preg_match('/[\x00-\x1f\x7f\\\\<>:"|?*%#]/', $key)) {
            throw new InvalidArgumentException('Use a relative UTF-8 storage key of at most 1024 bytes without controls, reserved characters, or percent escapes.');
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || strlen($segment) > 255 || in_array($segment, ['.', '..'], true)
                || preg_match('/[. ]$/D', $segment) || str_starts_with($segment, '.')) {
                throw new InvalidArgumentException('Storage keys must have portable nonempty path segments without dot segments or hidden filenames.');
            }
            Filesystem::portableName($segment);
        }
    }
}
