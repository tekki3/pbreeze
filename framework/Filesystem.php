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
            throw new InvalidArgumentException('Het databasepad mag niet leeg zijn of NUL-bytes bevatten.');
        }
        $path = str_replace('\\', '/', $path);
        if (preg_match('/^[a-zA-Z]:(?!\/)/', $path)) {
            throw new InvalidArgumentException('Gebruik een volledig Windows-pad zoals C:/data/app.sqlite, geen schijfrelatief pad.');
        }
        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//', $path)) {
            return $path;
        }
        return rtrim(str_replace('\\', '/', $root), '/') . '/' . $path;
    }

    public static function permissions(string $path, int $mode): void
    {
        // Windows beschermt bestanden met geerfde NTFS-ACL's, niet met POSIX-modes.
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($path, $mode)) {
            throw new RuntimeException("Kan bestandsrechten niet instellen: $path");
        }
    }

    public static function portableName(string $name): void
    {
        foreach (explode('.', $name) as $segment) {
            if (preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/iD', $segment)) {
                throw new InvalidArgumentException('Deze bestandsnaam is gereserveerd op Windows.');
            }
        }
    }
}
