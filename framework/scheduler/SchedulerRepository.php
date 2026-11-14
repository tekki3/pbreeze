<?php
declare(strict_types=1);

namespace PBreeze\Scheduler;

use PBreeze\Database;
use InvalidArgumentException;

final class SchedulerRepository
{
    public function __construct(private readonly Database $db) {}

    public function transaction(callable $work): mixed
    {
        return $this->db->transaction($work);
    }

    public function initialize(string $taskKey, string $definitionHash, int $nextRunAt, int $now): bool
    {
        self::key($taskKey);
        self::hash($definitionHash);
        self::timestamps($nextRunAt, $now);
        $driver = $this->db->driver();
        if ($driver === 'sqlsrv') {
            return $this->db->execute(
                'INSERT INTO pbreeze_scheduler_tasks (task_key, definition_hash, next_run_at, last_scheduled_at, updated_at) '
                    . 'SELECT ?, ?, ?, NULL, ? WHERE NOT EXISTS (SELECT 1 FROM pbreeze_scheduler_tasks WITH (UPDLOCK, HOLDLOCK) WHERE task_key = ?)',
                [$taskKey, $definitionHash, $nextRunAt, $now, $taskKey],
            )->rowCount() === 1;
        } elseif ($driver === 'mysql') {
            return $this->db->execute(
                'INSERT INTO pbreeze_scheduler_tasks (task_key, definition_hash, next_run_at, last_scheduled_at, updated_at) '
                    . 'VALUES (?, ?, ?, NULL, ?) ON DUPLICATE KEY UPDATE task_key = VALUES(task_key)',
                [$taskKey, $definitionHash, $nextRunAt, $now],
            )->rowCount() === 1;
        } else {
            return $this->db->execute(
                'INSERT INTO pbreeze_scheduler_tasks (task_key, definition_hash, next_run_at, last_scheduled_at, updated_at) '
                    . 'VALUES (?, ?, ?, NULL, ?) ON CONFLICT (task_key) DO NOTHING',
                [$taskKey, $definitionHash, $nextRunAt, $now],
            )->rowCount() === 1;
        }
    }

    /** @return array{definition_hash: string, next_run_at: int|string, last_scheduled_at: int|string|null, updated_at: int|string}|null */
    public function cursor(string $taskKey, bool $lock = false): ?array
    {
        self::key($taskKey);
        $sql = 'SELECT definition_hash, next_run_at, last_scheduled_at, updated_at FROM pbreeze_scheduler_tasks WHERE task_key = ?';
        if ($lock && $this->db->driver() === 'sqlsrv') {
            $sql = 'SELECT definition_hash, next_run_at, last_scheduled_at, updated_at FROM pbreeze_scheduler_tasks WITH (UPDLOCK, ROWLOCK) WHERE task_key = ?';
        } elseif ($lock && in_array($this->db->driver(), ['mysql', 'pgsql'], true)) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->db->execute($sql, [$taskKey])->fetch();
        return is_array($row) ? $row : null;
    }

    public function compareAndSwap(
        string $taskKey,
        string $oldDefinitionHash,
        int $oldNextRunAt,
        string $definitionHash,
        int $nextRunAt,
        ?int $lastScheduledAt,
        int $updatedAt,
    ): bool {
        self::key($taskKey);
        self::hash($oldDefinitionHash);
        self::hash($definitionHash);
        self::timestamps($oldNextRunAt, $nextRunAt, $updatedAt);
        if ($lastScheduledAt !== null && $lastScheduledAt < 0) {
            throw new InvalidArgumentException('Scheduler timestamps must be nonnegative.');
        }
        return $this->db->execute(
            'UPDATE pbreeze_scheduler_tasks SET definition_hash = ?, next_run_at = ?, last_scheduled_at = ?, updated_at = ? '
                . 'WHERE task_key = ? AND definition_hash = ? AND next_run_at = ?',
            [$definitionHash, $nextRunAt, $lastScheduledAt, $updatedAt, $taskKey, $oldDefinitionHash, $oldNextRunAt],
        )->rowCount() === 1;
    }

    public function hasOutstandingTask(string $taskKey): bool
    {
        self::key($taskKey);
        return (int) $this->db->execute(
            "SELECT COUNT(*) FROM scheduled_occurrences o INNER JOIN background_jobs j ON j.id = o.job_id "
                . "WHERE o.task_key = ? AND j.status IN ('pending', 'reserved', 'running', 'uncertain')",
            [$taskKey],
        )->fetchColumn() > 0;
    }

    private static function key(string $taskKey): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,189}$/D', $taskKey)) {
            throw new InvalidArgumentException('Invalid scheduler task key.');
        }
    }

    private static function hash(string $definitionHash): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $definitionHash)) {
            throw new InvalidArgumentException('Invalid scheduler definition hash.');
        }
    }

    private static function timestamps(int ...$timestamps): void
    {
        foreach ($timestamps as $timestamp) {
            if ($timestamp < 0) {
                throw new InvalidArgumentException('Scheduler timestamps must be nonnegative.');
            }
        }
    }
}
