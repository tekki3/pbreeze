<?php
declare(strict_types=1);

namespace PBreeze\Scheduler;

use DateTimeZone;
use InvalidArgumentException;

final class IntervalExpression implements ScheduleExpression
{
    public function __construct(
        public readonly int $seconds,
    ) {
        if ($seconds < 1 || $seconds > 31536000) {
            throw new InvalidArgumentException('A schedule interval must be between 1 and 31536000 seconds.');
        }
    }

    public function nextAfter(int $timestamp, DateTimeZone $timezone): ?int
    {
        $quotient = intdiv($timestamp, $this->seconds);
        if ($timestamp < 0 && $timestamp % $this->seconds !== 0) {
            $quotient--;
        }
        return ($quotient + 1) * $this->seconds;
    }

    public function fingerprint(): array
    {
        return ['type' => 'utc-interval', 'seconds' => $this->seconds];
    }
}
