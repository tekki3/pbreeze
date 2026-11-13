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
  serve [poort]              Start op http://127.0.0.1:8000
  routes                     Toon alle routes
  make:controller Naam       Maak een controller in app/controllers
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
        $mailCommands = ['mail:queue', 'mail:list', 'mail:show', 'mail:send', 'mail:work', 'mail:retry', 'mail:fetch'];
        $allowsName = in_array($command, ['serve', 'make:controller', 'make:model', 'make:view', 'make:template', 'make:migration', 'make:seeder', 'db:seed', ...$mailCommands], true);
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
                foreach ($this->app->router->listing() as $route) {
                    printf("%-7s %s\n", $route['method'], $route['path']);
                }
                break;
            case 'make:controller':
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
            if ($command === 'make:controller') {
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
        } finally {
            fclose($handle);
        }
        echo "Aangemaakt: $path\n";
    }
}
