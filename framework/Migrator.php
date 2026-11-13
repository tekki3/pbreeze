<?php
declare(strict_types=1);

namespace PBreeze;

use PDO;
use RuntimeException;

abstract class Migration
{
    abstract public function up(PDO $db): void;
    abstract public function down(PDO $db): void;
}

final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $root)
    {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        return $this->locked(function (): array {
            $this->repository();
            $applied = $this->applied();
            $files = $this->files();
            foreach ($applied as $name => $batchNumber) {
                if (!isset($files[$name])) {
                    throw new RuntimeException("Applied migration file is missing: $name");
                }
            }
            $batch = (int) $this->db->execute('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn() + 1;
            $done = [];
            foreach ($files as $name => $file) {
                if (isset($applied[$name])) {
                    continue;
                }
                $migration = $this->load($file);
                $this->atomic(function (PDO $pdo) use ($migration, $name, $batch): void {
                    $migration->up($pdo);
                    $this->db->execute('INSERT INTO migrations (name, batch) VALUES (?, ?)', [$name, $batch]);
                });
                $done[] = $name;
            }
            return $done;
        });
    }

    /** @return list<string> */
    public function rollback(): array
    {
        return $this->locked(function (): array {
            $this->repository();
            $rows = $this->db->execute('SELECT name FROM migrations WHERE batch = (SELECT MAX(batch) FROM migrations) ORDER BY name DESC')->fetchAll();
            $files = $this->files();
            foreach ($rows as $row) {
                if (!isset($files[$row['name']])) {
                    throw new RuntimeException('Migration file is missing: ' . $row['name']);
                }
            }
            $done = [];
            foreach ($rows as $row) {
                $name = $row['name'];
                $migration = $this->load($files[$name]);
                $this->atomic(function (PDO $pdo) use ($migration, $name): void {
                    $migration->down($pdo);
                    $this->db->execute('DELETE FROM migrations WHERE name = ?', [$name]);
                });
                $done[] = $name;
            }
            return $done;
        });
    }

    /** @return array<string, int|null> */
    public function status(): array
    {
        return $this->locked(function (): array {
            $this->repository();
            $applied = $this->applied();
            $result = [];
            foreach ($this->files() as $name => $file) {
                $result[$name] = $applied[$name] ?? null;
            }
            foreach ($applied as $name => $batch) {
                if (!array_key_exists($name, $result)) {
                    throw new RuntimeException("Applied migration file is missing: $name");
                }
            }
            return $result;
        });
    }

    private function repository(): void
    {
        $this->db->execute($this->db->driver() === 'sqlsrv'
            ? "IF OBJECT_ID(N'migrations', N'U') IS NULL CREATE TABLE migrations (name VARCHAR(190) PRIMARY KEY, batch INTEGER NOT NULL)"
            : 'CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, batch INTEGER NOT NULL)');
    }

    /** @return array<string, int> */
    private function applied(): array
    {
        $result = [];
        foreach ($this->db->execute('SELECT name, batch FROM migrations')->fetchAll() as $row) {
            $result[$row['name']] = (int) $row['batch'];
        }
        return $result;
    }

    /** @return array<string, string> */
    private function files(): array
    {
        $files = glob($this->root . '/database/migrations/*.php');
        if ($files === false) {
            throw new RuntimeException('Cannot find migration files.');
        }
        sort($files, SORT_STRING);
        $result = [];
        foreach ($files as $file) {
            if ($this->db->driver() === 'sqlsrv') {
                $override = dirname($file) . '/sqlsrv/' . basename($file);
                if (is_file($override)) {
                    $file = $override;
                }
            }
            $name = basename($file, '.php');
            if (!preg_match('/^[0-9]{14,20}_[a-z][a-z0-9_]*$/D', $name) || strlen($name) > 190) {
                throw new RuntimeException("Invalid migration filename: $name");
            }
            $result[$name] = $file;
        }
        return $result;
    }

    private function load(string $file): Migration
    {
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new RuntimeException('Migration must return a PBreeze\Migration: ' . basename($file));
        }
        return $migration;
    }

    private function atomic(callable $work): void
    {
        // MySQL DDL commits implicitly; a transaction would incorrectly suggest atomicity.
        if (in_array($this->db->driver(), ['sqlite', 'sqlsrv'], true)) {
            $this->db->transaction($work);
        } else {
            $work($this->db->pdo());
        }
    }

    private function locked(callable $work): mixed
    {
        $handle = fopen($this->root . '/storage/migrations.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the migration lock.');
        }
        $mysqlLock = null;
        $sqlsrvLock = false;
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('A migration process is already running.');
            }
            if ($this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $mysqlLock = 'pbreeze:' . substr(hash('sha256', (string) $this->db->execute('SELECT DATABASE()')->fetchColumn()), 0, 40);
                if ((int) $this->db->execute('SELECT GET_LOCK(?, 0)', [$mysqlLock])->fetchColumn() !== 1) {
                    $mysqlLock = null;
                    throw new RuntimeException('The database is locked by another migration process.');
                }
            }
            if ($this->db->driver() === 'sqlsrv') {
                $result = $this->db->execute("SET NOCOUNT ON; DECLARE @result int;
                    EXEC @result = sys.sp_getapplock @Resource = N'pbreeze:migrations',
                        @LockMode = 'Exclusive', @LockOwner = 'Session', @LockTimeout = 0;
                    SET NOCOUNT OFF; SELECT @result");
                $code = $result->fetchColumn();
                $result->closeCursor();
                if ($code === false || (int) $code < 0) {
                    throw new RuntimeException('The database is locked by another migration process.');
                }
                $sqlsrvLock = true;
            }
            return $work();
        } finally {
            try {
                if ($mysqlLock !== null) {
                    $this->db->execute('SELECT RELEASE_LOCK(?)', [$mysqlLock]);
                }
                if ($sqlsrvLock) {
                    $this->db->execute("EXEC sys.sp_releaseapplock @Resource = N'pbreeze:migrations', @LockOwner = 'Session'")->closeCursor();
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }
}
