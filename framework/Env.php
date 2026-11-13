<?php
declare(strict_types=1);

namespace PBreeze;

use RuntimeException;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        self::$values = [];
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Kan .env niet lezen.');
        }
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/D', $line, $match)) {
                throw new RuntimeException('Ongeldige .env-regel: ' . ($index + 1));
            }
            $value = trim($match[2]);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                if (strlen($value) < 2 || $value[-1] !== $value[0]) {
                    throw new RuntimeException('Ongesloten aanhalingsteken in .env.');
                }
                $value = substr($value, 1, -1);
            }
            self::$values[$match[1]] = $value;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value === false ? (self::$values[$key] ?? $default) : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? 'true' : 'false');
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? throw new RuntimeException("$key moet true of false zijn.");
    }
}
