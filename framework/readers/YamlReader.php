<?php
declare(strict_types=1);

namespace PBreeze\Readers;

use RuntimeException;

final class YamlReader extends Reader
{
    public function read(string $path, array $options = []): array
    {
        $options = $this->options($options);
        $contents = $this->utf8($this->contents($path, $options['max_bytes']));
        if (!function_exists('yaml_parse')) {
            throw new RuntimeException('YAML reading requires the native PHP YAML extension (ext-yaml).');
        }
        if (filter_var(ini_get('yaml.decode_php'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('YAML reading requires yaml.decode_php=0 to prevent PHP object deserialization.');
        }
        set_error_handler(static function (int $severity, string $message): never {
            preg_match('/line[: ]+(\d+)/i', $message, $line);
            throw new RuntimeException('Invalid YAML' . (isset($line[1]) ? ' near line ' . $line[1] : '') . '.');
        });
        try {
            $documents = 0;
            $parsed = yaml_parse($contents, -1, $documents, [
                '!php/object' => static function (): never {
                    throw new RuntimeException('YAML PHP object tags are not allowed.');
                },
            ]);
        } finally {
            restore_error_handler();
        }
        if ($documents !== 1 || !is_array($parsed) || !is_array($parsed[0] ?? null)) {
            throw new RuntimeException('YAML must contain exactly one mapping or sequence document.');
        }
        $count = 0;
        return $this->normalize($parsed[0], 1, $options['max_depth'], $count);
    }

    private function normalize(array $data, int $depth, int $limit, int &$count): array
    {
        if ($depth > $limit || ++$count > 100000) {
            throw new RuntimeException('YAML exceeds max_depth or the 100000-value limit (possibly cyclic aliases).');
        }
        $result = [];
        foreach ($data as $key => $value) {
            if (++$count > 100000) {
                throw new RuntimeException('YAML exceeds the 100000-value limit (possibly expanding aliases).');
            }
            if (is_array($value)) {
                $result[$key] = $this->normalize($value, $depth + 1, $limit, $count);
            } elseif (is_scalar($value) || $value === null) {
                if (is_float($value) && !is_finite($value)) {
                    throw new RuntimeException('Nonfinite YAML numbers are not supported.');
                }
                $result[$key] = $value;
            } else {
                throw new RuntimeException('YAML may contain only arrays and scalar values, not objects or resources.');
            }
        }
        return $result;
    }
}
