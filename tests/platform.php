<?php
declare(strict_types=1);

use PBreeze\Database;
use PBreeze\Filesystem;

test('Databasepaden ondersteunen Linux, Windows-schijven, UNC en relatieve paden', static function (): void {
    foreach ([
        ['/app', ':memory:', ':memory:'],
        ['/app', 'database/app.sqlite', '/app/database/app.sqlite'],
        ['/app', '/data/app.sqlite', '/data/app.sqlite'],
        ['C:\\Sites\\PBreeze', 'database\\app.sqlite', 'C:/Sites/PBreeze/database/app.sqlite'],
        ['C:\\Sites\\PBreeze', 'D:\\Data\\app.sqlite', 'D:/Data/app.sqlite'],
        ['C:/Sites/PBreeze', 'D:/Data/app.sqlite', 'D:/Data/app.sqlite'],
        ['C:/Sites/PBreeze', '\\\\server\\share\\app.sqlite', '//server/share/app.sqlite'],
    ] as [$root, $input, $expected]) {
        same($expected, Filesystem::databasePath($root, $input));
    }
    foreach (['C:relative.sqlite', 'D:', '', "bad\0path"] as $path) {
        raises(static fn () => Filesystem::databasePath('/app', $path), InvalidArgumentException::class);
    }
});

test('SQLite opent een absoluut lokaal pad en relatieve backslashpaden correct', static function () use ($temp): void {
    $db = new Database($temp, ['driver' => 'sqlite', 'database' => $temp . '/absolute.sqlite']);
    $db->execute('CREATE TABLE absolute_path (id INTEGER)');
    check(is_file($temp . '/absolute.sqlite'));
    $relative = new Database($temp, ['driver' => 'sqlite', 'database' => 'database\\relative.sqlite']);
    $relative->execute('CREATE TABLE relative_path (id INTEGER)');
    check(is_file($temp . '/database/relative.sqlite'));
});

test('Generators weigeren Windows-apparaatnamen op beide platforms', static function () use ($app): void {
    foreach ([
        ['make:view', 'con'], ['make:view', 'pages.nul'], ['make:view', 'aux.index'],
        ['make:model', 'NUL'], ['make:seeder', 'COM1'], ['db:seed', 'LPT9'],
    ] as $arguments) {
        raises(static fn () => console($app, $arguments), InvalidArgumentException::class);
    }
    raises(static fn () => $app->views->render('con'), InvalidArgumentException::class);
    Filesystem::portableName('console.index');
    Filesystem::portableName('Company');
});

test('CLI-processen behouden spaties, quotes, procenttekens en exitcodes zonder shell', static function () use ($root, $temp): void {
    $fixture = $temp . "/process % ! & ' fixture.php";
    copy($root . '/tests/fixtures/process.php', $fixture);
    $arguments = ['met spaties', '100%', '!PATH!', '%PATH%', '& whoami', 'een"quote', "een'quote", 'C:\\Sites\\app'];
    $output = $temp . '/process-output.json';
    $error = $temp . '/process-error.log';
    $process = proc_open([PHP_BINARY, $fixture, $root . '/bootstrap.php', 'nested', ...$arguments], [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $output, 'w'],
        2 => ['file', $error, 'w'],
    ], $pipes, $temp, null, ['bypass_shell' => true]);
    check(is_resource($process), 'Testproces kon niet starten.');
    same(7, proc_close($process));
    same('', file_get_contents($error));
    $result = json_decode((string) file_get_contents($output), true, 32, JSON_THROW_ON_ERROR);
    same($arguments, $result['arguments']);
    same(realpath($temp), $result['directory']);
});

test('Env ondersteunt CRLF-bestanden en Windows-paden zonder escapeverwerking', static function () use ($temp): void {
    $file = $temp . '/windows.env';
    file_put_contents($file, "PBREEZE_TEST_WINDOWS_PATH=\"C:\\Data\\mail\"\r\nPBREEZE_TEST_WINDOWS_BOOL=true\r\n");
    \PBreeze\Env::load($file);
    try {
        same('C:\\Data\\mail', \PBreeze\Env::get('PBREEZE_TEST_WINDOWS_PATH'));
        same(true, \PBreeze\Env::bool('PBREEZE_TEST_WINDOWS_BOOL'));
    } finally {
        \PBreeze\Env::load($temp . '/missing.env');
    }
});
