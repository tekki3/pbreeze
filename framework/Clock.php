<?php
declare(strict_types=1);

namespace PBreeze;

interface Clock
{
    public function now(): int;
    public function monotonic(): float;
}

final class SystemClock implements Clock
{
    public function now(): int { return time(); }
    public function monotonic(): float { return hrtime(true) / 1_000_000; }
}
