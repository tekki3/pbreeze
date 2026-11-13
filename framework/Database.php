<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
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
        } elseif ($driver === 'pgsql') {
            $host = $this->config['host'] ?? '127.0.0.1';
            $port = $this->config['port'] ?? '5432';
            $sslmode = $this->config['sslmode'] ?? 'verify-full';
            $sslrootcert = $this->config['sslrootcert'] ?? '';
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $host)
                || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
                || !preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,62}$/D', $name)
                || !in_array($sslmode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
                throw new RuntimeException('Invalid PostgreSQL host, port, database name, or SSL mode.');
            }
            if ($sslrootcert !== '' && (preg_match('/[;\'\x00-\x1f\x7f]/', $sslrootcert) || !is_readable($sslrootcert))) {
                throw new RuntimeException('PostgreSQL requires a readable local CA file without DSN delimiters.');
            }
            $dsn = "pgsql:host=$host;port=$port;dbname=$name;sslmode=$sslmode;connect_timeout=15";
            if ($sslrootcert !== '') {
                $dsn .= ';sslrootcert=' . $sslrootcert;
            }
            if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
                throw new RuntimeException('PostgreSQL requires the native pdo_pgsql extension and libpq.');
            }
        } elseif ($driver === 'sqlsrv') {
            $host = $this->config['host'] ?? '127.0.0.1';
            $port = $this->config['port'] ?? '1433';
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $host)
                || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
                || $name === '' || strlen($name) > 128 || preg_match('/[{};\x00-\x1f\x7f]/', $name)) {
                throw new RuntimeException('Invalid SQL Server host, port, or database name.');
            }
            $encrypt = $this->config['encrypt'] ?? 'true';
            $trust = $this->config['trust_server_certificate'] ?? 'false';
            if (!in_array($encrypt, ['true', 'false'], true) || !in_array($trust, ['true', 'false'], true)) {
                throw new RuntimeException('SQL Server encryption settings must be true or false.');
            }
            $dsn = "sqlsrv:Server=$host,$port;Database=$name;Encrypt=$encrypt;TrustServerCertificate=$trust;LoginTimeout=15";
            if (!in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
                throw new RuntimeException('SQL Server requires the native pdo_sqlsrv extension and Microsoft ODBC Driver for SQL Server.');
            }
        } else {
            throw new RuntimeException('DB_CONNECTION must be sqlite, mysql, sqlsrv, or pgsql.');
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
        } elseif ($driver === 'sqlsrv') {
            $pdo->exec('SET ANSI_NULLS ON; SET QUOTED_IDENTIFIER ON; SET ANSI_PADDING ON;
                SET ANSI_WARNINGS ON; SET CONCAT_NULL_YIELDS_NULL ON; SET ARITHABORT ON;
                SET NUMERIC_ROUNDABORT OFF; SET NOCOUNT OFF');
        } elseif ($driver === 'pgsql') {
            $pdo->exec("SET client_encoding TO 'UTF8'; SET TIME ZONE 'UTC'");
        }
        return $this->connection = $pdo;
    }

    /** @param array<string|int, mixed> $parameters */
    public function execute(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        foreach ($parameters as $key => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $statement->execute();
        return $statement;
    }

    public function driver(): string
    {
        return $this->config['driver'];
    }

    /** @param array<string, scalar|null> $data */
    public function insert(string $table, #[\SensitiveParameter] array $data, ?string $identity = 'id'): int|string
    {
        $quote = fn (string $name): string => Query::identifier($name, $this->driver());
        $sql = 'INSERT INTO ' . $quote($table);
        $columns = [];
        foreach ($data as $column => $value) {
            if (!is_string($column) || (!is_scalar($value) && $value !== null)
                || (is_float($value) && !is_finite($value))) {
                throw new InvalidArgumentException('Insert fields must be named scalar or null values.');
            }
            $columns[] = $quote($column);
        }
        if ($identity !== null) {
            $quote($identity);
        }
        if ($columns !== []) {
            $sql .= ' (' . implode(', ', $columns) . ')';
        }
        if ($this->driver() === 'sqlsrv' && $identity !== null) {
            $sql .= ' OUTPUT INSERTED.' . $quote($identity);
        }
        $sql .= $columns === []
            ? ($this->driver() === 'mysql' ? ' () VALUES ()' : ' DEFAULT VALUES')
            : ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        if ($this->driver() === 'pgsql' && $identity !== null) {
            $sql .= ' RETURNING ' . $quote($identity);
        }
        $statement = $this->execute($sql, array_values($data));
        if ($identity === null) {
            $statement->closeCursor();
            return 0;
        }
        $id = in_array($this->driver(), ['sqlsrv', 'pgsql'], true) ? $statement->fetchColumn() : $this->pdo()->lastInsertId();
        $statement->closeCursor();
        if ($id === false || $id === null) {
            throw new RuntimeException('Cannot retrieve the insert ID.');
        }
        return filter_var($id, FILTER_VALIDATE_INT) !== false ? (int) $id : (string) $id;
    }

    public function limit(string $sql, int $limit, int $offset = 0): string
    {
        if ($limit < 1 || $offset < 0 || !str_starts_with($sql, 'SELECT ')) {
            throw new InvalidArgumentException('Use a SELECT statement, positive limit, and nonnegative offset.');
        }
        if ($this->driver() === 'sqlsrv') {
            if ($offset === 0) {
                return 'SELECT TOP (' . $limit . ') ' . substr($sql, 7);
            }
            return $sql . ' OFFSET ' . $offset . ' ROWS FETCH NEXT ' . $limit . ' ROWS ONLY';
        }
        return $sql . ' LIMIT ' . $limit . ($offset === 0 ? '' : ' OFFSET ' . $offset);
    }

    public function ensureRateLimit(string $table, string $bucket, int $expires): void
    {
        $table = Query::identifier($table, $this->driver());
        $sql = match ($this->driver()) {
            'mysql' => "INSERT INTO $table (bucket, expires_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE bucket = bucket",
            'sqlsrv' => "INSERT INTO $table (bucket, expires_at)
                SELECT CAST(? AS CHAR(64)), CAST(? AS BIGINT)
                WHERE NOT EXISTS (SELECT 1 FROM $table WITH (UPDLOCK, HOLDLOCK) WHERE bucket = ?)",
            default => "INSERT INTO $table (bucket, expires_at) VALUES (?, ?) ON CONFLICT(bucket) DO NOTHING",
        };
        $this->execute($sql, $this->driver() === 'sqlsrv' ? [$bucket, $expires, $bucket] : [$bucket, $expires])->closeCursor();
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
