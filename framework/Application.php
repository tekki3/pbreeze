<?php
declare(strict_types=1);

namespace PBreeze;

use Throwable;

final class Application
{
    public readonly Router $router;
    public readonly View $views;
    public readonly Formatter $format;
    public readonly Strings $strings;
    public readonly Ai $ai;
    public readonly Cache $cache;
    public readonly Session $session;
    public readonly Database $db;
    public readonly \PBreeze\Mail\Mailer $mail;
    public readonly Auth $auth;
    public readonly Encryption $encryption;
    public readonly BotProtection $botProtection;
    public readonly Api $api;
    public readonly Uploads $uploads;
    public readonly Logger $log;
    public readonly string $storagePath;
    public readonly \PBreeze\Queue\Queue $queue;
    public readonly \PBreeze\Scheduler\Schedule $schedule;
    public readonly \PBreeze\Scheduler\Scheduler $scheduler;
    public readonly \PBreeze\Worker\Worker $worker;
    private bool $backgroundLoaded = false;

    /** @param array{name: string, storage_path?: string, locale?: string, timezone?: string, url?: string, key?: string, api_docs?: bool, upload_max_bytes?: int, log_retention_days?: int, log_level?: string, environment: string, debug: bool, secure_cookie: bool, session?: array{driver?: 'native'|'database', lifetime?: int, lock_timeout?: int}, database: array<string, string>, mail?: array{log?: bool, from?: string, smtp?: array<string, string>, imap?: array<string, string>}, cache?: array<string, mixed>, scheduler?: array<string, mixed>, queue?: array<string, mixed>, ai?: array{provider?: string, timeout?: int, max_tokens?: int, ca_file?: string, openai?: array{key?: string, model?: string}, anthropic?: array{key?: string, model?: string}}} $config */
    public function __construct(public readonly string $root, #[\SensitiveParameter] public readonly array $config)
    {
        $this->storagePath = Filesystem::databasePath($root, ($config['storage_path'] ?? '') !== '' ? $config['storage_path'] : 'storage');
        $this->router = new Router();
        $this->log = new Logger($root, $config['log_retention_days'] ?? 5, $config['log_level'] ?? 'debug', $this->storagePath);
        $this->uploads = new Uploads($root, $config['upload_max_bytes'] ?? 2097152, $this->storagePath);
        $this->format = new Formatter($config['locale'] ?? 'en-US', $config['timezone'] ?? 'UTC');
        $this->strings = new Strings();
        $this->ai = new Ai($config['ai'] ?? []);
        $this->cache = new Cache($config['cache'] ?? []);
        $this->views = new View($root . '/app/views', $this->storagePath . '/views', formatter: $this->format, strings: $this->strings,
            storageRoot: $root, storagePath: $this->storagePath);
        $session = $config['session'] ?? [];
        if (!in_array($session['driver'] ?? 'native', ['native', 'database'], true)) {
            throw new \InvalidArgumentException('SESSION_DRIVER must be native or database.');
        }
        $this->session = new Session(($session['driver'] ?? 'native') === 'database'
            ? new DatabaseSessionHandler(new Database($root, $config['database'], production: $config['environment'] === 'production'),
                $root, $this->storagePath, $session['lifetime'] ?? 7200, $session['lock_timeout'] ?? 10)
            : null);
        $this->botProtection = new BotProtection($this->session);
        $this->encryption = new Encryption($config['key'] ?? '');
        $this->db = new Database($root, $config['database'], $this->encryption, $config['environment'] === 'production');
        $this->mail = new \PBreeze\Mail\Mailer($this->db, $config['mail'] ?? []);
        $this->auth = new Auth($this->db, $this->session, $this->mail, $config['url'] ?? 'http://127.0.0.1:8000', $config['environment'] === 'production');
        $this->api = new Api($this->db, $this->views, $config['name'], $config['api_docs'] ?? $config['environment'] !== 'production', $this->log);
        $registry = new \PBreeze\Queue\JobRegistry(function (\PBreeze\Queue\JobRegistry $registry): void {
            $app = $this;
            $path = $this->root . '/routes/jobs.php';
            $jobs = is_file($path) ? require $path : [];
            if (!is_array($jobs)) {
                throw new \RuntimeException('routes/jobs.php must return a job registry array.');
            }
            foreach ($jobs as $type => $job) {
                if (!is_string($type) || $type === 'scheduled-task' || !is_array($job)
                    || !is_callable($job['validate'] ?? null) || !is_callable($job['handle'] ?? null)) {
                    throw new \RuntimeException('Each job type requires explicit validate and handle callbacks.');
                }
                $registry->register($type, $job['validate'], $job['handle']);
            }
        });
        $registry->register('scheduled-task', static function (array $payload): void {
            if (array_keys($payload) !== ['task_key', 'definition_hash']
                || !is_string($payload['task_key']) || !is_string($payload['definition_hash'])
                || preg_match('/^[a-f0-9]{64}$/D', $payload['definition_hash']) !== 1) {
                throw new \InvalidArgumentException('Invalid scheduled task payload.');
            }
        }, function (array $payload, \PBreeze\Queue\ExecutionContext $context): void {
            $this->background();
            $this->scheduler->executeTask($payload['task_key'], $payload['definition_hash'], $context);
        });
        $queueConfig = $config['queue'] ?? [];
        $schedulerConfig = $config['scheduler'] ?? [];
        $this->queue = new \PBreeze\Queue\Queue($this->db, $this->log, $registry, $queueConfig);
        $this->schedule = new \PBreeze\Scheduler\Schedule($schedulerConfig['timezone'] ?? 'UTC');
        $this->scheduler = new \PBreeze\Scheduler\Scheduler($this->db, $this->queue, $this->schedule, $schedulerConfig, app: $this);
        $this->worker = new \PBreeze\Worker\Worker($this, $this->queue, $queueConfig);
    }

    public function background(): void
    {
        if ($this->backgroundLoaded) {
            return;
        }
        $path = $this->root . '/routes/schedule.php';
        if (is_file($path)) {
            $register = require $path;
            if (!is_callable($register)) {
                throw new \RuntimeException('routes/schedule.php must return a schedule registration callback.');
            }
            $register($this->schedule, $this);
        }
        $this->backgroundLoaded = true;
    }

    public function routes(): void
    {
        $app = $this;
        $router = $this->router;
        require $this->root . '/routes/web.php';
        $api = $this->api;
        if (is_file($this->root . '/routes/api.php')) {
            require $this->root . '/routes/api.php';
        }
    }

    public function handle(Request $request): Response
    {
        if (Api::matches($request->path)) {
            return $this->api->dispatch($request);
        }
        $this->session->verifyCsrf($request);
        return $this->router->dispatch($request);
    }

    public function readiness(): Response
    {
        try {
            (new Diagnostics($this))->ready();
            return Response::json(['status' => 'ready']);
        } catch (Throwable $error) {
            Logger::report($error, $this->log);
            return Response::json(['status' => 'not_ready'], 503);
        }
    }

    public function run(): void
    {
        $apiRequest = Api::matches(rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]));
        try {
            $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            if ($this->config['environment'] === 'production' && !$this->config['secure_cookie'] && !$https) {
                throw new \RuntimeException('Production requires HTTPS or SESSION_SECURE_COOKIE=true behind an HTTPS proxy.');
            }
            $probe = in_array(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0], ['/health', '/ready'], true)
                && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true);
            if (!$apiRequest && !$probe) {
                $this->session->start($https || $this->config['secure_cookie']);
            }
            $this->routes();
            $response = $this->handle(Request::capture());
        } catch (Throwable $error) {
            $response = $apiRequest ? Api::errorResponse($error, $this->log)
                : self::errorResponse($error, $this->config['debug'] && $this->config['environment'] === 'local', $this->log);
        }
        try {
            $this->session->close();
        } catch (Throwable $error) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            $response = $apiRequest ? Api::errorResponse($error, $this->log)
                : self::errorResponse($error, $this->config['debug'] && $this->config['environment'] === 'local', $this->log);
        }
        $response->send(($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
    }

    public static function errorResponse(Throwable $error, bool $debug = false, ?Logger $logger = null): Response
    {
        if ($error instanceof HttpException) {
            if ($error->status >= 500) {
                Logger::report($error, $logger);
            }
            return new Response($error->getMessage(), $error->status, $error->headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        Logger::report($error, $logger);
        return new Response($debug ? (string) $error : 'Something went wrong. Please try again later.', 500, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
