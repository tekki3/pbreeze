<?php
declare(strict_types=1);

use PBreeze\Api;
use PBreeze\Application;
use PBreeze\HttpException;
use PBreeze\Logger;
use PBreeze\Request;

test('Native logger writes structured UTC daily entries for all severity levels with private permissions', static function () use ($temp): void {
    $root = $temp . '/logger-levels';
    mkdir($root, 0700);
    $logger = new Logger($root);
    check(!is_dir($root . '/storage'));
    foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level) {
        $logger->$level("A log message.\nNot another entry.", ['count' => 2, 'active' => true, 'text' => 'Café 日本語']);
    }
    $path = $root . '/storage/logs/app-' . gmdate('Y-m-d') . '.jsonl';
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    same(8, count($lines));
    foreach ($lines as $index => $line) {
        $entry = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        same(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'][$index], $entry['level']);
        same("A log message.\nNot another entry.", $entry['message']);
        same(['count' => 2, 'active' => true, 'text' => 'Café 日本語'], $entry['context']);
        check(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $entry['timestamp']) === 1);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        same(0600, fileperms($path) & 0777);
        same(0700, fileperms(dirname($path)) & 0777);
    }
});

test('Logger redacts nested sensitive keys and excludes exception messages, traces, and arguments', static function () use ($temp): void {
    $root = $temp . '/logger-secrets';
    mkdir($root, 0700);
    $logger = new Logger($root);
    $logger->info('Operation finished.', [
        'password' => 'never-store-this', 'nested' => [
            'access_token' => 'secret-token', 'Authorization' => 'Bearer secret',
            'api_key' => 'secret-key', 'cookie' => 'session-secret', 'safe' => 'retained',
        ],
        'exception' => new RuntimeException('Secret exception contents.'),
    ]);
    $text = file_get_contents($root . '/storage/logs/app-' . gmdate('Y-m-d') . '.jsonl');
    foreach (['never-store-this', 'secret-token', 'Bearer secret', 'secret-key', 'session-secret', 'Secret exception contents.'] as $secret) {
        check(!str_contains($text, $secret));
    }
    $entry = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
    same('[REDACTED]', $entry['context']['password']);
    same('retained', $entry['context']['nested']['safe']);
    same(['class', 'file', 'line'], array_keys($entry['context']['exception']));
});

test('Logger retention keeps five UTC calendar days and preserves unrelated files and symlinks', static function () use ($temp): void {
    $root = $temp . '/logger-retention';
    mkdir($root, 0700);
    $logger = new Logger($root, 5);
    $logger->info('Create storage.');
    $directory = $root . '/storage/logs';
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $keep = $directory . '/app-' . $today->modify('-4 days')->format('Y-m-d') . '.jsonl';
    $expire = $directory . '/app-' . $today->modify('-5 days')->format('Y-m-d') . '.jsonl';
    file_put_contents($keep, 'keep');
    file_put_contents($expire, 'expire');
    file_put_contents($directory . '/other.jsonl', 'unrelated');
    if (PHP_OS_FAMILY !== 'Windows') {
        symlink($directory . '/other.jsonl', $directory . '/app-2000-01-01.jsonl');
    }
    $logger->info('Apply retention.');
    check(is_file($keep));
    check(!file_exists($expire));
    same('unrelated', file_get_contents($directory . '/other.jsonl'));
    if (PHP_OS_FAMILY !== 'Windows') {
        check(is_link($directory . '/app-2000-01-01.jsonl'));
        unlink($directory . '/app-2000-01-01.jsonl');
    }
});

test('Logger filters severity and explicitly rejects invalid configuration and unsupported context', static function () use ($temp): void {
    $root = $temp . '/logger-invalid';
    mkdir($root, 0700);
    $logger = new Logger($root, 5, 'warning');
    $logger->info('Filtered.');
    check(!is_dir($root . '/storage'));
    $logger->warning('Written.');
    $lines = file($root . '/storage/logs/app-' . gmdate('Y-m-d') . '.jsonl');
    same(1, count($lines));
    foreach ([0, -1, 3651] as $days) {
        raises(static fn () => new Logger($root, $days), InvalidArgumentException::class);
    }
    raises(static fn () => new Logger($root, 5, 'invalid'), InvalidArgumentException::class);
    raises(static fn () => $logger->log('invalid', 'Invalid.'), InvalidArgumentException::class);
    raises(static fn () => $logger->error('Invalid.', ['object' => new stdClass()]), InvalidArgumentException::class);
    raises(static fn () => $logger->error(str_repeat('x', 65536)), InvalidArgumentException::class);
    raises(static fn () => $logger->error("\xff"), JsonException::class);
    raises(static fn () => $logger->error('Invalid.', ['number' => INF]), JsonException::class);
    $cycle = [];
    $cycle['self'] = &$cycle;
    raises(static fn () => $logger->error('Invalid.', $cycle), InvalidArgumentException::class);
});

test('Web and API unexpected errors use the configured logger without logging ordinary client errors', static function () use ($temp, $config): void {
    $root = $temp . '/logger-errors';
    mkdir($root, 0700);
    $app = new Application($root, $config);
    $error = new RuntimeException('Confidential message.');
    same(500, Application::errorResponse($error, logger: $app->log)->status);
    same(500, Api::errorResponse($error, $app->log)->status);
    $path = $root . '/storage/logs/app-' . gmdate('Y-m-d') . '.jsonl';
    same(2, count(file($path)));
    same(404, Application::errorResponse(new HttpException(404, 'Not found.'), logger: $app->log)->status);
    same(422, Api::errorResponse(new HttpException(422, 'Invalid.'), $app->log)->status);
    same(2, count(file($path)));
    $app->api->add('GET', '/api/logger-failure', static function (): never {
        throw new RuntimeException('Private API failure.');
    }, ['summary' => 'Test logging', 'operationId' => 'testLogging', 'responses' => ['500' => ['description' => 'Failure']]], false);
    same(500, $app->api->dispatch(new Request('GET', '/api/logger-failure'))->status);
    same(3, count(file($path)));
    check(!str_contains(file_get_contents($path), 'Private API failure.'));
    check(!str_contains(file_get_contents($path), 'Confidential message.'));
});

test('Log retention and minimum severity load from environment configuration', static function () use ($root, $temp, $config): void {
    $previous = getenv('LOG_RETENTION_DAYS');
    $previousLevel = getenv('LOG_LEVEL');
    try {
        putenv('LOG_RETENTION_DAYS=5');
        putenv('LOG_LEVEL=notice');
        $loaded = require $root . '/config/app.php';
        $app = new Application($temp, array_replace($config, [
            'log_retention_days' => $loaded['log_retention_days'], 'log_level' => $loaded['log_level'],
        ]));
        same(5, $app->log->retentionDays);
        same('notice', $app->log->minimumLevel);
        foreach (['0', '-1', '3651', '', '5 days', '1.5'] as $invalid) {
            putenv('LOG_RETENTION_DAYS=' . $invalid);
            raises(static fn () => require $root . '/config/app.php', RuntimeException::class);
        }
    } finally {
        putenv($previous === false ? 'LOG_RETENTION_DAYS' : 'LOG_RETENTION_DAYS=' . $previous);
        putenv($previousLevel === false ? 'LOG_LEVEL' : 'LOG_LEVEL=' . $previousLevel);
    }
});

test('Logger failures remain explicit and automatic error reporting falls back to the PHP server log', static function () use ($temp): void {
    $root = $temp . '/logger-failure';
    mkdir($root, 0700);
    mkdir($root . '/storage', 0700);
    file_put_contents($root . '/storage/logs', 'blocked');
    $logger = new Logger($root);
    raises(static fn () => $logger->error('Cannot write.'), RuntimeException::class);
    $previous = ini_get('error_log');
    $fallback = $temp . '/logger-fallback.log';
    ini_set('error_log', $fallback);
    try {
        Logger::report(new RuntimeException('Sensitive data.'), $logger);
        $text = file_get_contents($fallback);
        check(str_contains($text, 'PBreeze file logging failed'));
        check(!str_contains($text, 'Sensitive data.'));
    } finally {
        ini_set('error_log', $previous);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        unlink($root . '/storage/logs');
        symlink($temp, $root . '/storage/logs');
        raises(static fn () => $logger->error('No symlink writes.'), RuntimeException::class);
        unlink($root . '/storage/logs');
    }
});
