<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use PBreeze\Readers\CsvReader;
use PBreeze\Readers\JsonReader;
use PBreeze\Readers\ReaderInterface;
use PBreeze\Readers\XmlReader;
use PBreeze\Readers\YamlReader;

require_once __DIR__ . '/readers/ReaderInterface.php';
require_once __DIR__ . '/readers/Reader.php';
require_once __DIR__ . '/readers/CsvReader.php';
require_once __DIR__ . '/readers/JsonReader.php';
require_once __DIR__ . '/readers/XmlReader.php';
require_once __DIR__ . '/readers/YamlReader.php';

final class FileReader
{
    /** @var array<string, ReaderInterface> */
    private static array $readers = [];

    public static function read(string $path, array $options = []): array
    {
        return self::reader($path, $options)->read($path, $options);
    }

    public static function iterate(string $path, array $options = []): iterable
    {
        return self::reader($path, $options)->iterate($path, $options);
    }

    public static function register(string $format, ReaderInterface $reader): void
    {
        self::$readers[self::format($format)] = $reader;
    }

    private static function reader(string $path, array &$options): ReaderInterface
    {
        $format = array_key_exists('format', $options) ? $options['format'] : pathinfo($path, PATHINFO_EXTENSION);
        if (!is_string($format)) {
            throw new InvalidArgumentException('File format must be a string.');
        }
        $format = self::format($format);
        unset($options['format']);
        return self::$readers[$format] ?? match ($format) {
            'csv' => new CsvReader(),
            'json' => new JsonReader(),
            'xml' => new XmlReader(),
            'yaml', 'yml' => new YamlReader(),
            default => throw new InvalidArgumentException("Unsupported file format: $format"),
        };
    }

    private static function format(string $format): string
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]{0,31}$/D', $format)) {
            throw new InvalidArgumentException('Specify a supported file format or use a recognized extension.');
        }
        return strtolower($format);
    }
}
