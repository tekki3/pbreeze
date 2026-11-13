<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use RuntimeException;

final class Filesystem
{
    public static function privateDirectory(string $root, ?string $storagePath = null, string $child = '', bool $create = true): string
    {
        $root = realpath($root);
        if ($root === false) {
            throw new RuntimeException('The application root does not exist.');
        }
        if ($child !== '' && !preg_match('/^[a-z][a-z0-9_-]*$/D', $child)) {
            throw new InvalidArgumentException('Use a simple private storage directory name.');
        }
        $path = self::databasePath($root, $storagePath ?? 'storage');
        if ($path === ':memory:' || preg_match('~(?:^|/)\.\.?(?:/|$)|[\x00-\x1f\x7f]~', $path)
            || str_contains(preg_replace('/^[a-zA-Z]:/', '', $path), ':')) {
            throw new InvalidArgumentException('Storage requires a local path without dot segments or stream wrappers.');
        }
        $path = rtrim($path, '/');
        if ($path === '' || preg_match('/^[a-zA-Z]:$/D', $path)) {
            throw new InvalidArgumentException('The filesystem root cannot be used as private storage.');
        }
        if ($child !== '') {
            $path .= '/' . $child;
        }
        $missing = [];
        $ancestor = $path;
        while (true) {
            if (is_link($ancestor)) {
                throw new RuntimeException('Private storage directories must not be symlinks.');
            }
            if (file_exists($ancestor)) {
                if (!is_dir($ancestor)) {
                    throw new RuntimeException('Private storage paths must be directories.');
                }
            } else {
                array_unshift($missing, $ancestor);
            }
            $parent = dirname($ancestor);
            if ($parent === $ancestor || $ancestor === '') {
                break;
            }
            $ancestor = $parent;
        }
        $normalize = static function (string $value): string {
            $value = preg_replace('~/+~', '/', str_replace('\\', '/', $value));
            return PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value;
        };
        $existing = $missing === [] ? $path : dirname($missing[0]);
        $resolved = realpath($existing);
        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve private storage.');
        }
        $target = $normalize($resolved);
        foreach ($missing as $directory) {
            $target .= '/' . (PHP_OS_FAMILY === 'Windows' ? strtolower(basename($directory)) : basename($directory));
        }
        $public = $normalize(realpath($root . '/public') ?: $root . '/public');
        if ($target === $public || str_starts_with($target, $public . '/') || str_starts_with($public, $target . '/')) {
            throw new RuntimeException('Private storage must be outside the public directory and must not contain it.');
        }
        if ($create) {
            foreach ($missing as $directory) {
                set_error_handler(static function (int $severity, string $message): never {
                    throw new RuntimeException('Cannot create private storage: ' . $message);
                });
                try {
                    if (!mkdir($directory, 0700) && !is_dir($directory)) {
                        throw new RuntimeException('Cannot create private storage.');
                    }
                } catch (RuntimeException $error) {
                    if (!is_dir($directory) || is_link($directory)) {
                        throw $error;
                    }
                } finally {
                    restore_error_handler();
                }
            }
        } elseif ($missing !== []) {
            throw new RuntimeException('Private storage directory does not exist.');
        }
        return $path;
    }

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
