<?php
declare(strict_types=1);

namespace PBreeze\Readers;

use InvalidArgumentException;
use RuntimeException;

final class CsvReader extends Reader
{
    public function read(string $path, array $options = []): array
    {
        return iterator_to_array($this->iterate($path, $options), false);
    }

    public function iterate(string $path, array $options = []): iterable
    {
        $options = $this->options($options, [
            'headers' => true, 'delimiter' => ',', 'enclosure' => '"', 'escape' => '',
            'skip_empty_lines' => true,
        ]);
        foreach (['delimiter', 'enclosure', 'escape'] as $name) {
            $value = $options[$name];
            if (!is_string($value) || (strlen($value) !== 1 && !($name === 'escape' && $value === ''))
                || ($value !== '' && str_contains("\r\n\0", $value))) {
                throw new InvalidArgumentException("$name must be one non-newline byte (escape may also be empty).");
            }
        }
        if ($options['delimiter'] === $options['enclosure']
            || ($options['escape'] !== '' && in_array($options['escape'], [$options['delimiter'], $options['enclosure']], true))) {
            throw new InvalidArgumentException('CSV delimiter, enclosure, and nonempty escape must differ.');
        }
        if (!is_bool($options['headers']) && !is_array($options['headers'])
            || !is_bool($options['skip_empty_lines'])) {
            throw new InvalidArgumentException('headers must be a boolean or list of names; skip_empty_lines must be boolean.');
        }
        $headers = is_array($options['headers']) ? $this->headers($options['headers']) : null;
        $stream = $this->open($path, $options['max_bytes']);
        try {
            $prefix = fread($stream, 4);
            if ($prefix === false || !rewind($stream)) {
                throw new RuntimeException('Cannot read the CSV encoding prefix.');
            }
            if (str_starts_with($prefix, "\xff\xfe") || str_starts_with($prefix, "\xfe\xff")
                || str_starts_with($prefix, "\0\0\xfe\xff")) {
                throw new RuntimeException('CSV must be UTF-8, not UTF-16 or UTF-32.');
            }
            if (str_starts_with($prefix, "\xef\xbb\xbf") && fseek($stream, 3) !== 0) {
                throw new RuntimeException('Cannot skip the CSV BOM.');
            }
            $record = 0;
            while (!feof($stream)) {
                $start = ftell($stream);
                $row = fgetcsv($stream, null, $options['delimiter'], $options['enclosure'], $options['escape']);
                $end = ftell($stream);
                if ($start === false || $end === false || $end > $options['max_bytes']) {
                    throw new RuntimeException('CSV exceeds max_bytes or cannot be read.');
                }
                if ($row === false) {
                    if (!feof($stream)) {
                        throw new RuntimeException('Cannot read the CSV record.');
                    }
                    break;
                }
                $record++;
                if (fseek($stream, $start) !== 0) {
                    throw new RuntimeException('Cannot validate the CSV record.');
                }
                $raw = $end === $start ? '' : fread($stream, $end - $start);
                if ($raw === false || strlen($raw) !== $end - $start) {
                    throw new RuntimeException('Cannot read the CSV record for validation.');
                }
                $this->validateRecord($raw, $options, $record);
                if ($row === [null] && $options['skip_empty_lines']) {
                    continue;
                }
                $row = array_map(static fn (?string $value): string => $value ?? '', $row);
                if ($headers === null && $options['headers'] === true) {
                    try {
                        $headers = $this->headers($row);
                    } catch (InvalidArgumentException $error) {
                        throw new RuntimeException("Invalid CSV headers in record $record: " . $error->getMessage(), 0, $error);
                    }
                    continue;
                }
                if ($headers !== null) {
                    if (count($row) !== count($headers)) {
                        throw new RuntimeException("CSV record $record does not match the header column count.");
                    }
                    yield array_combine($headers, $row);
                } else {
                    yield $row;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private function headers(array $headers): array
    {
        if ($headers === [] || !array_is_list($headers)) {
            throw new InvalidArgumentException('CSV headers must be a nonempty list of unique, nonempty strings.');
        }
        foreach ($headers as $name) {
            if (!is_string($name) || $name === '' || str_contains($name, "\0")) {
                throw new InvalidArgumentException('CSV headers must be unique, nonempty strings without NUL bytes.');
            }
            if (preg_match('//u', $name) !== 1) {
                throw new InvalidArgumentException('CSV headers must contain valid UTF-8.');
            }
        }
        if (count(array_unique($headers)) !== count($headers)) {
            throw new InvalidArgumentException('CSV headers must be unique.');
        }
        return $headers;
    }

    private function validateRecord(string $raw, array $options, int $record): void
    {
        $this->utf8($raw);
        $state = 'start';
        $length = strlen(rtrim($raw, "\r\n"));
        for ($index = 0; $index < $length; $index++) {
            $char = $raw[$index];
            if ($state === 'quoted') {
                if ($options['escape'] !== '' && $char === $options['escape']
                    && $index + 1 < $length && in_array($raw[$index + 1], [$options['escape'], $options['enclosure']], true)) {
                    $index++;
                } elseif ($char === $options['enclosure']) {
                    if ($index + 1 < $length && $raw[$index + 1] === $options['enclosure']) {
                        $index++;
                    } else {
                        $state = 'closed';
                    }
                }
            } elseif ($char === $options['delimiter']) {
                $state = 'start';
            } elseif ($char === $options['enclosure'] && $state === 'start') {
                $state = 'quoted';
            } elseif ($state === 'closed' || $char === $options['enclosure'] || $char === "\r" || $char === "\n") {
                throw new RuntimeException("Invalid quoting in CSV record $record.");
            } else {
                $state = 'unquoted';
            }
        }
        if ($state === 'quoted') {
            throw new RuntimeException("Unterminated quoted field in CSV record $record.");
        }
    }
}
