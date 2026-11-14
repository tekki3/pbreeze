<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $text = match ($driver) { 'mysql' => 'LONGTEXT', 'sqlsrv' => 'VARCHAR(MAX)', default => 'TEXT' };
        $name = $driver === 'sqlsrv' ? 'VARCHAR(190) COLLATE Latin1_General_100_BIN2' : 'VARCHAR(190)';
        $queueName = $driver === 'sqlsrv' ? 'VARCHAR(80) COLLATE Latin1_General_100_BIN2' : 'VARCHAR(80)';
        $taskName = $driver === 'sqlsrv' ? 'VARCHAR(190) COLLATE Latin1_General_100_BIN2' : 'VARCHAR(190)';
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $db->exec("CREATE TABLE background_jobs (
            id CHAR(32) PRIMARY KEY, job_type $name NOT NULL, payload $text NOT NULL,
            queue_name $queueName NOT NULL, kind VARCHAR(16) NOT NULL, priority INTEGER NOT NULL,
            status VARCHAR(16) NOT NULL, attempts INTEGER NOT NULL, max_tries INTEGER NOT NULL,
            backoff $text NOT NULL, timeout_seconds INTEGER NOT NULL, retry_safe INTEGER NOT NULL,
            available_at BIGINT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL,
            claim_token CHAR(64) NULL, worker_id CHAR(32) NULL, lease_until BIGINT NULL,
            cancel_requested INTEGER NOT NULL, unique_lock CHAR(64) NULL, overlap_lock CHAR(64) NULL,
            failure_code VARCHAR(64) NULL
        )$suffix");
        $db->exec('CREATE INDEX background_jobs_ready ON background_jobs (status, kind, queue_name, available_at)');
        $db->exec('CREATE INDEX background_jobs_stale ON background_jobs (status, lease_until)');
        $db->exec('CREATE INDEX background_jobs_history ON background_jobs (status, updated_at)');
        $db->exec("CREATE TABLE background_attempts (
            id CHAR(32) PRIMARY KEY, job_id CHAR(32) NOT NULL, attempt_number INTEGER NOT NULL,
            claim_token CHAR(64) NOT NULL, started_at BIGINT NOT NULL, finished_at BIGINT NULL,
            outcome VARCHAR(32) NOT NULL, failure_code VARCHAR(64) NULL,
            exception_class VARCHAR(255) NULL, exception_file $text NULL, exception_line INTEGER NULL, duration_ms BIGINT NULL,
            UNIQUE (job_id, attempt_number)
        )$suffix");
        $db->exec("CREATE TABLE background_workers (
            id CHAR(32) PRIMARY KEY, kind VARCHAR(16) NOT NULL, queues $text NOT NULL,
            process_id BIGINT NOT NULL, started_at BIGINT NOT NULL, heartbeat_at BIGINT NOT NULL,
            stop_requested INTEGER NOT NULL, status VARCHAR(16) NOT NULL, hostname VARCHAR(255) NOT NULL, stopped_at BIGINT NULL
        )$suffix");
        $db->exec('CREATE INDEX background_workers_heartbeat ON background_workers (status, heartbeat_at)');
        $db->exec("CREATE TABLE background_locks (
            lock_key CHAR(64) PRIMARY KEY, owner_id CHAR(32) NOT NULL, created_at BIGINT NOT NULL
        )$suffix");
        $db->exec("CREATE TABLE scheduled_occurrences (
            occurrence_key CHAR(64) PRIMARY KEY, job_id CHAR(32) NOT NULL,
            task_key $taskName NOT NULL, definition_hash CHAR(64) NOT NULL, scheduled_at BIGINT NOT NULL,
            UNIQUE (task_key, scheduled_at)
        )$suffix");
        $db->exec("CREATE TABLE pbreeze_scheduler_tasks (
            task_key $taskName PRIMARY KEY, definition_hash CHAR(64) NOT NULL,
            next_run_at BIGINT NOT NULL, last_scheduled_at BIGINT NULL, updated_at BIGINT NOT NULL
        )$suffix");
    }

    public function down(PDO $db): void
    {
        foreach (['pbreeze_scheduler_tasks', 'scheduled_occurrences', 'background_locks', 'background_workers', 'background_attempts', 'background_jobs'] as $table) {
            $db->exec("DROP TABLE $table");
        }
    }
};
