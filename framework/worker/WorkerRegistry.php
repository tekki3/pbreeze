<?php
declare(strict_types=1);

namespace PBreeze\Worker;

use PBreeze\Queue\DatabaseQueueStore;
use InvalidArgumentException;
use RuntimeException;

final class WorkerRegistry
{
    public function __construct(private readonly DatabaseQueueStore $store, private readonly int $staleAfter = 30)
    {
        if ($staleAfter < 1 || $staleAfter > 3600) {
            throw new InvalidArgumentException('Worker stale threshold must be between 1 and 3600 seconds.');
        }
    }

    public function register(string $kind, array $queues): string
    {
        $id = bin2hex(random_bytes(16));
        $now = $this->store->now();
        $hostname = gethostname();
        if ($hostname === false || $hostname === '') {
            throw new RuntimeException('Cannot determine the worker hostname.');
        }
        $this->store->db->insert('background_workers', [
            'id' => $id, 'kind' => $kind, 'queues' => json_encode($queues, JSON_THROW_ON_ERROR),
            'process_id' => getmypid(), 'started_at' => $now, 'heartbeat_at' => $now,
            'stop_requested' => 0, 'status' => 'running',
            'hostname' => $hostname, 'stopped_at' => null,
        ], null);
        return $id;
    }

    public function listing(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Worker result limit must be between 1 and 1000.');
        }
        $rows = $this->store->db->execute($this->store->db->limit(
            'SELECT * FROM background_workers ORDER BY started_at DESC, id', $limit))->fetchAll();
        $now = $this->store->now();
        foreach ($rows as &$row) {
            $row['suspected_offline'] = $row['status'] === 'running' && (int) $row['heartbeat_at'] < $now - $this->staleAfter;
        }
        return $rows;
    }

    public function heartbeat(string $id): bool
    {
        $this->store->db->execute("UPDATE background_workers SET heartbeat_at = ? WHERE id = ? AND status = 'running'", [$this->store->now(), $id]);
        $row = $this->store->db->execute('SELECT stop_requested, status FROM background_workers WHERE id = ?', [$id])->fetch();
        return is_array($row) && $row['status'] === 'running' && !(bool) $row['stop_requested'];
    }

    public function stop(string $id): void
    {
        $row = $this->store->db->execute('SELECT id FROM background_workers WHERE id = ?', [$id])->fetchColumn();
        if ($row === false) {
            throw new RuntimeException('The worker does not exist.');
        }
        $this->store->db->execute('UPDATE background_workers SET stop_requested = 1 WHERE id = ?', [$id]);
    }

    public function stopped(string $id): void
    {
        $now = $this->store->now();
        $this->store->db->execute("UPDATE background_workers SET status = 'stopped', heartbeat_at = ?, stopped_at = ? WHERE id = ?", [$now, $now, $id]);
    }
}
