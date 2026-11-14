<?php
declare(strict_types=1);

namespace PBreeze\Queue;

final class ExecutionContext
{
    public readonly string $idempotencyKey;

    public function __construct(
        public readonly string $jobId,
        public readonly int $attempt = 1,
        private readonly ?\Closure $cancelled = null,
        private readonly ?\Closure $pulse = null,
    ) {
        $this->idempotencyKey = $jobId;
    }

    public function isCancelled(): bool { return $this->cancelled !== null && ($this->cancelled)(); }
    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new JobCancelled('Background work was cancelled.');
        }
    }
    public function heartbeat(): void
    {
        if ($this->pulse !== null) {
            ($this->pulse)();
        }
        $this->throwIfCancelled();
    }

    public function checkpoint(): void { $this->heartbeat(); }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
}
