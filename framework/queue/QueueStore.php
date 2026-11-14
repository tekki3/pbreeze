<?php
declare(strict_types=1);

namespace PBreeze\Queue;

interface QueueStore
{
    public function status(?string $queue = null): array;
    public function jobs(string $status = 'pending', int $limit = 50, ?string $queue = null): array;
    public function history(string $jobId, int $limit = 50): array;
    public function failed(int $limit = 50): array;
    public function retry(string $id): void;
    public function resolve(string $id, string $resolution): void;
    public function prune(int $before, int $limit = 100): int;
}
