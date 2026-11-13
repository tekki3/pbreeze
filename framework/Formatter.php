<?php
declare(strict_types=1);

namespace PBreeze;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use InvalidArgumentException;
use Locale;
use NumberFormatter;
use ResourceBundle;
use RuntimeException;

final class Formatter
{
    public function __construct(
        public readonly string $locale = 'en-US',
        public readonly string $timezone = 'UTC',
    ) {
        self::localeSyntax($locale);
        self::zone($timezone);
    }

    public function date(DateTimeInterface|int $value, string $style = 'medium', ?string $locale = null, ?string $timezone = null): string
    {
        return $this->dateTime($value, $style, 'none', $locale, $timezone);
    }

    public function time(DateTimeInterface|int $value, string $style = 'medium', ?string $locale = null, ?string $timezone = null): string
    {
        return $this->dateTime($value, 'none', $style, $locale, $timezone);
    }

    public function dateTime(
        DateTimeInterface|int $value,
        string $dateStyle = 'medium',
        string $timeStyle = 'short',
        ?string $locale = null,
        ?string $timezone = null,
        ?string $pattern = null,
    ): string {
        $locale = $this->resolveLocale($locale);
        $zone = self::zone($timezone ?? $this->timezone);
        $styles = [
            'none' => IntlDateFormatter::NONE, 'short' => IntlDateFormatter::SHORT,
            'medium' => IntlDateFormatter::MEDIUM, 'long' => IntlDateFormatter::LONG,
            'full' => IntlDateFormatter::FULL,
        ];
        if (!isset($styles[$dateStyle], $styles[$timeStyle])
            || ($pattern === null && $dateStyle === 'none' && $timeStyle === 'none')) {
            throw new InvalidArgumentException('Use short, medium, long, full, or none date/time styles, with at least one visible style.');
        }
        if ($pattern !== null && ($pattern === '' || strlen($pattern) > 256 || preg_match('//u', $pattern) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $pattern))) {
            throw new InvalidArgumentException('Use a nonempty UTF-8 ICU date pattern of at most 256 bytes without control characters.');
        }
        $date = is_int($value) ? new DateTimeImmutable('@' . $value) : DateTimeImmutable::createFromInterface($value);
        $formatter = new IntlDateFormatter($locale, $styles[$dateStyle], $styles[$timeStyle], $zone, IntlDateFormatter::GREGORIAN, $pattern);
        $result = $formatter->format($date);
        if ($result === false) {
            throw new RuntimeException('Cannot format the date/time: ' . $formatter->getErrorMessage());
        }
        return $result;
    }

    public function decimal(
        string|int $value,
        int $precision = 2,
        ?string $locale = null,
        bool $grouping = true,
    ): string {
        $formatter = $this->number($locale, 'decimal');
        return self::renderNumber($value, $precision, $formatter, $grouping);
    }

    /** The input is a ratio: "0.125" becomes 12.50% at precision 2. */
    public function percent(string|int $value, int $precision = 2, ?string $locale = null, bool $grouping = true): string
    {
        return self::renderNumber($value, $precision, $this->number($locale, 'percent'), $grouping, 2);
    }

    public function money(string $amount, string $currency = 'USD', ?string $locale = null, bool $grouping = true): string
    {
        $formatter = $this->currency($currency, $locale);
        return self::renderNumber($amount, self::currencyPrecision($formatter), $formatter, $grouping);
    }

    public function moneyMinor(int $amount, string $currency = 'USD', ?string $locale = null, bool $grouping = true): string
    {
        $formatter = $this->currency($currency, $locale);
        $precision = self::currencyPrecision($formatter);
        $text = (string) $amount;
        $negative = str_starts_with($text, '-');
        $digits = str_pad(ltrim($text, '-'), $precision + 1, '0', STR_PAD_LEFT);
        $decimal = $precision === 0 ? $digits : substr($digits, 0, -$precision) . '.' . substr($digits, -$precision);
        return self::renderNumber(($negative ? '-' : '') . $decimal, $precision, $formatter, $grouping);
    }

    private function currency(string $currency, ?string $locale): NumberFormatter
    {
        if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Use an uppercase three-letter ISO 4217 currency code.');
        }
        $formatter = $this->number($locale, 'currency');
        if (!$formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency)) {
            throw new RuntimeException('Cannot configure the currency: ' . $formatter->getErrorMessage());
        }
        return $formatter;
    }

    private static function currencyPrecision(NumberFormatter $formatter): int
    {
        $digits = $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
        if (!is_int($digits) || $digits < 0 || $digits > 20) {
            throw new RuntimeException('Cannot determine currency precision.');
        }
        return $digits;
    }

    private function number(?string $locale, string $style): NumberFormatter
    {
        $locale = $this->resolveLocale($locale);
        return new NumberFormatter($locale, match ($style) {
            'decimal' => NumberFormatter::DECIMAL,
            'percent' => NumberFormatter::PERCENT,
            'currency' => NumberFormatter::CURRENCY,
        });
    }

    private function resolveLocale(?string $locale): string
    {
        if (!extension_loaded('intl')) {
            throw new RuntimeException('Localized formatting requires the native intl extension.');
        }
        $locale ??= $this->locale;
        self::localeSyntax($locale);
        $available = ResourceBundle::getLocales('');
        if ($available === false || Locale::lookup($available, $locale, true, '') === '') {
            throw new InvalidArgumentException('The formatting locale is not supported by this ICU installation.');
        }
        return str_replace('-', '_', $locale);
    }

    private static function localeSyntax(string $locale): void
    {
        if (!preg_match('/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z]{4})?(?:[-_](?:[a-zA-Z]{2}|[0-9]{3}))?$/D', $locale)) {
            throw new InvalidArgumentException('Use a locale language tag with an optional script and region, such as en-US.');
        }
    }

    private static function zone(string $timezone): DateTimeZone
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Use a recognized IANA time zone, such as UTC or America/New_York.');
        }
        return new DateTimeZone($timezone);
    }

    /** @return array{bool, string, string} */
    private static function parts(string|int $value): array
    {
        $value = (string) $value;
        if (strlen($value) > 1024 || !preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('Use an integer or decimal string without whitespace, grouping, or exponent notation (at most 1024 bytes).');
        }
        return [$matches[1] === '-', ltrim($matches[2], '0') ?: '0', $matches[3] ?? ''];
    }

    /** @return array{bool, string, string} */
    private static function rounded(string|int $value, int $precision, int $shift): array
    {
        if ($precision < 0 || $precision > 20) {
            throw new InvalidArgumentException('Formatting precision must be between 0 and 20.');
        }
        [$negative, $whole, $fraction] = self::parts($value);
        if ($shift > 0) {
            $fraction = str_pad($fraction, $shift, '0');
            $whole = ltrim($whole . substr($fraction, 0, $shift), '0') ?: '0';
            $fraction = substr($fraction, $shift);
        }
        $digits = $whole . str_pad(substr($fraction, 0, $precision), $precision, '0');
        if (isset($fraction[$precision]) && $fraction[$precision] >= '5') {
            $carry = true;
            for ($index = strlen($digits) - 1; $index >= 0 && $carry; $index--) {
                if ($digits[$index] === '9') {
                    $digits[$index] = '0';
                } else {
                    $digits[$index] = chr(ord($digits[$index]) + 1);
                    $carry = false;
                }
            }
            if ($carry) {
                $digits = '1' . $digits;
            }
        }
        $negative = $negative && trim($digits, '0') !== '';
        return [$negative, $precision === 0 ? $digits : substr($digits, 0, -$precision),
            $precision === 0 ? '' : substr($digits, -$precision)];
    }

    private static function renderNumber(string|int $value, int $precision, NumberFormatter $formatter, bool $grouping, int $shift = 0): string
    {
        [$negative, $whole, $fraction] = self::rounded($value, $precision, $shift);
        $pattern = $formatter->getPattern();
        $sample = $formatter->format($negative ? -1 : 1);
        if ($pattern === false || $sample === false
            || !preg_match('/\A([^\p{Nd}]*)(?:\p{Nd}.*\p{Nd}|\p{Nd})([^\p{Nd}]*)\z/us', $sample, $affixes)) {
            throw new RuntimeException('Cannot determine locale number affixes.');
        }
        // Formatting a tiny sample also applies ICU currency spacing and bidi marks.
        $prefix = $affixes[1];
        $suffix = $affixes[2];
        $isCurrency = str_contains($pattern, '¤');
        $decimal = $formatter->getSymbol($isCurrency ? NumberFormatter::MONETARY_SEPARATOR_SYMBOL : NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        $separator = $formatter->getSymbol($isCurrency ? NumberFormatter::MONETARY_GROUPING_SEPARATOR_SYMBOL : NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
        $primary = $formatter->getAttribute(NumberFormatter::GROUPING_SIZE);
        $secondary = $formatter->getAttribute(NumberFormatter::SECONDARY_GROUPING_SIZE);
        if ($decimal === false || $separator === false
            || !is_int($primary) || !is_int($secondary)) {
            throw new RuntimeException('Cannot read locale number formatting rules.');
        }
        // Ask ICU when grouping starts; some locales leave four-digit numbers ungrouped.
        $minimum = 0;
        if ($grouping && $primary > 0) {
            $probe = clone $formatter;
            if (!$probe->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 0)
                || !$probe->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0)
                || !$probe->setTextAttribute(NumberFormatter::POSITIVE_PREFIX, '')
                || !$probe->setTextAttribute(NumberFormatter::POSITIVE_SUFFIX, '')) {
                throw new RuntimeException('Cannot configure the grouping probe.');
            }
            // Percent formatters scale input by 100.
            $percent = str_contains($pattern, '%');
            for ($length = $primary + 1; $length <= $primary + 3; $length++) {
                $formatted = $probe->format((10 ** ($length - 1)) / ($percent ? 100 : 1));
                if ($formatted === false) {
                    throw new RuntimeException('Cannot determine locale grouping rules.');
                }
                if ($separator !== '' && str_contains($formatted, $separator)) {
                    $minimum = $length;
                    break;
                }
            }
        }
        if ($minimum > 0 && strlen($whole) >= $minimum) {
            $groups = [substr($whole, -$primary)];
            $remaining = substr($whole, 0, -$primary);
            $size = $secondary > 0 ? $secondary : $primary;
            while ($remaining !== '') {
                array_unshift($groups, substr($remaining, -$size));
                $remaining = strlen($remaining) <= $size ? '' : substr($remaining, 0, -$size);
            }
            $whole = implode($separator, $groups);
        }
        $digitFormatter = new NumberFormatter($formatter->getLocale(Locale::VALID_LOCALE), NumberFormatter::DECIMAL);
        if (!$digitFormatter->setAttribute(NumberFormatter::GROUPING_USED, 0)
            || !$digitFormatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0)) {
            throw new RuntimeException('Cannot configure localized digits.');
        }
        $digits = [];
        for ($digit = 0; $digit <= 9; $digit++) {
            $localized = $digitFormatter->format($digit);
            if ($localized === false) {
                throw new RuntimeException('Cannot format localized digits.');
            }
            $digits[(string) $digit] = $localized;
        }
        return $prefix . strtr($whole, $digits)
            . ($precision > 0 ? $decimal . strtr($fraction, $digits) : '') . $suffix;
    }
}
