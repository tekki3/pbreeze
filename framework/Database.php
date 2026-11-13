<?php
declare(strict_types=1);

namespace PBreeze;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private ?PDO $connection = null;
    public readonly Encryption $encryption;

    /** @param array<string, string> $config */
    public function __construct(private readonly string $root, private readonly array $config, ?Encryption $encryption = null)
    {
        $this->encryption = $encryption ?? new Encryption();
    }

    public function pdo(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $driver = $this->config['driver'];
        $name = $this->config['database'];
        if ($driver === 'sqlite') {
            $path = Filesystem::databasePath($this->root, $name);
            if ($path !== ':memory:' && !is_dir(dirname($path))) {
                throw new RuntimeException('The database directory does not exist.');
            }
            if ($path !== ':memory:' && !file_exists($path)) {
                $handle = fopen($path, 'c');
                if ($handle === false) {
                    throw new RuntimeException('Cannot create the SQLite database.');
                }
                try {
                    Filesystem::permissions($path, 0600);
                } finally {
                    fclose($handle);
                }
            }
            $dsn = 'sqlite:' . $path;
        } elseif ($driver === 'mysql') {
            foreach (['database', 'host', 'port'] as $key) {
                if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $this->config[$key])) {
                    throw new RuntimeException("Invalid database configuration: $key");
                }
            }
            if (!ctype_digit($this->config['port']) || (int) $this->config['port'] < 1 || (int) $this->config['port'] > 65535) {
                throw new RuntimeException('Invalid database port.');
            }
            $dsn = "mysql:host={$this->config['host']};port={$this->config['port']};dbname=$name;charset=utf8mb4";
        } else {
            throw new RuntimeException('DB_CONNECTION must be sqlite or mysql.');
        }
        $pdo = new PDO($dsn, $this->config['username'] ?? '', $this->config['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
        return $this->connection = $pdo;
    }

    /** @param array<string|int, mixed> $parameters */
    public function execute(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    /** @return Query<array<string, mixed>> */
    public function table(string $table): Query
    {
        return new Query($this, $table);
    }

    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
