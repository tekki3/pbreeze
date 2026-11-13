<?php
declare(strict_types=1);

use PBreeze\Database;
use PBreeze\DatabaseSessionHandler;
use PBreeze\Migrator;

/** @return array{resource, array<int, resource>} */
function sessionProcess(string $directory, string $action, ?string $id = null): array
{
    $command = [PHP_BINARY, __DIR__ . '/fixtures/database-session.php', $directory, $action];
    if ($id !== null) {
        $command[] = $id;
    }
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    check(is_resource($process), 'Cannot start session test process.');
    fclose($pipes[0]);
    return [$process, $pipes];
}

function sessionResult(array $process): array
{
    [$handle, $pipes] = $process;
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($handle);
    check($status === 0 && $errors === '', 'Session process failed: ' . $errors);
    return json_decode($output, true, 16, JSON_THROW_ON_ERROR);
}

test('Database session environment settings accept valid limits and reject invalid configuration', static function () use ($root): void {
    $keys = ['SESSION_DRIVER', 'SESSION_LIFETIME', 'SESSION_LOCK_TIMEOUT'];
    $original = [];
    foreach ($keys as $key) {
        $original[$key] = getenv($key);
    }
    try {
        putenv('SESSION_DRIVER=database');
        putenv('SESSION_LIFETIME=7200');
        putenv('SESSION_LOCK_TIMEOUT=10');
        $config = require $root . '/config/app.php';
        same(['driver' => 'database', 'lifetime' => 7200, 'lock_timeout' => 10], $config['session']);
        foreach ([
            ['SESSION_DRIVER', 'redis'], ['SESSION_LIFETIME', '0'], ['SESSION_LIFETIME', '31536001'],
            ['SESSION_LIFETIME', '2.5'], ['SESSION_LOCK_TIMEOUT', '0'], ['SESSION_LOCK_TIMEOUT', '61'],
        ] as [$key, $value]) {
            $valid = getenv($key);
            putenv($key . '=' . $value);
            raises(static fn () => require $root . '/config/app.php', RuntimeException::class);
            putenv($key . '=' . $valid);
        }
        putenv('SESSION_DRIVER=native');
        same('native', (require $root . '/config/app.php')['session']['driver']);
    } finally {
        foreach ($original as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
});

test('Database session handler persists binary payloads, refreshes idle expiry, rejects expired IDs, and cleans up', static function (): void {
    $app = productionApp();
    (new Migrator($app->db, $app->root))->migrate();
    $handler = new DatabaseSessionHandler($app->db, $app->root, $app->storagePath, 60, 1);
    $id = bin2hex(random_bytes(24));
    try {
        same(false, $handler->validateId($id));
        same(false, $handler->validateId('short'));
        same('', $handler->read($id));
        same(true, $handler->write($id, "binary\0\xff'\";"));
        $handler->close();
        $row = $app->db->execute('SELECT * FROM sessions')->fetch();
        same(hash('sha256', $id), $row['id']);
        same("binary\0\xff'\";", base64_decode($row['payload'], true));
        check((int) $row['expires_at'] >= time() + 59);
        same(true, $handler->validateId($id));
        same("binary\0\xff'\";", $handler->read($id));
        $app->db->execute('UPDATE sessions SET expires_at = ?', [time() + 1]);
        $handler->updateTimestamp($id, 'unchanged');
        check((int) $app->db->execute('SELECT expires_at FROM sessions')->fetchColumn() >= time() + 59);
        $handler->close();
        $app->db->execute('UPDATE sessions SET expires_at = ?', [time() - 1]);
        same(false, $handler->validateId($id));
        same('', $handler->read($id));
        $handler->close();
        same(1, $handler->gc(60));
        same(0, $handler->gc(60));
        $handler->write($id, 'remove');
        $handler->destroy($id);
        same(false, $handler->validateId($id));
        raises(static fn () => $handler->read('../bad'), InvalidArgumentException::class);
        raises(static fn () => new DatabaseSessionHandler($app->db, $app->root, $app->storagePath, 0), InvalidArgumentException::class);
    } finally {
        $handler->close();
    }
});

test('Database session server adapters acquire and release scoped locks and use portable transactions', static function () use ($temp): void {
    foreach (['mysql', 'pgsql', 'sqlsrv'] as $driver) {
        $db = new Database($temp, ['driver' => $driver, 'database' => 'test']);
        $pdo = new SqlServerRecordingPdo($driver);
        (new ReflectionProperty(Database::class, 'connection'))->setValue($db, $pdo);
        $pdo->columnResults = match ($driver) {
            'mysql' => ['test', 1, 1],
            'pgsql' => [true, true],
            default => [],
        };
        $handler = new DatabaseSessionHandler($db, $temp, $temp . '/storage', 60, 1);
        $handler->write(str_repeat('a', 48), "payload\0\xff");
        $handler->close();
        same(1, $pdo->commits);
        $sql = implode("\n", $pdo->sql);
        check(str_contains($sql, 'DELETE FROM sessions WHERE id = ?'));
        check(str_contains($sql, 'INSERT INTO sessions'));
        check(str_contains($sql, match ($driver) {
            'mysql' => 'GET_LOCK', 'pgsql' => 'pg_try_advisory_lock', default => 'sp_getapplock',
        }));
        check(str_contains($sql, match ($driver) {
            'mysql' => 'RELEASE_LOCK', 'pgsql' => 'pg_advisory_unlock', default => 'sp_releaseapplock',
        }));
    }
});

test('Database session locking times out without unlocked writes and releases on close', static function (): void {
    $app = productionApp();
    (new Migrator($app->db, $app->root))->migrate();
    $first = new DatabaseSessionHandler($app->db, $app->root, $app->storagePath, 60, 1);
    $second = new DatabaseSessionHandler(new Database($app->root, $app->config['database']), $app->root, $app->storagePath, 60, 1);
    $id = bin2hex(random_bytes(24));
    try {
        $first->write($id, 'first');
        raises(static fn () => $second->write($id, 'lost update'), RuntimeException::class);
        $first->close();
        same('first', $second->read($id));
        $second->close();
        $app->db->execute('UPDATE sessions SET payload = ?', ['!invalid base64!']);
        raises(static fn () => $second->read($id), RuntimeException::class);
    } finally {
        $first->close();
        $second->close();
    }
});

test('Native PHP database sessions keep CSRF, reject fixation, serialize parallel requests, and invalidate regenerated IDs', static function (): void {
    $app = productionApp(['session' => ['driver' => 'database', 'lifetime' => 60, 'lock_timeout' => 2]]);
    check(!file_exists($app->root . '/database/app.sqlite'));
    (new Migrator($app->db, $app->root))->migrate();
    $chosen = bin2hex(random_bytes(24));
    $initial = sessionResult(sessionProcess($app->root, 'increment', $chosen));
    check($initial['id'] !== $chosen, 'Strict mode accepted an unknown client-selected ID.');
    same(1, $initial['count']);
    $read = sessionResult(sessionProcess($app->root, 'read', $initial['id']));
    same($initial['id'], $read['id']);
    same($initial['token'], $read['token']);
    $first = sessionProcess($app->root, 'increment', $initial['id']);
    $second = sessionProcess($app->root, 'increment', $initial['id']);
    $counts = [sessionResult($first)['count'], sessionResult($second)['count']];
    sort($counts);
    same([2, 3], $counts);
    $reset = sessionResult(sessionProcess($app->root, 'reset', $initial['id']));
    check($reset['id'] !== $initial['id']);
    same(0, $reset['count']);
    $old = sessionResult(sessionProcess($app->root, 'read', $initial['id']));
    check($old['id'] !== $initial['id']);
    check($old['token'] !== $initial['token']);
    $app->db->execute('UPDATE sessions SET expires_at = ?', [time() - 1]);
    $expired = sessionResult(sessionProcess($app->root, 'read', $reset['id']));
    check($expired['id'] !== $reset['id']);
    same(0, $expired['count']);
    console($app, ['storage:prepare']);
    same(200, $app->readiness()->status);
    $app->db->execute('DROP TABLE sessions');
    same(503, $app->readiness()->status);
});

test('Database session lifecycle saves error-response state and reports failed writes before sending success', static function (): void {
    $app = productionApp();
    (new Migrator($app->db, $app->root))->migrate();
    mkdir($app->root . '/routes', 0700);
    file_put_contents($app->root . '/routes/web.php', <<<'PHP'
<?php
$router->get('/state-error', static function () use ($app): \PBreeze\Response {
    $app->session->put('count', 9);
    throw new \PBreeze\HttpException(422, 'Expected validation error.');
});
$router->get('/write-error', static function () use ($app): \PBreeze\Response {
    $app->session->put('count', 10);
    $app->db->execute('DROP TABLE sessions');
    return new \PBreeze\Response('This must not be sent.');
});
PHP);
    $initial = sessionResult(sessionProcess($app->root, 'increment'));
    $error = sessionResult(sessionProcess($app->root, 'response-state-error', $initial['id']));
    same(422, $error['status']);
    same('Expected validation error.', $error['body']);
    $read = sessionResult(sessionProcess($app->root, 'read', $initial['id']));
    same(9, $read['count']);
    $failure = sessionResult(sessionProcess($app->root, 'response-write-error', $initial['id']));
    same(500, $failure['status']);
    same('Something went wrong. Please try again later.', $failure['body']);
});
