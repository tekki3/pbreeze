<?php
declare(strict_types=1);

final class SqlServerRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $sql = [];
    /** @var list<array<int|string, array{mixed, int}>> */
    public array $bindings = [];
    public bool $transaction = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    public int $lockResult = 0;

    public function __construct() {}

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'sqlsrv' : null;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sql[] = $query;
        return new SqlServerRecordingStatement($this, $query);
    }

    public function exec(string $statement): int|false
    {
        $this->sql[] = $statement;
        return 0;
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function commit(): bool
    {
        $this->commits++;
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->rollbacks++;
        $this->transaction = false;
        return true;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        throw new RuntimeException('SQL Server inserts must use OUTPUT, never connection-wide identity.');
    }
}

final class SqlServerRecordingStatement extends PDOStatement
{
    /** @var array<int|string, array{mixed, int}> */
    private array $values = [];

    public function __construct(private SqlServerRecordingPdo $connection, private string $sql) {}

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->values[$param] = [$value, $type];
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $this->connection->bindings[] = $this->values;
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->sql, 'sp_getapplock')) {
            return $this->connection->lockResult;
        }
        return str_contains($this->sql, 'OUTPUT INSERTED.') ? '42' : 0;
    }

    public function rowCount(): int
    {
        return 1;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}

/** @return array{PBreeze\Database, SqlServerRecordingPdo} */
function sqlServerRecordingDatabase(): array
{
    global $temp;
    $db = new \PBreeze\Database($temp, ['driver' => 'sqlsrv', 'database' => 'test']);
    $pdo = new SqlServerRecordingPdo();
    (new ReflectionProperty(\PBreeze\Database::class, 'connection'))->setValue($db, $pdo);
    return [$db, $pdo];
}

test('SQL Server quotes identifiers and compiles TOP and ordered OFFSET/FETCH', static function (): void {
    [$db, $pdo] = sqlServerRecordingDatabase();
    same('[order]', \PBreeze\Query::identifier('order', 'sqlsrv'));
    same('`order`', \PBreeze\Query::identifier('order'));
    raises(static fn () => \PBreeze\Query::identifier('id]; DROP TABLE users', 'sqlsrv'), InvalidArgumentException::class);
    $db->table('notes')->where('title', 'Ada')->orderBy('id', 'desc')->limit(5)->get();
    same('SELECT TOP (5) * FROM [notes] WHERE [title] = ? ORDER BY [id] DESC', end($pdo->sql));
    same([1 => ['Ada', PDO::PARAM_STR]], end($pdo->bindings));
    $db->table('notes')->orderBy('id')->limit(10)->offset(20)->get();
    same('SELECT * FROM [notes] ORDER BY [id] ASC OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', end($pdo->sql));
    $db->table('notes')->limit(2)->offset(1)->get();
    same('SELECT * FROM [notes] ORDER BY (SELECT NULL) OFFSET 1 ROWS FETCH NEXT 2 ROWS ONLY', end($pdo->sql));
    $db->table('notes')->where('id', 42)->exists();
    same('SELECT TOP (1) 1 FROM [notes] WHERE [id] = ?', end($pdo->sql));
    raises(static fn () => $db->table('notes')->offset(2)->get(), LogicException::class);
    raises(static fn () => $db->limit('DELETE FROM notes', 2), InvalidArgumentException::class);
    raises(static fn () => $db->limit('SELECT * FROM notes', 0), InvalidArgumentException::class);
});

test('SQL Server inserts use scoped OUTPUT, custom keys, default rows, and typed bindings', static function (): void {
    [$db, $pdo] = sqlServerRecordingDatabase();
    same(42, $db->table('notes')->insert(['title' => 'Unicode: café', 'active' => true, 'extra' => null]));
    same('INSERT INTO [notes] ([title], [active], [extra]) OUTPUT INSERTED.[id] VALUES (?, ?, ?)', end($pdo->sql));
    same([1 => ['Unicode: café', PDO::PARAM_STR], 2 => [true, PDO::PARAM_BOOL], 3 => [null, PDO::PARAM_NULL]], end($pdo->bindings));
    same(42, $db->table('things')->insert([], 'thing_id'));
    same('INSERT INTO [things] OUTPUT INSERTED.[thing_id] DEFAULT VALUES', end($pdo->sql));
    same(0, $db->table('keys')->insert(['code' => 'ABC'], null));
    same('INSERT INTO [keys] ([code]) VALUES (?)', end($pdo->sql));
    $db->table('notes')->where('id', 42)->update(['title' => 'Revised']);
    same('UPDATE [notes] SET [title] = ? WHERE [id] = ?', end($pdo->sql));
    $db->table('notes')->where('id', 42)->delete();
    same('DELETE FROM [notes] WHERE [id] = ?', end($pdo->sql));
    raises(static fn () => $db->table('notes')->delete(), LogicException::class);
    raises(static fn () => $db->insert('notes', ['title' => []]), InvalidArgumentException::class);
});

test('SQL Server mail limits and rate-limit initialization use the native dialect', static function (): void {
    [$db, $pdo] = sqlServerRecordingDatabase();
    $store = new \PBreeze\Mail\MailStore($db);
    $message = new \PBreeze\Mail\Message('sender@example.test', ['ada@example.test'], 'Hello', 'Body');
    same(42, $store->queue($message));
    check(str_contains(end($pdo->sql), 'OUTPUT INSERTED.[id]'));
    $store->listing(5);
    check(str_starts_with(end($pdo->sql), 'SELECT TOP (5) id, direction'));
    $store->pending(3);
    check(str_starts_with(end($pdo->sql), 'SELECT TOP (3) id FROM emails'));
    $db->ensureRateLimit('api_limits', 'bucket', 100);
    check(str_contains(end($pdo->sql), '[api_limits] WITH (UPDLOCK, HOLDLOCK)'));
    same([1 => ['bucket', PDO::PARAM_STR], 2 => [100, PDO::PARAM_INT], 3 => ['bucket', PDO::PARAM_STR]], end($pdo->bindings));
});

test('SQL Server migrations select overrides, use transactional DDL, and lock the database', static function (): void {
    global $root, $temp;
    [$db, $pdo] = sqlServerRecordingDatabase();
    $migrator = new \PBreeze\Migrator($db, $root);
    $files = (new ReflectionMethod($migrator, 'files'))->invoke($migrator);
    same(4, count($files));
    foreach ($files as $file) {
        check(str_contains($file, '/sqlsrv/'), 'SQL Server migration override was not selected.');
        (require $file)->up($pdo);
    }
    $schema = implode("\n", $pdo->sql);
    check(str_contains($schema, 'IDENTITY(1,1)') && str_contains($schema, 'NVARCHAR(MAX)'), 'Missing SQL Server identity or Unicode text.');
    check(str_contains($schema, 'WHERE source_key IS NOT NULL'), 'Missing nullable unique filtered index.');
    check(!str_contains($schema, 'AUTO_INCREMENT') && !str_contains($schema, 'AUTOINCREMENT'), 'Foreign identity syntax found.');
    check(!str_contains($schema, 'TIMESTAMP'), 'SQL Server timestamp is not a date/time type.');
    (new ReflectionMethod($migrator, 'atomic'))->invoke($migrator, static fn (PDO $connection) => $connection->exec('SELECT 1'));
    same(1, $pdo->commits);
    raises(static fn () => (new ReflectionMethod($migrator, 'atomic'))->invoke($migrator, static function (): void {
        throw new RuntimeException('Abort migration.');
    }), RuntimeException::class);
    same(1, $pdo->rollbacks);
    $isolated = new \PBreeze\Migrator($db, $temp);
    (new ReflectionMethod($isolated, 'locked'))->invoke($isolated, static fn () => true);
    check(str_contains(implode("\n", $pdo->sql), 'sp_releaseapplock'), 'Migration application lock was not released.');
    $pdo->lockResult = -1;
    raises(static fn () => (new ReflectionMethod($isolated, 'locked'))->invoke($isolated, static fn () => true), RuntimeException::class);
});

test('SQL Server configuration rejects DSN injection and insecure flag typos before connecting', static function (): void {
    global $temp;
    $base = ['driver' => 'sqlsrv', 'database' => 'pbreeze', 'host' => 'localhost', 'port' => '1433'];
    foreach ([
        ['host' => 'localhost;Encrypt=false'], ['database' => 'db;Encrypt=false'],
        ['port' => '1433;Database=other'], ['port' => '0'],
        ['encrypt' => 'tru'], ['trust_server_certificate' => 'yes'],
    ] as $invalid) {
        raises(static fn () => (new \PBreeze\Database($temp, array_replace($base, $invalid)))->pdo(), RuntimeException::class);
    }
    if (!in_array('sqlsrv', PDO::getAvailableDrivers(), true)) {
        try {
            (new \PBreeze\Database($temp, $base))->pdo();
            throw new LogicException('Expected missing driver error.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'pdo_sqlsrv'));
        }
    }
});
