<?php
declare(strict_types=1);

namespace PBreeze\Queue;

use PBreeze\Database;
use PBreeze\Clock;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/QueueStore.php';

final class DatabaseQueueStore implements QueueStore
{
    public function __construct(public readonly Database $db, private readonly int $leaseSeconds = 30,
        private readonly int $priorityAgingSeconds = 60, private readonly ?Clock $testClock = null)
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Queue leases must be between 5 and 3600 seconds.');
        }
        if ($priorityAgingSeconds < 1 || $priorityAgingSeconds > 86400) {
            throw new InvalidArgumentException('Priority aging must be between 1 and 86400 seconds.');
        }
    }

    public function now(): int
    {
        if ($this->testClock !== null) {
            return $this->testClock->now();
        }
        $sql = match ($this->db->driver()) {
            'mysql' => 'SELECT UNIX_TIMESTAMP()',
            'pgsql' => 'SELECT CAST(FLOOR(EXTRACT(EPOCH FROM clock_timestamp())) AS BIGINT)',
            'sqlsrv' => "SELECT DATEDIFF_BIG(SECOND, '19700101', SYSUTCDATETIME())",
            default => "SELECT CAST(strftime('%s', 'now') AS INTEGER)",
        };
        return (int) $this->db->execute($sql)->fetchColumn();
    }

    public function transaction(callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $result = $work();
            if ($owns) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Insert only on a primary-key miss without aborting a caller's PostgreSQL transaction. */
    public function insertOnce(string $table, string $key, array $row): bool
    {
        $allowed = ['background_locks' => 'lock_key', 'scheduled_occurrences' => 'occurrence_key'];
        if (($allowed[$table] ?? null) !== $key) {
            throw new InvalidArgumentException('Invalid background insertion target.');
        }
        $columns = implode(', ', array_keys($row));
        $values = implode(', ', array_fill(0, count($row), '?'));
        $parameters = array_values($row);
        $sql = match ($this->db->driver()) {
            'mysql' => "INSERT INTO $table ($columns) VALUES ($values)",
            'sqlsrv' => "INSERT INTO $table ($columns) SELECT $values WHERE NOT EXISTS (SELECT 1 FROM $table WITH (UPDLOCK, HOLDLOCK) WHERE $key = ?)",
            default => "INSERT INTO $table ($columns) VALUES ($values) ON CONFLICT ($key) DO NOTHING",
        };
        if ($this->db->driver() === 'sqlsrv') {
            $parameters[] = $row[$key];
        }
        try {
            return $this->db->execute($sql, $parameters)->rowCount() === 1;
        } catch (\PDOException $error) {
            if ($this->db->driver() === 'mysql' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $error;
        }
    }

    public function get(string $id): ?array
    {
        $row = $this->db->execute('SELECT * FROM background_jobs WHERE id = ?', [$id])->fetch();
        return is_array($row) ? $row : null;
    }

    private function lockedGet(string $id): ?array
    {
        if ($this->db->driver() === 'sqlite') {
            $this->db->execute('UPDATE background_jobs SET updated_at = updated_at WHERE id = ?', [$id]);
        }
        $sql = $this->db->driver() === 'sqlsrv'
            ? 'SELECT * FROM background_jobs WITH (UPDLOCK, HOLDLOCK) WHERE id = ?'
            : 'SELECT * FROM background_jobs WHERE id = ?' . (in_array($this->db->driver(), ['mysql', 'pgsql'], true) ? ' FOR UPDATE' : '');
        $row = $this->db->execute($sql, [$id])->fetch();
        return is_array($row) ? $row : null;
    }

    public function status(?string $queue = null): array
    {
        $result = [];
        if ($queue !== null) {
            JobRegistry::queueName($queue);
        }
        $sql = 'SELECT status, COUNT(*) AS total FROM background_jobs'
            . ($queue === null ? '' : ' WHERE queue_name = ?') . ' GROUP BY status';
        foreach ($this->db->execute($sql, $queue === null ? [] : [$queue])->fetchAll() as $row) {
            $result[$row['status']] = (int) $row['total'];
        }
        return $result;
    }

    public function jobs(string $status = 'pending', int $limit = 50, ?string $queue = null): array
    {
        $this->limit($limit);
        if (!in_array($status, ['pending', 'reserved', 'running', 'completed', 'failed', 'uncertain'], true)) {
            throw new InvalidArgumentException('Invalid job status filter.');
        }
        $parameters = [$status];
        $sql = 'SELECT id, job_type, queue_name, kind, priority, status, attempts, max_tries, available_at, created_at, updated_at, failure_code FROM background_jobs WHERE status = ?';
        if ($queue !== null) {
            JobRegistry::queueName($queue);
            $sql .= ' AND queue_name = ?';
            $parameters[] = $queue;
        }
        return $this->db->execute($this->db->limit($sql . ' ORDER BY updated_at DESC, id', $limit), $parameters)->fetchAll();
    }

    public function history(string $jobId, int $limit = 50): array
    {
        $this->limit($limit);
        if (!preg_match('/^[a-f0-9]{32}$/D', $jobId)) {
            throw new InvalidArgumentException('Invalid job identifier.');
        }
        return $this->db->execute($this->db->limit(
            'SELECT id, job_id, attempt_number, started_at, finished_at, outcome, failure_code, exception_class, exception_file, exception_line, duration_ms FROM background_attempts WHERE job_id = ? ORDER BY attempt_number DESC', $limit), [$jobId])->fetchAll();
    }

    public function failed(int $limit = 50): array
    {
        $this->limit($limit);
        return $this->db->execute($this->db->limit(
            "SELECT id, job_type, queue_name, status, attempts, max_tries, failure_code, updated_at FROM background_jobs WHERE status IN ('failed', 'uncertain') ORDER BY updated_at DESC, id", $limit))->fetchAll();
    }

    private function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The background result limit must be between 1 and 1000.');
        }
    }

    public function claim(string $worker, string $kind, array $queues, ?string $onlyJobId = null, ?int $tries = null): ?array
    {
        if (!in_array($kind, ['queue', 'scheduler'], true) || $queues === [] || count($queues) > 100) {
            throw new InvalidArgumentException('Invalid worker kind or queue selection.');
        }
        foreach ($queues as $queue) {
            JobRegistry::queueName($queue);
        }
        if ($tries !== null && ($tries < 1 || $tries > 100)) {
            throw new InvalidArgumentException('Worker tries must be between 1 and 100.');
        }
        $this->quarantineExpired();
        return $this->transaction(function () use ($worker, $kind, $queues, $onlyJobId, $tries): ?array {
            if ($this->db->driver() === 'sqlite') {
                // Acquire the writer reservation before reading candidates, avoiding snapshot upgrade races.
                $this->db->execute("UPDATE background_jobs SET updated_at = updated_at WHERE id = '-claim-mutex-'");
            }
            $now = $this->now();
            $marks = implode(',', array_fill(0, count($queues), '?'));
            $sql = "SELECT * FROM background_jobs WHERE status = 'pending' AND kind = ? AND queue_name IN ($marks) AND available_at <= ?
                AND (overlap_lock IS NULL OR NOT EXISTS (SELECT 1 FROM background_locks WHERE lock_key = background_jobs.overlap_lock))";
            $params = [$kind, ...$queues, $now];
            if ($onlyJobId !== null) {
                $sql .= ' AND id = ?';
                $params[] = $onlyJobId;
            }
            // Age only ready time; delayed retries must not accrue priority while unavailable.
            $age = '(? - available_at) / ' . $this->priorityAgingSeconds . '.0';
            $sql .= " ORDER BY (priority + $age) DESC, created_at, id";
            $params[] = $now;
            foreach ($this->db->execute($this->db->limit($sql, 100), $params)->fetchAll() as $row) {
                $ceiling = $tries === null ? (int) $row['max_tries'] : min($tries, (int) $row['max_tries']);
                if ((int) $row['attempts'] >= $ceiling) {
                    $changed = $this->db->execute("UPDATE background_jobs SET status = 'failed', failure_code = 'tries_exhausted', updated_at = ? WHERE id = ? AND status = 'pending' AND attempts >= ?",
                        [$now, $row['id'], $ceiling])->rowCount();
                    if ($changed === 1) {
                        $this->release($row, true);
                    }
                    continue;
                }
                $token = bin2hex(random_bytes(32));
                $changed = $this->db->execute("UPDATE background_jobs SET status = 'reserved', claim_token = ?, worker_id = ?, lease_until = ?, updated_at = ?, attempts = attempts + 1 WHERE id = ? AND status = 'pending'",
                    [$token, $worker, $now + $this->leaseSeconds, $now, $row['id']])->rowCount();
                if ($changed !== 1) {
                    continue;
                }
                if ($row['overlap_lock'] !== null && !$this->insertOnce('background_locks', 'lock_key',
                    ['lock_key' => $row['overlap_lock'], 'owner_id' => $row['id'], 'created_at' => $now])) {
                    $this->db->execute("UPDATE background_jobs SET status = 'pending', claim_token = NULL, worker_id = NULL, lease_until = NULL, attempts = attempts - 1 WHERE id = ? AND claim_token = ?", [$row['id'], $token]);
                    continue;
                }
                if ($tries !== null) {
                    $this->db->execute('UPDATE background_jobs SET max_tries = ? WHERE id = ? AND claim_token = ?', [min($tries, (int) $row['max_tries']), $row['id'], $token]);
                }
                $this->db->insert('background_attempts', [
                    'id' => bin2hex(random_bytes(16)), 'job_id' => $row['id'],
                    'attempt_number' => (int) $row['attempts'] + 1, 'claim_token' => $token,
                    'started_at' => $now, 'finished_at' => null, 'outcome' => 'reserved', 'failure_code' => null,
                ], null);
                return $this->get($row['id']);
            }
            return null;
        });
    }

    public function start(string $id, #[\SensitiveParameter] string $token): ?array
    {
        $now = $this->now();
        $updated = $this->db->execute("UPDATE background_jobs SET status = 'running', updated_at = ? WHERE id = ? AND claim_token = ? AND status = 'reserved' AND lease_until >= ? AND cancel_requested = 0",
            [$now, $id, $token, $now])->rowCount();
        if ($updated !== 1) {
            return null;
        }
        $this->db->execute("UPDATE background_attempts SET outcome = 'running' WHERE job_id = ? AND claim_token = ? AND outcome = 'reserved'", [$id, $token]);
        return $this->get($id);
    }

    public function heartbeat(string $id, #[\SensitiveParameter] string $token): bool
    {
        $now = $this->now();
        $this->db->execute("UPDATE background_jobs SET lease_until = ?, updated_at = ? WHERE id = ? AND claim_token = ? AND status IN ('reserved', 'running')",
            [$now + $this->leaseSeconds, $now, $id, $token]);
        $row = $this->lockedGet($id);
        return $row !== null && hash_equals((string) $row['claim_token'], $token)
            && in_array($row['status'], ['reserved', 'running'], true) && !(bool) $row['cancel_requested'];
    }

    public function cancelled(string $id, #[\SensitiveParameter] string $token): bool
    {
        $row = $this->lockedGet($id);
        return $row === null || !hash_equals((string) $row['claim_token'], $token)
            || !in_array($row['status'], ['reserved', 'running'], true) || (bool) $row['cancel_requested'];
    }

    public function cancel(string $id, #[\SensitiveParameter] string $token): void
    {
        $this->db->execute("UPDATE background_jobs SET cancel_requested = 1 WHERE id = ? AND claim_token = ? AND status IN ('reserved', 'running', 'uncertain')", [$id, $token]);
    }

    public function finish(string $id, #[\SensitiveParameter] string $token, string $outcome,
        ?Throwable $error = null, ?float $durationMs = null): bool
    {
        if (!in_array($outcome, ['completed', 'failed', 'retry', 'uncertain', 'cancelled'], true)) {
            throw new InvalidArgumentException('Invalid job outcome.');
        }
        return $this->transaction(function () use ($id, $token, $outcome, $error, $durationMs): bool {
            $row = $this->lockedGet($id);
            if ($row === null || !hash_equals((string) $row['claim_token'], $token)
                || !in_array($row['status'], ['reserved', 'running'], true)) {
                return false;
            }
            $now = $this->now();
            $status = $outcome;
            $available = $now;
            if ($outcome === 'retry') {
                $status = (int) $row['attempts'] < (int) $row['max_tries'] ? 'pending' : 'failed';
                $backoff = json_decode($row['backoff'], true, 16, JSON_THROW_ON_ERROR);
                $available += $backoff[min((int) $row['attempts'] - 1, count($backoff) - 1)] ?? 0;
            }
            if ($outcome === 'cancelled') {
                $status = 'uncertain';
            }
            $code = in_array($outcome, ['completed', 'retry'], true) ? null : $outcome;
            $changed = $this->db->execute("UPDATE background_jobs SET status = ?, available_at = ?, updated_at = ?, failure_code = ?, lease_until = NULL WHERE id = ? AND claim_token = ? AND status IN ('reserved', 'running')",
                [$status, $available, $now, $code, $id, $token])->rowCount();
            if ($changed !== 1) {
                return false;
            }
            $this->db->execute('UPDATE background_attempts SET outcome = ?, finished_at = ?, failure_code = ?, exception_class = ?, exception_file = ?, exception_line = ?, duration_ms = ? WHERE job_id = ? AND claim_token = ?',
                [$status, $now, $code, $error === null ? null : $error::class, $error?->getFile(),
                    $error?->getLine(), $durationMs === null ? null : max(0, (int) $durationMs), $id, $token]);
            if ($status !== 'uncertain') {
                $this->release($row, $status !== 'pending');
            }
            return true;
        });
    }

    private function release(array $row, bool $unique): void
    {
        foreach ([$row['overlap_lock'], $unique ? $row['unique_lock'] : null] as $lock) {
            if ($lock !== null) {
                $this->db->execute('DELETE FROM background_locks WHERE lock_key = ? AND owner_id = ?', [$lock, $row['id']]);
            }
        }
    }

    public function quarantineExpired(): int
    {
        $count = 0;
        $now = $this->now();
        $sql = $this->db->limit("SELECT id, claim_token FROM background_jobs WHERE status IN ('reserved', 'running') AND lease_until < ? ORDER BY lease_until, id", 100);
        foreach ($this->db->execute($sql, [$now])->fetchAll() as $row) {
            // Expiry is evidence of lost supervision, never authorization to replay side effects.
            $count += (int) $this->finishExpired($row['id'], $row['claim_token'], $now);
        }
        return $count;
    }

    private function finishExpired(string $id, string $token, int $now): bool
    {
        return $this->transaction(function () use ($id, $token, $now): bool {
            $changed = $this->db->execute("UPDATE background_jobs SET status = 'uncertain', cancel_requested = 1, failure_code = 'lease_expired', updated_at = ? WHERE id = ? AND claim_token = ? AND status IN ('reserved', 'running') AND lease_until < ?",
                [$now, $id, $token, $now])->rowCount();
            if ($changed === 1) {
                $this->db->execute("UPDATE background_attempts SET outcome = 'uncertain', failure_code = 'lease_expired', finished_at = ? WHERE job_id = ? AND claim_token = ?", [$now, $id, $token]);
            }
            return $changed === 1;
        });
    }

    public function retry(string $id): void
    {
        $this->transaction(function () use ($id): void {
            $row = $this->get($id);
            if ($row === null || $row['status'] !== 'failed') {
                throw new RuntimeException('Only known failed jobs can be retried; uncertain jobs require explicit resolution.');
            }
            $this->requeue($row);
        });
    }

    private function requeue(array $row): void
    {
        $now = $this->now();
        if ($row['unique_lock'] !== null && !$this->insertOnce('background_locks', 'lock_key',
            ['lock_key' => $row['unique_lock'], 'owner_id' => $row['id'], 'created_at' => $now])) {
            $owner = $this->db->execute('SELECT owner_id FROM background_locks WHERE lock_key = ?', [$row['unique_lock']])->fetchColumn();
            if ($owner !== $row['id']) {
                throw new RuntimeException('Another job holds the unique lock.');
            }
        }
        $changed = $this->db->execute("UPDATE background_jobs SET status = 'pending', max_tries = attempts + 1, available_at = ?, updated_at = ?, claim_token = NULL, worker_id = NULL, lease_until = NULL, cancel_requested = 0, failure_code = NULL WHERE id = ? AND status = ?",
            [$now, $now, $row['id'], $row['status']])->rowCount();
        if ($changed !== 1) {
            throw new RuntimeException('Job state changed during retry.');
        }
    }

    public function resolve(string $id, string $resolution): void
    {
        if (!in_array($resolution, ['completed', 'failed', 'retry'], true)) {
            throw new InvalidArgumentException('Resolve uncertain jobs as completed, failed, or retry only after external execution has stopped.');
        }
        $this->transaction(function () use ($id, $resolution): void {
            $row = $this->get($id);
            if ($row === null || $row['status'] !== 'uncertain') {
                throw new RuntimeException('Only uncertain jobs need resolution.');
            }
            // This explicit operator assertion fences any late child completion by clearing its token.
            if ($resolution === 'retry') {
                $this->release($row, false);
                $this->requeue($row);
            } else {
                $changed = $this->db->execute("UPDATE background_jobs SET status = ?, claim_token = NULL, lease_until = NULL, updated_at = ?, failure_code = 'operator_resolved' WHERE id = ? AND status = 'uncertain'",
                    [$resolution, $this->now(), $id])->rowCount();
                if ($changed !== 1) {
                    throw new RuntimeException('Job state changed during resolution.');
                }
                $this->release($row, true);
            }
        });
    }

    public function prune(int $before, int $limit = 100): int
    {
        $this->limit($limit);
        return $this->transaction(function () use ($before, $limit): int {
            $rows = $this->db->execute($this->db->limit("SELECT id FROM background_jobs WHERE status IN ('completed', 'failed') AND updated_at < ? ORDER BY updated_at, id", $limit), [$before])->fetchAll();
            $count = 0;
            foreach ($rows as $row) {
                $changed = $this->db->execute("DELETE FROM background_jobs WHERE id = ? AND status IN ('completed', 'failed') AND updated_at < ?", [$row['id'], $before])->rowCount();
                if ($changed === 1) {
                    $this->db->execute('DELETE FROM background_attempts WHERE job_id = ?', [$row['id']]);
                    $count++;
                }
            }
            // Occurrence tombstones deliberately survive pruning to prevent duplicate scheduled delivery.
            return $count;
        });
    }
}
