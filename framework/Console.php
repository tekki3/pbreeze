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
  about                      Show safe runtime and configured driver information
  env:check                  Compare environment keys without printing values
  cache:test                 Verify cache round trip using one temporary key
  logs:tail [lines] [level]  Show recent JSONL logs (default: 50; optional minimum level)
  make:command Name          Create a custom command for explicit registration
  make:test Name             Create a native test for explicit registration
  scheduler:install          Check background prerequisites; show setup steps
  scheduler:list             List configured schedules and next occurrences
  scheduler:status           Show schedule state and background work counts
  scheduler:run              Materialize due schedules and execute direct work once
  scheduler:work             Run the native scheduler continuously
  queue:work                 Process durable jobs (--queue=default --sleep=1)
  queue:status               Show queue counts without payloads
  queue:failed               List recent failed and uncertain jobs
  queue:retry ID             Retry one confirmed failed job
  queue:retry-all            Retry confirmed failures in bounded batches
  queue:resolve ID action    Resolve uncertain work (retry or complete; requires --force)
  queue:prune                Remove old terminal history (--days=30 --limit=100)
  workers:list               List worker heartbeats and health
  workers:stop ID            Request graceful worker shutdown
  doctor                     Check production configuration and dependency readiness
  storage:prepare            Create private storage directories for deployment
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
  make:form folder.name field:type ...  Create a Breeze POST form view
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
        if (str_starts_with($command, 'scheduler:') || str_starts_with($command, 'queue:')
            || str_starts_with($command, 'workers:') || $command === '_background:execute') {
            return (new \PBreeze\Scheduler\SchedulerConsole($this->app))->run($command, $arguments);
        }
        if (in_array($command, ['about', 'env:check', 'cache:test', 'logs:tail', 'make:command', 'make:test'], true)) {
            return $this->utilityCommand($command, $arguments);
        }
        preg_match_all('/^  ([a-z][a-z:-]+)\s/m', self::HELP, $builtins);
        if (!in_array($command, $builtins[1], true)) {
            $custom = $this->customCommands();
            if (!isset($custom[$command])) {
                throw new InvalidArgumentException("Unknown command: $command. Use php breeze help.");
            }
            $result = $custom[$command]($this->app, $arguments);
            if (!is_int($result) || $result < 0 || $result > 255) {
                throw new RuntimeException('Custom commands must return an integer exit code from 0 through 255.');
            }
            return $result;
        }
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
        if ($command === 'make:form') {
            if ($force || count($arguments) < 2) {
                throw new InvalidArgumentException('Usage: php breeze make:form folder.name field:type [field:type ...] (no --force).');
            }
            $name = array_shift($arguments);
            $this->generate($command, $name, $arguments);
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
            case 'doctor':
                if ($force) {
                    throw new InvalidArgumentException('doctor does not accept --force.');
                }
                $failed = false;
                foreach ((new Diagnostics($this->app))->check() as $check) {
                    echo strtoupper($check['status']) . ' ' . $check['name'] . ': ' . $check['message'] . "\n";
                    $failed = $failed || $check['status'] === 'fail';
                }
                return $failed ? 1 : 0;
            case 'storage:prepare':
                if ($force) {
                    throw new InvalidArgumentException('storage:prepare does not accept --force.');
                }
                foreach (['', 'logs', 'uploads', 'views'] as $child) {
                    Filesystem::privateDirectory($this->app->root, $this->app->storagePath, $child);
                }
                echo "Private storage prepared. Verify ownership and NTFS ACLs before serving requests.\n";
                break;
            case 'key:generate':
                if ($force) {
                    throw new InvalidArgumentException('key:generate does not overwrite keys and does not accept --force.');
                }
                echo 'APP_KEY=' . Encryption::generateKey() . PHP_EOL;
                break;
            case 'help':
                echo self::HELP . PHP_EOL;
                foreach ($this->customCommands() as $name => $handler) {
                    echo "  $name (custom command)\n";
                }
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
                $migrator = new Migrator($this->app->db, $this->app->root, $this->app->storagePath);
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

    /** @param list<string> $arguments */
    private function utilityCommand(string $command, array $arguments): int
    {
        $max = match ($command) { 'logs:tail' => 2, 'make:command', 'make:test' => 1, default => 0 };
        if (count($arguments) > $max) {
            throw new InvalidArgumentException('Too many arguments. Use php breeze help.');
        }
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '-')) {
                throw new InvalidArgumentException('These commands do not accept options or --force.');
            }
        }
        switch ($command) {
            case 'make:test':
                $name = $arguments[0] ?? '';
                Filesystem::portableName($name);
                if (!preg_match('/^[A-Z][a-zA-Z0-9]{0,100}$/D', $name)) {
                    throw new InvalidArgumentException('Use a test name such as ContactTest.');
                }
                if (!str_ends_with($name, 'Test')) {
                    $name .= 'Test';
                }
                $directory = $this->app->root . '/tests';
                if (is_link($directory) || is_link($directory . '/application')) {
                    throw new RuntimeException('Test directories must not be symlinks.');
                }
                $this->write($directory . "/application/$name.php", str_replace('{{name}}', $name, <<<'PHP'
<?php
declare(strict_types=1);

test('{{name}}', static function (): void {
    throw new LogicException('Implement this test with check(), same(), or raises().');
});

PHP));
                echo "Register explicitly in tests/application.php:\n"
                    . "require __DIR__ . '/application/$name.php';\n"
                    . "Replace the placeholder with assertions, then run php breeze test.\n";
                return 0;
            case 'about':
                $safe = static fn (string $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                echo "PBreeze: native PHP framework (no release version declared)\n";
                echo 'PHP: ' . PHP_VERSION . "\nPlatform: " . PHP_OS_FAMILY . "\nSAPI: " . PHP_SAPI . "\n";
                foreach ([
                    'Environment' => $this->app->config['environment'],
                    'Database driver' => $this->app->config['database']['driver'],
                    'Cache driver' => $this->app->config['cache']['driver'] ?? 'none',
                    'Session handler' => ($this->app->config['session']['driver'] ?? 'native') === 'database'
                        ? 'database' : (string) ini_get('session.save_handler'),
                ] as $label => $value) {
                    echo $label . ': ' . $safe($value) . "\n";
                }
                $extensions = get_loaded_extensions();
                sort($extensions, SORT_NATURAL | SORT_FLAG_CASE);
                echo 'Extensions: ' . implode(', ', $extensions) . "\n";
                return 0;
            case 'env:check':
                return $this->checkEnvironment();
            case 'cache:test':
                $key = 'cli-probe:' . bin2hex(random_bytes(24));
                $value = bin2hex(random_bytes(24));
                $cache = $this->app->cache;
                $cache->set($key, $value, 30);
                try {
                    if ($cache->get($key) !== $value) {
                        throw new RuntimeException('Cache probe value did not round-trip.');
                    }
                } finally {
                    if (!$cache->delete($key)) {
                        throw new RuntimeException('Cache probe cleanup failed; its key expires after 30 seconds.');
                    }
                }
                echo "PASS Cache write/read/delete completed using an isolated temporary key.\n";
                return 0;
            case 'logs:tail':
                $lines = $arguments[0] ?? '50';
                if (!ctype_digit($lines) || strlen($lines) > 4 || (int) $lines < 1 || (int) $lines > 1000) {
                    throw new InvalidArgumentException('Log line count must be between 1 and 1000.');
                }
                $this->tailLogs((int) $lines, $arguments[1] ?? 'debug');
                return 0;
            case 'make:command':
                $name = $arguments[0] ?? '';
                Filesystem::portableName($name);
                if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/D', $name)) {
                    throw new InvalidArgumentException('Use a command filename such as Report.');
                }
                $directory = $this->app->root . '/app/commands';
                if (is_link($this->app->root . '/app') || is_link($directory)) {
                    throw new RuntimeException('Command directories must not be symlinks.');
                }
                $this->write($directory . "/$name.php", <<<'PHP'
<?php
declare(strict_types=1);

use PBreeze\Application;

return static function (Application $app, array $arguments): int {
    if ($arguments !== []) {
        throw new InvalidArgumentException('This command does not accept arguments.');
    }
    throw new LogicException('Implement this command before using it.');
};

PHP);
                $key = 'app:' . strtolower($name);
                echo "Register explicitly in routes/console.php:\n"
                    . "    '$key' => require \$app->root . '/app/commands/$name.php',\n";
                return 0;
        }
        throw new \LogicException('Unknown utility command.');
    }

    /** @return array<string, \Closure> */
    private function customCommands(): array
    {
        $path = $this->app->root . '/routes/console.php';
        if (!is_file($path)) {
            return [];
        }
        $app = $this->app;
        $commands = require $path;
        if (!is_array($commands)) {
            throw new RuntimeException('routes/console.php must return an array of command names and Closures.');
        }
        preg_match_all('/^  ([a-z][a-z:-]+)\s/m', self::HELP, $builtins);
        foreach ($commands as $name => $handler) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)*$/D', $name)
                || in_array($name, $builtins[1], true) || !$handler instanceof \Closure) {
                throw new RuntimeException('Custom commands require unique nonbuiltin names and Closure handlers.');
            }
        }
        return $commands;
    }

    private function checkEnvironment(): int
    {
        $example = $this->app->root . '/.env.example';
        $source = file($example, FILE_IGNORE_NEW_LINES);
        if ($source === false) {
            throw new RuntimeException('Cannot read the example environment.');
        }
        $expected = [];
        foreach ($source as $line) {
            if (preg_match('/^(#\s*)?([A-Z][A-Z0-9_]*)=/', $line, $match)) {
                $expected[$match[2]] = $match[1] !== '';
            }
        }
        $actual = [];
        $path = $this->app->root . '/.env';
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                throw new RuntimeException('Cannot read the environment file.');
            }
            foreach ($lines as $index => $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/D', $line, $match)) {
                    throw new RuntimeException('Invalid environment syntax at line ' . ($index + 1) . '.');
                }
                if (isset($actual[$match[1]])) {
                    throw new RuntimeException('Duplicate environment key at line ' . ($index + 1) . '.');
                }
                $value = trim($match[2]);
                if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                    if (strlen($value) < 2 || $value[-1] !== $value[0]) {
                        throw new RuntimeException('Invalid environment quotation at line ' . ($index + 1) . '.');
                    }
                    $value = substr($value, 1, -1);
                }
                $actual[$match[1]] = $value;
            }
        }
        $missing = false;
        foreach ($expected as $key => $optional) {
            $process = getenv($key);
            $value = $process !== false ? $process : ($actual[$key] ?? null);
            $status = $value === null ? ($optional ? 'OPTIONAL' : 'MISSING') : ($value === '' ? 'EMPTY' : 'SET');
            echo "$status $key\n";
            $missing = $missing || $status === 'MISSING';
        }
        foreach (array_diff_key($actual, $expected) as $key => $value) {
            echo "CUSTOM $key\n";
        }
        echo "Values are never printed. EMPTY may be intentional; MISSING keys may still have framework defaults. Use doctor for production validation.\n";
        return $missing ? 1 : 0;
    }

    private function tailLogs(int $limit, string $minimum): void
    {
        $levels = array_flip(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']);
        if (!isset($levels[$minimum])) {
            throw new InvalidArgumentException('Use a valid lowercase minimum log level.');
        }
        if (!file_exists($this->app->storagePath) && !is_link($this->app->storagePath)) {
            echo "No application logs found.\n";
            return;
        }
        $storage = Filesystem::privateDirectory($this->app->root, $this->app->storagePath, '', false);
        if (!file_exists($storage . '/logs') && !is_link($storage . '/logs')) {
            echo "No application logs found.\n";
            return;
        }
        $directory = Filesystem::privateDirectory($this->app->root, $this->app->storagePath, 'logs', false);
        $files = glob($directory . '/app-????-??-??.jsonl');
        if ($files === false) {
            throw new RuntimeException('Cannot list application logs.');
        }
        rsort($files, SORT_STRING);
        $output = [];
        $scanned = 0;
        foreach ($files as $file) {
            if (is_link($file) || !is_file($file)) {
                throw new RuntimeException('Daily log paths must be regular files, not symlinks.');
            }
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Cannot read a daily log.');
            }
            try {
                if (fseek($handle, 0, SEEK_END) !== 0 || ($position = ftell($handle)) === false) {
                    throw new RuntimeException('Cannot seek within a daily log.');
                }
                $buffer = '';
                while ($position > 0) {
                    $length = min(8192, $position);
                    $scanned += $length;
                    if ($scanned > 8388608) {
                        throw new RuntimeException('Log scan exceeded 8 MiB. Request fewer lines or a less restrictive level.');
                    }
                    $position -= $length;
                    if (fseek($handle, $position) !== 0 || ($chunk = fread($handle, $length)) === false || strlen($chunk) !== $length) {
                        throw new RuntimeException('Cannot read the log snapshot; retry if logs were rotated.');
                    }
                    $buffer = $chunk . $buffer;
                    $parts = explode("\n", $buffer);
                    $buffer = array_shift($parts);
                    if ($position === 0) {
                        array_unshift($parts, $buffer);
                        $buffer = '';
                    }
                    foreach (array_reverse($parts) as $line) {
                        if ($line === '') {
                            continue;
                        }
                        if (strlen($line) > 65536) {
                            throw new RuntimeException('A daily log entry exceeds 64 KiB.');
                        }
                        try {
                            $entry = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                        } catch (\JsonException) {
                            throw new RuntimeException('A daily log contains invalid JSON.');
                        }
                        if (!is_array($entry) || !is_string($entry['level'] ?? null) || !isset($levels[$entry['level']])) {
                            throw new RuntimeException('A daily log contains an invalid entry.');
                        }
                        if ($levels[$entry['level']] >= $levels[$minimum]) {
                            $output[] = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                            if (count($output) === $limit) {
                                break 3;
                            }
                        }
                    }
                    if (strlen($buffer) > 65536) {
                        throw new RuntimeException('A daily log entry exceeds 64 KiB.');
                    }
                }
            } finally {
                fclose($handle);
            }
        }
        echo $output === [] ? "No matching application logs found.\n" : implode("\n", array_reverse($output)) . "\n";
    }

    /** @param list<string> $fields */
    private function generate(string $command, string $name, array $fields = []): void
    {
        Filesystem::portableName($name);
        if (in_array($command, ['make:view', 'make:template', 'make:form'], true)) {
            if (!preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z][a-z0-9_-]*)*$/D', $name)) {
                throw new InvalidArgumentException('Use a view name such as posts.index.');
            }
            $base = '/app/views/' . str_replace('.', '/', $name);
            foreach (['.php', '.breeze.php'] as $extension) {
                if (file_exists($this->app->root . $base . $extension) || is_link($this->app->root . $base . $extension)) {
                    throw new RuntimeException("View already exists: $name");
                }
            }
            $path = $base . ($command === 'make:view' ? '.php' : '.breeze.php');
            $content = $command === 'make:form' ? $this->formTemplate($name, $fields) : ($command === 'make:template' ? <<<'TEMPLATE'
<h1>{{ $title ?? 'New page' }}</h1>

TEMPLATE : <<<'PHP'
<?php
declare(strict_types=1);
?>
<h1><?= e($title ?? 'New page') ?></h1>

PHP);
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
        } elseif ($command === 'make:form') {
            echo "Render with session and a trusted local action URL; optionally pass old and errors arrays.\n";
            echo "No routes or validation rules were created. Validate and authorize submissions in your controller.\n";
        }
    }

    /** @param list<string> $fields */
    private function formTemplate(string $name, array $fields): string
    {
        $types = ['text', 'email', 'password', 'textarea', 'number', 'date', 'time', 'datetime-local', 'url', 'tel', 'checkbox'];
        $seen = [];
        $content = "<form method=\"post\" action=\"{{ \$action }}\">\n    @csrf(\$session)\n";
        foreach ($fields as $definition) {
            if (!preg_match('/^([a-z][a-z0-9_]*):([a-z-]+)$/D', $definition, $match)
                || !in_array($match[2], $types, true)) {
                throw new InvalidArgumentException('Use field:type with a lowercase snake_case field and one of: ' . implode(', ', $types) . '.');
            }
            [, $field, $type] = $match;
            if (isset($seen[$field])) {
                throw new InvalidArgumentException("Duplicate form field: $field");
            }
            $seen[$field] = true;
            $id = 'form-' . str_replace('.', '-', $name) . '-' . $field;
            $label = ucfirst(str_replace('_', ' ', $field));
            $value = "{{ is_scalar(\$old['$field'] ?? null) ? \$old['$field'] : '' }}";
            $content .= "    <div class=\"auth-field\">\n        <label for=\"$id\">$label</label>\n";
            if ($type === 'textarea') {
                $control = "        <textarea id=\"$id\" name=\"$field\" rows=\"5\"";
            } else {
                $control = "        <input id=\"$id\" name=\"$field\" type=\"$type\"";
                $control .= match ($type) {
                    'password' => ' autocomplete="new-password"',
                    'checkbox' => " value=\"1\" @if(in_array(\$old['$field'] ?? null, ['1', 1, true], true)) checked @endif",
                    default => " value=\"$value\"",
                };
            }
            $content .= $control . "\n            @if(!empty(\$errors['$field'])) aria-invalid=\"true\" aria-describedby=\"$id-error\" @endif>";
            $content .= $type === 'textarea' ? "$value</textarea>\n" : "\n";
            $content .= "        @if(!empty(\$errors['$field']))\n"
                . "            <p class=\"field-error\" id=\"$id-error\">{{ implode(' ', \$errors['$field']) }}</p>\n"
                . "        @endif\n    </div>\n";
        }
        return $content . "    <button type=\"submit\">Submit</button>\n</form>\n";
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
