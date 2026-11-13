<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

final class ApiValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Check your input.');
    }
}

final class Api
{
    public readonly ApiTokens $tokens;
    private readonly Router $router;
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $paths = [];
    /** @var array<string, true> */
    private array $operationIds = [];

    public function __construct(
        private readonly Database $db,
        private readonly View $views,
        private readonly string $name,
        private readonly bool $docsEnabled,
        private readonly ?Logger $logger = null,
    ) {
        $this->tokens = new ApiTokens($db);
        $this->router = new Router();
        $this->add('GET', '/api/ping', static fn (Request $request): Response => Response::json(['data' => ['status' => 'ok']]), [
            'summary' => 'Check API availability', 'operationId' => 'apiPing',
            'responses' => ['200' => ['description' => 'API is available']],
        ], false);
        $this->add('GET', '/api/me', static fn (Request $request): Response => Response::json(['data' => $request->apiUser()]), [
            'summary' => 'Get the authenticated user', 'operationId' => 'apiMe',
            'responses' => ['200' => ['description' => 'Verified user and current token ID']],
        ]);
        $this->add('DELETE', '/api/token', function (Request $request): Response {
            $this->tokens->revoke($request->apiUser()['token_id']);
            return new Response('', 204, ['Content-Type' => 'application/json; charset=UTF-8']);
        }, [
            'summary' => 'Revoke the current bearer token', 'operationId' => 'apiRevokeToken',
            'responses' => ['204' => ['description' => 'Token revoked']],
        ]);
    }

    public static function matches(string $path): bool
    {
        return $path === '/api' || str_starts_with($path, '/api/');
    }

    /** @param array<string, string> $rules
     *  @return array<string, mixed>
     */
    public static function validate(Request $request, array $rules): array
    {
        $data = $request->all();
        $errors = Validator::errors($data, $rules);
        if ($errors !== []) {
            throw new ApiValidationException($errors);
        }
        return array_intersect_key($data, $rules);
    }

    /** @param array<string, mixed> $operation */
    public function add(string $method, string $path, callable $handler, array $operation, bool $authenticated = true): void
    {
        $method = strtoupper($method);
        $path = rtrim($path, '/');
        if (!str_starts_with($path, '/api/') || preg_match('~^/api/(?:[a-zA-Z0-9_-]+|\{[a-zA-Z][a-zA-Z0-9_]*\})(?:/(?:[a-zA-Z0-9_-]+|\{[a-zA-Z][a-zA-Z0-9_]*\}))*$~D', $path) !== 1
            || in_array($path, ['/api/docs', '/api/openapi.json'], true)
            || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)) {
            throw new InvalidArgumentException('Use an API path under /api/ and a valid HTTP method.');
        }
        if (!is_string($operation['summary'] ?? null) || trim($operation['summary']) === ''
            || !is_string($operation['operationId'] ?? null) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $operation['operationId'])
            || !is_array($operation['responses'] ?? null) || $operation['responses'] === []) {
            throw new InvalidArgumentException('An API operation requires summary, a unique operationId, and responses.');
        }
        if (isset($this->operationIds[$operation['operationId']])) {
            throw new LogicException('Duplicate OpenAPI operationId.');
        }
        preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', $path, $parameters);
        $operation['parameters'] ??= [];
        if (!is_array($operation['parameters'])) {
            throw new InvalidArgumentException('OpenAPI parameters must be a list.');
        }
        foreach ($parameters[1] as $parameter) {
            $found = false;
            foreach ($operation['parameters'] as $definition) {
                if (($definition['in'] ?? null) === 'path' && ($definition['name'] ?? null) === $parameter) {
                    if (($definition['required'] ?? false) !== true) {
                        throw new InvalidArgumentException('OpenAPI path parameters must be required.');
                    }
                    $found = true;
                }
            }
            if (!$found) {
                $operation['parameters'][] = ['name' => $parameter, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
            }
        }
        $operation['security'] = $authenticated ? [['bearerAuth' => []]] : [];
        $error = ['$ref' => '#/components/responses/Error'];
        $operation['responses'] += ['400' => $error, '413' => $error, '415' => $error, '422' => $error, '429' => $error, '500' => $error];
        if ($authenticated) {
            $operation['responses'] += ['401' => $error];
        }
        $this->router->add($method, $path, function (Request $request, string ...$values) use ($handler, $authenticated): Response {
            if ($authenticated) {
                $request = $request->withApiUser($this->tokens->authenticate($request->header('authorization')));
            }
            $response = $handler($request, ...$values);
            if (!$response instanceof Response || !str_starts_with($response->headers['Content-Type'] ?? '', 'application/json')) {
                throw new LogicException('API actions must return a JSON Response.');
            }
            return $response;
        });
        $this->paths[$path][strtolower($method)] = $operation;
        $this->operationIds[$operation['operationId']] = true;
    }

    public function dispatch(Request $request): Response
    {
        try {
            $path = rtrim($request->path, '/');
            if (in_array($path, ['/api/docs', '/api/openapi.json'], true)) {
                if (!$this->docsEnabled) {
                    throw new HttpException(404, 'API documentation is disabled.');
                }
                if (!in_array($request->method, ['GET', 'HEAD'], true)) {
                    throw new HttpException(405, 'HTTP method not allowed.', ['Allow' => 'GET, HEAD']);
                }
                return $path === '/api/openapi.json' ? Response::json($this->openApi()) : $this->documentation();
            }
            $this->limit($request->clientIp());
            return $this->router->dispatch($request);
        } catch (Throwable $error) {
            return self::errorResponse($error, $this->logger);
        }
    }

    public static function errorResponse(Throwable $error, ?Logger $logger = null): Response
    {
        $status = match (true) {
            $error instanceof HttpException => $error->status,
            $error instanceof AuthException => $error->status,
            $error instanceof ApiValidationException => 422,
            $error instanceof RecordNotFoundException => 404,
            default => 500,
        };
        if ($status >= 500) {
            Logger::report($error, $logger);
        }
        $message = match (true) {
            $status === 500 => 'Something went wrong. Please try again later.',
            $error instanceof AuthException, $error instanceof ApiValidationException => 'Check your input.',
            default => $error->getMessage(),
        };
        $body = ['error' => ['status' => $status, 'message' => $message]];
        if ($error instanceof AuthException || $error instanceof ApiValidationException) {
            $body['error']['details'] = $error->errors;
        }
        $response = Response::json($body, $status);
        $headers = $error instanceof HttpException ? $error->headers : [];
        if ($error instanceof AuthException && $error->retryAfter > 0) {
            $headers['Retry-After'] = (string) $error->retryAfter;
        }
        return new Response($response->body, $status, $response->headers + $headers);
    }

    /** @return array<string, mixed> */
    public function openApi(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => $this->name . ' API', 'version' => '1.0.0', 'description' => 'Native PBreeze JSON API. Use a CLI-issued bearer token for protected endpoints.'],
            'servers' => [['url' => '/']],
            'paths' => $this->paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Token issued by php breeze api:token. No browser session authentication.']],
                'schemas' => [
                    'Error' => ['type' => 'object', 'required' => ['error'], 'properties' => [
                        'error' => ['type' => 'object', 'required' => ['status', 'message'], 'properties' => [
                            'status' => ['type' => 'integer'], 'message' => ['type' => 'string'],
                            'details' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                        ]],
                    ]],
                ],
                'responses' => ['Error' => [
                    'description' => 'JSON error response',
                    'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
                ]],
            ],
        ];
    }

    /** @return list<array{method: string, path: string}> */
    public function listing(): array
    {
        $routes = $this->router->listing();
        if ($this->docsEnabled) {
            $routes[] = ['method' => 'GET', 'path' => '/api/docs'];
            $routes[] = ['method' => 'GET', 'path' => '/api/openapi.json'];
        }
        return $routes;
    }

    private function limit(string $ip): void
    {
        $expires = (intdiv(time(), 60) + 1) * 60;
        $bucket = hash('sha256', $ip . '|' . $expires);
        $this->db->execute('DELETE FROM api_limits WHERE expires_at <= ?', [time()]);
        $mysql = $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->db->execute($mysql
            ? 'INSERT INTO api_limits (bucket, expires_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE bucket = bucket'
            : 'INSERT INTO api_limits (bucket, expires_at) VALUES (?, ?) ON CONFLICT(bucket) DO NOTHING', [$bucket, $expires]);
        if ($this->db->execute('UPDATE api_limits SET attempts = attempts + 1 WHERE bucket = ? AND attempts < 120', [$bucket])->rowCount() !== 1) {
            throw new HttpException(429, 'Too many API requests. Please try again later.', ['Retry-After' => (string) max(1, $expires - time())]);
        }
    }

    private function documentation(): Response
    {
        return new Response($this->views->render('api.docs', ['name' => $this->name]), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; object-src 'none'",
        ]);
    }
}
