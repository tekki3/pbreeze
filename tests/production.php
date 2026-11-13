<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Console;
use PBreeze\Database;
use PBreeze\Diagnostics;
use PBreeze\Encryption;
use PBreeze\Filesystem;
use PBreeze\Migrator;

function productionApp(array $replace = []): Application
{
    global $temp, $root;
    $directory = $temp . '/production-' . bin2hex(random_bytes(5));
    foreach (['', '/app/views', '/public', '/database/migrations'] as $suffix) {
        mkdir($directory . $suffix, 0700, true);
    }
    foreach (glob($root . '/database/migrations/*.php') as $file) {
        copy($file, $directory . '/database/migrations/' . basename($file));
    }
    return new Application($directory, array_replace([
        'name' => 'Production tests', 'environment' => 'production', 'debug' => false,
        'secure_cookie' => true, 'api_docs' => false, 'url' => 'https://app.example.test',
        'key' => Encryption::generateKey(),
        'database' => ['driver' => 'sqlite', 'database' => 'database/app.sqlite'],
        'mail' => ['from' => 'noreply@company.test', 'smtp' => ['host' => 'smtp.company.test', 'encryption' => 'starttls']],
    ], $replace));
}

test('Private storage configuration wires logs, uploads, views, and migration locks outside release directories', static function () use ($temp): void {
    $shared = str_replace('\\', '/', $temp) . '/shared private storage';
    $app = productionApp(['storage_path' => $shared]);
    check(!file_exists($shared));
    console($app, ['storage:prepare']);
    console($app, ['storage:prepare']);
    foreach (['logs', 'uploads', 'views'] as $child) {
        same($shared . '/' . $child, Filesystem::privateDirectory($app->root, $shared, $child, false));
    }
    $app->log->info('Shared storage check.');
    check(count(glob($shared . '/logs/app-*.jsonl')) === 1);
    file_put_contents($app->root . '/app/views/test.breeze.php', '{{ $message }}');
    same('&lt;safe&gt;', $app->views->render('test', ['message' => '<safe>']));
    check(count(glob($shared . '/views/breeze-*.php')) === 1);
    (new Migrator($app->db, $app->root, $app->storagePath))->migrate();
    check(is_file($shared . '/migrations.lock'));
    same(200, $app->readiness()->status);
    $key = str_repeat('a', 64) . '.txt';
    mkdir($shared . '/uploads/' . str_repeat('a', 64), 0700);
    file_put_contents($shared . '/uploads/' . str_repeat('a', 64) . '/file.txt', 'Private text');
    same('Private text', $app->uploads->download($key)->body);
    $app->uploads->delete($key);
    check(!file_exists($app->root . '/storage'));
    check(str_contains(console($app, ['view:clear']), 'Compiled templates removed: 1'));
});

test('Private storage rejects public paths, traversal, wrappers, and symlinks without writing there', static function () use ($temp): void {
    $app = productionApp();
    foreach ([$app->root . '/public/private', $app->root . '//public/private', $app->root] as $path) {
        raises(static fn () => Filesystem::privateDirectory($app->root, $path), RuntimeException::class);
    }
    foreach ([$app->root . '/storage/../public', 'php://memory', ':memory:', '/', 'C:/', 'C:relative', "bad\0path"] as $path) {
        raises(static fn () => Filesystem::privateDirectory($app->root, $path), InvalidArgumentException::class);
    }
    check(!is_dir($app->root . '/public/private'));
    if (PHP_OS_FAMILY !== 'Windows') {
        $target = $temp . '/storage-symlink-target';
        mkdir($target);
        symlink($target, $app->root . '/storage');
        raises(static fn () => console($app, ['storage:prepare']), RuntimeException::class);
        raises(static fn () => $app->log->info('Blocked.'), RuntimeException::class);
        file_put_contents($app->root . '/app/views/test.breeze.php', 'Blocked');
        raises(static fn () => $app->views->render('test'), RuntimeException::class);
        raises(static fn () => (new Migrator($app->db, $app->root))->migrate(), RuntimeException::class);
        unlink($app->root . '/storage');
        same([], array_values(array_diff(scandir($target), ['.', '..'])));
    }
});

test('Readiness never creates a missing SQLite database and rejects missing or mismatched schema', static function (): void {
    $app = productionApp();
    console($app, ['storage:prepare']);
    same(503, $app->readiness()->status);
    check(!file_exists($app->root . '/database/app.sqlite'));
    (new Migrator($app->db, $app->root, $app->storagePath))->migrate();
    same(['status' => 'ready'], json_decode($app->readiness()->body, true, 32, JSON_THROW_ON_ERROR));
    file_put_contents($app->root . '/database/migrations/20270101000000_pending.php', '<?php');
    same(503, $app->readiness()->status);
    unlink($app->root . '/database/migrations/20270101000000_pending.php');
    same(200, $app->readiness()->status);
    $app->db->execute('DROP TABLE api_limits');
    $response = $app->readiness();
    same(503, $response->status);
    same(['status' => 'not_ready'], json_decode($response->body, true, 32, JSON_THROW_ON_ERROR));
    check(!str_contains($response->body, $app->root));
});

test('Doctor reports actionable redacted checks and uses exit codes without schema or storage writes', static function (): void {
    $app = productionApp();
    $checks = (new Diagnostics($app))->check();
    $byName = array_column($checks, null, 'name');
    same('fail', $byName['storage']['status']);
    same('fail', $byName['database']['status']);
    check(!file_exists($app->root . '/storage'));
    check(!file_exists($app->root . '/database/app.sqlite'));
    console($app, ['storage:prepare']);
    (new Migrator($app->db, $app->root, $app->storagePath))->migrate();
    ob_start();
    try {
        same(0, (new Console($app))->run(['doctor']));
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    check(str_contains($output, 'PASS database'));
    check(!str_contains($output, $app->config['key']));
    $bad = new Application($app->root, array_replace($app->config, ['environment' => 'local', 'debug' => true, 'url' => 'http://localhost']));
    ob_start();
    try {
        same(1, (new Console($bad))->run(['doctor']));
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    check(str_contains($output, 'FAIL environment'));
    check(str_contains($output, 'FAIL debug'));
    check(str_contains($output, 'FAIL origin'));
    raises(static fn () => (new Console($bad))->run(['doctor', '--force']), InvalidArgumentException::class);
    raises(static fn () => (new Console($bad))->run(['storage:prepare', '--force']), InvalidArgumentException::class);
});

test('MySQL TLS is required by default and plaintext opt-out is restricted to local development', static function () use ($temp): void {
    $method = new ReflectionMethod(Database::class, 'mysqlOptions');
    $base = ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'test', 'port' => '3306'];
    raises(static fn () => $method->invoke(new Database($temp, $base)), RuntimeException::class);
    foreach ([
        [$base + ['tls' => 'invalid'], false],
        [$base + ['tls' => 'disabled'], true],
        [array_replace($base, ['host' => 'database.example.test', 'tls' => 'disabled']), false],
        [$base + ['ssl_ca' => '/missing-ca.pem'], false],
    ] as [$settings, $production]) {
        raises(static fn () => $method->invoke(new Database($temp, $settings, production: $production)), RuntimeException::class);
    }
    same([PDO::ATTR_TIMEOUT => 15], $method->invoke(new Database($temp, $base + ['tls' => 'disabled'])));
    if (in_array('mysql', PDO::getAvailableDrivers(), true)) {
        $ca = mailCertificate();
        $options = $method->invoke(new Database($temp, $base + ['ssl_ca' => $ca]));
        $prefix = class_exists(\Pdo\Mysql::class) ? 'Pdo\\Mysql::' : 'PDO::MYSQL_';
        same($ca, $options[constant($prefix . 'ATTR_SSL_CA')]);
        same(true, $options[constant($prefix . 'ATTR_SSL_VERIFY_SERVER_CERT')]);
    }
});
