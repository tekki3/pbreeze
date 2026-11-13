<?php
declare(strict_types=1);

namespace PBreeze;

use JsonException;
use RuntimeException;

final class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(public readonly int $status, string $message, public readonly array $headers = [])
    {
        parent::__construct($message);
    }
}

final class Request
{
    /** @var array{id: int, name: string, email: string, token_id: int}|null */
    private ?array $apiIdentity = null;
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly string $ip = 'unknown',
    ) {
    }

    public static function capture(): self
    {
        $uri = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
        if (preg_match('/%(?![0-9a-f]{2})/i', $uri)) {
            throw new HttpException(400, 'Ongeldige URL.');
        }
        $path = rawurldecode($uri);
        if (!str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f\\\\]/', $path)) {
            throw new HttpException(400, 'Ongeldige URL.');
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $serverKey => $header) {
            if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])) {
                $headers[$header] = $_SERVER[$serverKey];
            }
        }
        if (!isset($headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $contentType = strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]);
        $body = $_POST;
        $api = Api::matches($path);
        $raw = null;
        if ($api) {
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
                throw new HttpException(413, 'API-requestinhoud mag maximaal 1 MiB zijn.');
            }
            $raw = file_get_contents('php://input', false, null, 0, 1048577);
            if ($raw === false) {
                throw new RuntimeException('Kan requestinhoud niet lezen.');
            }
            if (strlen($raw) > 1048576) {
                throw new HttpException(413, 'API-requestinhoud mag maximaal 1 MiB zijn.');
            }
            if ($contentType !== 'application/json'
                && ($raw !== '' || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 || $_POST !== [] || $_FILES !== [])) {
                throw new HttpException(415, 'Gebruik Content-Type: application/json.');
            }
            $body = [];
        }
        if ($contentType === 'application/json') {
            try {
                $raw ??= file_get_contents('php://input');
                if ($raw === false) {
                    throw new RuntimeException('Kan requestinhoud niet lezen.');
                }
                $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
                if (!$decoded instanceof \stdClass) {
                    throw new HttpException(400, 'De JSON-inhoud moet een object zijn.');
                }
                $body = get_object_vars($decoded);
            } catch (JsonException) {
                throw new HttpException(400, 'Ongeldige JSON-inhoud.');
            }
        }
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $body, $headers, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function clientIp(): string
    {
        return $this->ip;
    }

    /** @internal @param array{id: int, name: string, email: string, token_id: int} $user */
    public function withApiUser(array $user): self
    {
        $request = clone $this;
        $request->apiIdentity = $user;
        return $request;
    }

    /** @return array{id: int, name: string, email: string, token_id: int} */
    public function apiUser(): array
    {
        return $this->apiIdentity ?? throw new \LogicException('Deze request heeft geen API-identiteit.');
    }
}

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $body = '',
        public readonly int $status = 200,
        public readonly array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Ongeldige HTTP-status.');
        }
        foreach ($headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/D', $name) || preg_match('/[\r\n\x00]/', $value)) {
                throw new \InvalidArgumentException('Ongeldige HTTP-header.');
            }
        }
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP), $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public static function redirect(string $path, int $status = 303): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\x00-\x20\x7f\\\\]/', $path)) {
            throw new \InvalidArgumentException('Redirects moeten lokale absolute paden zijn.');
        }
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new \InvalidArgumentException('Ongeldige redirectstatus.');
        }
        return new self('', $status, ['Location' => $path]);
    }

    public function send(bool $head = false): void
    {
        http_response_code($this->status);
        $headers = $this->headers + [
            'Content-Security-Policy' => "default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cache-Control' => 'no-store',
        ];
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        if (!$head) {
            echo $this->body;
        }
    }
}
