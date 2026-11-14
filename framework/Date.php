<?php
declare(strict_types=1);

namespace PBreeze;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class Date
{
    public static function now(string $timezone = 'UTC'): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::zone($timezone));
    }

    public static function parse(string|int|DateTimeInterface $value, ?string $timezone = null): DateTimeImmutable
    {
        $zone = $timezone === null ? null : self::zone($timezone);
        if ($value instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($value);
            return $zone === null ? $date : $date->setTimezone($zone);
        }
        if (is_int($value)) {
            return (new DateTimeImmutable('@' . $value))->setTimezone($zone ?? self::zone('UTC'));
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?)?)(?:\.\d{1,6})?(Z|[+-](?:0\d|1[0-4]):[0-5]\d)?$/D', $value, $matches)
            || (strlen($matches[1]) === 10 && isset($matches[2]))
            || (str_contains($value, '.') && strlen($matches[1]) !== 19)
            || (isset($matches[2]) && preg_match('/^[+-]14:(?!00)/', $matches[2]))) {
            throw new InvalidArgumentException('Use a date in YYYY-MM-DD or ISO/SQL date-time format, an integer Unix timestamp, or DateTimeInterface.');
        }
        try {
            $date = new DateTimeImmutable($value, $zone ?? self::zone('UTC'));
        } catch (DateMalformedStringException $error) {
            throw new InvalidArgumentException('Invalid date/time input.', 0, $error);
        }
        $errors = DateTimeImmutable::getLastErrors();
        $wall = str_replace('T', ' ', $matches[1]);
        $pattern = strlen($wall) === 10 ? 'Y-m-d' : (strlen($wall) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s');
        if (($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format($pattern) !== $wall) {
            throw new InvalidArgumentException('Invalid calendar date/time or nonexistent local time.');
        }
        return $zone !== null && !empty($matches[2]) ? $date->setTimezone($zone) : $date;
    }

    public static function addDays(int $days, string|int|DateTimeInterface|null $value = null, ?string $timezone = null): DateTimeImmutable
    {
        if ($days < -365000 || $days > 365000) {
            throw new InvalidArgumentException('Day offsets must be between -365000 and 365000.');
        }
        $date = self::base($value, $timezone);
        return $date->modify(($days >= 0 ? '+' : '') . $days . ' days');
    }

    public static function startOfDay(string|int|DateTimeInterface|null $value = null, ?string $timezone = null): DateTimeImmutable
    {
        return self::base($value, $timezone)->setTime(0, 0, 0, 0);
    }

    public static function format(string $pattern, string|int|DateTimeInterface|null $value = null, ?string $timezone = null): string
    {
        if ($pattern === '' || strlen($pattern) > 256 || preg_match('//u', $pattern) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $pattern)) {
            throw new InvalidArgumentException('Use a nonempty UTF-8 PHP date pattern of at most 256 bytes without controls.');
        }
        return self::base($value, $timezone)->format($pattern);
    }

    public static function diffForHumans(
        string|int|DateTimeInterface|null $value = null,
        string|int|DateTimeInterface|null $relativeTo = null,
    ): string {
        $reference = self::base($relativeTo, null);
        $date = $value === null ? $reference : self::parse($value);
        $difference = (float) $date->format('U.u') - (float) $reference->format('U.u');
        $seconds = abs($difference);
        if ($seconds < 1) {
            return 'just now';
        }
        foreach (['year' => 31536000, 'month' => 2592000, 'week' => 604800, 'day' => 86400, 'hour' => 3600, 'minute' => 60, 'second' => 1] as $unit => $length) {
            if ($seconds >= $length) {
                $count = (int) floor($seconds / $length);
                $text = $count . ' ' . $unit . ($count === 1 ? '' : 's');
                return $difference < 0 ? $text . ' ago' : 'in ' . $text;
            }
        }
        throw new \LogicException('Cannot determine the relative date/time unit.');
    }

    private static function base(string|int|DateTimeInterface|null $value, ?string $timezone): DateTimeImmutable
    {
        return $value === null ? self::now($timezone ?? 'UTC') : self::parse($value, $timezone);
    }

    private static function zone(string $timezone): DateTimeZone
    {
        if ($timezone !== 'UTC' && !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Use UTC or an IANA timezone identifier.');
        }
        return new DateTimeZone($timezone);
    }
}
