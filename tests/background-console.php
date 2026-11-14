<?php
declare(strict_types=1);

test('Background CLI setup is non-destructive and retains native loading', static function (): void {
    $app = utilityApp();
    $output = console($app, ['scheduler:install']);
    check(str_contains($output, 'No files or schema were changed.'));
    check(str_contains($output, 'php breeze scheduler:work'));
    check(!file_exists($app->root . '/database/app.sqlite'));
    check(!file_exists($app->root . '/storage'));
    $output = console($app, ['help']);
    foreach (['scheduler:work', 'queue:resolve', 'workers:stop'] as $command) {
        check(str_contains($output, $command));
    }
});

test('Background CLI rejects invalid and duplicate options before connecting services', static function (): void {
    $app = utilityApp();
    foreach ([
        ['queue:work', '--sleep=0'],
        ['queue:work', '--sleep=1', '--sleep=2'],
        ['queue:work', '--queue=../private'],
        ['queue:work', '--queue=default,'],
        ['queue:work', '--tries=0'],
        ['queue:work', '--once=yes'],
        ['queue:work', '--sleep'],
        ['scheduler:work', '--queue=default'],
        ['scheduler:run', '--force'],
        ['scheduler:unknown'],
        ['queue:retry'],
        ['queue:retry', 'invalid'],
        ['queue:resolve', str_repeat('a', 32), 'retry'],
        ['queue:resolve', str_repeat('a', 32), 'unknown', '--force'],
        ['workers:stop', '../private'],
    ] as $arguments) {
        raises(static fn () => console($app, $arguments), InvalidArgumentException::class);
    }
    check(!file_exists($app->root . '/database/app.sqlite'));
    check(!file_exists($app->root . '/storage'));
});

test('Background registry loading is explicit, lazy, and performed only once', static function (): void {
    $app = utilityApp();
    file_put_contents($app->root . '/routes/schedule.php', <<<'PHP'
<?php
return static function (\PBreeze\Scheduler\Schedule $schedule, \PBreeze\Application $app): void {
    $marker = $app->root . '/schedule-loaded';
    file_put_contents($marker, (string) ((int) file_get_contents($marker) + 1));
    $schedule->task('registered')->everyMinute()->run(static function (): void {});
};
PHP);
    file_put_contents($app->root . '/schedule-loaded', '0');
    same('0', file_get_contents($app->root . '/schedule-loaded'));
    $app->background();
    $app->background();
    same('1', file_get_contents($app->root . '/schedule-loaded'));
    check(!file_exists($app->root . '/database/app.sqlite'));
});

test('Registered queue jobs validate before dispatch and reject arbitrary PHP classes', static function (): void {
    $app = utilityApp();
    file_put_contents($app->root . '/routes/jobs.php', <<<'PHP'
<?php
return [
    'fixture.v1' => [
        'validate' => static function (array $payload): void {
            if (($payload['value'] ?? null) !== 42) {
                throw new InvalidArgumentException('Expected fixture value.');
            }
        },
        'handle' => static function (array $payload, \PBreeze\Queue\ExecutionContext $context): void {},
    ],
];
PHP);
    raises(static fn () => $app->queue->dispatch('fixture.v1', ['value' => 41]), InvalidArgumentException::class);
    raises(static fn () => $app->queue->dispatch('RuntimeException', []), InvalidArgumentException::class);
    check(!file_exists($app->root . '/database/app.sqlite'));
});

test('Background environment configuration rejects unsafe limits without changing live configuration', static function () use ($root): void {
    foreach ([
        ['scheduler', 'SCHEDULER_TIMEZONE=Invalid/Zone'],
        ['scheduler', 'SCHEDULER_POLL_INTERVAL=0'],
        ['scheduler', 'SCHEDULER_CATCH_UP_LIMIT=101'],
        ['queue', 'QUEUE_DEFAULT=../private'],
        ['queue', 'QUEUE_PAYLOAD_MAX_BYTES=65537'],
        ['queue', 'QUEUE_TRIES=0'],
        ['queue', 'WORKER_HEARTBEAT_INTERVAL=60'],
    ] as [$file, $setting]) {
        $source = 'require ' . var_export($root . '/framework/Env.php', true) . ';'
            . 'putenv(' . var_export($setting, true) . ');'
            . 'try { require ' . var_export($root . '/config/' . $file . '.php', true) . '; exit(2); }'
            . 'catch (RuntimeException $error) { echo "rejected"; }';
        $process = proc_open([PHP_BINARY, '-r', $source], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        check(is_resource($process));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        same(0, proc_close($process));
        same('rejected', $stdout);
        same('', $stderr);
    }
});

test('Real background CLI schedules native commands, executes queued callbacks, and supports the pbreeze alias', static function () use ($root): void {
    $app = utilityApp();
    $fixtureConfig = [
        'name' => 'Background CLI fixture', 'environment' => 'testing', 'debug' => false,
        'secure_cookie' => false, 'database' => ['driver' => 'sqlite', 'database' => 'database/app.sqlite'],
        'queue' => ['default_queue' => 'billing'],
    ];
    file_put_contents($app->root . '/bootstrap.php', '<?php require ' . var_export($root . '/bootstrap.php', true)
        . '; return new \\PBreeze\\Application(__DIR__, ' . var_export($fixtureConfig, true) . ');');
    copy($root . '/breeze', $app->root . '/breeze');
    copy($root . '/pbreeze', $app->root . '/pbreeze');
    mkdir($app->root . '/framework', 0700);
    copy($root . '/framework/Console.php', $app->root . '/framework/Console.php');
    file_put_contents($app->root . '/routes/console.php', <<<'PHP'
<?php
return [
    'fixture:probe' => static function (\PBreeze\Application $app, array $arguments): int {
        if ($arguments !== ['literal; not a shell command']) {
            throw new InvalidArgumentException('Command arguments changed.');
        }
        file_put_contents($app->root . '/command-result', $arguments[0]);
        return 0;
    },
];
PHP);
    file_put_contents($app->root . '/routes/schedule.php', <<<'PHP'
<?php
return static function (\PBreeze\Scheduler\Schedule $schedule, \PBreeze\Application $app): void {
    $schedule->command('fixture:probe', ['literal; not a shell command'])->everySecond();
    $schedule->task('queued-callback')->everySecond()->queue('billing')->run(
        static function (\PBreeze\Queue\ExecutionContext $context) use ($app): void {
            file_put_contents($app->root . '/queue-result', $context->idempotencyKey());
        },
    );
};
PHP);
    (new \PBreeze\Migrator($app->db, $app->root))->migrate();
    $execute = static function (string $entry, array $arguments) use ($app): string {
        $process = proc_open([PHP_BINARY, $app->root . '/' . $entry, ...$arguments], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $app->root, null, ['bypass_shell' => true]);
        check(is_resource($process));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        check($exit === 0, "CLI failed ($exit): $error\n$output");
        same('', $error);
        return $output;
    };
    check(str_contains($execute('breeze', ['scheduler:run']), 'occurrence(s) materialized'));
    same('literal; not a shell command', file_get_contents($app->root . '/command-result'));
    check(!file_exists($app->root . '/queue-result'));
    check(str_contains($execute('pbreeze', ['queue:work', '--once']), 'One execution processed.'));
    check(preg_match('/^[a-f0-9]{32}$/D', file_get_contents($app->root . '/queue-result')) === 1);
    same(2, (int) $app->db->execute("SELECT COUNT(*) FROM background_jobs WHERE status = 'completed'")->fetchColumn());
    check(str_contains($execute('breeze', ['queue:work', '--once']), 'No eligible work.'));
    check(str_contains($execute('pbreeze', ['scheduler:list']), 'queued-callback'));
    file_put_contents($app->root . '/routes/schedule.php', <<<'PHP'
<?php
return static function (\PBreeze\Scheduler\Schedule $schedule, \PBreeze\Application $app): void {
    $schedule->task('continuous-ticks')->everySecond()->run(
        static function (\PBreeze\Queue\ExecutionContext $context) use ($app): void {
            file_put_contents($app->root . '/continuous-result', 'x', FILE_APPEND);
        },
    );
};
PHP);
    $execute('pbreeze', ['scheduler:work', '--max-jobs=2', '--sleep=1']);
    same('xx', file_get_contents($app->root . '/continuous-result'));
});
