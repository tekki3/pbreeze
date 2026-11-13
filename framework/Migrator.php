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
            $batch = (int) $this->db->execute('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn() + 1;
            $done = [];
            foreach ($this->files() as $name => $file) {
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
                    throw new RuntimeException('Migratiebestand ontbreekt: ' . $row['name']);
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
                    throw new RuntimeException("Toegepast migratiebestand ontbreekt: $name");
                }
            }
            return $result;
        });
    }

    private function repository(): void
    {
        $this->db->execute('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, batch INTEGER NOT NULL)');
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
            throw new RuntimeException('Kan migratiebestanden niet vinden.');
        }
        sort($files, SORT_STRING);
        $result = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!preg_match('/^[0-9]{14,20}_[a-z][a-z0-9_]*$/D', $name) || strlen($name) > 190) {
                throw new RuntimeException("Ongeldige migratiebestandsnaam: $name");
            }
            $result[$name] = $file;
        }
        return $result;
    }

    private function load(string $file): Migration
    {
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new RuntimeException('Migratie moet een PBreeze\Migration teruggeven: ' . basename($file));
        }
        return $migration;
    }

    private function atomic(callable $work): void
    {
        // MySQL DDL commit impliciet; een transactie suggereert daar onterecht atomiciteit.
        if ($this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->db->transaction($work);
        } else {
            $work($this->db->pdo());
        }
    }

    private function locked(callable $work): mixed
    {
        $handle = fopen($this->root . '/storage/migrations.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Kan migratieslot niet openen.');
        }
        $mysqlLock = null;
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Er draait al een migratieproces.');
            }
            if ($this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $mysqlLock = 'pbreeze:' . substr(hash('sha256', (string) $this->db->execute('SELECT DATABASE()')->fetchColumn()), 0, 40);
                if ((int) $this->db->execute('SELECT GET_LOCK(?, 0)', [$mysqlLock])->fetchColumn() !== 1) {
                    $mysqlLock = null;
                    throw new RuntimeException('De database is vergrendeld door een ander migratieproces.');
                }
            }
            return $work();
        } finally {
            try {
                if ($mysqlLock !== null) {
                    $this->db->execute('SELECT RELEASE_LOCK(?)', [$mysqlLock]);
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }
}
