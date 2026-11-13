<?php
declare(strict_types=1);

namespace PBreeze;

use Throwable;

final class Application
{
    public readonly Router $router;
    public readonly View $views;
    public readonly Session $session;
    public readonly Database $db;
    public readonly \PBreeze\Mail\Mailer $mail;
    public readonly Auth $auth;
    public readonly Encryption $encryption;
    public readonly BotProtection $botProtection;

    /** @param array{name: string, url?: string, key?: string, environment: string, debug: bool, secure_cookie: bool, database: array<string, string>, mail?: array{log?: bool, from?: string, smtp?: array<string, string>, imap?: array<string, string>}} $config */
    public function __construct(public readonly string $root, #[\SensitiveParameter] public readonly array $config)
    {
        $this->router = new Router();
        $this->views = new View($root . '/app/views');
        $this->session = new Session();
        $this->botProtection = new BotProtection($this->session);
        $this->encryption = new Encryption($config['key'] ?? '');
        $this->db = new Database($root, $config['database'], $this->encryption);
        $this->mail = new \PBreeze\Mail\Mailer($this->db, $config['mail'] ?? []);
        $this->auth = new Auth($this->db, $this->session, $this->mail, $config['url'] ?? 'http://127.0.0.1:8000', $config['environment'] === 'production');
    }

    public function routes(): void
    {
        $app = $this;
        $router = $this->router;
        require $this->root . '/routes/web.php';
    }

    public function handle(Request $request): Response
    {
        $this->session->verifyCsrf($request);
        return $this->router->dispatch($request);
    }

    public function run(): void
    {
        try {
            $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            if ($this->config['environment'] === 'production' && !$this->config['secure_cookie'] && !$https) {
                throw new \RuntimeException('Productie vereist HTTPS of SESSION_SECURE_COOKIE=true achter een HTTPS-proxy.');
            }
            $this->session->start($https || $this->config['secure_cookie']);
            $this->routes();
            $response = $this->handle(Request::capture());
        } catch (Throwable $error) {
            $response = self::errorResponse($error, $this->config['debug'] && $this->config['environment'] === 'local');
        }
        $response->send(($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
    }

    public static function errorResponse(Throwable $error, bool $debug = false): Response
    {
        if ($error instanceof HttpException) {
            return new Response($error->getMessage(), $error->status, $error->headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        error_log((string) $error);
        return new Response($debug ? (string) $error : 'Er ging iets mis. Probeer het later opnieuw.', 500, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
