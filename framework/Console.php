<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class Console
{
    private const HELP = <<<'TEXT'
PBreeze - native PHP, zonder Composer of autoloader

Gebruik: php breeze <commando> [naam] [--force]

  init                       Maak een lokale .env (bestaande blijft behouden)
  key:generate               Toon een nieuwe APP_KEY; wijzigt geen bestanden
  serve [poort]              Start op http://127.0.0.1:8000
  routes                     Toon alle routes
  make:controller Naam       Maak een controller in app/controllers
  make:api Naam              Maak API-controller, routes en OpenAPI; registreer expliciet
  api:token ID [naam] [dagen] Maak token voor bevestigde gebruiker (standaard 30 dagen)
  api:tokens ID              Toon tokenmetadata voor een gebruiker, nooit de tokens
  api:revoke ID              Trek een API-token in
  api:openapi                Schrijf OpenAPI JSON naar stdout
  make:model Naam            Maak een model in app/models
  make:view map.naam         Maak een PHP-view in app/views
  make:template map.naam     Maak een gecompileerde Breeze-template
  view:clear                 Verwijder gecompileerde templates
  make:migration naam        Maak een up/down-migratie
  make:seeder Naam           Maak een seeder
  migrate                    Voer openstaande migraties uit
  migrate:status             Toon migraties en batchnummers
  migrate:rollback           Draai de laatste batch terug
  db:seed [Naam]             Voer een seeder uit (standaard DatabaseSeeder)
  mail:queue bestand.json    Bewaar een uitgaande e-mail in de wachtrij
  mail:list [limiet]          Toon de laatste e-mails (standaard 50)
  mail:show ID                Toon een opgeslagen e-mail als JSON
  mail:send ID                Verzend een queued e-mail via SMTP
  mail:work [limiet]          Verzend queued e-mails, stopt bij eerste fout
  mail:retry ID [--force]     Zet failed opnieuw klaar; force herstelt uncertain/sending
  mail:fetch [limiet]         Lees IMAP zonder Seen-vlag of mailboxwijzigingen
  test                       Voer de native tests uit
  help                       Toon deze hulp

Databasewijzigingen in productie vereisen --force. Rollback verwijdert mogelijk data.
TEXT;

    public function __construct(private readonly Application $app)
    {
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        $command = array_shift($arguments) ?? 'help';
        $force = in_array('--force', $arguments, true);
        $arguments = array_values(array_filter($arguments, static fn (string $arg): bool => $arg !== '--force'));
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '-')) {
                throw new InvalidArgumentException("Onbekende optie: $argument");
            }
        }
        if (in_array($command, ['api:token', 'api:tokens', 'api:revoke', 'api:openapi'], true)) {
            if ($force) {
                throw new InvalidArgumentException('API-commando accepteert geen --force.');
            }
            $this->apiCommand($command, $arguments);
            return 0;
        }
        $mailCommands = ['mail:queue', 'mail:list', 'mail:show', 'mail:send', 'mail:work', 'mail:retry', 'mail:fetch'];
        $allowsName = in_array($command, ['serve', 'make:controller', 'make:api', 'make:model', 'make:view', 'make:template', 'make:migration', 'make:seeder', 'db:seed', ...$mailCommands], true);
        if (count($arguments) > ($allowsName ? 1 : 0)) {
            throw new InvalidArgumentException('Te veel argumenten. Gebruik php breeze help.');
        }
        $name = $arguments[0] ?? null;
        if (in_array($command, $mailCommands, true)) {
            (new \PBreeze\Mail\MailConsole($this->app->mail))->run($command, $name, $force);
            return 0;
        }
        if (in_array($command, ['migrate', 'migrate:rollback', 'db:seed'], true)
            && $this->app->config['environment'] === 'production' && !$force) {
            throw new RuntimeException('Productie is beschermd. Gebruik bewust --force na een back-up.');
        }
        switch ($command) {
            case 'key:generate':
                if ($force) {
                    throw new InvalidArgumentException('key:generate overschrijft geen sleutels en accepteert geen --force.');
                }
                echo 'APP_KEY=' . Encryption::generateKey() . PHP_EOL;
                break;
            case 'help':
                echo self::HELP . PHP_EOL;
                break;
            case 'init':
                $target = $this->app->root . '/.env';
                if (is_file($target)) {
                    echo ".env bestaat al; niets gewijzigd.\n";
                    break;
                }
                $example = file_get_contents($this->app->root . '/.env.example');
                if ($example === false) {
                    throw new RuntimeException('Kan .env.example niet lezen.');
                }
                $this->write($target, $example, 0600);
                break;
            case 'serve':
                $port = $name ?? '8000';
                if (!ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
                    throw new InvalidArgumentException('Gebruik een poort tussen 1024 en 65535.');
                }
                echo "Ontwikkelserver: http://127.0.0.1:$port (niet voor productie)\n";
                return Process::run([
                    PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $this->app->root . '/public',
                    $this->app->root . '/public/router.php',
                ], $this->app->root);
            case 'routes':
                $this->app->routes();
                foreach ([...$this->app->router->listing(), ...$this->app->api->listing()] as $route) {
                    printf("%-7s %s\n", $route['method'], $route['path']);
                }
                break;
            case 'make:controller':
            case 'make:api':
            case 'make:model':
            case 'make:view':
            case 'make:template':
            case 'make:migration':
            case 'make:seeder':
                if ($name === null) {
                    throw new InvalidArgumentException('Geef een naam op.');
                }
                $this->generate($command, $name);
                break;
            case 'view:clear':
                echo 'Gecompileerde templates verwijderd: ' . $this->app->views->clearCache() . "\n";
                break;
            case 'migrate':
            case 'migrate:rollback':
            case 'migrate:status':
                $migrator = new Migrator($this->app->db, $this->app->root);
                if ($command === 'migrate:status') {
                    $rows = $migrator->status();
                    foreach ($rows as $migration => $batch) {
                        echo ($batch === null ? 'OPEN' : "BATCH $batch") . "  $migration\n";
                    }
                    if ($rows === []) {
                        echo "Geen migraties gevonden.\n";
                    }
                } else {
                    if ($this->app->config['database']['driver'] === 'mysql') {
                        fwrite(STDERR, "Let op: MySQL DDL is niet transactioneel. Maak eerst een back-up.\n");
                    }
                    $done = $command === 'migrate' ? $migrator->migrate() : $migrator->rollback();
                    foreach ($done as $migration) {
                        echo "$command: $migration\n";
                    }
                    if ($done === []) {
                        echo "Geen wijzigingen nodig.\n";
                    }
                }
                break;
            case 'db:seed':
                $this->seed($name ?? 'DatabaseSeeder');
                break;
            case 'test':
                return Process::run([PHP_BINARY, $this->app->root . '/tests/run.php'], $this->app->root);
            default:
                throw new InvalidArgumentException("Onbekend commando: $command. Gebruik php breeze help.");
        }
        return 0;
    }

    private function generate(string $command, string $name): void
    {
        Filesystem::portableName($name);
        if ($command === 'make:view' || $command === 'make:template') {
            if (!preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)*$/D', $name)) {
                throw new InvalidArgumentException('Gebruik een viewnaam zoals berichten.index.');
            }
            $base = '/app/views/' . str_replace('.', '/', $name);
            foreach (['.php', '.breeze.php'] as $extension) {
                if (file_exists($this->app->root . $base . $extension)) {
                    throw new RuntimeException("View bestaat al: $name");
                }
            }
            $path = $base . ($command === 'make:template' ? '.breeze.php' : '.php');
            $content = $command === 'make:template' ? <<<'TEMPLATE'
<h1>{{ $title ?? 'Nieuwe pagina' }}</h1>

TEMPLATE : <<<'PHP'
<?php
declare(strict_types=1);
?>
<h1><?= e($title ?? 'Nieuwe pagina') ?></h1>

PHP;
        } elseif ($command === 'make:migration') {
            if (!preg_match('/^[a-z][a-z0-9_]{0,100}$/D', $name)) {
                throw new InvalidArgumentException('Gebruik snake_case voor migraties (maximaal 101 tekens).');
            }
            $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('YmdHisu');
            $path = "/database/migrations/{$stamp}_$name.php";
            $content = <<<'PHP'
<?php
declare(strict_types=1);

use PBreeze\Migration;

return new class extends Migration {
    public function up(PDO $db): void
    {
        throw new LogicException('Implementeer eerst up().');
    }

    public function down(PDO $db): void
    {
        throw new LogicException('Implementeer eerst down().');
    }
};

PHP;
        } else {
            if (!preg_match('/^[A-Z][a-zA-Z0-9]{0,100}$/D', $name)
                || token_get_all('<?php ' . $name)[1][0] !== T_STRING
                || in_array(strtolower($name), ['class', 'trait', 'interface', 'enum', 'match', 'function', 'parent', 'self', 'static', 'readonly', 'namespace', 'use', 'new', 'default', 'return', 'abstract', 'final', 'extends', 'implements', 'echo', 'print', 'list', 'array', 'string', 'int', 'float', 'bool', 'true', 'false', 'null', 'object', 'mixed', 'never', 'void', 'iterable', 'callable', 'resource'], true)) {
                throw new InvalidArgumentException('Gebruik een geldige PascalCase-naam zonder gereserveerde woorden.');
            }
            if ($command === 'make:api') {
                $this->generateApi($name);
                return;
            } elseif ($command === 'make:controller') {
                if (!str_ends_with($name, 'Controller')) {
                    $name .= 'Controller';
                }
                $path = "/app/controllers/$name.php";
                $content = str_replace('{{name}}', $name, <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Controllers;

use PBreeze\Application;
use PBreeze\Request;
use PBreeze\Response;

final class {{name}}
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        return Response::json(['message' => 'Vervang dit door je eigen actie.']);
    }
}

PHP);
            } elseif ($command === 'make:model') {
                $path = "/app/models/$name.php";
                $table = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . 's';
                $content = str_replace(['{{name}}', '{{table}}'], [$name, $table], <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Models;

final class {{name}} extends \PBreeze\Model
{
    protected string $table = '{{table}}';
    protected array $fillable = [];
    protected array $hidden = [];
    protected array $casts = [];
}

PHP);
            } else {
                $path = "/database/seeders/$name.php";
                $content = <<<'PHP'
<?php
declare(strict_types=1);

return static function (PDO $db): void {
    throw new LogicException('Implementeer eerst de seeder.');
};

PHP;
            }
        }
        $this->write($this->app->root . $path, $content);
        if ($command === 'make:controller') {
            echo "Laad de controller expliciet met require_once in routes/web.php en registreer de route.\n";
        } elseif ($command === 'make:model') {
            echo "Controleer de tabelnaam en vul fillable/casts/hidden in. Laad het model expliciet met require_once.\n";
        }
    }

    private function seed(string $name): void
    {
        Filesystem::portableName($name);
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/D', $name)) {
            throw new InvalidArgumentException('Ongeldige seedernaam.');
        }
        $path = $this->app->root . "/database/seeders/$name.php";
        if (!is_file($path)) {
            throw new RuntimeException("Seeder bestaat niet: $name");
        }
        $seeder = require $path;
        if (!$seeder instanceof \Closure) {
            throw new RuntimeException('Een seeder moet een Closure teruggeven.');
        }
        $this->app->db->transaction($seeder);
        echo "Seeder uitgevoerd: $name\n";
    }

    /** @param list<string> $arguments */
    private function apiCommand(string $command, array $arguments): void
    {
        $count = count($arguments);
        if ($command === 'api:openapi') {
            if ($count !== 0) {
                throw new InvalidArgumentException('Gebruik: php breeze api:openapi');
            }
            $this->app->routes();
            echo json_encode($this->app->api->openApi(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return;
        }
        if ($count < 1 || $count > ($command === 'api:token' ? 3 : 1)
            || !ctype_digit($arguments[0]) || strlen($arguments[0]) > 18 || (int) $arguments[0] < 1) {
            throw new InvalidArgumentException('Gebruik een positief ID en controleer php breeze help.');
        }
        $id = (int) $arguments[0];
        if ($command === 'api:token') {
            $days = $arguments[2] ?? '30';
            if (!ctype_digit($days) || strlen($days) > 3 || (int) $days < 1 || (int) $days > 365) {
                throw new InvalidArgumentException('Tokenverval moet tussen 1 en 365 dagen liggen.');
            }
            $result = $this->app->api->tokens->create($id, $arguments[1] ?? 'cli', (int) $days);
            echo "Bewaar dit bearer-token veilig: het wordt maar één keer getoond.\n";
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        } elseif ($command === 'api:tokens') {
            echo json_encode($this->app->api->tokens->listing($id), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        } else {
            $this->app->api->tokens->revoke($id);
            echo "API-token ingetrokken: $id\n";
        }
    }

    private function generateApi(string $name): void
    {
        $resource = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
        if (in_array($resource, ['ping', 'me', 'token', 'docs'], true)) {
            throw new InvalidArgumentException('Deze API-resourcenaam is gereserveerd.');
        }
        $class = $name . 'ApiController';
        $controller = $this->app->root . "/app/controllers/api/$class.php";
        $module = $this->app->root . "/routes/api/$resource.php";
        $registry = $this->app->root . '/routes/api.php';
        foreach ([$controller, $module] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException("Bestand bestaat al: $path");
            }
        }
        if (!is_file($registry)) {
            throw new RuntimeException('routes/api.php ontbreekt; herstel eerst het API-register.');
        }
        $handle = fopen($registry, 'r+');
        if ($handle === false) {
            throw new RuntimeException('Kan API-register niet openen.');
        }
        $created = [];
        $original = null;
        $changed = false;
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Kan API-register niet vergrendelen.');
            }
            $original = stream_get_contents($handle);
            if ($original === false) {
                throw new RuntimeException('Kan API-register niet lezen.');
            }
            foreach (token_get_all($original, TOKEN_PARSE) as $token) {
                if (is_array($token) && in_array($token[0], [T_CLOSE_TAG, T_INLINE_HTML, T_HALT_COMPILER], true)) {
                    throw new RuntimeException('API-register moet een PHP-bestand zonder sluitende tag zijn.');
                }
            }
            $preview = new Application($this->app->root, $this->app->config);
            $preview->routes();
            if (class_exists("App\\Controllers\\Api\\$class", false)) {
                throw new RuntimeException('API-controllerklasse bestaat al; PHP-klassen zijn hoofdletterongevoelig.');
            }
            foreach ($preview->api->listing() as $route) {
                if ($route['path'] === "/api/$resource" || str_starts_with($route['path'], "/api/$resource/")) {
                    throw new RuntimeException('API-resource heeft al routes.');
                }
            }
            $ids = array_map(static fn (string $action): string => 'generated' . $name . ucfirst($action), ['index', 'store', 'show', 'update', 'destroy']);
            foreach ($preview->api->openApi()['paths'] as $operations) {
                foreach ($operations as $operation) {
                    if (in_array($operation['operationId'], $ids, true)) {
                        throw new RuntimeException('Een gegenereerd OpenAPI operationId bestaat al.');
                    }
                }
            }
            $controllerSource = str_replace('{{class}}', $class, <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use PBreeze\Application;
use PBreeze\HttpException;
use PBreeze\Request;
use PBreeze\Response;

final class {{class}}
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        throw new HttpException(501, 'Implementeer index(), inclusief toegangscontrole op je eigen gegevens.');
    }

    public function store(Request $request): Response
    {
        throw new HttpException(501, 'Implementeer store(), inclusief invoervalidatie en toegangscontrole.');
    }

    public function show(Request $request, string $id): Response
    {
        throw new HttpException(501, 'Implementeer show(), inclusief toegangscontrole op dit record.');
    }

    public function update(Request $request, string $id): Response
    {
        throw new HttpException(501, 'Implementeer update(), inclusief invoervalidatie en toegangscontrole.');
    }

    public function destroy(Request $request, string $id): Response
    {
        throw new HttpException(501, 'Implementeer destroy(), inclusief toegangscontrole op dit record.');
    }
}

PHP);
            $moduleSource = "<?php\ndeclare(strict_types=1);\n\n";
            $moduleSource .= "require_once \$app->root . '/app/controllers/api/$class.php';\n\n";
            $moduleSource .= "(static function (\\PBreeze\\Application \$app, \\PBreeze\\Api \$api): void {\n";
            $moduleSource .= "    \$controller = new \\App\\Controllers\\Api\\$class(\$app);\n";
            foreach ([
                ['GET', '', 'index', 'List'], ['POST', '', 'store', 'Create'],
                ['GET', '/{id}', 'show', 'Get'], ['PUT', '/{id}', 'update', 'Update'], ['DELETE', '/{id}', 'destroy', 'Delete'],
            ] as [$method, $suffix, $action, $summary]) {
                $moduleSource .= "    \$api->add('$method', '/api/$resource$suffix', [\$controller, '$action'], [\n";
                $moduleSource .= "        'summary' => '$summary $name', 'operationId' => 'generated{$name}" . ucfirst($action) . "',\n";
                if (in_array($method, ['POST', 'PUT'], true)) {
                    $moduleSource .= "        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],\n";
                }
                $moduleSource .= "        'tags' => ['$name'],\n        'responses' => ['501' => ['description' => 'Implement this action before use']],\n    ]);\n";
            }
            $moduleSource .= "})(\$app, \$api);\n";
            $this->write($controller, $controllerSource);
            $created[] = $controller;
            $this->write($module, $moduleSource);
            $created[] = $module;
            $line = "\nrequire \$app->root . '/routes/api/$resource.php';\n";
            $changed = true;
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                throw new RuntimeException('Kan API-module niet registreren.');
            }
            echo "Geregistreerd: /api/$resource. Alle acties vereisen een bearer-token en geven 501 tot implementatie.\n";
        } catch (\Throwable $error) {
            if ($changed && is_string($original)) {
                if (!ftruncate($handle, strlen($original)) || !fflush($handle)) {
                    throw new RuntimeException('Herstel van API-register mislukt; controleer routes/api.php.', 0, $error);
                }
            }
            foreach (array_reverse($created) as $path) {
                if (!unlink($path)) {
                    throw new RuntimeException("Opruimen mislukt: $path", 0, $error);
                }
            }
            throw $error;
        } finally {
            fclose($handle);
        }
    }

    private function write(string $path, string $content, int $mode = 0644): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException("Bestand bestaat al: $path");
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Kan directory niet aanmaken.');
        }
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException("Bestand bestaat al of is niet schrijfbaar: $path");
        }
        try {
            Filesystem::permissions($path, $mode);
            if (fwrite($handle, $content) !== strlen($content)) {
                throw new RuntimeException("Kan bestand niet volledig schrijven: $path");
            }
        } catch (\Throwable $error) {
            fclose($handle);
            if (!unlink($path)) {
                throw new RuntimeException("Kan onvolledig bestand niet verwijderen: $path", 0, $error);
            }
            throw $error;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        echo "Aangemaakt: $path\n";
    }
}
