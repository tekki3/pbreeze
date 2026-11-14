<?php
declare(strict_types=1);

namespace PBreeze\Scheduler;

use DateTimeZone;

interface ScheduleExpression
{
    /** Return the earliest occurrence strictly after timestamp, or null when none remains. */
    public function nextAfter(int $timestamp, DateTimeZone $timezone): ?int;

    /** @return array<string, int|string> */
    public function fingerprint(): array;
}
