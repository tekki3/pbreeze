<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Formatter;
use PBreeze\View;

test('Date/time formatting supports styles, ICU patterns, timestamps, and per-call locale overrides', static function (): void {
    $format = new Formatter();
    $date = new DateTimeImmutable('2026-11-13T15:45:06+00:00');
    same('11/13/26', $format->date($date, 'short'));
    same('Nov 13, 2026', $format->date($date));
    same('November 13, 2026', $format->date($date, 'long'));
    same('Friday, November 13, 2026', $format->date($date, 'full'));
    same('13.11.26', $format->date($date, 'short', 'de-DE'));
    same('2026-11-13 15:45:06', $format->dateTime($date, pattern: 'yyyy-MM-dd HH:mm:ss'));
    same('2026-11-13 15:45:06', $format->dateTime($date->getTimestamp(), pattern: 'yyyy-MM-dd HH:mm:ss'));
    foreach (['short', 'medium', 'long', 'full'] as $style) {
        $native = new IntlDateFormatter('en_US', IntlDateFormatter::NONE, [
            'short' => IntlDateFormatter::SHORT, 'medium' => IntlDateFormatter::MEDIUM,
            'long' => IntlDateFormatter::LONG, 'full' => IntlDateFormatter::FULL,
        ][$style], 'UTC', IntlDateFormatter::GREGORIAN);
        same($native->format($date), $format->time($date, $style));
    }
    same('1970-01-01', $format->dateTime(0, pattern: 'yyyy-MM-dd'));
    same('1969-12-31', $format->dateTime(-1, pattern: 'yyyy-MM-dd'));
});

test('Time zones handle daylight saving transitions without changing dates or global defaults', static function (): void {
    $format = new Formatter(timezone: 'America/New_York');
    $locale = Locale::getDefault();
    $timezone = date_default_timezone_get();
    $date = new DateTime('2026-03-08T06:59:00Z');
    same('2026-03-08 01:59 -05:00', $format->dateTime($date, pattern: 'yyyy-MM-dd HH:mm XXX'));
    same('2026-03-08 03:00 -04:00', $format->dateTime(new DateTimeImmutable('2026-03-08T07:00:00Z'), pattern: 'yyyy-MM-dd HH:mm XXX'));
    same('2026-03-08 06:59 Z', $format->dateTime($date, timezone: 'UTC', pattern: 'yyyy-MM-dd HH:mm XXX'));
    same('UTC', $date->getTimezone()->getName() === 'Z' ? 'UTC' : $date->getTimezone()->getName());
    same('2026-03-08T06:59:00+00:00', $date->format(DateTimeInterface::ATOM));
    same($locale, Locale::getDefault());
    same($timezone, date_default_timezone_get());
});

test('Decimal formatting rounds exact strings half away from zero without floating-point conversion', static function (): void {
    $format = new Formatter();
    same('1,234.50', $format->decimal('1234.5'));
    same('1,234', $format->decimal(1234, 0));
    same('1.01', $format->decimal('1.005'));
    same('-1.01', $format->decimal('-1.005'));
    same('10.00', $format->decimal('9.999'));
    same('0.00', $format->decimal('-0.004'));
    same('0.00', $format->decimal('-0'));
    same('1234.500', $format->decimal('001234.5', 3, grouping: false));
    same('1,000,000,000,000,000,000,000.00', $format->decimal('999999999999999999999.995'));
    same('9,007,199,254,740,993.01', $format->decimal('9007199254740993.005'));
    same('0.12345678901234567890', $format->decimal('0.12345678901234567890', 20));
    same('1.234,50', $format->decimal('1234.5', locale: 'de-DE'));
    same('1,23,45,678.50', $format->decimal('12345678.5', locale: 'hi-IN'));
});

test('Localized decimals match native ICU grouping and digit rules across locales', static function (): void {
    $format = new Formatter();
    foreach (['en-US', 'nl-NL', 'de-DE', 'fr-FR', 'es-ES', 'pl-PL', 'hi-IN', 'ar-EG', 'fa-IR', 'bn-BD', 'ja-JP', 'de-CH'] as $locale) {
        $native = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $native->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $native->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);
        foreach (['0', '1', '1234.5', '12345.5', '12345678.5', '-1234.5'] as $value) {
            same($native->format((float) $value), $format->decimal($value, 2, $locale));
        }
        $native->setAttribute(NumberFormatter::GROUPING_USED, 0);
        same($native->format(12345.5), $format->decimal('12345.5', 2, $locale, false));
    }
});

test('Money formatting uses currency-specific precision, exact rounding, symbols, and affix spacing', static function (): void {
    $format = new Formatter();
    same('$1,234.57', $format->money('1234.565'));
    same('-$1.01', $format->money('-1.005'));
    same('$0.00', $format->money('-0.004'));
    same('$9,007,199,254,740,993.01', $format->money('9007199254740993.005'));
    foreach (['en-US', 'nl-NL', 'de-DE', 'fr-FR', 'es-ES', 'hi-IN', 'ar-EG', 'fa-IR'] as $locale) {
        foreach (['USD', 'EUR', 'GBP', 'JPY', 'KWD', 'CHF', 'CAD', 'CLF'] as $currency) {
            $native = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            foreach (['0', '1', '1234', '-1234', '12345678'] as $amount) {
                same($native->formatCurrency((float) $amount, $currency), $format->money($amount, $currency, $locale));
            }
        }
    }
    same('¥1,235', $format->money('1234.5', 'JPY'));
    check(str_ends_with($format->money('1.2345', 'KWD'), '1.235'));
    same('$1234.50', $format->money('1234.5', grouping: false));
});

test('Integer minor units include zero-, three-, and four-decimal currencies and integer extremes', static function (): void {
    $format = new Formatter();
    same('$123.45', $format->moneyMinor(12345));
    same('-$0.01', $format->moneyMinor(-1));
    same('$0.00', $format->moneyMinor(0));
    same('¥12,345', $format->moneyMinor(12345, 'JPY'));
    same($format->money('12.345', 'KWD'), $format->moneyMinor(12345, 'KWD'));
    same($format->money('1.2345', 'CLF'), $format->moneyMinor(12345, 'CLF'));
    $min = (string) PHP_INT_MIN;
    $max = (string) PHP_INT_MAX;
    same($format->money(substr($min, 0, -2) . '.' . substr($min, -2)), $format->moneyMinor(PHP_INT_MIN));
    same($format->money(substr($max, 0, -2) . '.' . substr($max, -2)), $format->moneyMinor(PHP_INT_MAX));
});

test('Percent formatting scales ratios exactly with localized symbols, rounding, and grouping', static function (): void {
    $format = new Formatter();
    same('12.50%', $format->percent('0.125'));
    same('0.10%', $format->percent('0.001'));
    same('1.01%', $format->percent('0.01005'));
    same('-1.01%', $format->percent('-0.01005'));
    same('100.00%', $format->percent(1));
    same('0.00%', $format->percent('-0.00001'));
    same('12,345.00%', $format->percent('123.45'));
    same('12345.00%', $format->percent('123.45', grouping: false));
    same('90,071,992,547,409,930.01%', $format->percent('900719925474099.30005'));
    same(str_repeat('1', 1024) . '00%', $format->percent(str_repeat('1', 1024), 0, grouping: false));
    foreach (['en-US', 'de-DE', 'fr-FR', 'es-ES', 'hi-IN', 'ar-EG', 'fa-IR'] as $locale) {
        $native = new NumberFormatter($locale, NumberFormatter::PERCENT);
        $native->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $native->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);
        foreach (['0', '0.125', '-0.125', '12.5', '1234.5'] as $amount) {
            same($native->format((float) $amount), $format->percent($amount, 2, $locale));
        }
    }
});

test('Formatting rejects unsupported locales, time zones, styles, currencies, and ambiguous numbers', static function (): void {
    $format = new Formatter();
    foreach (['', "en-US\0", 'en@currency=USD', '../../en', 'en-US-extra'] as $locale) {
        raises(static fn () => new Formatter($locale), InvalidArgumentException::class);
    }
    raises(static fn () => $format->decimal('1', locale: 'zz-ZZ'), InvalidArgumentException::class);
    foreach (['', 'Invalid/Zone', '+02:00'] as $zone) {
        raises(static fn () => new Formatter(timezone: $zone), InvalidArgumentException::class);
        raises(static fn () => $format->date(0, timezone: $zone), InvalidArgumentException::class);
    }
    foreach (['', '1,234', '1 234', ' 1', '1 ', '+1', '.5', '1.', '1e3', '--1', 'NaN', 'INF', str_repeat('1', 1025)] as $amount) {
        raises(static fn () => $format->money($amount), InvalidArgumentException::class);
        raises(static fn () => $format->decimal($amount), InvalidArgumentException::class);
        raises(static fn () => $format->percent($amount), InvalidArgumentException::class);
    }
    foreach ([-1, 21] as $precision) {
        raises(static fn () => $format->decimal('1', $precision), InvalidArgumentException::class);
        raises(static fn () => $format->percent('1', $precision), InvalidArgumentException::class);
    }
    foreach (['usd', 'EURO', '', '$', "USD\n"] as $currency) {
        raises(static fn () => $format->money('1', $currency), InvalidArgumentException::class);
        raises(static fn () => $format->moneyMinor(1, $currency), InvalidArgumentException::class);
    }
    raises(static fn () => $format->money(1.005), TypeError::class);
    raises(static fn () => $format->moneyMinor(1.5), TypeError::class);
    raises(static fn () => $format->date(0, 'invalid'), InvalidArgumentException::class);
    raises(static fn () => $format->dateTime(0, 'none', 'none'), InvalidArgumentException::class);
    foreach (['', "\xff", "yyyy\nMM", str_repeat('y', 257)] as $pattern) {
        raises(static fn () => $format->dateTime(0, pattern: $pattern), InvalidArgumentException::class);
    }
});

test('Application and Breeze templates share configured formatters, including includes and components', static function () use ($temp, $config): void {
    $app = new Application($temp, array_replace($config, ['locale' => 'de-DE', 'timezone' => 'Europe/Berlin']));
    same('de-DE', $app->format->locale);
    same('Europe/Berlin', $app->format->timezone);
    same($app->format, $app->views->format);
    file_put_contents($temp . '/app/views/format-child.breeze.php', '{{ $format->decimal("1234.5") }}');
    file_put_contents($temp . '/app/views/format-component.breeze.php', '{{ $format->dateTime(0, pattern: "HH:mm") }}');
    file_put_contents($temp . '/app/views/format-test.breeze.php',
        '{{ $format->dateTime(0, pattern: "yyyy-MM-dd HH:mm") }}|@include("format-child")|@component("format-component")@endcomponent|{{ $format->money("1.005", "USD", "en-US") }}');
    same('1970-01-01 01:00|1.234,50|01:00|$1.01', $app->views->render('format-test', ['format' => 'cannot override the service']));
    file_put_contents($temp . '/app/views/format-plain.php', '<?= e($format->decimal("1234.5")) ?>');
    same('1.234,50', $app->views->render('format-plain'));
    $view = new View($temp . '/app/views');
    same('en-US', $view->format->locale);
    same('UTC', $view->format->timezone);
});
