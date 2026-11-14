<?php
declare(strict_types=1);

return [
    'PBreeze\\Date' => [
        'description' => 'Practical static date/time utility using native DateTimeImmutable, independent of HTTP, Intl, configuration and mutable global state. Defaults to UTC/current time. Explicit input objects keep their timezone unless overridden. Use Formatter for localized ICU output.',
        'setup' => 'use PBreeze\\Date;',
        'methods' => [
            'now' => ['Return current immutable time in UTC or an explicitly supplied IANA timezone. Does not change PHP global timezone or read APP_TIMEZONE implicitly.', '$now = \\PBreeze\\Date::now("Europe/Amsterdam");'],
            'parse' => ['Parse YYYY-MM-DD, ISO/SQL time strings (minutes or seconds, optional seconds fractions up to six digits and Z/offset), integer Unix seconds or DateTimeInterface. Reject empty/relative/rollover dates and nonexistent local times. Offset-bearing strings preserve their offset unless converted explicitly; timezone-less strings/timestamps default UTC. Copy mutable objects without modifying them.', '$date = \\PBreeze\\Date::parse("2026-11-14 12:30:00", "Europe/Amsterdam");'],
            'addDays' => ['Return an immutable date shifted by calendar days in its effective timezone, defaulting to now in UTC. Day offsets may be -365000..365000. Preserve wall-time/microseconds where local DST rules allow; one calendar day may be 23 or 25 elapsed hours.', '$later = \\PBreeze\\Date::addDays(5, $date);'],
            'startOfDay' => ['Return an immutable local day boundary with hours/minutes/seconds/microseconds reset to zero. Defaults to today in UTC. Native timezone transitions may normalize a nonexistent midnight to the earliest valid time.', '$start = \\PBreeze\\Date::startOfDay($date);'],
            'format' => ['Format the supplied date or current UTC time using a nonempty UTF-8 native PHP pattern (maximum 256 bytes without controls). Not ICU/localized formatting or HTML escaping. Optional timezone converts the instant explicitly.', '$text = \\PBreeze\\Date::format("Y-m-d", $date);'],
            'diffForHumans' => ['Compare a supplied date with an explicit reference or now; return just now for elapsed differences below one second, otherwise the largest whole elapsed unit with ago/in direction. Units are seconds/minutes/hours/days/weeks/30-day months/365-day years; this is concise English, not calendar-month arithmetic or localization. No arguments returns just now.', '$text = \\PBreeze\\Date::diffForHumans("2026-11-13T12:00:00Z", "2026-11-14T12:00:00Z");'],
        ],
    ],
];
