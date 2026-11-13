<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class Console
{
    private const HELP = <<<'TEXT'
PBreeze - native PHP, without Composer or an autoloader

Usage: php breeze <command> [name] [--force]

  init                       Create a local .env (preserve an existing file)
  key:generate               Print a new APP_KEY; do not modify any files
  serve [port]               Start at http://127.0.0.1:8000
  routes                     List all routes
  make:controller Name       Create a controller in app/controllers
  make:api Name              Create and explicitly register API controller, routes, OpenAPI
  api:token ID [name] [days]  Create a token for a verified user (default: 30 days)
  api:tokens ID              List token metadata for a user, never the tokens
  api:revoke ID              Revoke an API token
  api:openapi                Write OpenAPI JSON to stdout
  make:model Name            Create a model in app/models
  make:view folder.name      Create a PHP view in app/views
  make:template folder.name  Create a compiled Breeze template
  view:clear                 Remove compiled templates
  make:migration name        Create an up/down migration
  make:seeder Name           Create a seeder
  migrate                    Run pending migrations
  migrate:status             List migrations and batch numbers
  migrate:rollback           Roll back the latest batch
  db:seed [Name]             Run a seeder (default: DatabaseSeeder)
  mail:queue message.json    Queue an outgoing email
  mail:list [limit]          List recent emails (default: 50)
  mail:show ID                Show a stored email as JSON
  mail:send ID                Send a queued email via SMTP
  mail:work [limit]          Send queued emails; stop on the first error
  mail:retry ID [--force]     Requeue failed mail; force recovers uncertain/sending
  mail:fetch [limit]         Read IMAP without setting Seen or changing the mailbox
  test                       Run the native tests
  help                       Show this help

Production database changes require --force. Rollback may delete data.
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
                throw new InvalidArgumentException("Unknown option: $argument");
            }
        }
        if (in_array($command, ['api:token', 'api:tokens', 'api:revoke', 'api:openapi'], true)) {
            if ($force) {
                throw new InvalidArgumentException('API commands do not accept --force.');
            }
            $this->apiCommand($command, $arguments);
            return 0;
        }
        $mailCommands = ['mail:queue', 'mail:list', 'mail:show', 'mail:send', 'mail:work', 'mail:retry', 'mail:fetch'];
        $allowsName = in_array($command, ['serve', 'make:controller', 'make:api', 'make:model', 'make:view', 'make:template', 'make:migration', 'make:seeder', 'db:seed', ...$mailCommands], true);
        if (count($arguments) > ($allowsName ? 1 : 0)) {
            throw new InvalidArgumentException('Too many arguments. Use php breeze help.');
        }
        $name = $arguments[0] ?? null;
        if (in_array($command, $mailCommands, true)) {
            (new \PBreeze\Mail\MailConsole($this->app->mail))->run($command, $name, $force);
            return 0;
        }
        if (in_array($command, ['migrate', 'migrate:rollback', 'db:seed'], true)
            && $this->app->config['environment'] === 'production' && !$force) {
            throw new RuntimeException('Production is protected. Back up your data before explicitly using --force.');
        }
        switch ($command) {
            case 'key:generate':
                if ($force) {
                    throw new InvalidArgumentException('key:generate does not overwrite keys and does not accept --force.');
                }
                echo 'APP_KEY=' . Encryption::generateKey() . PHP_EOL;
                break;
            case 'help':
                echo self::HELP . PHP_EOL;
                break;
            case 'init':
                $target = $this->app->root . '/.env';
                if (is_file($target)) {
                    echo ".env already exists; nothing changed.\n";
                    break;
                }
                $example = file_get_contents($this->app->root . '/.env.example');
                if ($example === false) {
                    throw new RuntimeException('Cannot read .env.example.');
                }
                $this->write($target, $example, 0600);
                break;
            case 'serve':
                $port = $name ?? '8000';
                if (!ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
                    throw new InvalidArgumentException('Use a port between 1024 and 65535.');
                }
                echo "Development server: http://127.0.0.1:$port (not for production)\n";
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
                    throw new InvalidArgumentException('Provide a name.');
                }
                $this->generate($command, $name);
                break;
            case 'view:clear':
                echo 'Compiled templates removed: ' . $this->app->views->clearCache() . "\n";
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
                        echo "No migrations found.\n";
                    }
                } else {
                    if ($this->app->config['database']['driver'] === 'mysql') {
                        fwrite(STDERR, "Warning: MySQL DDL is not transactional. Create a backup first.\n");
                    }
                    $done = $command === 'migrate' ? $migrator->migrate() : $migrator->rollback();
                    foreach ($done as $migration) {
                        echo "$command: $migration\n";
                    }
                    if ($done === []) {
                        echo "No changes needed.\n";
                    }
                }
                break;
            case 'db:seed':
                $this->seed($name ?? 'DatabaseSeeder');
                break;
            case 'test':
                return Process::run([PHP_BINARY, $this->app->root . '/tests/run.php'], $this->app->root);
            default:
                throw new InvalidArgumentException("Unknown command: $command. Use php breeze help.");
        }
        return 0;
    }

    private function generate(string $command, string $name): void
    {
        Filesystem::portableName($name);
        if ($command === 'make:view' || $command === 'make:template') {
            if (!preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)*$/D', $name)) {
                throw new InvalidArgumentException('Use a view name such as posts.index.');
            }
            $base = '/app/views/' . str_replace('.', '/', $name);
            foreach (['.php', '.breeze.php'] as $extension) {
                if (file_exists($this->app->root . $base . $extension)) {
                    throw new RuntimeException("View already exists: $name");
                }
            }
            $path = $base . ($command === 'make:template' ? '.breeze.php' : '.php');
            $content = $command === 'make:template' ? <<<'TEMPLATE'
<h1>{{ $title ?? 'New page' }}</h1>

TEMPLATE : <<<'PHP'
<?php
declare(strict_types=1);
?>
<h1><?= e($title ?? 'New page') ?></h1>

PHP;
        } elseif ($command === 'make:migration') {
            if (!preg_match('/^[a-z][a-z0-9_]{0,100}$/D', $name)) {
                throw new InvalidArgumentException('Use snake_case for migrations (up to 101 characters).');
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
        throw new LogicException('Implement up() first.');
    }

    public function down(PDO $db): void
    {
        throw new LogicException('Implement down() first.');
    }
};

PHP;
        } else {
            if (!preg_match('/^[A-Z][a-zA-Z0-9]{0,100}$/D', $name)
                || token_get_all('<?php ' . $name)[1][0] !== T_STRING
                || in_array(strtolower($name), ['class', 'trait', 'interface', 'enum', 'match', 'function', 'parent', 'self', 'static', 'readonly', 'namespace', 'use', 'new', 'default', 'return', 'abstract', 'final', 'extends', 'implements', 'echo', 'print', 'list', 'array', 'string', 'int', 'float', 'bool', 'true', 'false', 'null', 'object', 'mixed', 'never', 'void', 'iterable', 'callable', 'resource'], true)) {
                throw new InvalidArgumentException('Use a valid PascalCase name without reserved words.');
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
        return Response::json(['message' => 'Replace this with your own action.']);
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
    throw new LogicException('Implement the seeder first.');
};

PHP;
            }
        }
        $this->write($this->app->root . $path, $content);
        if ($command === 'make:controller') {
            echo "Load the controller explicitly with require_once in routes/web.php and register the route.\n";
        } elseif ($command === 'make:model') {
            echo "Check the table name and configure fillable/casts/hidden. Load the model explicitly with require_once.\n";
        }
    }

    private function seed(string $name): void
    {
        Filesystem::portableName($name);
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/D', $name)) {
            throw new InvalidArgumentException('Invalid seeder name.');
        }
        $path = $this->app->root . "/database/seeders/$name.php";
        if (!is_file($path)) {
            throw new RuntimeException("Seeder does not exist: $name");
        }
        $seeder = require $path;
        if (!$seeder instanceof \Closure) {
            throw new RuntimeException('A seeder must return a Closure.');
        }
        $this->app->db->transaction($seeder);
        echo "Seeder completed: $name\n";
    }

    /** @param list<string> $arguments */
    private function apiCommand(string $command, array $arguments): void
    {
        $count = count($arguments);
        if ($command === 'api:openapi') {
            if ($count !== 0) {
                throw new InvalidArgumentException('Usage: php breeze api:openapi');
            }
            $this->app->routes();
            echo json_encode($this->app->api->openApi(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return;
        }
        if ($count < 1 || $count > ($command === 'api:token' ? 3 : 1)
            || !ctype_digit($arguments[0]) || strlen($arguments[0]) > 18 || (int) $arguments[0] < 1) {
            throw new InvalidArgumentException('Use a positive ID and check php breeze help.');
        }
        $id = (int) $arguments[0];
        if ($command === 'api:token') {
            $days = $arguments[2] ?? '30';
            if (!ctype_digit($days) || strlen($days) > 3 || (int) $days < 1 || (int) $days > 365) {
                throw new InvalidArgumentException('Token expiration must be between 1 and 365 days.');
            }
            $result = $this->app->api->tokens->create($id, $arguments[1] ?? 'cli', (int) $days);
            echo "Store this bearer token securely: it is shown only once.\n";
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        } elseif ($command === 'api:tokens') {
            echo json_encode($this->app->api->tokens->listing($id), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        } else {
            $this->app->api->tokens->revoke($id);
            echo "API token revoked: $id\n";
        }
    }

    private function generateApi(string $name): void
    {
        $resource = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
        if (in_array($resource, ['ping', 'me', 'token', 'docs'], true)) {
            throw new InvalidArgumentException('This API resource name is reserved.');
        }
        $class = $name . 'ApiController';
        $controller = $this->app->root . "/app/controllers/api/$class.php";
        $module = $this->app->root . "/routes/api/$resource.php";
        $registry = $this->app->root . '/routes/api.php';
        foreach ([$controller, $module] as $path) {
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException("File already exists: $path");
            }
        }
        if (!is_file($registry)) {
            throw new RuntimeException('routes/api.php is missing; restore the API registry first.');
        }
        $handle = fopen($registry, 'r+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the API registry.');
        }
        $created = [];
        $original = null;
        $changed = false;
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the API registry.');
            }
            $original = stream_get_contents($handle);
            if ($original === false) {
                throw new RuntimeException('Cannot read the API registry.');
            }
            foreach (token_get_all($original, TOKEN_PARSE) as $token) {
                if (is_array($token) && in_array($token[0], [T_CLOSE_TAG, T_INLINE_HTML, T_HALT_COMPILER], true)) {
                    throw new RuntimeException('The API registry must be a PHP file without a closing tag.');
                }
            }
            $preview = new Application($this->app->root, $this->app->config);
            $preview->routes();
            if (class_exists("App\\Controllers\\Api\\$class", false)) {
                throw new RuntimeException('The API controller class already exists; PHP class names are case-insensitive.');
            }
            foreach ($preview->api->listing() as $route) {
                if ($route['path'] === "/api/$resource" || str_starts_with($route['path'], "/api/$resource/")) {
                    throw new RuntimeException('The API resource already has routes.');
                }
            }
            $ids = array_map(static fn (string $action): string => 'generated' . $name . ucfirst($action), ['index', 'store', 'show', 'update', 'destroy']);
            foreach ($preview->api->openApi()['paths'] as $operations) {
                foreach ($operations as $operation) {
                    if (in_array($operation['operationId'], $ids, true)) {
                        throw new RuntimeException('A generated OpenAPI operationId already exists.');
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
        throw new HttpException(501, 'Implement index(), including authorization for your own data.');
    }

    public function store(Request $request): Response
    {
        throw new HttpException(501, 'Implement store(), including input validation and authorization.');
    }

    public function show(Request $request, string $id): Response
    {
        throw new HttpException(501, 'Implement show(), including authorization for this record.');
    }

    public function update(Request $request, string $id): Response
    {
        throw new HttpException(501, 'Implement update(), including input validation and authorization.');
    }

    public function destroy(Request $request, string $id): Response
    {
        throw new HttpException(501, 'Implement destroy(), including authorization for this record.');
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
                throw new RuntimeException('Cannot register the API module.');
            }
            echo "Registered: /api/$resource. All actions require a bearer token and return 501 until implemented.\n";
        } catch (\Throwable $error) {
            if ($changed && is_string($original)) {
                if (!ftruncate($handle, strlen($original)) || !fflush($handle)) {
                    throw new RuntimeException('Failed to restore the API registry; check routes/api.php.', 0, $error);
                }
            }
            foreach (array_reverse($created) as $path) {
                if (!unlink($path)) {
                    throw new RuntimeException("Cleanup failed: $path", 0, $error);
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
            throw new RuntimeException("File already exists: $path");
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the directory.');
        }
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException("File already exists or is not writable: $path");
        }
        try {
            Filesystem::permissions($path, $mode);
            if (fwrite($handle, $content) !== strlen($content)) {
                throw new RuntimeException("Cannot write the complete file: $path");
            }
        } catch (\Throwable $error) {
            fclose($handle);
            if (!unlink($path)) {
                throw new RuntimeException("Cannot remove the incomplete file: $path", 0, $error);
            }
            throw $error;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        echo "Created: $path\n";
    }
}
