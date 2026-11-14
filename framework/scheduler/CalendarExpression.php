<?php
declare(strict_types=1);

namespace PBreeze\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class CalendarExpression implements ScheduleExpression
{
    private function __construct(
        private readonly string $frequency,
        private readonly int $hour,
        private readonly int $minute,
        private readonly ?int $weekday = null,
        private readonly ?int $monthDay = null,
    ) {}

    public static function hourly(): self
    {
        return new self('hourly', 0, 0);
    }

    public static function daily(string $time = '00:00'): self
    {
        [$hour, $minute] = self::parseTime($time);
        return new self('daily', $hour, $minute);
    }

    public static function weekly(int $weekday = 7, string $time = '00:00'): self
    {
        if ($weekday < 1 || $weekday > 7) {
            throw new InvalidArgumentException('An ISO weekday must be between 1 (Monday) and 7 (Sunday).');
        }
        [$hour, $minute] = self::parseTime($time);
        return new self('weekly', $hour, $minute, $weekday);
    }

    public static function monthly(int $monthDay = 1, string $time = '00:00'): self
    {
        if ($monthDay < 1 || $monthDay > 31) {
            throw new InvalidArgumentException('A monthly day must be between 1 and 31.');
        }
        [$hour, $minute] = self::parseTime($time);
        return new self('monthly', $hour, $minute, monthDay: $monthDay);
    }

    public function nextAfter(int $timestamp, DateTimeZone $timezone): ?int
    {
        $local = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        $date = new DateTimeImmutable($local->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));
        for ($day = 0; $day < 3700; $day++) {
            $dateText = $date->format('Y-m-d');
            if ($this->matchesDate($date)) {
                if ($this->frequency === 'hourly') {
                    for ($hour = 0; $hour < 24; $hour++) {
                        $candidate = self::wallTimeCandidates($dateText, $hour, 0, $timezone)[0] ?? null;
                        if ($candidate !== null && $candidate > $timestamp) {
                            return $candidate;
                        }
                    }
                } else {
                    $candidate = self::wallTimeCandidates($dateText, $this->hour, $this->minute, $timezone)[0] ?? null;
                    if ($candidate !== null && $candidate > $timestamp) {
                        return $candidate;
                    }
                }
            }
            $date = $date->modify('+1 day');
        }
        return null;
    }

    public function fingerprint(): array
    {
        return array_filter([
            'type' => 'calendar',
            'frequency' => $this->frequency,
            'hour' => $this->hour,
            'minute' => $this->minute,
            'weekday' => $this->weekday,
            'month_day' => $this->monthDay,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array{int, int} */
    private static function parseTime(string $time): array
    {
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
            throw new InvalidArgumentException('A schedule time must use the 24-hour HH:MM format.');
        }
        return [(int) substr($time, 0, 2), (int) substr($time, 3, 2)];
    }

    private function matchesDate(DateTimeImmutable $date): bool
    {
        return match ($this->frequency) {
            'hourly', 'daily' => true,
            'weekly' => (int) $date->format('N') === $this->weekday,
            'monthly' => (int) $date->format('j') === $this->monthDay,
            default => false,
        };
    }

    /** @return list<int> */
    private static function wallTimeCandidates(string $date, int $hour, int $minute, DateTimeZone $timezone): array
    {
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', sprintf('%s %02d:%02d:00', $date, $hour, $minute), new DateTimeZone('UTC'));
        if (!$wall instanceof DateTimeImmutable) {
            return [];
        }
        $wallTimestamp = $wall->getTimestamp();
        $transitions = $timezone->getTransitions($wallTimestamp - 259200, $wallTimestamp + 259200);
        if ($transitions === false) {
            $offset = $timezone->getOffset(new DateTimeImmutable('@' . $wallTimestamp));
            $candidates = [$wallTimestamp - $offset];
        } else {
            $offsets = [];
            foreach ($transitions as $transition) {
                $offsets[(int) $transition['offset']] = true;
            }
            $candidates = [];
            foreach (array_keys($offsets) as $offset) {
                $candidate = $wallTimestamp - $offset;
                if ((new DateTimeImmutable('@' . $candidate))->setTimezone($timezone)->format('Y-m-d H:i:s')
                    === sprintf('%s %02d:%02d:00', $date, $hour, $minute)) {
                    $candidates[] = $candidate;
                }
            }
        }
        sort($candidates, SORT_NUMERIC);
        return array_values(array_unique($candidates));
    }
}
