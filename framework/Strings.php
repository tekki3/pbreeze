<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use RuntimeException;

final class Strings
{
    public function lower(string $text): string
    {
        return $this->convert($text, 'lower');
    }

    public function upper(string $text): string
    {
        return $this->convert($text, 'upper');
    }

    public function title(string $text): string
    {
        return $this->convert($text, 'title');
    }

    public function trim(string $text): string
    {
        $this->valid($text);
        return preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $text)
            ?? throw new RuntimeException('Cannot trim the string.');
    }

    public function length(string $text): int
    {
        $this->unicode($text);
        $length = grapheme_strlen($text);
        return $length === false ? throw new RuntimeException('Cannot measure the string.') : $length;
    }

    public function slice(string $text, int $offset, ?int $length = null): string
    {
        $size = $this->length($text);
        $offset = $offset < 0 ? max(0, $size + $offset) : min($offset, $size);
        $available = $size - $offset;
        $length = $length === null ? $available : ($length < 0 ? max(0, $available + $length) : min($length, $available));
        if ($length === 0) {
            return '';
        }
        $result = grapheme_substr($text, $offset, $length);
        return $result === false ? throw new RuntimeException('Cannot slice the string.') : $result;
    }

    public function contains(string $text, string $needle): bool
    {
        $this->valid($text);
        $this->valid($needle);
        return str_contains($text, $needle);
    }

    public function startsWith(string $text, string $prefix): bool
    {
        $this->valid($text);
        $this->valid($prefix);
        return str_starts_with($text, $prefix);
    }

    public function endsWith(string $text, string $suffix): bool
    {
        $this->valid($text);
        $this->valid($suffix);
        return str_ends_with($text, $suffix);
    }

    public function before(string $text, string $delimiter): string
    {
        $this->delimiter($text, $delimiter);
        $position = strpos($text, $delimiter);
        return $position === false ? $text : substr($text, 0, $position);
    }

    public function after(string $text, string $delimiter): string
    {
        $this->delimiter($text, $delimiter);
        $position = strpos($text, $delimiter);
        return $position === false ? $text : substr($text, $position + strlen($delimiter));
    }

    public function replace(string $text, string $search, string $replacement): string
    {
        $this->delimiter($text, $search);
        $this->valid($replacement);
        return str_replace($search, $replacement, $text);
    }

    public function truncate(string $text, int $limit = 100, string $suffix = '...'): string
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('The string limit must not be negative.');
        }
        $size = $this->length($text);
        $suffixSize = $this->length($suffix);
        if ($size <= $limit) {
            return $text;
        }
        if ($suffixSize > $limit) {
            return $this->slice($suffix, 0, $limit);
        }
        return $this->slice($text, 0, $limit - $suffixSize) . $suffix;
    }

    public function slug(string $text, string $separator = '-'): string
    {
        $this->unicode($text);
        if (!in_array($separator, ['-', '_'], true)) {
            throw new InvalidArgumentException('Use a hyphen or underscore slug separator.');
        }
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
        if ($ascii === false) {
            throw new RuntimeException('Cannot transliterate the string.');
        }
        $slug = preg_replace('/[^a-z0-9]+/', $separator, $ascii);
        return $slug === null ? throw new RuntimeException('Cannot create a slug.') : trim($slug, $separator);
    }

    public function snake(string $text): string
    {
        return implode('_', array_map($this->lower(...), $this->words($text)));
    }

    public function studly(string $text): string
    {
        return implode('', array_map(
            fn (string $word): string => $this->upper($this->slice($word, 0, 1)) . $this->lower($this->slice($word, 1)),
            $this->words($text),
        ));
    }

    public function camel(string $text): string
    {
        $studly = $this->studly($text);
        return $this->lower($this->slice($studly, 0, 1)) . $this->slice($studly, 1);
    }

    public function random(int $length = 32, string $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'): string
    {
        if ($length < 1 || $length > 65536 || strlen($alphabet) < 2 || strlen($alphabet) > 94
            || preg_match('/^[!-~]+$/D', $alphabet) !== 1
            || count(array_unique(str_split($alphabet))) !== strlen($alphabet)) {
            throw new InvalidArgumentException('Use length 1..65536 and 2..94 distinct printable non-space ASCII alphabet characters.');
        }
        $size = strlen($alphabet);
        $ceiling = intdiv(256, $size) * $size;
        $result = '';
        while (strlen($result) < $length) {
            foreach (unpack('C*', random_bytes(min(4096, $length - strlen($result) + 32))) as $byte) {
                if ($byte < $ceiling) {
                    $result .= $alphabet[$byte % $size];
                    if (strlen($result) === $length) {
                        break;
                    }
                }
            }
        }
        return $result;
    }

    private function valid(string $text): void
    {
        if (preg_match('//u', $text) !== 1) {
            throw new InvalidArgumentException('Strings must contain valid UTF-8.');
        }
    }

    private function unicode(string $text): void
    {
        $this->valid($text);
        if (!extension_loaded('intl')) {
            throw new RuntimeException('Unicode string operations require the native intl extension.');
        }
    }

    private function convert(string $text, string $mode): string
    {
        $this->valid($text);
        if (!extension_loaded('mbstring')) {
            throw new RuntimeException('String case conversion requires the native mbstring extension.');
        }
        return mb_convert_case($text, match ($mode) {
            'lower' => MB_CASE_LOWER,
            'upper' => MB_CASE_UPPER,
            'title' => MB_CASE_TITLE,
        }, 'UTF-8');
    }

    private function delimiter(string $text, string $delimiter): void
    {
        $this->valid($text);
        $this->valid($delimiter);
        if ($delimiter === '') {
            throw new InvalidArgumentException('The string delimiter/search must not be empty.');
        }
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        $this->unicode($text);
        $text = preg_replace('/(\p{Lu})(\p{Lu}\p{Ll})/u', '$1 $2', $text)
            ?? throw new RuntimeException('Cannot split string words.');
        $text = preg_replace('/([\p{Ll}\p{N}])(\p{Lu})/u', '$1 $2', $text)
            ?? throw new RuntimeException('Cannot split string words.');
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $words === false ? throw new RuntimeException('Cannot split string words.') : $words;
    }
}
