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
    public readonly Session $session;
    public readonly Database $db;
    public readonly \PBreeze\Mail\Mailer $mail;
    public readonly Auth $auth;
    public readonly Encryption $encryption;
    public readonly BotProtection $botProtection;
    public readonly Api $api;
    public readonly Uploads $uploads;
    public readonly Logger $log;

    /** @param array{name: string, locale?: string, timezone?: string, url?: string, key?: string, api_docs?: bool, upload_max_bytes?: int, log_retention_days?: int, log_level?: string, environment: string, debug: bool, secure_cookie: bool, database: array<string, string>, mail?: array{log?: bool, from?: string, smtp?: array<string, string>, imap?: array<string, string>}} $config */
    public function __construct(public readonly string $root, #[\SensitiveParameter] public readonly array $config)
    {
        $this->router = new Router();
        $this->log = new Logger($root, $config['log_retention_days'] ?? 5, $config['log_level'] ?? 'debug');
        $this->uploads = new Uploads($root, $config['upload_max_bytes'] ?? 2097152);
        $this->format = new Formatter($config['locale'] ?? 'en-US', $config['timezone'] ?? 'UTC');
        $this->strings = new Strings();
        $this->views = new View($root . '/app/views', formatter: $this->format, strings: $this->strings);
        $this->session = new Session();
        $this->botProtection = new BotProtection($this->session);
        $this->encryption = new Encryption($config['key'] ?? '');
        $this->db = new Database($root, $config['database'], $this->encryption);
        $this->mail = new \PBreeze\Mail\Mailer($this->db, $config['mail'] ?? []);
        $this->auth = new Auth($this->db, $this->session, $this->mail, $config['url'] ?? 'http://127.0.0.1:8000', $config['environment'] === 'production');
        $this->api = new Api($this->db, $this->views, $config['name'], $config['api_docs'] ?? $config['environment'] !== 'production', $this->log);
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

    public function run(): void
    {
        $apiRequest = Api::matches(rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]));
        try {
            $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            if ($this->config['environment'] === 'production' && !$this->config['secure_cookie'] && !$https) {
                throw new \RuntimeException('Production requires HTTPS or SESSION_SECURE_COOKIE=true behind an HTTPS proxy.');
            }
            if (!$apiRequest) {
                $this->session->start($https || $this->config['secure_cookie']);
            }
            $this->routes();
            $response = $this->handle(Request::capture());
        } catch (Throwable $error) {
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
