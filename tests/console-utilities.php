<?php
declare(strict_types=1);

function utilityApp(): \PBreeze\Application
{
    $app = productionApp();
    mkdir($app->root . '/routes', 0700);
    return $app;
}

test('CLI about reports only safe runtime metadata without creating storage or connecting services', static function (): void {
    $app = utilityApp();
    $output = console($app, ['about']);
    check(str_contains($output, 'PHP: ' . PHP_VERSION));
    check(str_contains($output, 'Cache driver: "none"'));
    check(!str_contains($output, $app->config['key']));
    check(!str_contains($output, $app->root));
    check(!file_exists($app->root . '/storage'));
    check(!file_exists($app->root . '/database/app.sqlite'));
});

test('CLI custom command generator requires explicit registration and preserves arguments and exit codes', static function (): void {
    $app = utilityApp();
    $output = console($app, ['make:command', 'Report']);
    check(str_contains($output, "'app:report' => require"));
    $path = $app->root . '/app/commands/Report.php';
    $handler = require $path;
    check($handler instanceof Closure);
    raises(static fn () => $handler($app, []), LogicException::class);
    raises(static fn () => $handler($app, ['unexpected']), InvalidArgumentException::class);
    raises(static fn () => console($app, ['make:command', 'Report']), RuntimeException::class);
    raises(static fn () => console($app, ['app:report']), InvalidArgumentException::class);
    file_put_contents($app->root . '/routes/console.php', <<<'PHP'
<?php
return [
    'app:report' => static function (\PBreeze\Application $application, array $arguments) use ($app): int {
        if ($application !== $app || $arguments !== ['one', '--flag', '--force']) {
            throw new LogicException('Arguments or application changed.');
        }
        return 7;
    },
];
PHP);
    same(7, (new \PBreeze\Console($app))->run(['app:report', 'one', '--flag', '--force']));
    check(str_contains(console($app, ['help']), 'app:report (custom command)'));
    foreach ([
        '<?php return ["help" => static fn () => 0];',
        '<?php return ["app:bad" => "strlen"];',
        '<?php return ["Bad name" => static fn () => 0];',
        '<?php return null;',
        '<?php return ["app:report" => static fn () => "0"];',
        '<?php return ["app:report" => static fn () => 256];',
    ] as $source) {
        file_put_contents($app->root . '/routes/console.php', $source);
        raises(static fn () => (new \PBreeze\Console($app))->run(['app:report']), RuntimeException::class);
    }
});

test('CLI utilities reject invalid arguments and cache probes fail explicitly when disabled', static function (): void {
    $app = utilityApp();
    foreach ([
        ['about', 'extra'], ['env:check', '--force'], ['cache:test', '--force'],
        ['logs:tail', '0'], ['logs:tail', '1001'], ['logs:tail', '50', 'invalid'],
        ['logs:tail', '50', 'info', 'extra'], ['make:command'], ['make:command', '../Report'],
        ['make:command', 'NUL'], ['make:command', 'report'], ['make:command', 'Report', '--force'],
    ] as $arguments) {
        raises(static fn () => console($app, $arguments), InvalidArgumentException::class);
    }
    raises(static fn () => console($app, ['cache:test']), RuntimeException::class);
});

test('CLI env check compares names, process overrides, empty and optional keys without leaking values', static function (): void {
    $app = utilityApp();
    file_put_contents($app->root . '/.env.example', "PBREEZE_CLI_SECRET=\nPBREEZE_CLI_EMPTY=\n# PBREEZE_CLI_OPTIONAL=true\nPBREEZE_CLI_PROCESS=\n");
    file_put_contents($app->root . '/.env', "PBREEZE_CLI_SECRET=\"private-value\"\nPBREEZE_CLI_EMPTY=''\nPBREEZE_CLI_CUSTOM=hidden\n");
    $previous = getenv('PBREEZE_CLI_PROCESS');
    putenv('PBREEZE_CLI_PROCESS=process-secret');
    try {
        $output = console($app, ['env:check']);
        foreach (['SET PBREEZE_CLI_SECRET', 'EMPTY PBREEZE_CLI_EMPTY', 'OPTIONAL PBREEZE_CLI_OPTIONAL', 'SET PBREEZE_CLI_PROCESS', 'CUSTOM PBREEZE_CLI_CUSTOM'] as $line) {
            check(str_contains($output, $line));
        }
        foreach (['private-value', 'hidden', 'process-secret'] as $secret) {
            check(!str_contains($output, $secret));
        }
        unlink($app->root . '/.env');
        ob_start();
        try {
            same(1, (new \PBreeze\Console($app))->run(['env:check']));
            check(str_contains(ob_get_contents(), 'MISSING PBREEZE_CLI_SECRET'));
        } finally {
            ob_end_clean();
        }
        foreach (["PBREEZE_CLI_SECRET=x\nPBREEZE_CLI_SECRET=y\n", "invalid private-value\n", "PBREEZE_CLI_SECRET=\"private-value\n"] as $source) {
            file_put_contents($app->root . '/.env', $source);
            raises(static fn () => console($app, ['env:check']), RuntimeException::class);
        }
    } finally {
        putenv($previous === false ? 'PBREEZE_CLI_PROCESS' : 'PBREEZE_CLI_PROCESS=' . $previous);
    }
});

test('CLI log tail reads recent entries across UTC files with filtering and terminal-safe JSON', static function (): void {
    $app = utilityApp();
    check(str_contains(console($app, ['logs:tail']), 'No application logs'));
    check(!file_exists($app->root . '/storage'));
    $app->log->info('First');
    $directory = $app->storagePath . '/logs';
    $today = $directory . '/app-' . gmdate('Y-m-d') . '.jsonl';
    file_put_contents($directory . '/app-2000-01-01.jsonl', json_encode(['level' => 'error', 'message' => 'Older']) . "\n");
    $rows = [];
    for ($i = 0; $i < 300; $i++) {
        $rows[] = json_encode(['level' => $i % 2 === 0 ? 'info' : 'error', 'message' => "Entry $i " . str_repeat('x', 100)]);
    }
    $rows[] = json_encode(['level' => 'critical', 'message' => "Terminal \033[31m"]);
    file_put_contents($today, implode("\n", $rows) . "\n");
    $output = console($app, ['logs:tail', '2', 'error']);
    $entries = array_map(static fn (string $line): array => json_decode($line, true, 32, JSON_THROW_ON_ERROR), explode("\n", trim($output)));
    check(str_starts_with($entries[0]['message'], 'Entry 299 '));
    same("Terminal \033[31m", $entries[1]['message']);
    check(!str_contains($output, "\033"));
    $all = console($app, ['logs:tail', '1000', 'error']);
    $entries = explode("\n", trim($all));
    same(152, count($entries));
    same('Older', json_decode($entries[0], true)['message']);
    file_put_contents($today, json_encode(['level' => 'warning', 'message' => 'No final newline']));
    same('No final newline', json_decode(trim(console($app, ['logs:tail', '1'])), true)['message']);
    file_put_contents($today, "invalid-json\n");
    raises(static fn () => console($app, ['logs:tail']), RuntimeException::class);
    file_put_contents($today, str_repeat('x', 65537));
    raises(static fn () => console($app, ['logs:tail']), RuntimeException::class);
    file_put_contents($today, str_repeat(json_encode(['level' => 'info', 'message' => str_repeat('x', 1000)]) . "\n", 8500));
    raises(static fn () => console($app, ['logs:tail', '50', 'emergency']), RuntimeException::class);
    if (PHP_OS_FAMILY !== 'Windows') {
        unlink($today);
        symlink($directory . '/app-2000-01-01.jsonl', $today);
        raises(static fn () => console($app, ['logs:tail']), RuntimeException::class);
        unlink($today);
    }
});
