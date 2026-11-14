<?php
declare(strict_types=1);

use PBreeze\Date;

test('Date utility provides static current-time operations without changing global timezone', static function (): void {
    $global = date_default_timezone_get();
    $before = time();
    $now = Date::now();
    check($now instanceof DateTimeImmutable);
    check($now->getTimestamp() >= $before && $now->getTimestamp() <= time());
    same('UTC', $now->getTimezone()->getName());
    same('Europe/Amsterdam', Date::now('Europe/Amsterdam')->getTimezone()->getName());
    same('00:00:00.000000', Date::startOfDay()->format('H:i:s.u'));
    check(preg_match('/^\d{4}-\d{2}-\d{2}$/D', Date::format('Y-m-d')) === 1);
    same('just now', Date::diffForHumans());
    check(abs(Date::addDays(5)->getTimestamp() - $now->getTimestamp() - 432000) <= 2);
    same($global, date_default_timezone_get());
});

test('Date parses ISO and SQL dates, timestamps and immutable copies with timezone conversion', static function (): void {
    foreach ([
        ['2024-02-29', '2024-02-29 00:00:00.000000 +00:00'],
        ['2026-11-14 12:34', '2026-11-14 12:34:00.000000 +00:00'],
        ['2026-11-14T12:34+01:00', '2026-11-14 12:34:00.000000 +01:00'],
        ['2026-11-14T12:34:56.123456Z', '2026-11-14 12:34:56.123456 +00:00'],
        ['2026-11-14T12:34:56+01:00', '2026-11-14 12:34:56.000000 +01:00'],
    ] as [$input, $expected]) {
        same($expected, Date::parse($input)->format('Y-m-d H:i:s.u P'));
    }
    same('1970-01-01', Date::format('Y-m-d', 0));
    same('1969-12-31 23:59:59', Date::format('Y-m-d H:i:s', -1));
    same('2026-11-14 11:34:56', Date::format('Y-m-d H:i:s', '2026-11-14T12:34:56+01:00', 'UTC'));
    $mutable = new DateTime('2026-11-14T12:34:56.123456+01:00');
    $copy = Date::parse($mutable);
    same($mutable->format('c.u'), $copy->format('c.u'));
    $later = Date::addDays(5, $mutable);
    same('2026-11-19', $later->format('Y-m-d'));
    same('2026-11-14', $mutable->format('Y-m-d'));
    same('+01:00', $copy->getTimezone()->getName());
});

test('Date rejects invalid or ambiguous string syntax and nonexistent local times explicitly', static function (): void {
    foreach (['', 'now', 'tomorrow', '11/14/2026', '2023-02-29', '2026-02-30', '2026-13-01',
        '2026-11-14T24:00:00Z', '2026-11-14T12:60:00Z', '2026-11-14T12:34:60Z',
        '2026-11-14T12:34:56+14:01', '2026-11-14.1', '2026-11-14Z', '2026-11-14T12:34.1Z',
        '2026-11-14T12:34:56.1234567Z', "2026-11-14\0", '2026-11-14 garbage'] as $input) {
        raises(static fn () => Date::parse($input), InvalidArgumentException::class);
    }
    raises(static fn () => Date::parse('2026-03-08 02:30:00', 'America/New_York'), InvalidArgumentException::class);
    foreach (['Invalid/Zone', '+01:00', 'CET', ''] as $timezone) {
        raises(static fn () => Date::now($timezone), InvalidArgumentException::class);
    }
    raises(static fn () => Date::addDays(PHP_INT_MAX), InvalidArgumentException::class);
    foreach (['', "\0", "\xff", str_repeat('Y', 257)] as $pattern) {
        raises(static fn () => Date::format($pattern), InvalidArgumentException::class);
    }
});

test('Date calendar-day arithmetic and day boundaries preserve local DST behavior and microseconds', static function (): void {
    $before = Date::parse('2026-03-07 12:00:00.123456', 'America/New_York');
    $after = Date::addDays(1, $before);
    same('2026-03-08 12:00:00.123456 -04:00', $after->format('Y-m-d H:i:s.u P'));
    same(23 * 3600, $after->getTimestamp() - $before->getTimestamp());
    same('2026-03-08 00:00:00.000000 -05:00', Date::startOfDay($after)->format('Y-m-d H:i:s.u P'));
    $fall = Date::parse('2026-10-31 12:00:00', 'America/New_York');
    same(25 * 3600, Date::addDays(1, $fall)->getTimestamp() - $fall->getTimestamp());
    same('2024-02-29', Date::format('Y-m-d', Date::addDays(-1, '2024-03-01')));
    same('2027-01-01', Date::format('Y-m-d', Date::addDays(1, '2026-12-31')));
    same($before->format('c.u'), Date::addDays(0, $before)->format('c.u'));
});

test('Date human differences use bounded elapsed-time units, correct direction and pluralization', static function (): void {
    $reference = Date::parse('2026-11-14T12:00:00Z');
    foreach ([1 => '1 second', 2 => '2 seconds', 59 => '59 seconds', 60 => '1 minute',
        120 => '2 minutes', 3600 => '1 hour', 86400 => '1 day', 604800 => '1 week',
        2592000 => '1 month', 31536000 => '1 year', 63072000 => '2 years'] as $seconds => $text) {
        same($text . ' ago', Date::diffForHumans($reference->getTimestamp() - $seconds, $reference));
        same('in ' . $text, Date::diffForHumans($reference->getTimestamp() + $seconds, $reference));
    }
    same('just now', Date::diffForHumans($reference, $reference));
    same('just now', Date::diffForHumans('2026-11-14T12:00:00.999999Z', $reference));
    same('just now', Date::diffForHumans('2026-11-14T13:00:00+01:00', $reference));
    same('1 minute ago', Date::diffForHumans($reference->getTimestamp() - 119, $reference));
});
