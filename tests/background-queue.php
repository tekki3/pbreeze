<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Database;
use PBreeze\Logger;
use PBreeze\Queue\ExecutionContext;
use PBreeze\Queue\JobFailure;
use PBreeze\Queue\JobRegistry;
use PBreeze\Queue\Queue;
use PBreeze\Queue\RetryableJobFailure;
use PBreeze\Worker\Worker;

foreach (['Encryption', 'Filesystem', 'Query', 'Database', 'Logger', 'Migrator', 'Application'] as $backgroundDependency) {
    require_once dirname(__DIR__) . '/framework/' . $backgroundDependency . '.php';
}
require_once dirname(__DIR__) . '/framework/queue/Queue.php';
require_once dirname(__DIR__) . '/framework/worker/Worker.php';

function backgroundQueueServices(string $directory): array
{
    $db = new Database($directory, ['driver' => 'sqlite', 'database' => 'work.sqlite']);
    $logger = new Logger($directory);
    $registry = new JobRegistry();
    $registry->register('test-job', static fn (array $payload): bool => isset($payload['action']) && is_string($payload['action']),
        static function (array $payload, ExecutionContext $context) use ($directory): void {
            if ($payload['action'] === 'retry') {
                throw new RetryableJobFailure('private payload must never appear in logs');
            }
            if ($payload['action'] === 'fail') {
                throw new JobFailure('private payload must never appear in logs');
            }
            if ($payload['action'] === 'uncertain') {
                throw new RuntimeException('private payload must never appear in logs');
            }
            if ($payload['action'] === 'crash') {
                exit(19);
            }
            if ($payload['action'] === 'crash-after-work') {
                file_put_contents($directory . '/business-effect', 'committed', FILE_APPEND);
                exit(19);
            }
            if ($payload['action'] === 'output') {
                echo 'private output content';
                fwrite(STDERR, 'private stderr content');
            }
            if ($payload['action'] === 'stop-worker') {
                (new Database($directory, ['driver' => 'sqlite', 'database' => 'work.sqlite']))
                    ->execute('UPDATE background_workers SET stop_requested = 1');
                usleep(300000);
            }
            if ($payload['action'] === 'cancel') {
                while (!$context->isCancelled()) {
                    usleep(10000);
                }
                $context->throwIfCancelled();
            }
            file_put_contents($directory . '/result', $context->idempotencyKey);
        });
    $registry->register('scheduled-task', static fn (array $payload): bool => isset($payload['task_key'], $payload['definition_hash']),
        static function (array $payload, ExecutionContext $context): void {});
    $queue = new Queue($db, $logger, $registry, ['lease_seconds' => 5]);
    $app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
    foreach (['root' => $directory, 'db' => $db, 'log' => $logger] as $property => $value) {
        (new ReflectionProperty(Application::class, $property))->setValue($app, $value);
    }
    return [$db, $queue, new Worker($app, $queue)];
}

if (defined('BACKGROUND_QUEUE_CHILD')) {
    [$backgroundDb, $backgroundQueue, $backgroundWorker] = backgroundQueueServices(dirname($argv[0]));
    exit($backgroundWorker->executeChild($argv[2] ?? ''));
}
if (($argv[1] ?? '') === '--background-claim') {
    [$backgroundDb, $backgroundQueue] = backgroundQueueServices($argv[2]);
    $backgroundClaim = $backgroundQueue->store()->claim($argv[3], 'queue', ['default']);
    echo $backgroundClaim['id'] ?? 'none';
    exit(0);
}

$backgroundStandalone = !function_exists('test');
if ($backgroundStandalone) {
    $backgroundFailures = 0;
    function check(bool $condition, string $message = 'Check failed.'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
    function same(mixed $expected, mixed $actual): void
    {
        check($expected === $actual, 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    function test(string $name, callable $work): void
    {
        global $backgroundFailures;
        try {
            $work();
            echo "OK    $name\n";
        } catch (Throwable $error) {
            $backgroundFailures++;
            fwrite(STDERR, "FAIL  $name: {$error->getMessage()}\n");
        }
    }
}

function backgroundQueueFixture(): array
{
    $directory = __DIR__ . '/.background-queue-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    register_shutdown_function(static function () use ($directory): void {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    });
    file_put_contents($directory . '/breeze', '<?php define("BACKGROUND_QUEUE_CHILD", true); require ' . var_export(__FILE__, true) . ';');
    [$db, $queue, $worker] = backgroundQueueServices($directory);
    $migration = require dirname(__DIR__) . '/database/migrations/20261114120000_create_background_work.php';
    $migration->up($db->pdo());
    return [$db, $queue, $worker, $directory];
}

function backgroundReject(callable $work, string $class = InvalidArgumentException::class): void
{
    try {
        $work();
    } catch (Throwable $error) {
        check($error instanceof $class, 'Unexpected exception type: ' . $error::class);
        return;
    }
    throw new RuntimeException('Expected an exception.');
}

test('Background queue validates registry, JSON size, options, lazy loading, and synchronous context', static function (): void {
    [$db, $queue, $worker, $directory] = backgroundQueueFixture();
    backgroundReject(static fn () => $queue->dispatch('not-registered'));
    backgroundReject(static fn () => $queue->dispatch('test-job', []));
    backgroundReject(static fn () => $queue->dispatch('test-job', ['action' => 'done'], '../queue'));
    backgroundReject(static fn () => $queue->dispatch('test-job', ['action' => str_repeat('x', 65536)]));
    backgroundReject(static fn () => $queue->dispatch('test-job', ['action' => 'done'], tries: 101));
    backgroundReject(static fn () => $queue->dispatch('test-job', ['action' => 'done'], backoff: [-1]));
    backgroundReject(static fn () => $queue->dispatch('test-job', ['action' => 'done', 'nested' => [new stdClass()]]));
    backgroundReject(static fn () => $queue->dispatch('test-job', ['action' => 'done', 'nested' => [INF]]));
    $queue->runNow('test-job', ['action' => 'done']);
    same(32, strlen(file_get_contents($directory . '/result')));
    $loads = 0;
    $registry = new JobRegistry(static function (JobRegistry $registry) use (&$loads): void {
        $loads++;
        $registry->register('lazy', static fn (array $p): bool => true, static function (): void {});
    });
    $registry->validate('lazy', []);
    $registry->validate('lazy', []);
    same(1, $loads);
    same([], $queue->store()->status());
});

test('Background constructors stay lazy and dispatch honors configured defaults with explicit overrides', static function (): void {
    [$db, $queue, $worker, $directory] = backgroundQueueFixture();
    $configured = new Queue($db, new Logger($directory), $queue->registry(),
        ['default_queue' => 'reports', 'default_tries' => 7, 'timeout' => 42]);
    $id = $configured->dispatch('test-job', ['action' => 'done']);
    $row = $configured->store()->get($id);
    same('reports', $row['queue_name']);
    same(7, (int) $row['max_tries']);
    same(42, (int) $row['timeout_seconds']);
    $id = $configured->dispatch('test-job', ['action' => 'done'], 'explicit', tries: 2, timeout: 8);
    $row = $configured->store()->get($id);
    same('explicit', $row['queue_name']);
    same(2, (int) $row['max_tries']);
    same(8, (int) $row['timeout_seconds']);
    $lazyDb = new Database($directory, ['driver' => 'sqlite', 'database' => 'lazy.sqlite']);
    $lazyQueue = new Queue($lazyDb, new Logger($directory), new JobRegistry());
    $lazyWorker = new Worker((new ReflectionClass(Application::class))->newInstanceWithoutConstructor(), $lazyQueue);
    same(false, file_exists($directory . '/lazy.sqlite'));
    backgroundReject(static fn () => $configured->dispatch('test-job', ['action' => 'done'], priority: 4));
    backgroundReject(static fn () => $configured->dispatch('test-job', ['action' => 'done'], queue: str_repeat('q', 81)));
    $configured->dispatch('test-job', ['action' => 'done'], queue: str_repeat('q', 80), priority: 3);
    $small = new Queue($db, new Logger($directory), $queue->registry(), ['payload_max_bytes' => 17]);
    $small->dispatch('test-job', ['action' => 'done']);
    backgroundReject(static fn () => $small->dispatch('test-job', ['action' => 'longer']));
    backgroundReject(static fn () => new Queue($db, new Logger($directory), $queue->registry(), ['payload_max_bytes' => 65537]));
    backgroundReject(static fn () => new Queue($db, new Logger($directory), $queue->registry(), ['priority_aging_seconds' => 0]));
    backgroundReject(static fn () => new Queue($db, new Logger($directory), $queue->registry(), ['driver' => 'redis']));
    backgroundReject(static fn () => new Worker((new ReflectionClass(Application::class))->newInstanceWithoutConstructor(), $lazyQueue, ['heartbeat_interval' => 0]));
    backgroundReject(static fn () => $worker->run(sleep: 0));
    backgroundReject(static fn () => $worker->registry()->listing(0));
});

test('Background dispatch joins transactions and scheduled occurrence tombstones remain stable', static function (): void {
    [$db, $queue] = backgroundQueueFixture();
    $db->pdo()->beginTransaction();
    $queue->dispatch('test-job', ['action' => 'done'], unique: 'atomic');
    check($db->pdo()->inTransaction());
    $db->pdo()->rollBack();
    same([], $queue->store()->status());
    same(0, (int) $db->execute('SELECT COUNT(*) FROM background_locks')->fetchColumn());
    $hash = hash('sha256', 'definition');
    $id = $queue->enqueueScheduled('daily', $hash, 100, 'direct', 'default', 3, [0], 10);
    same($id, $queue->enqueueScheduled('daily', $hash, 100, 'direct', 'default', 3, [0], 10));
    same($id, $queue->enqueueScheduled('daily', hash('sha256', 'changed'), 100, 'direct', 'default', 3, [0], 10));
    $row = $queue->store()->claim('test', 'scheduler', ['default']);
    same($id, $row['id']);
    check($queue->store()->finish($id, $row['claim_token'], 'completed'));
    same(1, $queue->store()->prune($queue->store()->now() + 1));
    same($id, $queue->enqueueScheduled('daily', $hash, 100, 'direct', 'default', 3, [0], 10));
    same([], $queue->store()->status());
    $db->pdo()->beginTransaction();
    $queue->enqueueScheduled('daily', $hash, 101, 'queued', 'default', 3, [], 10);
    $db->pdo()->rollBack();
    same(1, (int) $db->execute('SELECT COUNT(*) FROM scheduled_occurrences')->fetchColumn());
});

test('Background task identifiers preserve case-sensitive identity', static function (): void {
    [$db, $queue] = backgroundQueueFixture();
    $hash = hash('sha256', 'case-sensitive');
    foreach (['Cleanup', 'cleanup'] as $task) {
        $db->insert('pbreeze_scheduler_tasks', ['task_key' => $task, 'definition_hash' => $hash,
            'next_run_at' => 100, 'last_scheduled_at' => null, 'updated_at' => 1], null);
    }
    same(2, (int) $db->execute('SELECT COUNT(*) FROM pbreeze_scheduler_tasks')->fetchColumn());
    $upper = $queue->enqueueScheduled('Cleanup', $hash, 100, 'queued', 'default', 1, [], 10);
    $lower = $queue->enqueueScheduled('cleanup', $hash, 100, 'queued', 'default', 1, [], 10);
    check($upper !== $lower);
    same(2, (int) $db->execute('SELECT COUNT(*) FROM scheduled_occurrences')->fetchColumn());
});

test('Background claims fence tokens, age priorities, quarantine stale reservations, and retain unique locks', static function (): void {
    [$db, $queue] = backgroundQueueFixture();
    $low = $queue->dispatch('test-job', ['action' => 'done'], priority: 0, unique: 'guard');
    same($low, $queue->dispatch('test-job', ['action' => 'done'], unique: 'guard'));
    $queue->dispatch('test-job', ['action' => 'done'], priority: 3);
    $db->execute('UPDATE background_jobs SET created_at = created_at - 1200 WHERE id = ?', [$low]);
    $db->execute('UPDATE background_jobs SET available_at = available_at - 1200 WHERE id = ?', [$low]);
    $row = $queue->store()->claim('worker', 'queue', ['default']);
    same($low, $row['id']);
    same(false, $queue->store()->finish($low, str_repeat('0', 64), 'completed'));
    same(null, $queue->store()->start($low, str_repeat('0', 64)));
    $db->execute('UPDATE background_jobs SET lease_until = 1 WHERE id = ?', [$low]);
    same(1, $queue->store()->quarantineExpired());
    same('uncertain', $queue->store()->get($low)['status']);
    same(null, $queue->store()->start($low, $row['claim_token']));
    same($low, $queue->dispatch('test-job', ['action' => 'done'], unique: 'guard'));
    backgroundReject(static fn () => $queue->store()->retry($low), RuntimeException::class);
    same(0, $queue->store()->prune($queue->store()->now() + 1));
    $queue->store()->resolve($low, 'retry');
    same(false, $queue->store()->finish($low, $row['claim_token'], 'completed'));
    $new = $queue->store()->claim('worker2', 'queue', ['default'], $low);
    same(2, (int) $new['attempts']);
    check($new['claim_token'] !== $row['claim_token']);
});

test('Background execution retries only declared failures, caps tries, and sanitizes logs', static function (): void {
    [$db, $queue, $worker, $directory] = backgroundQueueFixture();
    foreach (['retry' => 'failed', 'fail' => 'failed', 'uncertain' => 'uncertain'] as $action => $expected) {
        $id = $queue->dispatch('test-job', ['action' => $action], tries: 2, backoff: [0]);
        $claim = $queue->store()->claim('worker', 'queue', ['default'], $id);
        same(1, $worker->executeChild($id, $claim['claim_token']));
        if ($action === 'retry') {
            same('pending', $queue->store()->get($id)['status']);
            $claim = $queue->store()->claim('worker', 'queue', ['default'], $id);
            same(1, $worker->executeChild($id, $claim['claim_token']));
        }
        same($expected, $queue->store()->get($id)['status']);
    }
    $log = file_get_contents(glob($directory . '/storage/logs/app-*.jsonl')[0]);
    check(!str_contains($log, 'private payload'));
    same(3, count($queue->store()->failed()));
    $failed = $queue->store()->failed()[0];
    if ($failed['status'] === 'failed') {
        $queue->store()->retry($failed['id']);
        same('pending', $queue->store()->get($failed['id'])['status']);
    }
});

test('Background overlap exclusion survives expired leases until explicit operator resolution', static function (): void {
    [$db, $queue] = backgroundQueueFixture();
    $hash = hash('sha256', 'definition');
    $first = $queue->enqueueScheduled('exclusive', $hash, 100, 'queued', 'default', 3, [0], 10, false, true);
    $second = $queue->enqueueScheduled('exclusive', $hash, 101, 'queued', 'default', 3, [0], 10, false, true);
    $claim = $queue->store()->claim('worker', 'queue', ['default'], $first);
    same(null, $queue->store()->claim('worker2', 'queue', ['default'], $second));
    $db->execute('UPDATE background_jobs SET lease_until = 1 WHERE id = ?', [$first]);
    $queue->store()->quarantineExpired();
    same(null, $queue->store()->claim('worker2', 'queue', ['default'], $second));
    $queue->store()->resolve($first, 'completed');
    check($queue->store()->claim('worker2', 'queue', ['default'], $second) !== null);
});

test('Background more than one hundred overlap-blocked occurrences do not starve unrelated work', static function (): void {
    [$db, $queue] = backgroundQueueFixture();
    $hash = hash('sha256', 'backlog');
    $first = $queue->enqueueScheduled('backlog', $hash, 1, 'queued', 'default', 3, [0], 10, false, true);
    check($queue->store()->claim('first', 'queue', ['default'], $first) !== null);
    for ($i = 2; $i <= 105; $i++) {
        $queue->enqueueScheduled('backlog', $hash, $i, 'queued', 'default', 3, [0], 10, false, true);
    }
    $id = $queue->dispatch('test-job', ['action' => 'done'], priority: 0);
    same($id, $queue->store()->claim('second', 'queue', ['default'])['id']);
});

test('Background caught retry-safe failures and pre-handler invalid payloads have explicit outcomes and safe diagnostics', static function (): void {
    [$db, $queue, $worker, $directory] = backgroundQueueFixture();
    $safe = $queue->dispatch('test-job', ['action' => 'uncertain'], retrySafe: true, backoff: [0]);
    $claim = $queue->store()->claim('test', 'queue', ['default'], $safe);
    same(1, $worker->executeChild($safe, $claim['claim_token']));
    same('pending', $queue->store()->get($safe)['status']);
    $attempt = $db->execute('SELECT * FROM background_attempts WHERE job_id = ?', [$safe])->fetch();
    same(RuntimeException::class, $attempt['exception_class']);
    check((int) $attempt['duration_ms'] >= 0);
    check((int) $attempt['exception_line'] > 0);
    $history = $queue->store()->history($safe);
    same(RuntimeException::class, $history[0]['exception_class']);
    check(!array_key_exists('claim_token', $history[0]));
    $jobs = $queue->store()->jobs('pending', 50, 'default');
    same($safe, $jobs[0]['id']);
    check(!array_key_exists('payload', $jobs[0]));
    check(!array_key_exists('claim_token', $jobs[0]));
    same(['pending' => 1], $queue->store()->status('default'));
    same([], $queue->store()->jobs('pending', 50, 'missing'));
    backgroundReject(static fn () => $queue->store()->history('wrong'));
    backgroundReject(static fn () => $queue->store()->jobs('wrong'));
    foreach (['unknown', 'invalid', 'oversized'] as $mode) {
        $id = $queue->dispatch('test-job', ['action' => 'done']);
        if ($mode === 'unknown') {
            $db->execute('UPDATE background_jobs SET job_type = ? WHERE id = ?', ['unknown', $id]);
        } else {
            $db->execute('UPDATE background_jobs SET payload = ? WHERE id = ?', [$mode === 'invalid' ? '{"wrong":1}' : str_repeat('x', 65537), $id]);
        }
        $claim = $queue->store()->claim('test', 'queue', ['default'], $id);
        same(1, $worker->executeChild($id, $claim['claim_token']));
        same('failed', $queue->store()->get($id)['status']);
    }
    same(false, file_exists($directory . '/result'));
});

test('Background injected test clock drives retry readiness and aging uses availability, not creation', static function (): void {
    [$db, $queue] = backgroundQueueFixture();
    $clock = new class implements \PBreeze\Clock {
        public int $seconds = 2000000000;
        public function now(): int { return $this->seconds; }
        public function monotonic(): float { return $this->seconds * 1000.0; }
    };
    $store = new \PBreeze\Queue\DatabaseQueueStore($db, 30, 60, $clock);
    $id = $queue->dispatch('test-job', ['action' => 'done']);
    $db->execute('UPDATE background_jobs SET available_at = ?, created_at = ? WHERE id = ?', [$clock->seconds, 1, $id]);
    $claim = $store->claim('test', 'queue', ['default'], $id);
    check($store->finish($id, $claim['claim_token'], 'retry'));
    same(null, $store->claim('test', 'queue', ['default'], $id));
    $clock->seconds += 60;
    check($store->claim('test', 'queue', ['default'], $id) !== null);
});

test('Background backoff, explicit retry, token cancellation, worker limits, and lifecycle hooks are bounded', static function (): void {
    [$db, $queue, $worker] = backgroundQueueFixture();
    $id = $queue->dispatch('test-job', ['action' => 'retry'], backoff: [60]);
    $claim = $queue->store()->claim('test', 'queue', ['default'], $id);
    check($queue->store()->finish($id, $claim['claim_token'], 'retry'));
    same(null, $queue->store()->claim('test', 'queue', ['default'], $id));
    check((int) $queue->store()->get($id)['available_at'] >= $queue->store()->now() + 59);
    $db->execute('UPDATE background_jobs SET available_at = 0 WHERE id = ?', [$id]);
    $claim = $queue->store()->claim('test', 'queue', ['default'], $id, 2);
    $queue->store()->cancel($id, str_repeat('0', 64));
    same(false, $queue->store()->cancelled($id, $claim['claim_token']));
    $queue->store()->cancel($id, $claim['claim_token']);
    same(true, $queue->store()->cancelled($id, $claim['claim_token']));
    check($queue->store()->finish($id, $claim['claim_token'], 'failed'));
    $queue->store()->retry($id);
    same((int) $claim['attempts'] + 1, (int) $queue->store()->get($id)['max_tries']);
    $db->execute('UPDATE background_jobs SET available_at = available_at + 1000 WHERE id = ?', [$id]);
    $events = [];
    $worker->events->on('job.finished', static function (): void { throw new RuntimeException('private listener error'); });
    $worker->events->on('job.finished', static function (array $metadata) use (&$events): void { $events[] = $metadata; });
    $done = $queue->dispatch('test-job', ['action' => 'done']);
    same(0, $worker->run(maxJobs: 1));
    same('completed', $queue->store()->get($done)['status']);
    same($done, $events[0]['id']);
    $workerId = $worker->registry()->register('queue', ['default']);
    same(true, $worker->registry()->heartbeat($workerId));
    $worker->registry()->stop($workerId);
    same(false, $worker->registry()->heartbeat($workerId));
    $worker->registry()->stopped($workerId);
    same(0, $worker->run(sleep: 0.01, maxTime: 1));
    $bounded = $queue->dispatch('test-job', ['action' => 'retry'], backoff: [0]);
    same(true, $worker->runOnce(onlyJobId: $bounded, tries: 1));
    same('failed', $queue->store()->get($bounded)['status']);
    same(1, (int) $queue->store()->get($bounded)['attempts']);
    backgroundReject(static fn () => $worker->runOnce(tries: 0));
    same([30, 60, 120, 240, 300, 300], Queue::exponentialBackoff(30, 6, 300));
    same([0, 0, 0], Queue::exponentialBackoff(0, 3, 0));
    backgroundReject(static fn () => Queue::exponentialBackoff(301, 3, 300));
    backgroundReject(static fn () => Queue::exponentialBackoff(1, 101));
});

test('Background native child process completes, quarantines crashes, and cooperatively cancels on timeout', static function (): void {
    [$db, $queue, $worker, $directory] = backgroundQueueFixture();
    $id = $queue->dispatch('test-job', ['action' => 'done']);
    same(true, $worker->runOnce());
    same('completed', $queue->store()->get($id)['status']);
    same($id, file_get_contents($directory . '/result'));
    same(false, $worker->runOnce());
    $crash = $queue->dispatch('test-job', ['action' => 'crash']);
    same(true, $worker->runOnce());
    same('uncertain', $queue->store()->get($crash)['status']);
    $cancel = $queue->dispatch('test-job', ['action' => 'cancel'], timeout: 1);
    same(true, $worker->runOnce());
    same('uncertain', $queue->store()->get($cancel)['status']);
    same(1, (int) $queue->store()->get($cancel)['cancel_requested']);
    same(4, count($worker->registry()->listing()));
    foreach ($worker->registry()->listing() as $row) {
        same('stopped', $row['status']);
    }
    same(2, $worker->executeChild($id, str_repeat('0', 64)));
    $output = $queue->dispatch('test-job', ['action' => 'output']);
    same(true, $worker->runOnce(onlyJobId: $output));
    $log = file_get_contents(glob($directory . '/storage/logs/app-*.jsonl')[0]);
    check(str_contains($log, 'output was discarded'));
    check(!str_contains($log, 'private output'));
    check(!str_contains($log, 'private stderr'));
    $listed = $worker->registry()->listing()[0];
    check($listed['hostname'] !== '');
    check($listed['stopped_at'] !== null);
    same(false, $listed['suspected_offline']);
});

test('Background separate PHP claimants reserve each job at most once', static function (): void {
    [$db, $queue, $worker, $directory] = backgroundQueueFixture();
    $id = $queue->dispatch('test-job', ['action' => 'done']);
    $processes = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--background-claim', $directory, 'worker-' . $i],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(is_resource($process));
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $results[] = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        same(0, proc_close($process));
        same('', $error);
    }
    sort($results);
    $expected = [$id, 'none'];
    sort($expected);
    same($expected, $results);
    same(1, (int) $queue->store()->get($id)['attempts']);
});

test('A crash after committed business work is quarantined rather than automatically repeated', static function (): void {
    [$db, $queue, $worker, $directory] = backgroundQueueFixture();
    $id = $queue->dispatch('test-job', ['action' => 'crash-after-work'], unique: 'business-operation');
    same(true, $worker->runOnce());
    same('committed', file_get_contents($directory . '/business-effect'));
    same('uncertain', $queue->store()->get($id)['status']);
    same(false, $worker->runOnce());
    same($id, $queue->dispatch('test-job', ['action' => 'crash-after-work'], unique: 'business-operation'));
    same('committed', file_get_contents($directory . '/business-effect'));
    $queue->store()->resolve($id, 'completed');
    same('completed', $queue->store()->get($id)['status']);
    same('committed', file_get_contents($directory . '/business-effect'));
});

test('Background graceful stop finishes current work without cancellation and stops new claims', static function (): void {
    [$db, $queue, $original] = backgroundQueueFixture();
    $app = (new ReflectionProperty(Worker::class, 'app'))->getValue($original);
    $worker = new Worker($app, $queue, ['heartbeat_interval' => 0.1]);
    $first = $queue->dispatch('test-job', ['action' => 'stop-worker'], priority: 3);
    $second = $queue->dispatch('test-job', ['action' => 'done']);
    same(0, $worker->run(maxJobs: 2, maxTime: 3));
    same('completed', $queue->store()->get($first)['status']);
    same(0, (int) $queue->store()->get($first)['cancel_requested']);
    same('pending', $queue->store()->get($second)['status']);
});

if ($backgroundStandalone) {
    exit($backgroundFailures === 0 ? 0 : 1);
}
