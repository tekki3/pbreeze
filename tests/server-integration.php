<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use PBreeze\Application;
use PBreeze\Database;
use PBreeze\HttpException;
use PBreeze\Migrator;
use PBreeze\Model;
use PBreeze\Request;

function sqlsrvCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!isset($integrationDriver) || !in_array($integrationDriver, ['sqlsrv', 'pgsql', 'mysql'], true)) {
    throw new RuntimeException('Run mysql-integration.php, sqlsrv-integration.php, or pgsql-integration.php instead.');
}
$prefix = strtoupper($integrationDriver) . '_TEST_';
$label = match ($integrationDriver) { 'pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', default => 'SQL Server' };
$name = getenv($prefix . 'DATABASE') ?: '';
sqlsrvCheck(
    preg_match('/^pbreeze_test_[a-zA-Z0-9_]+$/D', $name) === 1
        && getenv($prefix . 'ALLOW_DESTRUCTIVE') === 'true',
    'Use an empty dedicated pbreeze_test_* database and ' . $prefix . 'ALLOW_DESTRUCTIVE=true.',
);
$config = [
    'driver' => $integrationDriver, 'database' => $name,
    'host' => getenv($prefix . 'HOST') ?: '127.0.0.1',
    'port' => getenv($prefix . 'PORT') ?: match ($integrationDriver) { 'pgsql' => '5432', 'mysql' => '3306', default => '1433' },
    'username' => getenv($prefix . 'USERNAME') ?: '',
    'password' => getenv($prefix . 'PASSWORD') ?: '',
    'encrypt' => 'true',
    'trust_server_certificate' => getenv($prefix . 'TRUST_SERVER_CERTIFICATE') ?: 'false',
    'sslmode' => getenv($prefix . 'SSLMODE') ?: 'verify-full',
    'sslrootcert' => getenv($prefix . 'SSLROOTCERT') ?: '',
    'tls' => getenv($prefix . 'TLS') ?: 'required',
    'ssl_ca' => getenv($prefix . 'SSL_CA') ?: '',
];
$db = new Database(dirname(__DIR__), $config);
sqlsrvCheck((int) $db->execute($integrationDriver === 'pgsql'
    ? "SELECT COUNT(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
       WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%'
       AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f')"
    : ($integrationDriver === 'mysql'
        ? 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        : 'SELECT COUNT(*) FROM sys.tables WHERE is_ms_shipped = 0'))->fetchColumn() === 0,
    'The dedicated test database must contain no user tables.');

$temporary = sys_get_temp_dir() . '/pbreeze-' . $integrationDriver . '-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$migrator = null;
$ownsSchema = false;
try {
    foreach (['storage', 'database', 'database/migrations', 'database/migrations/' . $integrationDriver, 'app', 'app/views'] as $directory) {
        sqlsrvCheck(mkdir($temporary . '/' . $directory, 0700), 'Cannot create an integration-test directory.');
    }
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.php') as $file) {
        sqlsrvCheck(copy($file, $temporary . '/database/migrations/' . basename($file)), 'Cannot copy migration.');
        $override = dirname($file) . '/' . $integrationDriver . '/' . basename($file);
        if ($integrationDriver !== 'mysql' && is_file($override)) {
            sqlsrvCheck(copy($override, $temporary . '/database/migrations/' . $integrationDriver . '/' . basename($file)), 'Cannot copy driver migration.');
        }
    }
    $app = new Application($temporary, [
        'name' => $label . ' integration', 'environment' => 'testing', 'debug' => false,
        'secure_cookie' => false, 'database' => $config,
        'session' => ['driver' => 'database', 'lifetime' => 60, 'lock_timeout' => 1],
        'mail' => ['from' => 'noreply@example.test'],
    ]);
    $db = $app->db;
    $migrator = new Migrator($db, $temporary);
    $ownsSchema = true;
    sqlsrvCheck(count($migrator->migrate()) === 6, 'Expected all six migrations.');
    sqlsrvCheck($migrator->migrate() === [], 'Repeated migration must be a no-op.');
    sqlsrvCheck(!in_array(null, $migrator->status(), true), 'Pending migration after migrate.');
    $bucket = hash('sha256', 'integration-limiter');
    $db->ensureRateLimit('api_limits', $bucket, time() + 60);
    $db->execute('UPDATE api_limits SET attempts = 7 WHERE bucket = ?', [$bucket]);
    $db->ensureRateLimit('api_limits', $bucket, time() + 60);
    sqlsrvCheck((int) $db->execute('SELECT attempts FROM api_limits WHERE bucket = ?', [$bucket])->fetchColumn() === 7,
        'Repeated rate-limit initialization reset the attempt count.');
    $seed = require dirname(__DIR__) . '/database/seeders/DatabaseSeeder.php';
    $db->transaction($seed);
    $db->transaction($seed);
    sqlsrvCheck($db->table('notes')->count() === 1, 'Seeder must be repeatable.');

    $id = $db->table('notes')->insert(['title' => 'Unicode: café 漢字']);
    sqlsrvCheck($db->table('notes')->findOrFail($id)['title'] === 'Unicode: café 漢字', 'Unicode round trip failed.');
    sqlsrvCheck($db->table('notes')->where('id', $id)->exists(), 'exists failed.');
    sqlsrvCheck(count($db->table('notes')->orderBy('id')->limit(1)->offset(1)->get()) === 1, 'OFFSET/FETCH failed.');
    sqlsrvCheck($db->table('notes')->paginate(1, 2)['total'] === 2, 'Pagination count failed.');
    $db->table('notes')->where('id', $id)->update(['title' => 'Revised']);
    sqlsrvCheck($db->table('notes')->findOrFail($id)['title'] === 'Revised', 'Update failed.');
    $db->table('notes')->where('id', $id)->delete();
    sqlsrvCheck(!$db->table('notes')->where('id', $id)->exists(), 'Delete failed.');

    $model = new class($db) extends Model {
        protected string $table = 'notes';
        protected array $fillable = ['title'];
    };
    $note = $model->create(['title' => 'ORM note']);
    $note->update(['title' => 'ORM revised']);
    sqlsrvCheck($note->title === 'ORM revised', 'ORM persistence failed.');
    $note->delete();
    if ($integrationDriver === 'pgsql') {
        $manual = new class($db) extends Model {
            protected string $table = 'notes';
            protected bool $incrementing = false;
            protected array $fillable = ['id', 'title'];
        };
        $manualNote = $manual->create(['id' => 1000000, 'title' => 'Manual key']);
        sqlsrvCheck((int) $manualNote->key() === 1000000, 'Manual primary key failed.');
        $manualNote->delete();
    }
    $before = $db->table('notes')->count();
    try {
        $db->transaction(static function () use ($db): void {
            $db->table('notes')->insert(['title' => 'Rolled back']);
            throw new LogicException('Expected rollback.');
        });
    } catch (LogicException $error) {
        sqlsrvCheck($error->getMessage() === 'Expected rollback.', 'Unexpected transaction exception.');
    }
    sqlsrvCheck($db->table('notes')->count() === $before, 'Transaction did not roll back.');

    $app->session->start();
    $credentials = ['name' => 'Ada', 'email' => 'ada@example.test',
        'password' => 'A long password 42!', 'password_confirmation' => 'A long password 42!'];
    $app->auth->register($credentials, '127.0.0.1');
    $app->auth->register($credentials, '127.0.0.1');
    sqlsrvCheck($db->table('users')->count() === 1, 'Duplicate registration changed user count.');
    $userId = (int) $db->execute('SELECT id FROM users WHERE email = ?', [$credentials['email']])->fetchColumn();
    sqlsrvCheck($userId > 0, 'Registration failed.');
    $tokenFromEmail = static function (string $subject) use ($db): string {
        $body = $db->execute($db->limit('SELECT text_body FROM emails WHERE subject = ? ORDER BY id DESC', 1), [$subject])->fetchColumn();
        sqlsrvCheck(is_string($body) && preg_match('/\?token=([a-f0-9]{64})/', $body, $match) === 1, 'Missing auth email token.');
        return $match[1];
    };
    $app->auth->verifyEmail($tokenFromEmail('Verify your email address'), '127.0.0.1', $credentials);
    $app->auth->login($credentials, '127.0.0.1');
    sqlsrvCheck($app->auth->user()['id'] === $userId, 'Login failed.');
    $issued = $app->api->tokens->create($userId);
    sqlsrvCheck($app->api->tokens->authenticate('Bearer ' . $issued['token'])['id'] === $userId, 'Bearer authentication failed.');
    sqlsrvCheck($app->api->dispatch(new Request('GET', '/api/ping'))->status === 200, 'API dispatch/limiter failed.');
    $app->auth->forgotPassword(['email' => $credentials['email']], '127.0.0.1');
    $app->auth->resetPassword([
        'token' => $tokenFromEmail('Reset your password'),
        'password' => 'Another long password 42!', 'password_confirmation' => 'Another long password 42!',
    ], '127.0.0.1');
    try {
        $app->api->tokens->authenticate('Bearer ' . $issued['token']);
        throw new RuntimeException('Password reset did not revoke the bearer token.');
    } catch (HttpException $error) {
        sqlsrvCheck($error->status === 401, 'Unexpected bearer status.');
    }

    $message = $app->mail->compose(['to' => ['ada@example.test'], 'subject' => 'Queue test', 'text' => 'Hello']);
    $mailId = $app->mail->queue($message);
    $app->mail->queue($message);
    sqlsrvCheck(count($app->mail->store->listing(2)) === 2, 'Mail listing failed.');
    sqlsrvCheck(in_array($mailId, $app->mail->store->pending(100), true), 'Mail pending failed.');
    $app->mail->store->claim($mailId);
    $app->mail->store->fail($mailId, new \PBreeze\Mail\DeliveryException('Fixture failure.', false));
    $app->mail->store->retry($mailId);
    sqlsrvCheck($app->mail->store->find($mailId)['status'] === 'queued', 'Mail transitions failed.');

    $incoming = [
        'source_key' => str_repeat('a', 64), 'uid' => '1', 'uidvalidity' => '1',
        'mailbox' => 'INBOX', 'raw' => 'Fixture message',
        'message' => ['from' => 'ada@example.test', 'to' => 'receiver@example.test',
            'subject' => 'Incoming', 'text' => 'Hello', 'html' => '', 'message_id' => '',
            'headers' => []],
    ];
    $incomingId = $app->mail->store->receive($incoming);
    sqlsrvCheck($db->transaction(static fn () => $app->mail->store->receive($incoming)) === $incomingId,
        'Duplicate IMAP import must preserve the row and allow the transaction to commit.');

    $app->session->put('integration', 'session round trip');
    $sessionId = session_id();
    $app->session->close();
    $sessionHandler = new \PBreeze\DatabaseSessionHandler(new Database($temporary, $config),
        $temporary, $temporary . '/storage', 60, 1);
    try {
        sqlsrvCheck($sessionHandler->validateId($sessionId), 'Stored session ID was rejected.');
        sqlsrvCheck(str_contains($sessionHandler->read($sessionId), 'session round trip'), 'Session payload did not persist.');
        $sessionHandler->destroy($sessionId);
        sqlsrvCheck(!$sessionHandler->validateId($sessionId), 'Destroyed session remained valid.');
    } finally {
        $sessionHandler->close();
    }

    $app->queue->registry()->register('integration.queue.v1',
        static function (array $payload): void {
            if ($payload !== ['value' => 42]) {
                throw new InvalidArgumentException('Invalid integration payload.');
            }
        },
        static function (array $payload, \PBreeze\Queue\ExecutionContext $context): void {
            $context->checkpoint();
        },
    );
    $jobId = $app->queue->dispatch('integration.queue.v1', ['value' => 42],
        queue: 'integration', unique: 'integration-once');
    sqlsrvCheck($app->queue->dispatch('integration.queue.v1', ['value' => 42],
        queue: 'integration', unique: 'integration-once') === $jobId, 'Queue uniqueness failed.');
    $workerId = $app->worker->registry()->register('queue', ['integration']);
    $store = $app->queue->store();
    $claim = $store->claim($workerId, 'queue', ['integration']);
    sqlsrvCheck(is_array($claim) && $claim['id'] === $jobId, 'Queue claim failed.');
    sqlsrvCheck($store->claim($workerId, 'queue', ['integration']) === null, 'Queue job was claimed twice.');
    sqlsrvCheck($store->start($jobId, $claim['claim_token']) !== null, 'Queue start failed.');
    sqlsrvCheck(!$store->finish($jobId, str_repeat('0', 64), 'completed'), 'Stale token acknowledged a job.');
    sqlsrvCheck($store->finish($jobId, $claim['claim_token'], 'completed'), 'Queue completion failed.');
    sqlsrvCheck($store->get($jobId)['status'] === 'completed', 'Queue terminal status failed.');
    $rolledBackJob = null;
    try {
        $db->transaction(static function () use ($app, &$rolledBackJob): void {
            $rolledBackJob = $app->queue->dispatch('integration.queue.v1', ['value' => 42], queue: 'integration');
            throw new LogicException('Expected queue rollback.');
        });
    } catch (LogicException $error) {
        sqlsrvCheck($error->getMessage() === 'Expected queue rollback.', 'Unexpected queue transaction exception.');
    }
    sqlsrvCheck(is_string($rolledBackJob) && $store->get($rolledBackJob) === null,
        'Queue dispatch committed a caller-owned transaction.');
    $app->worker->registry()->stopped($workerId);

    sqlsrvCheck(count($migrator->rollback()) === 6, 'Rollback did not revert all migrations.');
    sqlsrvCheck(count($migrator->migrate()) === 6, 'Schema could not be migrated after rollback.');
    echo "$label integration passed: schema, seed, Unicode CRUD, ORM, pagination, transactions, database sessions, auth/reset, API tokens, rate limits, mail queue, and durable queue claims/uniqueness/rollback. No SMTP was sent.\n";
} finally {
    try {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if ($ownsSchema && $migrator !== null) {
            $migrator->rollback();
            $db->execute('DROP TABLE migrations');
        }
    } finally {
        $paths = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($paths as $path) {
            if ($path->isDir()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        rmdir($temporary);
    }
}
