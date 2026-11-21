<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use RuntimeException;
use stdClass;

final class Translator
{
    public function __construct(
        private readonly string $directory,
        public readonly string $locale = 'en-US',
        public readonly string $fallbackLocale = 'en-US',
    ) {
        self::validateLocale($locale);
        self::validateLocale($fallbackLocale);
    }

    /** @param array<string, string|int|float> $replacements */
    public function get(string $key, array $replacements = [], ?string $locale = null): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('Translation keys must not be empty.');
        }
        $locale ??= $this->locale;
        self::validateLocale($locale);
        $tokens = [];
        foreach ($replacements as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)
                || (!is_string($value) && !is_int($value) && !is_float($value))) {
                throw new InvalidArgumentException('Translation replacements require named string or numeric values.');
            }
            $tokens['{' . $name . '}'] = (string) $value;
        }
        foreach (array_unique([$locale, $this->fallbackLocale]) as $candidate) {
            $catalog = $this->catalog($candidate);
            if (array_key_exists($key, $catalog)) {
                return strtr($catalog[$key], $tokens);
            }
        }
        throw new RuntimeException("Translation key not found: $key");
    }

    private static function validateLocale(string $locale): void
    {
        if (!preg_match('/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z]{4})?(?:[-_](?:[a-zA-Z]{2}|[0-9]{3}))?$/D', $locale)) {
            throw new InvalidArgumentException('Use a locale language tag with an optional script and region, such as en-US.');
        }
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        if ((file_exists($this->directory) || is_link($this->directory))
            && (!is_dir($this->directory) || !is_readable($this->directory))) {
            throw new RuntimeException('Cannot read the translation catalog directory.');
        }
        $path = $this->directory . '/' . $locale . '.json';
        if (!file_exists($path) && !is_link($path)) {
            return [];
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Cannot read translation catalog: $locale");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read translation catalog: $locale");
        }
        $data = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        if (!$data instanceof stdClass) {
            throw new RuntimeException("Translation catalog must be a JSON object: $locale");
        }
        $catalog = [];
        foreach ($data as $key => $value) {
            if ($key === '' || !is_string($value)) {
                throw new RuntimeException("Translation catalog keys must be nonempty and values must be strings: $locale");
            }
            $catalog[$key] = $value;
        }
        return $catalog;
    }
}
