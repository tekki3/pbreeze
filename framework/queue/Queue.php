<?php
declare(strict_types=1);

namespace PBreeze\Queue;

use PBreeze\Clock;
use PBreeze\Database;
use PBreeze\Logger;
use PBreeze\SystemClock;
use InvalidArgumentException;
use RuntimeException;

require_once dirname(__DIR__) . '/Clock.php';
require_once __DIR__ . '/ExecutionContext.php';
require_once __DIR__ . '/JobRegistry.php';
require_once __DIR__ . '/DatabaseQueueStore.php';

final class Queue
{
    private readonly DatabaseQueueStore $persistence;
    private readonly Clock $clock;

    public function __construct(
        private readonly Database $db,
        private readonly Logger $log,
        private readonly JobRegistry $registry,
        private readonly array $config = [],
        ?Clock $clock = null,
    ) {
        if (($config['driver'] ?? 'database') !== 'database') {
            throw new InvalidArgumentException('The background queue supports only the database driver.');
        }
        $this->clock = $clock ?? new SystemClock();
        $this->persistence = new DatabaseQueueStore($db, (int) ($config['lease_seconds'] ?? $config['stale_after'] ?? 30),
            (int) ($config['priority_aging_seconds'] ?? 60));
        if (!is_int($config['payload_max_bytes'] ?? 65536) || ($config['payload_max_bytes'] ?? 65536) < 1
            || ($config['payload_max_bytes'] ?? 65536) > 65536) {
            throw new InvalidArgumentException('The queue payload limit must be between 1 and 65536 bytes.');
        }
    }

    public function registry(): JobRegistry { return $this->registry; }
    public function store(): DatabaseQueueStore { return $this->persistence; }

    public static function exponentialBackoff(int $base, int $attempts, int $cap = 604800): array
    {
        if ($base < 0 || $attempts < 1 || $attempts > 100 || $cap < 0 || $cap > 604800 || $base > $cap) {
            throw new InvalidArgumentException('Invalid exponential backoff base, attempt count, or cap.');
        }
        $delays = [];
        $delay = $base;
        for ($i = 0; $i < $attempts; $i++) {
            $delays[] = $delay;
            $delay = $delay > intdiv($cap, 2) ? $cap : min($cap, $delay * 2);
        }
        return $delays;
    }

    public function dispatch(string $type, array $payload = [], ?string $queue = null, int $priority = 1,
        ?int $tries = null, array $backoff = [60, 300], ?int $timeout = null, ?string $unique = null, bool $retrySafe = false): string
    {
        $queue ??= $this->config['default_queue'] ?? 'default';
        $tries ??= $this->config['default_tries'] ?? 3;
        $timeout ??= $this->config['timeout'] ?? 300;
        $json = $this->validate($type, $payload, $queue, $priority, $tries, $backoff, $timeout);
        if ($unique !== null && ($unique === '' || strlen($unique) > 1024)) {
            throw new InvalidArgumentException('Unique job keys must contain between 1 and 1024 bytes.');
        }
        return $this->persistence->transaction(function () use ($type, $json, $queue, $priority, $tries, $backoff, $timeout, $unique, $retrySafe): string {
            $id = bin2hex(random_bytes(16));
            $lock = $unique === null ? null : hash('sha256', 'unique:' . $type . ':' . $unique);
            if ($lock !== null && !$this->persistence->insertOnce('background_locks', 'lock_key',
                ['lock_key' => $lock, 'owner_id' => $id, 'created_at' => $this->persistence->now()])) {
                $owner = $this->db->execute('SELECT owner_id FROM background_locks WHERE lock_key = ?', [$lock])->fetchColumn();
                if (!is_string($owner) || preg_match('/^[a-f0-9]{32}$/D', $owner) !== 1) {
                    throw new RuntimeException('The unique job lock changed; retry dispatch.');
                }
                return $owner;
            }
            $this->insert($id, $type, $json, $queue, 'queue', $priority, $tries, $backoff, $timeout, $retrySafe, $lock, null);
            return $id;
        });
    }

    public function enqueueScheduled(string $taskKey, string $definitionHash, int $scheduledAt, string $delivery,
        string $queue, int $tries, array $backoff, int $timeout, bool $retrySafe = false, bool $withoutOverlapping = false): string
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,189}$/D', $taskKey)
            || !preg_match('/^[a-f0-9]{64}$/D', $definitionHash) || $scheduledAt < 0
            || !in_array($delivery, ['direct', 'queued'], true)) {
            throw new InvalidArgumentException('Invalid scheduled occurrence.');
        }
        $payload = ['task_key' => $taskKey, 'definition_hash' => $definitionHash];
        $json = $this->validate('scheduled-task', $payload, $queue, 1, $tries, $backoff, $timeout);
        return $this->persistence->transaction(function () use ($taskKey, $definitionHash, $scheduledAt, $delivery, $queue, $tries, $backoff, $timeout, $retrySafe, $withoutOverlapping, $json): string {
            $key = hash('sha256', $taskKey . ':' . $scheduledAt);
            $id = bin2hex(random_bytes(16));
            if (!$this->persistence->insertOnce('scheduled_occurrences', 'occurrence_key', [
                'occurrence_key' => $key, 'job_id' => $id, 'task_key' => $taskKey,
                'definition_hash' => $definitionHash, 'scheduled_at' => $scheduledAt,
            ])) {
                $existing = $this->db->execute('SELECT job_id FROM scheduled_occurrences WHERE occurrence_key = ?', [$key])->fetchColumn();
                if (!is_string($existing) || preg_match('/^[a-f0-9]{32}$/D', $existing) !== 1) {
                    throw new RuntimeException('The scheduled occurrence changed; retry materialization.');
                }
                return $existing;
            }
            $this->insert($id, 'scheduled-task', $json, $queue, $delivery === 'direct' ? 'scheduler' : 'queue',
                1, $tries, $backoff, $timeout, $retrySafe, null,
                $withoutOverlapping ? hash('sha256', 'overlap:' . $taskKey) : null);
            return $id;
        });
    }

    private function validate(string $type, array $payload, string $queue, int $priority, int $tries, array $backoff, int $timeout): string
    {
        JobRegistry::name($type);
        JobRegistry::queueName($queue);
        if ($priority < 0 || $priority > 3 || $tries < 1 || $tries > 100 || $timeout < 1 || $timeout > 86400
            || count($backoff) > 100 || !array_is_list($backoff)) {
            throw new InvalidArgumentException('Invalid job priority, tries, timeout, or backoff.');
        }
        foreach ($backoff as $delay) {
            if (!is_int($delay) || $delay < 0 || $delay > 604800) {
                throw new InvalidArgumentException('Backoff delays must be integer seconds between 0 and 604800.');
            }
        }
        self::jsonValues($payload);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES, 64);
        if (strlen($json) > ($this->config['payload_max_bytes'] ?? 65536)) {
            throw new InvalidArgumentException('The job payload exceeds the configured byte limit.');
        }
        $this->registry->validate($type, $payload);
        return $json;
    }

    private static function jsonValues(array $payload, int $depth = 0): void
    {
        if ($depth > 63) {
            throw new InvalidArgumentException('Job payload nesting exceeds the JSON depth limit.');
        }
        foreach ($payload as $value) {
            if (is_array($value)) {
                self::jsonValues($value, $depth + 1);
            } elseif ((!is_scalar($value) && $value !== null) || (is_float($value) && !is_finite($value))) {
                throw new InvalidArgumentException('Job payload values must be JSON arrays, scalars, or null.');
            }
        }
    }

    public function decodePayload(string $json): array
    {
        if (strlen($json) > ($this->config['payload_max_bytes'] ?? 65536)) {
            throw new InvalidJob('The persisted job payload exceeds the configured byte limit.');
        }
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new InvalidJob('The persisted job payload is invalid.', 0, $error);
        }
        if (!is_array($payload)) {
            throw new InvalidJob('The persisted job payload is not an array.');
        }
        return $payload;
    }

    private function insert(string $id, string $type, string $payload, string $queue, string $kind, int $priority,
        int $tries, array $backoff, int $timeout, bool $retrySafe, ?string $unique, ?string $overlap): void
    {
        $now = $this->persistence->now();
        $this->db->insert('background_jobs', [
            'id' => $id, 'job_type' => $type, 'payload' => $payload, 'queue_name' => $queue, 'kind' => $kind,
            'priority' => $priority, 'status' => 'pending', 'attempts' => 0, 'max_tries' => $tries,
            'backoff' => json_encode($backoff, JSON_THROW_ON_ERROR), 'timeout_seconds' => $timeout,
            'retry_safe' => (int) $retrySafe, 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            'claim_token' => null, 'worker_id' => null, 'lease_until' => null, 'cancel_requested' => 0,
            'unique_lock' => $unique, 'overlap_lock' => $overlap, 'failure_code' => null,
        ], null);
    }

    public function runNow(string $type, array $payload = []): void
    {
        $this->validate($type, $payload, 'default', 1, 1, [], 300);
        $this->registry->execute($type, $payload, new ExecutionContext(bin2hex(random_bytes(16))));
    }
}
