<?php
declare(strict_types=1);

namespace PBreeze\Worker;

use InvalidArgumentException;

final class LifecycleEvents
{
    private array $listeners = [];

    public function on(string $event, callable $listener): void
    {
        if (!in_array($event, ['worker.started', 'worker.stopped', 'job.claimed', 'job.started', 'job.finished'], true)) {
            throw new InvalidArgumentException('Unknown background lifecycle event.');
        }
        $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, array $metadata): void
    {
        $firstError = null;
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener($metadata);
            } catch (\Throwable $error) {
                $firstError ??= $error;
            }
        }
        if ($firstError !== null) {
            throw $firstError;
        }
    }
}
