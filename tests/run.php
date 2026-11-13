<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';
require_once $root . '/framework/Console.php';
require_once $root . '/app/models/Note.php';

use PBreeze\Application;
use PBreeze\Console;
use PBreeze\Database;
use PBreeze\Env;
use PBreeze\HttpException;
use PBreeze\Migrator;
use PBreeze\Request;
use PBreeze\Response;
use PBreeze\Router;
use PBreeze\Session;
use PBreeze\Validator;
use PBreeze\View;

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$passed = 0;
$failed = 0;
$temp = sys_get_temp_dir() . "/pbreeze tests % ! & '-" . bin2hex(random_bytes(8));
mkdir($temp, 0700);
foreach (['storage', 'database/migrations', 'database/seeders', 'app/controllers', 'app/views'] as $directory) {
    mkdir($temp . '/' . $directory, 0700, true);
}
$config = [
    'name' => 'PBreeze',
    'environment' => 'local',
    'debug' => false,
    'secure_cookie' => false,
    'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
];
$app = new Application($temp, $config);
$server = null;

function check(bool $condition, string $message = 'Controle mislukt.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function same(mixed $expected, mixed $actual): void
{
    check($expected === $actual, 'Verwacht ' . var_export($expected, true) . ', kreeg ' . var_export($actual, true));
}

function raises(callable $work, string $class, ?int $status = null): void
{
    try {
        $work();
    } catch (Throwable $error) {
        check($error instanceof $class, 'Onverwachte exception: ' . $error::class . ' ' . $error->getMessage());
        if ($status !== null) {
            check($error instanceof HttpException && $error->status === $status, 'Onverwachte HTTP-status.');
        }
        return;
    }
    throw new RuntimeException("Verwachte exception ontbreekt: $class");
}

function test(string $name, callable $work): void
{
    global $passed, $failed;
    try {
        $work();
        $passed++;
        echo "OK    $name\n";
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL  $name\n      " . $error->getMessage() . "\n");
    }
}

/** @param list<string> $arguments */
function console(Application $app, array $arguments): string
{
    ob_start();
    try {
        same(0, (new Console($app))->run($arguments));
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

/** @return array{status: int, headers: list<string>, body: string} */
function http(string $base, string $path, string $method = 'GET', string $body = '', array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 5,
    ]]);
    $stream = fopen($base . $path, 'r', false, $context);
    if ($stream === false) {
        throw new RuntimeException('HTTP-verzoek mislukt.');
    }
    try {
        $content = stream_get_contents($stream);
        $metadata = stream_get_meta_data($stream);
    } finally {
        fclose($stream);
    }
    if ($content === false) {
        throw new RuntimeException('Kan HTTP-response niet lezen.');
    }
    $responseHeaders = $metadata['wrapper_data'];
    preg_match('/^HTTP\/\S+ (\d+)/', $responseHeaders[0], $match);
    return ['status' => (int) $match[1], 'headers' => $responseHeaders, 'body' => $content];
}

try {
    require __DIR__ . '/platform.php';
    require __DIR__ . '/mail.php';
    require __DIR__ . '/templates.php';

    test('HTML-escaping behandelt quotes en ongeldige UTF-8', static function (): void {
        same('&lt;script&gt;&quot;&#039;&amp;', e('<script>"\'&'));
        check(str_contains(e("\xFF"), "\xEF\xBF\xBD"));
    });

    test('Request houdt query en body gescheiden', static function (): void {
        $request = new Request('POST', '/', ['role' => 'admin'], ['name' => 'Ada']);
        same(null, $request->input('role'));
        same('admin', $request->query('role'));
        same('Ada', $request->input('name'));
    });

    test('Router verwerkt parameters op volgorde en trailing slash', static function (): void {
        $router = new Router();
        $router->get('/notes/{id}', static fn (Request $r, string $id): Response => Response::json(['id' => $id]));
        same('{"id":"42"}', $router->dispatch(new Request('GET', '/notes/42/'))->body);
    });

    test('Router behandelt statische regextekens letterlijk', static function (): void {
        $router = new Router();
        $router->get('/file.json', static fn (): Response => new Response('ok'));
        raises(static fn () => $router->dispatch(new Request('GET', '/fileXjson')), HttpException::class, 404);
    });

    test('Router geeft 404 en 405 met Allow-header', static function (): void {
        $router = new Router();
        $router->get('/', static fn (): Response => new Response('ok'));
        raises(static fn () => $router->dispatch(new Request('GET', '/missing')), HttpException::class, 404);
        try {
            $router->dispatch(new Request('DELETE', '/'));
            throw new RuntimeException('405 ontbreekt.');
        } catch (HttpException $error) {
            same(405, $error->status);
            same('GET, HEAD', $error->headers['Allow']);
        }
    });

    test('Router ondersteunt HEAD-terugval en expliciete HEAD', static function (): void {
        $router = new Router();
        $router->get('/', static fn (): Response => new Response('GET'));
        same('GET', $router->dispatch(new Request('HEAD', '/'))->body);
        $router->add('HEAD', '/', static fn (): Response => new Response('HEAD'));
        same('HEAD', $router->dispatch(new Request('HEAD', '/'))->body);
    });

    test('Router weigert dubbele of ongeldige routes en ongeldige responses', static function (): void {
        $router = new Router();
        $router->get('/{id}', static fn (): string => 'fout');
        raises(static fn () => $router->get('/{other}', static fn (): Response => new Response()), LogicException::class);
        raises(static fn () => $router->get('/{id}/{id}', static fn (): Response => new Response()), InvalidArgumentException::class);
        raises(static fn () => $router->dispatch(new Request('GET', '/1')), LogicException::class);
    });

    test('Redirects weigeren externe URLs en headerinjectie', static function (): void {
        same('/notes', Response::redirect('/notes')->headers['Location']);
        foreach (['https://evil.test', '//evil.test', '/\\evil.test', "/ok\r\nX-Test: bad"] as $path) {
            raises(static fn () => Response::redirect($path), InvalidArgumentException::class);
        }
        raises(static fn () => new Response('', 200, ['X-Test' => "ok\r\nbad"]), InvalidArgumentException::class);
    });

    test('CSRF accepteert alleen het juiste body- of headertoken', static function (): void {
        $_SESSION = [];
        $session = new Session();
        $token = $session->token();
        same(64, strlen($token));
        same($token, $session->token());
        $session->verifyCsrf(new Request('POST', '/', [], ['_token' => $token]));
        $session->verifyCsrf(new Request('DELETE', '/', [], [], ['x-csrf-token' => $token]));
        foreach ([null, 'bad', ['bad']] as $value) {
            raises(static fn () => $session->verifyCsrf(new Request('POST', '/', [], ['_token' => $value])), HttpException::class, 419);
        }
        $session->verifyCsrf(new Request('GET', '/'));
    });

    test('Validatie behandelt strings, arrays, Unicode en bevestiging', static function (): void {
        same([], Validator::errors(['name' => 'Zoë', 'email' => 'ada@example.test', 'age' => '0'], [
            'name' => 'required|string|min:3|max:3', 'email' => 'email', 'age' => 'integer',
        ]));
        check(isset(Validator::errors(['name' => []], ['name' => 'required|string'])['name']));
        check(isset(Validator::errors(['name' => ' '], ['name' => 'required'])['name']));
        check(isset(Validator::errors(['email' => ['bad']], ['email' => 'email'])['email']));
        check(isset(Validator::errors(['password' => 'abc'], ['password' => 'confirmed'])['password']));
        raises(static fn () => Validator::errors([], ['name' => 'unknown']), InvalidArgumentException::class);
        raises(static fn () => Validator::errors([], ['name' => 'max:bad']), InvalidArgumentException::class);
    });

    test('Views weigeren traversal en kunnen interne variabelen niet overschrijven', static function () use ($temp): void {
        file_put_contents($temp . '/app/views/example.php', '<?= e($title) ?>');
        $view = new View($temp . '/app/views');
        same('&lt;Ada&gt;', $view->render('example', ['title' => '<Ada>', '__file' => '/missing']));
        raises(static fn () => $view->render('../.env'), InvalidArgumentException::class);
        raises(static fn () => $view->render('missing'), RuntimeException::class);
    });

    test('View-exceptions ruimen de outputbuffer op', static function () use ($temp): void {
        file_put_contents($temp . '/app/views/broken.php', '<?php echo "partial"; throw new RuntimeException("broken");');
        $level = ob_get_level();
        raises(static fn () => (new View($temp . '/app/views'))->render('broken'), RuntimeException::class);
        same($level, ob_get_level());
    });

    test('Env leest quotes letterlijk en weigert kapotte regels', static function () use ($temp): void {
        file_put_contents($temp . '/test.env', "PBREEZE_TEST_VALUE=\"hello # world\"\nPBREEZE_TEST_BOOL=false\n");
        Env::load($temp . '/test.env');
        same('hello # world', Env::get('PBREEZE_TEST_VALUE'));
        same(false, Env::bool('PBREEZE_TEST_BOOL'));
        putenv('PBREEZE_TEST_VALUE=override');
        try {
            same('override', Env::get('PBREEZE_TEST_VALUE'));
        } finally {
            putenv('PBREEZE_TEST_VALUE');
        }
        file_put_contents($temp . '/bad.env', "NOT VALID\n");
        raises(static fn () => Env::load($temp . '/bad.env'), RuntimeException::class);
        Env::load($temp . '/absent.env');
    });

    test('PDO gebruikt bindings en schakelt SQLite foreign keys in', static function () use ($app): void {
        $app->db->execute('CREATE TABLE bindings (value TEXT NOT NULL)');
        $payload = "'); DROP TABLE bindings; --";
        $app->db->execute('INSERT INTO bindings (value) VALUES (?)', [$payload]);
        same($payload, $app->db->execute('SELECT value FROM bindings')->fetchColumn());
        same(1, (int) $app->db->execute('PRAGMA foreign_keys')->fetchColumn());
    });

    test('Database-transacties rollen terug bij fouten', static function () use ($app): void {
        raises(static function () use ($app): void {
            $app->db->transaction(static function (PDO $pdo): void {
                $pdo->exec("INSERT INTO bindings (value) VALUES ('rollback')");
                throw new RuntimeException('stop');
            });
        }, RuntimeException::class);
        same(1, (int) $app->db->execute('SELECT COUNT(*) FROM bindings')->fetchColumn());
    });

    test('Database weigert onbekende drivers en MySQL DSN-injectie', static function () use ($temp): void {
        raises(static fn () => (new Database($temp, ['driver' => 'other', 'database' => 'x']))->pdo(), RuntimeException::class);
        raises(static fn () => (new Database($temp, ['driver' => 'mysql', 'database' => 'x;host=evil', 'host' => 'localhost', 'port' => '3306']))->pdo(), RuntimeException::class);
    });

    test('CLI maakt alle generators en overschrijft geen bestaande bestanden', static function () use ($app, $temp): void {
        console($app, ['make:controller', 'Notes']);
        console($app, ['make:model', 'Article']);
        console($app, ['make:view', 'notes.index']);
        console($app, ['make:migration', 'create_examples']);
        console($app, ['make:seeder', 'NotesSeeder']);
        foreach (['/app/controllers/NotesController.php', '/app/models/Article.php', '/app/views/notes/index.php', '/database/seeders/NotesSeeder.php'] as $file) {
            check(is_file($temp . $file), "Ontbrekend bestand: $file");
            token_get_all((string) file_get_contents($temp . $file), TOKEN_PARSE);
        }
        $files = glob($temp . '/database/migrations/*.php');
        same(1, count($files));
        token_get_all((string) file_get_contents($files[0]), TOKEN_PARSE);
        // De template faalt expliciet totdat de ontwikkelaar up/down implementeert.
        raises(static fn () => (new Migrator($app->db, $temp))->migrate(), LogicException::class);
        unlink($files[0]);
        raises(static fn () => console($app, ['make:view', 'notes.index']), RuntimeException::class);
        same(0, (int) $app->db->execute('SELECT COUNT(*) FROM migrations')->fetchColumn());
    });

    test('CLI weigert traversal, onbekende opties en ontbrekende namen', static function () use ($app): void {
        foreach ([
            ['make:view', '../escape'], ['make:controller', '../Escape'], ['make:model', '../Escape'], ['make:seeder', '../Escape'],
            ['make:migration', '../../escape'], ['make:view'], ['oops'], ['help', '--oops'],
            ['make:model', 'While'], ['make:model', 'Int'], ['make:model', 'False'],
            ['help', 'extra'], ['serve', '80'], ['db:seed', '../Escape'],
        ] as $arguments) {
            raises(static fn () => console($app, $arguments), InvalidArgumentException::class);
        }
    });

    test('CLI beschermt productiedatabasewijzigingen', static function () use ($config, $temp): void {
        $config['environment'] = 'production';
        $production = new Application($temp, $config);
        foreach (['migrate', 'migrate:rollback', 'db:seed'] as $command) {
            raises(static fn () => console($production, [$command]), RuntimeException::class);
        }
        console($production, ['migrate', '--force']);
    });

    test('Migraties zijn herhaalbaar en rollback draait alleen de laatste batch terug', static function () use ($app, $root, $temp): void {
        copy($root . '/database/migrations/20260101000000_create_notes.php', $temp . '/database/migrations/20260101000000_create_notes.php');
        $migrator = new Migrator($app->db, $temp);
        same(['20260101000000_create_notes'], $migrator->migrate());
        same([], $migrator->migrate());
        same(['20260101000000_create_notes' => 1], $migrator->status());
        file_put_contents($temp . '/database/migrations/20260102000000_second.php', <<<'PHP'
<?php
return new class extends \PBreeze\Migration {
    public function up(PDO $db): void { $db->exec('CREATE TABLE second (id INTEGER)'); }
    public function down(PDO $db): void { $db->exec('DROP TABLE second'); }
};
PHP);
        same(['20260102000000_second'], $migrator->migrate());
        same(2, $migrator->status()['20260102000000_second']);
        same(['20260102000000_second'], $migrator->rollback());
        same(1, $migrator->status()['20260101000000_create_notes']);
        unlink($temp . '/database/migrations/20260102000000_second.php');
    });

    test('Migraties rollen SQLite DDL en registratie samen terug', static function () use ($app, $temp): void {
        $file = $temp . '/database/migrations/20260103000000_broken.php';
        file_put_contents($file, <<<'PHP'
<?php
return new class extends \PBreeze\Migration {
    public function up(PDO $db): void {
        $db->exec('CREATE TABLE broken (id INTEGER)');
        throw new RuntimeException('bewust kapot');
    }
    public function down(PDO $db): void {}
};
PHP);
        try {
            raises(static fn () => (new Migrator($app->db, $temp))->migrate(), RuntimeException::class);
            same(0, (int) $app->db->execute("SELECT COUNT(*) FROM sqlite_master WHERE name = 'broken'")->fetchColumn());
            same(1, (int) $app->db->execute('SELECT COUNT(*) FROM migrations')->fetchColumn());
        } finally {
            unlink($file);
        }
    });

    test('Migraties weigeren een actief slot en ontbrekende toegepaste bestanden', static function () use ($app, $temp): void {
        $migrator = new Migrator($app->db, $temp);
        $lock = fopen($temp . '/storage/migrations.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            raises(static fn () => $migrator->migrate(), RuntimeException::class);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $file = $temp . '/database/migrations/20260101000000_create_notes.php';
        rename($file, $file . '.bak');
        try {
            raises(static fn () => $migrator->migrate(), RuntimeException::class);
            raises(static fn () => $migrator->rollback(), RuntimeException::class);
            raises(static fn () => $migrator->status(), RuntimeException::class);
        } finally {
            rename($file . '.bak', $file);
        }
    });

    test('Voorbeeldseeder is herhaalbaar', static function () use ($app, $temp, $root): void {
        copy($root . '/database/seeders/DatabaseSeeder.php', $temp . '/database/seeders/DatabaseSeeder.php');
        console($app, ['db:seed']);
        console($app, ['db:seed']);
        same(1, (int) $app->db->execute('SELECT COUNT(*) FROM notes')->fetchColumn());
    });

    test('Voorbeeldmodel maakt notities veilig aan en begrenst resultaten', static function () use ($app): void {
        $notes = new \App\Models\Note($app->db);
        $id = $notes->create("Een 'veilige' notitie");
        check($id > 0);
        $rows = $notes->latest(1);
        same(1, count($rows));
        same($id, $rows[0]['id']);
        same("Een 'veilige' notitie", $rows[0]['title']);
        raises(static fn () => $notes->latest(101), InvalidArgumentException::class);
        raises(static fn () => $notes->create(' '), InvalidArgumentException::class);
        raises(static fn () => $notes->create(str_repeat('a', 121)), InvalidArgumentException::class);
    });

    test('SQLite-bestanden zijn bruikbaar met platformeigen bestandsrechten', static function () use ($temp): void {
        $db = new Database($temp, ['driver' => 'sqlite', 'database' => 'private.sqlite']);
        $db->pdo();
        check(is_readable($temp . '/private.sqlite') && is_writable($temp . '/private.sqlite'));
        if (PHP_OS_FAMILY !== 'Windows') {
            same(0600, fileperms($temp . '/private.sqlite') & 0777);
        }
    });

    test('CLI init bewaart bestaande configuratie met platformeigen bestandsrechten', static function () use ($app, $root, $temp): void {
        copy($root . '/.env.example', $temp . '/.env.example');
        console($app, ['init']);
        check(is_readable($temp . '/.env') && is_writable($temp . '/.env'));
        if (PHP_OS_FAMILY !== 'Windows') {
            same(0600, fileperms($temp . '/.env') & 0777);
        }
        file_put_contents($temp . '/.env', 'APP_NAME=Eigen');
        console($app, ['init']);
        same('APP_NAME=Eigen', file_get_contents($temp . '/.env'));
    });

    test('Applicatie past CSRF toe voor de routehandler', static function () use ($app): void {
        $app->router->post('/write', static fn (): Response => new Response('saved'));
        raises(static fn () => $app->handle(new Request('POST', '/write')), HttpException::class, 419);
        same('saved', $app->handle(new Request('POST', '/write', [], ['_token' => $app->session->token()]))->body);
    });

    test('Native HTTP-server start en geeft health JSON terug', static function () use ($root, $temp, &$server, &$base): void {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException($error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $base = 'http://' . $address;
        $environment = getenv();
        $environment['APP_ENV'] = 'local';
        $environment['APP_DEBUG'] = 'false';
        $environment['SESSION_SECURE_COOKIE'] = 'false';
        $environment['DB_CONNECTION'] = 'sqlite';
        $environment['DB_DATABASE'] = $temp . '/http.sqlite';
        $public = $temp . '/public';
        mkdir($public);
        copy($root . '/public/router.php', $public . '/router.php');
        mkdir($public . '/assets');
        copy($root . '/public/assets/welcome.css', $public . '/assets/welcome.css');
        file_put_contents($public . '/index.php', '<?php require ' . var_export($root . '/public/index.php', true) . ';');
        file_put_contents($public . '/fixture.css', 'body { color: black; }');
        file_put_contents($public . '/.private.css', 'not-public');
        $server = proc_open([PHP_BINARY, '-S', $address, '-t', $public, $public . '/router.php'], [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $temp . '/server.log', 'a'],
            2 => ['file', $temp . '/server.log', 'a'],
        ], $pipes, $root, $environment, ['bypass_shell' => true]);
        if (!is_resource($server)) {
            throw new RuntimeException('Kan HTTP-server niet starten.');
        }
        $ready = false;
        for ($attempt = 0; $attempt < 60; $attempt++) {
            usleep(50000);
            if (!proc_get_status($server)['running']) {
                throw new RuntimeException('HTTP-server gestopt: ' . file_get_contents($temp . '/server.log'));
            }
            try {
                $response = http($base, '/health');
                $ready = true;
                break;
            } catch (ErrorException) {
                continue;
            }
        }
        check($ready, 'HTTP-server reageert niet.');
        same(200, $response['status']);
        same(['status' => 'ok'], json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR));
    });

    if (isset($base) && is_resource($server) && proc_get_status($server)['running']) {
        test('HTTP-formulier heeft veilige cookie-attributen en CSP', static function () use ($base, &$cookie, &$token): void {
            $response = http($base, '/');
            same(200, $response['status']);
            $headers = implode("\n", $response['headers']);
            check(str_contains(strtolower($headers), 'httponly'));
            check(str_contains(strtolower($headers), 'samesite=lax'));
            check(str_contains(strtolower($headers), 'content-security-policy:'));
            check(str_contains(strtolower($headers), 'x-content-type-options: nosniff'));
            check(str_contains($response['body'], 'href="/assets/welcome.css"'));
            $stylesheet = http($base, '/assets/welcome.css');
            same(200, $stylesheet['status']);
            check(str_contains(implode("\n", $stylesheet['headers']), 'text/css'));
            check(str_contains($stylesheet['body'], '@media'));
            preg_match('/Set-Cookie: ([^;]+)/i', $headers, $match);
            $cookie = $match[1];
            preg_match('/name="_token" value="([a-f0-9]{64})"/', $response['body'], $match);
            $token = $match[1];
        });

        test('HTTP weigert ontbrekende CSRF en accepteert een geldig token', static function () use ($base, &$cookie, &$token): void {
            $headers = ['Content-Type: application/x-www-form-urlencoded', 'Cookie: ' . $cookie];
            same(419, http($base, '/greet', 'POST', 'name=Ada', $headers)['status']);
            $response = http($base, '/greet', 'POST', http_build_query(['_token' => $token, 'name' => '<script>alert(1)</script>']), $headers);
            same(200, $response['status']);
            check(str_contains($response['body'], '&lt;script&gt;'));
            check(!str_contains($response['body'], '<script>'));
            same(422, http($base, '/greet', 'POST', http_build_query(['_token' => $token, 'name' => 'A']), $headers)['status']);
        });

        test('HTTP valideert JSON en ondersteunt CSRF-header', static function () use ($base, &$cookie, &$token): void {
            $headers = ['Content-Type: application/json', 'Cookie: ' . $cookie, 'X-CSRF-Token: ' . $token];
            same(400, http($base, '/greet', 'POST', '{', $headers)['status']);
            same(400, http($base, '/greet', 'POST', '[]', $headers)['status']);
            same(200, http($base, '/greet', 'POST', '{"name":"Ada"}', $headers)['status']);
            same(422, http($base, '/greet', 'POST', '{"name":["Ada"]}', $headers)['status']);
        });

        test('HTTP HEAD is leeg en privébestanden zijn onbereikbaar', static function () use ($base, &$cookie, &$token): void {
            same('', http($base, '/', 'HEAD')['body']);
            same(419, http($base, '/', 'DELETE', '', ['X-CSRF-Token: invalid'])['status']);
            same(405, http($base, '/', 'DELETE', '', ['Cookie: ' . $cookie, 'X-CSRF-Token: ' . $token])['status']);
            $asset = http($base, '/fixture.css');
            same(200, $asset['status']);
            same('body { color: black; }', $asset['body']);
            foreach (['/.env', '/bootstrap.php', '/database/app.sqlite', '/%2e%2e/.env', '/%00', '/%5c.env', '/assets%5c..%5c.env', '/.private.css', '/%5c.private.css'] as $path) {
                $response = http($base, $path);
                check(in_array($response['status'], [400, 404], true), 'Onverwachte status voor ' . $path);
            }
            same(419, http($base, '/health', 'POST')['status']);
            same(405, http($base, '/health', 'POST', '', ['Cookie: ' . $cookie, 'X-CSRF-Token: ' . $token])['status']);
            same(404, http($base, '/onbekend')['status']);
        });
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    unset($files, $file);
    rmdir($temp);
}

echo "\n$passed geslaagd, $failed mislukt.\n";
exit($failed === 0 ? 0 : 1);
