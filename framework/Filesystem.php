<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use RuntimeException;

final class Filesystem
{
    public static function databasePath(string $root, string $path): string
    {
        if ($path === ':memory:') {
            return $path;
        }
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('The database path must not be empty or contain NUL bytes.');
        }
        $path = str_replace('\\', '/', $path);
        if (preg_match('/^[a-zA-Z]:(?!\/)/', $path)) {
            throw new InvalidArgumentException('Use a full Windows path such as C:/data/app.sqlite, not a drive-relative path.');
        }
        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//', $path)) {
            return $path;
        }
        return rtrim(str_replace('\\', '/', $root), '/') . '/' . $path;
    }

    public static function permissions(string $path, int $mode): void
    {
        // Windows protects files with inherited NTFS ACLs, not POSIX modes.
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($path, $mode)) {
            throw new RuntimeException("Cannot set file permissions: $path");
        }
    }

    public static function portableName(string $name): void
    {
        foreach (explode('.', $name) as $segment) {
            if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/iD', $segment)) {
                throw new InvalidArgumentException('This filename is reserved on Windows.');
            }
        }
    }
}
