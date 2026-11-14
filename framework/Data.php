<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use PBreeze\Documents\DocumentFile;
use PBreeze\Documents\Documents;
use RuntimeException;

require_once __DIR__ . '/FileReader.php';
require_once __DIR__ . '/Validator.php';
require_once __DIR__ . '/Filesystem.php';
require_once __DIR__ . '/documents/DocumentFile.php';
require_once __DIR__ . '/documents/Documents.php';

final class Data
{
    private function __construct(private readonly array $values)
    {
    }

    public static function from(array $values): self
    {
        return new self($values);
    }

    public static function read(string $path, array $options = []): self
    {
        return self::from(FileReader::read($path, $options));
    }

    public function filter(callable $predicate): self
    {
        $result = [];
        foreach ($this->values as $key => $value) {
            $keep = $predicate($value, $key);
            if (!is_bool($keep)) {
                throw new InvalidArgumentException('Data filter callbacks must return a boolean.');
            }
            if ($keep) {
                $result[$key] = $value;
            }
        }
        return new self(array_is_list($this->values) ? array_values($result) : $result);
    }

    public function map(callable|string $mapper): self
    {
        $callback = self::mapper($mapper);
        $result = [];
        foreach ($this->values as $key => $value) {
            $result[$key] = $callback($value, $key);
        }
        return new self($result);
    }

    public function transform(callable|string $mapper): self
    {
        $result = self::mapper($mapper)($this->values);
        if (!is_array($result)) {
            throw new InvalidArgumentException('Data transform must return an array for the whole document.');
        }
        return new self($result);
    }

    public function validate(array|string $rules, array $messages = [], array $attributes = []): self
    {
        if (is_string($rules)) {
            if (!class_exists($rules, false) || !is_callable([$rules, 'rules'])) {
                throw new InvalidArgumentException('Data rule classes must be explicitly loaded and expose public static rules(): array.');
            }
            $rules = $rules::rules();
            if (!is_array($rules)) {
                throw new InvalidArgumentException('Data rule classes must return a rule array.');
            }
        }
        return new self(Validator::make($this->values, $rules, $messages, $attributes)->validate());
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function write(string $path, array $options = []): self
    {
        $format = $options['format'] ?? pathinfo($path, PATHINFO_EXTENSION);
        if (!is_string($format)) {
            throw new InvalidArgumentException('Data output format must be a string.');
        }
        $format = strtolower($format);
        $allowed = match ($format) {
            'json', 'yaml', 'yml' => ['format', 'max_bytes'],
            'csv' => ['format', 'max_bytes', 'headers', 'delimiter', 'protect_formulas'],
            'xml' => ['format', 'max_bytes', 'root'],
            default => throw new InvalidArgumentException('Data writes support JSON, CSV, XML, YAML, and YML.'),
        };
        if (array_diff(array_keys($options), $allowed) !== []) {
            throw new InvalidArgumentException('Unsupported Data writer option.');
        }
        $limit = $options['max_bytes'] ?? 16777216;
        if (!is_int($limit) || $limit < 1 || $limit > 268435456) {
            throw new InvalidArgumentException('Data writer max_bytes must be 1..268435456.');
        }
        $count = 0;
        self::exportable($this->values, 0, $count);
        $document = match ($format) {
            'json' => Documents::json($this->values),
            'csv' => $this->csv($options),
            'xml' => $this->xml($options),
            'yaml', 'yml' => $this->yaml($format),
        };
        if (strlen($document->contents) > $limit) {
            throw new RuntimeException('Transformed output exceeds the configured maximum byte size.');
        }
        $document->save($path);
        return $this;
    }

    private static function mapper(callable|string $mapper): callable
    {
        if (is_string($mapper) && class_exists($mapper, false)) {
            if (!is_callable([$mapper, 'fromArray'])) {
                throw new InvalidArgumentException('Data mapper classes must expose public static fromArray(array $data).');
            }
            return static function (mixed $value) use ($mapper): mixed {
                if (!is_array($value)) {
                    throw new InvalidArgumentException('Class-based Data mapping requires array input.');
                }
                return $mapper::fromArray($value);
            };
        }
        if (!is_callable($mapper)) {
            throw new InvalidArgumentException('Use a callable mapper or an explicitly loaded fromArray() class.');
        }
        return $mapper;
    }

    private function csv(array $options): DocumentFile
    {
        $headers = $options['headers'] ?? true;
        $delimiter = $options['delimiter'] ?? ',';
        $protect = $options['protect_formulas'] ?? true;
        if (!is_bool($headers) || !is_bool($protect) || !is_string($delimiter) || !array_is_list($this->values)) {
            throw new InvalidArgumentException('CSV output requires a list of rows, boolean headers/protect_formulas and a string delimiter.');
        }
        $rows = [];
        $columns = null;
        foreach ($this->values as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('CSV output entries must be arrays.');
            }
            if ($columns === null) {
                $columns = array_keys($row);
                if ($headers) {
                    foreach ($columns as $column) {
                        if (!is_string($column) || $column === '') {
                            throw new InvalidArgumentException('CSV headers require nonempty string column names; use headers=false for positional rows.');
                        }
                    }
                    $rows[] = $columns;
                }
            } elseif (array_keys($row) !== $columns) {
                throw new InvalidArgumentException('CSV rows must have identical column keys in the same order.');
            }
            $rows[] = array_values($row);
        }
        return Documents::csv($rows, $delimiter, $protect);
    }

    private function xml(array $options): DocumentFile
    {
        $root = $options['root'] ?? 'document';
        if (!is_string($root)) {
            throw new InvalidArgumentException('XML output root must be a string.');
        }
        return Documents::xml($this->values, $root);
    }

    private function yaml(string $format): DocumentFile
    {
        if (!extension_loaded('yaml') || !function_exists('yaml_emit')) {
            throw new RuntimeException('YAML output requires the native PHP yaml extension.');
        }
        $content = yaml_emit($this->values, YAML_UTF8_ENCODING);
        if ($content === false) {
            throw new RuntimeException('Cannot encode transformed YAML data.');
        }
        return new DocumentFile($content, 'application/yaml', $format);
    }

    private static function exportable(mixed $value, int $depth, int &$count): void
    {
        if ($depth > 64 || ++$count > 100000) {
            throw new InvalidArgumentException('Data exports support at most 64 levels and 100000 values; cycles are not allowed.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key)) {
                    Documents::text($key);
                }
                self::exportable($child, $depth + 1, $count);
            }
        } elseif (is_string($value)) {
            Documents::text($value);
        } elseif ($value !== null && !is_bool($value) && !is_int($value) && !(is_float($value) && is_finite($value))) {
            throw new InvalidArgumentException('Data exports require arrays, UTF-8 text, finite numbers, booleans, or null. Convert DTOs explicitly before writing.');
        }
    }
}
