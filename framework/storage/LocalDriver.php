<?php
declare(strict_types=1);

namespace PBreeze\Storage;

use PBreeze\Filesystem;
use PBreeze\Storage;
use RuntimeException;

final class LocalDriver implements DriverInterface
{
    public function __construct(
        private readonly string $root,
        private readonly string $storagePath,
        private readonly int $maxBytes,
    ) {
    }

    public function put(string $key, #[\SensitiveParameter] string $content): void
    {
        $path = $this->path($key, true);
        $temporary = dirname($path) . '/.write-' . bin2hex(random_bytes(16));
        $stream = fopen($temporary, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot create the temporary storage file.');
        }
        $complete = false;
        try {
            Filesystem::permissions($temporary, 0600);
            for ($offset = 0, $length = strlen($content); $offset < $length; $offset += $written) {
                $written = fwrite($stream, substr($content, $offset, 1048576));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write the storage file.');
                }
            }
            if (!fflush($stream)) {
                throw new RuntimeException('Cannot flush the storage file.');
            }
            fclose($stream);
            $stream = null;
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Cannot publish the storage file.');
            }
            $complete = true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (!$complete && is_file($temporary) && !unlink($temporary)) {
                throw new RuntimeException('Cannot remove the incomplete storage file.');
            }
        }
    }

    public function get(string $key): string
    {
        $path = $this->path($key, false);
        if ($path === null) {
            throw new RuntimeException('Storage file not found.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot open the storage file.');
        }
        try {
            $content = stream_get_contents($stream, $this->maxBytes + 1);
            if ($content === false) {
                throw new RuntimeException('Cannot read the storage file.');
            }
            if (strlen($content) > $this->maxBytes) {
                throw new RuntimeException('Storage file exceeds the configured maximum byte size.');
            }
            return $content;
        } finally {
            fclose($stream);
        }
    }

    public function exists(string $key): bool
    {
        return $this->path($key, false) !== null;
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key, false);
        if ($path === null) {
            return false;
        }
        if (!unlink($path)) {
            throw new RuntimeException('Cannot delete the storage file.');
        }
        return true;
    }

    private function path(string $key, bool $create): ?string
    {
        Storage::key($key);
        clearstatcache();
        $path = Filesystem::privateDirectory($this->root, $this->storagePath, 'files', $create, !$create);
        if (!$create && !is_dir($path)) {
            return null;
        }
        $segments = explode('/', $key);
        foreach ($segments as $index => $segment) {
            if (!is_readable($path) || (PHP_OS_FAMILY !== 'Windows' && !is_executable($path))) {
                throw new RuntimeException('Cannot access the storage directory.');
            }
            $path .= '/' . $segment;
            if (is_link($path)) {
                throw new RuntimeException('Storage paths must not contain symlinks.');
            }
            $last = $index === count($segments) - 1;
            if (file_exists($path)) {
                if ($last ? !is_file($path) : !is_dir($path)) {
                    throw new RuntimeException('Storage path has an incompatible file type.');
                }
                if (!is_readable($path)) {
                    throw new RuntimeException('Cannot read the storage path.');
                }
            } elseif (!$last && $create) {
                if (!mkdir($path, 0700) && !is_dir($path)) {
                    throw new RuntimeException('Cannot create the storage directory.');
                }
            } elseif (!$create) {
                return null;
            }
        }
        return $path;
    }
}
