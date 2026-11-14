<?php
declare(strict_types=1);

namespace PBreeze\Readers;

use JsonException;
use RuntimeException;

final class JsonReader extends Reader
{
    public function read(string $path, array $options = []): array
    {
        $options = $this->options($options);
        $contents = $this->utf8($this->contents($path, $options['max_bytes']));
        try {
            $data = json_decode($contents, true, $options['max_depth'] + 1, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $error) {
            throw new RuntimeException('Invalid JSON: ' . $error->getMessage(), 0, $error);
        }
        if (!is_array($data)) {
            throw new RuntimeException('JSON must have an object or array at its root.');
        }
        array_walk_recursive($data, static function (mixed $value): void {
            if (is_float($value) && !is_finite($value)) {
                throw new RuntimeException('JSON contains a number outside the finite PHP float range.');
            }
        });
        return $data;
    }
}
