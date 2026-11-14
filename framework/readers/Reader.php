<?php
declare(strict_types=1);

namespace PBreeze\Readers;

use InvalidArgumentException;
use RuntimeException;

abstract class Reader implements ReaderInterface
{
    public function iterate(string $path, array $options = []): iterable
    {
        throw new RuntimeException('Streaming is not supported by this reader; use read().');
    }

    protected function options(array $options, array $defaults = []): array
    {
        $defaults += ['max_bytes' => 16777216, 'max_depth' => 64];
        foreach ($options as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                throw new InvalidArgumentException("Unknown file reader option: $key");
            }
        }
        $options += $defaults;
        if (!is_int($options['max_bytes']) || $options['max_bytes'] < 1
            || !is_int($options['max_depth']) || $options['max_depth'] < 1 || $options['max_depth'] > 256) {
            throw new InvalidArgumentException('max_bytes must be a positive integer; max_depth must be between 1 and 256.');
        }
        return $options;
    }

    /** @return resource */
    protected function open(string $path, int $maxBytes)
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path)
            || str_contains($path, '://') || preg_match('/^[a-zA-Z]:(?![\\\\\\/])/', $path)) {
            throw new InvalidArgumentException('File readers require a local file path without stream wrappers or control characters.');
        }
        if (!file_exists($path)) {
            throw new RuntimeException('The input file does not exist.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The input path is not a readable regular file.');
        }
        set_error_handler(static function (): never {
            throw new RuntimeException('Cannot open the input file for reading.');
        });
        try {
            $stream = fopen($path, 'rb');
        } finally {
            restore_error_handler();
        }
        if ($stream === false) {
            throw new RuntimeException('Cannot open the input file for reading.');
        }
        $stat = fstat($stream);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > $maxBytes) {
            fclose($stream);
            throw new RuntimeException('The input must be a regular file within max_bytes.');
        }
        return $stream;
    }

    protected function contents(string $path, int $maxBytes): string
    {
        $stream = $this->open($path, $maxBytes);
        try {
            $contents = '';
            while (!feof($stream)) {
                $remaining = $maxBytes - strlen($contents);
                $chunk = fread($stream, $remaining >= 8192 ? 8192 : $remaining + 1);
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    throw new RuntimeException('Cannot read the input file.');
                }
                $contents .= $chunk;
                if (strlen($contents) > $maxBytes) {
                    throw new RuntimeException('The input exceeds max_bytes.');
                }
            }
            return $contents;
        } finally {
            fclose($stream);
        }
    }

    protected function utf8(string $contents): string
    {
        if (str_starts_with($contents, "\xef\xbb\xbf")) {
            $contents = substr($contents, 3);
        }
        if (preg_match('//u', $contents) !== 1) {
            throw new RuntimeException('Input must be valid UTF-8 (an optional UTF-8 BOM is supported).');
        }
        return $contents;
    }
}
