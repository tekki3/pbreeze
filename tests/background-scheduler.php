<?php
declare(strict_types=1);

require_once $root . '/framework/Clock.php';
require_once $root . '/framework/queue/Queue.php';
require_once $root . '/framework/scheduler/ScheduleExpression.php';
require_once $root . '/framework/scheduler/IntervalExpression.php';
require_once $root . '/framework/scheduler/CalendarExpression.php';
require_once $root . '/framework/scheduler/ScheduledTask.php';
require_once $root . '/framework/scheduler/Schedule.php';
require_once $root . '/framework/scheduler/SchedulerRepository.php';
require_once $root . '/framework/scheduler/Scheduler.php';

use PBreeze\Scheduler\CalendarExpression;
use PBreeze\Database;
use PBreeze\Scheduler\IntervalExpression;
use PBreeze\Logger;
use PBreeze\Scheduler\Schedule;
use PBreeze\Scheduler\Scheduler;
use PBreeze\Scheduler\ScheduleExpression;
use PBreeze\Scheduler\ScheduledTask;
use PBreeze\Queue\ExecutionContext;
use PBreeze\Queue\JobFailure;
use PBreeze\Queue\JobRegistry;
use PBreeze\Queue\Queue;

final class BackgroundSchedulerTestClock implements \PBreeze\Clock
{
    public function __construct(public int $timestamp) {}
    public function now(): int { return $this->timestamp; }
    public function monotonic(): float { return (float) $this->timestamp * 1000; }
}

/** @return array{Database, Queue, BackgroundSchedulerTestClock} */
function backgroundSchedulerServices(string $root, ?BackgroundSchedulerTestClock $clock = null, bool $reject = false): array
{
    $clock ??= new BackgroundSchedulerTestClock(1700000100);
    $db = new Database($root, ['driver' => 'sqlite', 'database' => ':memory:']);
    $migration = require $root . '/database/migrations/20261114120000_create_background_work.php';
    $migration->up($db->pdo());
    $registry = new JobRegistry();
    $registry->register('scheduled-task',
        static fn (array $payload): bool => !$reject || ($payload['task_key'] ?? '') !== 'rejected',
        static function (array $payload, ExecutionContext $context): void {});
    return [$db, new Queue($db, new Logger($root), $registry, [], $clock), $clock];
}

test('Schedule expressions use UTC anchored intervals and local calendar recurrences', static function (): void {
    $utc = new DateTimeZone('UTC');
    same(120, (new IntervalExpression(60))->nextAfter(60, $utc));
    same(150, (new IntervalExpression(30))->nextAfter(121, $utc));
    same(['type' => 'utc-interval', 'seconds' => 60], (new IntervalExpression(60))->fingerprint());

    $schedule = new Schedule('UTC');
    $schedule->task('second')->everySecond()->run(static function (): void {});
    $schedule->task('seconds')->everySeconds(15)->run(static function (): void {});
    $schedule->task('minute')->everyMinute()->run(static function (): void {});
    $schedule->task('minutes')->everyMinutes(5)->run(static function (): void {});
    $schedule->task('hour')->hourly()->run(static function (): void {});
    $schedule->task('hours')->everyHours(2)->run(static function (): void {});
    $schedule->task('daily')->daily()->run(static function (): void {});
    $schedule->task('daily-at')->dailyAt('08:15')->run(static function (): void {});
    $schedule->task('weekly')->weekly()->run(static function (): void {});
    $schedule->task('weekly-on')->weeklyOn(1, '08:15')->run(static function (): void {});
    $schedule->task('monthly')->monthly(31, '08:15')->run(static function (): void {});
    $custom = new class implements ScheduleExpression {
        public function nextAfter(int $timestamp, DateTimeZone $timezone): ?int
        {
            return (intdiv($timestamp, 45) + 1) * 45;
        }
        public function fingerprint(): array
        {
            return ['type' => 'test-custom'];
        }
    };
    $schedule->task('custom')->using($custom)->run(static function (): void {});
    same(12, count($schedule->tasks()));
    same('calendar', CalendarExpression::daily('09:00')->fingerprint()['type']);
});

test('Calendar expressions skip nonexistent DST times and run repeated wall times only once', static function (): void {
    $timezone = new DateTimeZone('America/New_York');
    $daily = CalendarExpression::daily('02:30');
    $beforeGap = (new DateTimeImmutable('2024-03-10 00:00:00', $timezone))->getTimestamp();
    $afterGap = (new DateTimeImmutable('2024-03-11 02:30:00', $timezone))->getTimestamp();
    same($afterGap, $daily->nextAfter($beforeGap, $timezone));

    $dailyFold = CalendarExpression::daily('01:30');
    $afterFirstFoldTime = (new DateTimeImmutable('2024-11-03 01:45:00 -04:00'))->getTimestamp();
    $afterFoldDay = (new DateTimeImmutable('2024-11-04 01:30:00', $timezone))->getTimestamp();
    same($afterFoldDay, $dailyFold->nextAfter($afterFirstFoldTime, $timezone));

    $hourly = CalendarExpression::hourly();
    $afterFirstFoldHour = (new DateTimeImmutable('2024-11-03 01:15:00 -04:00'))->getTimestamp();
    $afterRepeatedHour = (new DateTimeImmutable('2024-11-03 02:00:00', $timezone))->getTimestamp();
    same($afterRepeatedHour, $hourly->nextAfter($afterFirstFoldHour, $timezone));
});

test('Scheduler validates task frequencies, settings, and delivery policy', static function (): void {
    raises(static fn () => new Schedule('Not/A_Zone'), InvalidArgumentException::class);
    $schedule = new Schedule();
    foreach (['bad key', ''] as $key) {
        raises(static fn () => $schedule->task($key), InvalidArgumentException::class);
    }
    $task = $schedule->task('valid');
    raises(static fn () => $task->everySeconds(0), InvalidArgumentException::class);
    raises(static fn () => $task->dailyAt('24:00'), InvalidArgumentException::class);
    raises(static fn () => $task->weeklyOn(0), InvalidArgumentException::class);
    raises(static fn () => $task->monthly(32), InvalidArgumentException::class);
    raises(static fn () => $task->timezone('Not/A_Zone'), InvalidArgumentException::class);
    raises(static fn () => $task->runMissed(101), InvalidArgumentException::class);
    raises(static fn () => $task->retries(-1), InvalidArgumentException::class);
    raises(static fn () => $task->retryAfter([]), InvalidArgumentException::class);
    raises(static fn () => $task->timeout(0), InvalidArgumentException::class);
    raises(static fn () => $task->queue('bad queue'), InvalidArgumentException::class);
    raises(static fn () => $task->command(['bad;command']), InvalidArgumentException::class);

    $task->everyMinute()->queue('reports')->withoutOverlapping()->onOneServer()->runMissed(2)->retries(2)->retrySafe();
    same('queued', $task->delivery());
    same('reports', $task->queueName());
    same(3, $task->tries());
    same(2, $task->missedLimit());
    same(true, $task->isRetrySafe());
    same(true, $task->clusterUnique());
    raises(static fn () => $task->hourly(), InvalidArgumentException::class);

    foreach ([
        ['poll_interval' => 0], ['due_tolerance' => 61], ['catch_up_limit' => 101], ['timezone' => 'Not/A_Zone'],
    ] as $config) {
        raises(static fn () => new Scheduler(new Database('.', ['driver' => 'sqlite', 'database' => ':memory:']),
            new Queue(new Database('.', ['driver' => 'sqlite', 'database' => ':memory:']), new Logger('.'), new JobRegistry()),
            new Schedule(), $config), InvalidArgumentException::class);
    }
});

test('Callbacks without readable source require an explicit definition version', static function (): void {
    $callback = eval('return static function (): void {};');
    check($callback instanceof Closure);
    $task = (new ScheduledTask('version-required'))->everySecond()->run($callback);
    raises(static fn () => $task->definitionHash('UTC'), InvalidArgumentException::class);
    $task->version('callback-v1');
    check((bool) preg_match('/^[a-f0-9]{64}$/D', $task->definitionHash('UTC')));
});

test('Task keys preserve case as distinct identifiers', static function () use ($root): void {
    $clock = new BackgroundSchedulerTestClock(1700000050);
    [$db, $queue] = backgroundSchedulerServices($root, $clock);
    $schedule = new Schedule();
    $schedule->task('Cleanup')->everySecond()->run(static function (): void {});
    $schedule->task('cleanup')->everySecond()->run(static function (): void {});
    $ids = (new Scheduler($db, $queue, $schedule, [], $clock))->run();
    same(2, count($ids));
    same(2, (int) $db->execute('SELECT COUNT(DISTINCT task_key) FROM scheduled_occurrences')->fetchColumn());
});

test('Scheduler preserves delivery, retry, timeout, and backoff settings in durable jobs', static function () use ($root): void {
    $clock = new BackgroundSchedulerTestClock(1700000060);
    [$db, $queue] = backgroundSchedulerServices($root, $clock);
    $schedule = new Schedule();
    $schedule->task('direct')->everySecond()->run(static function (): void {});
    $schedule->task('queued')->everySecond()->queue('reports')->retries(2)->retrySafe()
        ->retryAfter([3, 5])->timeout(30)->run(static function (): void {});

    $ids = (new Scheduler($db, $queue, $schedule, [], $clock))->run();
    same(2, count($ids));
    $rows = $db->execute('SELECT id, queue_name, kind, retry_safe, max_tries, backoff, timeout_seconds FROM background_jobs')->fetchAll();
    $byQueue = array_column($rows, null, 'queue_name');
    same('scheduler', $byQueue['default']['kind']);
    same('queue', $byQueue['reports']['kind']);
    same(1, (int) $byQueue['reports']['retry_safe']);
    same(3, (int) $byQueue['reports']['max_tries']);
    same('[3,5]', $byQueue['reports']['backoff']);
    same(30, (int) $byQueue['reports']['timeout_seconds']);
});

test('Scheduler bounds missed work, persists cursors, and prevents duplicate claims', static function () use ($root): void {
    $clock = new BackgroundSchedulerTestClock(1700000100);
    [$db, $queue] = backgroundSchedulerServices($root, $clock);
    $schedule = new Schedule();
    $task = $schedule->task('ticks')->everySecond()->run(static function (ExecutionContext $context): void {});
    $scheduler = new Scheduler($db, $queue, $schedule, ['catch_up_limit' => 10], $clock);
    $first = $scheduler->run();
    same(1, count($first));
    same([], (new Scheduler($db, $queue, $schedule, [], $clock))->run());

    $clock->timestamp += 5;
    $task->runMissed(2);
    $caughtUp = $scheduler->run();
    same(2, count($caughtUp));
    same(3, (int) $db->execute('SELECT COUNT(*) FROM scheduled_occurrences WHERE task_key = ?', ['ticks'])->fetchColumn());
    $listing = $scheduler->listing();
    same($clock->timestamp + 1, $listing[0]['next_run_at']);
    same($clock->timestamp, $listing[0]['last_scheduled_at']);
    same([$clock->timestamp - 1, $clock->timestamp], array_map('intval', $db->execute(
        'SELECT scheduled_at FROM scheduled_occurrences WHERE job_id IN (?, ?) ORDER BY scheduled_at', $caughtUp,
    )->fetchAll(PDO::FETCH_COLUMN)));
    same([], $scheduler->run());
});

test('Default skipMissed executes subsequent on-time occurrences and drops expired backlog', static function () use ($root): void {
    $clock = new BackgroundSchedulerTestClock(1700000400);
    [$db, $queue] = backgroundSchedulerServices($root, $clock);
    $schedule = new Schedule();
    $task = $schedule->task('skip')->everyMinute()->run(static function (): void {});
    same(true, $task->skipsMissed());
    same(1, $task->tries());
    $scheduler = new Scheduler($db, $queue, $schedule, [], $clock);
    same(1, count($scheduler->run()));
    $clock->timestamp += 70;
    same([], $scheduler->run());
    same(1, (int) $db->execute('SELECT COUNT(*) FROM scheduled_occurrences WHERE task_key = ?', ['skip'])->fetchColumn());
    $clock->timestamp += 50;
    same(1, count($scheduler->run()));
    same($clock->timestamp, (int) $db->execute('SELECT MAX(scheduled_at) FROM scheduled_occurrences')->fetchColumn());
});

test('Independent scheduler processes materialize one durable occurrence for the same task and instant', static function () use ($root): void {
    [$db, $queue, $worker, $directory] = backgroundQueueFixture();
    $script = $directory . '/schedule-race.php';
    file_put_contents($script, '<?php require ' . var_export($root . '/bootstrap.php', true) . ';' . <<<'PHP'

$db = new \PBreeze\Database(__DIR__, ['driver' => 'sqlite', 'database' => 'work.sqlite']);
$jobs = new \PBreeze\Queue\JobRegistry();
$jobs->register('scheduled-task', static fn (array $payload): bool => true, static function (): void {});
$queue = new \PBreeze\Queue\Queue($db, new \PBreeze\Logger(__DIR__), $jobs);
$clock = new class implements \PBreeze\Clock {
    public function now(): int { return 1700000100; }
    public function monotonic(): float { return 0; }
};
$schedule = new \PBreeze\Scheduler\Schedule();
$schedule->task('same-instant')->everySecond()->run(static function (): void {});
fgets(STDIN);
echo json_encode((new \PBreeze\Scheduler\Scheduler($db, $queue, $schedule, [], $clock))->run(), JSON_THROW_ON_ERROR);
PHP);
    $children = [];
    for ($index = 0; $index < 2; $index++) {
        $process = proc_open([PHP_BINARY, $script], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $directory, null, ['bypass_shell' => true]);
        check(is_resource($process));
        $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) {
        fwrite($pipes[0], "run\n");
        fclose($pipes[0]);
    }
    $materialized = [];
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        same(0, proc_close($process));
        same('', $errors);
        array_push($materialized, ...json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }
    same(1, count($materialized));
    same(1, (int) $db->execute('SELECT COUNT(*) FROM scheduled_occurrences')->fetchColumn());
    same(1, (int) $db->execute('SELECT COUNT(*) FROM background_jobs')->fetchColumn());
    same(1700000101, (int) $db->execute('SELECT next_run_at FROM pbreeze_scheduler_tasks')->fetchColumn());
});

test('Missed second intervals select the latest occurrences without scanning years of ticks', static function () use ($root): void {
    $clock = new BackgroundSchedulerTestClock(1700000100);
    [$db, $queue] = backgroundSchedulerServices($root, $clock);
    $expression = new class implements ScheduleExpression {
        public int $calls = 0;
        public function nextAfter(int $timestamp, DateTimeZone $timezone): ?int
        {
            $this->calls++;
            return $timestamp + 1;
        }
        public function fingerprint(): array { return ['type' => 'counted-seconds']; }
    };
    $schedule = new Schedule();
    $schedule->task('recent')->using($expression)->runMissed(2)->run(static function (): void {});
    $scheduler = new Scheduler($db, $queue, $schedule, [], $clock);
    same(1, count($scheduler->run()));
    $clock->timestamp += 315360000;
    $expression->calls = 0;
    $ids = $scheduler->run();
    same(2, count($ids));
    check($expression->calls < 150, 'Catch-up must use bounded searches, not historical tick iteration.');
    same([$clock->timestamp - 1, $clock->timestamp], array_map('intval', $db->execute(
        'SELECT scheduled_at FROM scheduled_occurrences WHERE job_id IN (?, ?) ORDER BY scheduled_at', $ids,
    )->fetchAll(PDO::FETCH_COLUMN)));
});

test('Schedule command API and ISO weekdays match documented developer usage', static function (): void {
    $schedule = new Schedule();
    $task = $schedule->command('mail:work', ['50'])->everyMinute()->withoutOverlapping();
    same(['mail:work', '50'], $task->commandArguments());
    same(true, $task->preventsOverlap());
    $firstHash = $task->definitionHash('UTC');
    $task->version('release-2');
    check($firstHash !== $task->definitionHash('UTC'));
    $schedule->command('mail:work', ['10'], name: 'small-mail-batch')->daily();
    same(2, count($schedule->tasks()));
    raises(static fn () => $schedule->command('mail:work'), InvalidArgumentException::class);
    $utc = new DateTimeZone('UTC');
    $friday = (new DateTimeImmutable('2026-11-13 12:00:00', $utc))->getTimestamp();
    same((new DateTimeImmutable('2026-11-15 08:00:00', $utc))->getTimestamp(),
        CalendarExpression::weekly(7, '08:00')->nextAfter($friday, $utc));
    same((new DateTimeImmutable('2026-11-16 08:00:00', $utc))->getTimestamp(),
        CalendarExpression::weekly(1, '08:00')->nextAfter($friday, $utc));
});

test('withoutOverlapping coalesces due backlog while one occurrence remains outstanding', static function () use ($root): void {
    $clock = new BackgroundSchedulerTestClock(1700000500);
    [$db, $queue] = backgroundSchedulerServices($root, $clock);
    $schedule = new Schedule();
    $schedule->task('coalesced')->everySecond()->withoutOverlapping()->runMissed(5)->run(static function (): void {});
    $scheduler = new Scheduler($db, $queue, $schedule, [], $clock);
    $first = $scheduler->run();
    same(1, count($first));

    $clock->timestamp += 5;
    same([], $scheduler->run());
    same(1, (int) $db->execute('SELECT COUNT(*) FROM scheduled_occurrences WHERE task_key = ?', ['coalesced'])->fetchColumn());

    $db->execute("UPDATE background_jobs SET status = 'completed' WHERE id = ?", [$first[0]]);
    $clock->timestamp += 5;
    same(1, count($scheduler->run()));
    same($clock->timestamp, (int) $db->execute('SELECT MAX(scheduled_at) FROM scheduled_occurrences')->fetchColumn());
});

test('Scheduler rolls back cursor claims when queue insertion fails', static function () use ($root): void {
    $clock = new BackgroundSchedulerTestClock(1700000200);
    [$db, $queue] = backgroundSchedulerServices($root, $clock, true);
    $schedule = new Schedule();
    $schedule->task('rejected')->everySecond()->run(static function (): void {});
    $scheduler = new Scheduler($db, $queue, $schedule, [], $clock);
    raises(static fn () => $scheduler->run(), InvalidArgumentException::class);
    same(0, (int) $db->execute('SELECT COUNT(*) FROM pbreeze_scheduler_tasks')->fetchColumn());
    same(0, (int) $db->execute('SELECT COUNT(*) FROM scheduled_occurrences')->fetchColumn());
});

test('Changed definitions fail old queued work without dropping the durable cursor', static function () use ($root): void {
    $clock = new BackgroundSchedulerTestClock(1700000300);
    [$db, $queue] = backgroundSchedulerServices($root, $clock);
    $oldSchedule = new Schedule();
    $oldSchedule->task('versioned')->everySecond()->run(static function (ExecutionContext $context): void {});
    $oldScheduler = new Scheduler($db, $queue, $oldSchedule, [], $clock);
    $oldIds = $oldScheduler->run();
    same(1, count($oldIds));
    $oldPayload = json_decode((string) $db->execute('SELECT payload FROM background_jobs WHERE id = ?', [$oldIds[0]])->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);

    $clock->timestamp++;
    $newSchedule = new Schedule();
    $newSchedule->task('versioned')->everySecond()->run(static function (ExecutionContext $context): void { $context->throwIfCancelled(); });
    $newScheduler = new Scheduler($db, $queue, $newSchedule, [], $clock);
    $newIds = $newScheduler->run();
    same(1, count($newIds));
    check($oldPayload['definition_hash'] !== $newSchedule->find('versioned')->definitionHash('UTC'));
    raises(static fn () => $newScheduler->executeTask('versioned', $oldPayload['definition_hash'], new ExecutionContext($oldIds[0])), JobFailure::class);
    same($clock->timestamp + 1, $newScheduler->listing()[0]['next_run_at']);
});
