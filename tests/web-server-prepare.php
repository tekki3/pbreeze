<?php
declare(strict_types=1);

// Creates only a new disposable fixture. Never points a server at the developer's data.
$source = dirname(__DIR__);
$directory = $argv[1] ?? '';
if ($directory === '' || file_exists($directory) || is_link($directory)) {
    throw new RuntimeException('Supply a new, nonexistent absolute fixture directory.');
}
if (!str_starts_with($directory, '/') && !preg_match('~^[a-zA-Z]:[\\\\/]~', $directory)) {
    throw new RuntimeException('The fixture path must be absolute.');
}
mkdir($directory, 0700, true);
$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) {
        mkdir($to, 0700);
        foreach (array_diff(scandir($from), ['.', '..']) as $name) {
            $copy($from . '/' . $name, $to . '/' . $name);
        }
    } elseif (!copy($from, $to)) {
        throw new RuntimeException('Cannot copy a web server fixture file.');
    }
};
foreach (['framework', 'app', 'config', 'routes', 'public'] as $path) {
    $copy($source . '/' . $path, $directory . '/' . $path);
}
mkdir($directory . '/database', 0700);
$copy($source . '/database/migrations', $directory . '/database/migrations');
copy($source . '/bootstrap.php', $directory . '/bootstrap.php');
copy(__DIR__ . '/fixtures/web-server-routes.php', $directory . '/routes/server-tests.php');
file_put_contents($directory . '/routes/web.php', "\nrequire __DIR__ . '/server-tests.php';\n", FILE_APPEND);
$settings = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'SESSION_SECURE_COOKIE' => 'false',
    'APP_URL' => 'http://localhost', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'database/test.sqlite',
    'STORAGE_PATH' => 'storage', 'CACHE_DRIVER' => 'none', 'API_DOCS_ENABLED' => 'true',
    'MAIL_LOG' => 'false', 'MAIL_FROM' => 'noreply@example.test',
];
$lines = [];
foreach ($settings as $key => $value) {
    putenv($key . '=' . $value);
    $lines[] = $key . '=' . $value;
}
file_put_contents($directory . '/.env', implode("\n", $lines) . "\n");
$app = require $directory . '/bootstrap.php';
foreach (['', 'logs', 'views', 'uploads'] as $child) {
    \PBreeze\Filesystem::privateDirectory($directory, $app->storagePath, $child);
}
(new \PBreeze\Migrator($app->db, $directory, $app->storagePath))->migrate();
$id = $app->db->insert('users', [
    'name' => 'Server Test', 'email' => 'server@example.test',
    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
    'email_verified_at' => time(), 'session_version' => 1, 'created_at' => time(),
]);
file_put_contents($directory . '/server-test.json', json_encode($app->api->tokens->create((int) $id, 'server-test'), JSON_THROW_ON_ERROR));
file_put_contents($directory . '/public/fixture.css', 'body { color: black; }');
foreach (['.private.css', 'leak.env', 'leak.sqlite', 'leak.log', 'leak.ini', 'leak.sql', 'leak.bak', 'leak.txt'] as $name) {
    file_put_contents($directory . '/public/' . $name, 'PBREEZE_PRIVATE_SENTINEL');
}
file_put_contents($directory . '/public/other.php', '<?php echo "PBREEZE_SCRIPT_EXECUTED";');
mkdir($directory . '/public/empty-directory');
echo $directory . "\n";
