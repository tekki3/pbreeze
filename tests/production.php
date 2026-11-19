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

test('Doctor prominently warns about emergency MFA bypass without changing policy or enrollment', static function (): void {
    foreach ([[], ['mfa_enforcement_enabled' => true], ['mfa_enforcement_enabled' => false]] as $policy) {
        $app = productionApp(['auth' => $policy]);
        $checks = array_column((new Diagnostics($app))->check(), null, 'name');
        $bypass = ($policy['mfa_enforcement_enabled'] ?? true) === false;
        same($bypass ? 'warn' : 'pass', $checks['mfa-enforcement']['status']);
        if ($bypass) {
            check(str_contains($checks['mfa-enforcement']['message'], 'EMERGENCY'));
            check(str_contains($checks['mfa-enforcement']['message'], 'password and OIDC'));
            check(str_contains($checks['mfa-enforcement']['message'], 'restart all application processes'));
            ob_start();
            try {
                (new Console($app))->run(['doctor']);
                $output = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            check(str_contains($output, 'WARN mfa-enforcement'));
        }
        same(!$bypass, $app->auth->mfaEnforcementEnabled());
        check(!file_exists($app->root . '/storage'));
        check(!file_exists($app->root . '/database/app.sqlite'));
    }
});

test('MySQL TLS and host configuration load from the environment without implicit enforcement', static function () use ($root): void {
    $keys = ['DB_MYSQL_TLS', 'DB_MYSQL_SSL_CA', 'DB_HOST'];
    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
    }
    try {
        putenv('DB_MYSQL_TLS');
        same('disabled', (require $root . '/config/app.php')['database']['tls']);
        foreach (['disabled', 'required', 'verify_ca'] as $tls) {
            putenv('DB_MYSQL_TLS=' . $tls);
            putenv('DB_MYSQL_SSL_CA=/absolute/path/to/ca.pem');
            putenv('DB_HOST=localhost');
            $database = (require $root . '/config/app.php')['database'];
            same($tls, $database['tls']);
            same('/absolute/path/to/ca.pem', $database['ssl_ca']);
            same('localhost', $database['host']);
        }
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }
});

test('MySQL TLS defaults to disabled without SSL options regardless of host or environment', static function () use ($temp): void {
    $method = new ReflectionMethod(Database::class, 'mysqlOptions');
    $base = ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'test', 'port' => '3306'];
    foreach (['localhost', '127.0.0.1', 'database.example.test'] as $host) {
        foreach ([false, true] as $production) {
            foreach ([[], ['tls' => 'disabled', 'ssl_ca' => '/ignored-ca.pem']] as $tls) {
                $settings = array_replace($base, ['host' => $host], $tls);
                same([PDO::ATTR_TIMEOUT => 15], $method->invoke(new Database($temp, $settings, production: $production)));
            }
        }
    }
});

test('MySQL TLS validates modes and CA files and preserves required with CA verification', static function () use ($temp): void {
    $method = new ReflectionMethod(Database::class, 'mysqlOptions');
    $base = ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'test', 'port' => '3306'];
    foreach ([
        ['tls' => 'invalid'],
        ['tls' => ''],
        ['tls' => 'verify_ca'],
        ['tls' => 'verify_ca', 'ssl_ca' => $temp . '/missing-ca.pem'],
        ['tls' => 'verify_ca', 'ssl_ca' => $temp],
        ['tls' => 'required', 'ssl_ca' => $temp . '/missing-ca.pem'],
    ] as $settings) {
        raises(static fn () => $method->invoke(new Database($temp, $base + $settings)), RuntimeException::class);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        $unreadable = $temp . '/unreadable-mysql-ca.pem';
        file_put_contents($unreadable, 'Unreadable CA fixture.');
        chmod($unreadable, 0000);
        try {
            if (!is_readable($unreadable)) {
                foreach (['required', 'verify_ca'] as $tls) {
                    raises(static fn () => $method->invoke(new Database($temp, $base + ['tls' => $tls, 'ssl_ca' => $unreadable])), RuntimeException::class);
                }
            }
        } finally {
            chmod($unreadable, 0600);
            unlink($unreadable);
        }
    }
    if (in_array('mysql', PDO::getAvailableDrivers(), true)) {
        $ca = mailCertificate();
        $prefix = class_exists(\Pdo\Mysql::class) ? 'Pdo\\Mysql::' : 'PDO::MYSQL_';
        foreach (['required', 'verify_ca'] as $tls) {
            $options = $method->invoke(new Database($temp, $base + ['tls' => $tls, 'ssl_ca' => $ca]));
            same($ca, $options[constant($prefix . 'ATTR_SSL_CA')]);
            same(true, $options[constant($prefix . 'ATTR_SSL_VERIFY_SERVER_CERT')]);
        }
        $options = $method->invoke(new Database($temp, $base + ['tls' => 'required']));
        same('DEFAULT', $options[constant($prefix . 'ATTR_SSL_CIPHER')]);
        same(false, $options[constant($prefix . 'ATTR_SSL_VERIFY_SERVER_CERT')]);
        check(!array_key_exists(constant($prefix . 'ATTR_SSL_CA'), $options));
    }
});
