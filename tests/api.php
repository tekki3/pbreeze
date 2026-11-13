<?php
declare(strict_types=1);

use PBreeze\Api;
use PBreeze\ApiValidationException;
use PBreeze\Application;
use PBreeze\HttpException;
use PBreeze\Migrator;
use PBreeze\Request;
use PBreeze\Response;

function apiApplication(): Application
{
    global $root, $config;
    $application = new Application($root, $config);
    (new Migrator($application->db, $root))->migrate();
    $application->db->execute(
        'INSERT INTO users (name, email, password_hash, email_verified_at, created_at) VALUES (?, ?, ?, ?, ?)',
        ['API Tester', 'api@example.test', 'not-a-usable-password-hash', time(), time()],
    );
    return $application;
}

test('API tokens require verified users and store only hashes', static function (): void {
    $application = apiApplication();
    $tokens = $application->api->tokens;
    raises(static fn () => $tokens->create(999), InvalidArgumentException::class);
    foreach ([[0, 'cli', 30], [1, '', 30], [1, str_repeat('x', 81), 30], [1, "bad\nname", 30], [1, "\xFF", 30], [1, 'cli', 0], [1, 'cli', 366]] as [$id, $name, $days]) {
        raises(static fn () => $tokens->create($id, $name, $days), InvalidArgumentException::class);
    }
    $application->db->execute('UPDATE users SET email_verified_at = NULL WHERE id = 1');
    raises(static fn () => $tokens->create(1), InvalidArgumentException::class);
    $application->db->execute('UPDATE users SET email_verified_at = ? WHERE id = 1', [time()]);
    $before = time();
    $issued = $tokens->create(1, 'integration', 1);
    check(preg_match('/^pbz_[a-f0-9]{64}$/D', $issued['token']) === 1);
    check($issued['expires_at'] >= $before + 86400 && $issued['expires_at'] <= time() + 86400);
    $stored = $application->db->execute('SELECT * FROM api_tokens')->fetch();
    same(hash('sha256', $issued['token']), $stored['token_hash']);
    check(!str_contains(json_encode($stored, JSON_THROW_ON_ERROR), $issued['token']));
    $user = $tokens->authenticate('Bearer ' . $issued['token']);
    same(1, $user['id']);
    same($issued['id'], $user['token_id']);
    same(['id', 'name', 'email', 'token_id'], array_keys($user));
    check(!str_contains(json_encode($tokens->listing(1), JSON_THROW_ON_ERROR), 'token_hash'));
    check($tokens->create(1)['token'] !== $issued['token']);
});

test('Bearer authentication rejects missing, malformed, and expired tokens', static function (): void {
    $application = apiApplication();
    $tokens = $application->api->tokens;
    $issued = $tokens->create(1);
    foreach ([null, '', $issued['token'], 'Basic ' . $issued['token'], 'Bearer ' . $issued['token'] . ' ', 'Bearer ' . str_repeat('a', 64)] as $authorization) {
        raises(static fn () => $tokens->authenticate($authorization), HttpException::class, 401);
    }
    same(1, $tokens->authenticate('bearer ' . $issued['token'])['id']);
    $application->db->execute('UPDATE api_tokens SET expires_at = ? WHERE id = ?', [time(), $issued['id']]);
    raises(static fn () => $tokens->authenticate('Bearer ' . $issued['token']), HttpException::class, 401);
});

test('Tokens are invalidated by revocation, password-reset version changes, and lost verification', static function (): void {
    $application = apiApplication();
    $tokens = $application->api->tokens;
    $issued = $tokens->create(1);
    $application->db->execute('UPDATE users SET session_version = session_version + 1 WHERE id = 1');
    raises(static fn () => $tokens->authenticate('Bearer ' . $issued['token']), HttpException::class, 401);
    $issued = $tokens->create(1);
    $application->db->execute('UPDATE users SET email_verified_at = NULL WHERE id = 1');
    raises(static fn () => $tokens->authenticate('Bearer ' . $issued['token']), HttpException::class, 401);
    $tokens->revoke($issued['id']);
    raises(static fn () => $tokens->authenticate('Bearer ' . $issued['token']), HttpException::class, 401);
    raises(static fn () => $tokens->revoke($issued['id']), PBreeze\RecordNotFoundException::class);
});

test('API is stateless, ignores query tokens, and returns JSON 401/404/405 with headers', static function (): void {
    $application = apiApplication();
    $issued = $application->api->tokens->create(1);
    same(200, $application->handle(new Request('GET', '/api/ping'))->status);
    $response = $application->handle(new Request('GET', '/api/me', ['token' => $issued['token']], [], ['cookie' => 'pbreeze_session=irrelevant']));
    same(401, $response->status);
    same('Bearer realm="api"', $response->headers['WWW-Authenticate']);
    same(401, json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['error']['status']);
    $headers = ['authorization' => 'Bearer ' . $issued['token']];
    same(1, json_decode($application->handle(new Request('GET', '/api/me', [], [], $headers))->body, true, 512, JSON_THROW_ON_ERROR)['data']['id']);
    same(404, $application->handle(new Request('GET', '/api/missing'))->status);
    $response = $application->handle(new Request('POST', '/api/me'));
    same(405, $response->status);
    same('GET, HEAD', $response->headers['Allow']);
    same(204, $application->handle(new Request('DELETE', '/api/token', [], [], $headers))->status);
    same(401, $application->handle(new Request('GET', '/api/me', [], [], $headers))->status);
});

test('API validation returns 422 details and only validated fields', static function (): void {
    same(['name' => 'Ada'], Api::validate(new Request('POST', '/api/test', [], ['name' => 'Ada', 'admin' => true]), ['name' => 'required|string|min:2|max:80']));
    try {
        Api::validate(new Request('POST', '/api/test', [], ['name' => ['bad']]), ['name' => 'required|string']);
        throw new RuntimeException('Expected validation error was not thrown.');
    } catch (ApiValidationException $error) {
        $response = Api::errorResponse($error);
        same(422, $response->status);
        check(isset(json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['error']['details']['name']));
    }
});

test('API hides unexpected errors, logs the cause, and rejects non-JSON actions', static function () use ($temp): void {
    $application = apiApplication();
    $log = $temp . '/api-errors.log';
    $previous = ini_set('error_log', $log);
    try {
        $operation = ['summary' => 'Broken action', 'operationId' => 'brokenAction', 'responses' => ['200' => ['description' => 'Example']]];
        $application->api->add('GET', '/api/broken', static function (): never {
            throw new RuntimeException('private-diagnostic-example');
        }, $operation, false);
        $response = $application->handle(new Request('GET', '/api/broken'));
        same(500, $response->status);
        check(!str_contains($response->body, 'private-diagnostic-example'));
        check(str_contains(file_get_contents($log), 'private-diagnostic-example'));
        $operation['operationId'] = 'wrongResponseType';
        $application->api->add('GET', '/api/wrong-response', static fn (): Response => new Response('<h1>wrong</h1>'), $operation, false);
        same(500, $application->handle(new Request('GET', '/api/wrong-response'))->status);
        same(404, Api::errorResponse(new PBreeze\RecordNotFoundException('Record not found.'))->status);
    } finally {
        ini_set('error_log', $previous);
    }
});

test('OpenAPI matches runtime routes, bearer policy, and path parameters', static function (): void {
    $application = apiApplication();
    $api = $application->api;
    $operation = ['summary' => 'Test endpoint', 'operationId' => 'testEntry', 'responses' => ['200' => ['description' => 'Entry']]];
    $api->add('GET', '/api/entries/{id}', static fn (Request $request, string $id): Response => Response::json(['data' => ['id' => $id]]), $operation);
    $spec = $api->openApi();
    same('3.0.3', $spec['openapi']);
    same([], $spec['paths']['/api/ping']['get']['security']);
    same([['bearerAuth' => []]], $spec['paths']['/api/entries/{id}']['get']['security']);
    same(['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']], $spec['paths']['/api/entries/{id}']['get']['parameters'][0]);
    same('http', $spec['components']['securitySchemes']['bearerAuth']['type']);
    foreach ($api->listing() as $route) {
        if (!in_array($route['path'], ['/api/docs', '/api/openapi.json'], true)) {
            check(isset($spec['paths'][$route['path']][strtolower($route['method'])]));
        }
    }
    raises(static fn () => $api->add('GET', '/api/other', static fn (): Response => Response::json([]), $operation), LogicException::class);
    $operation['operationId'] = 'anotherEntry';
    raises(static fn () => $api->add('GET', '/web', static fn (): Response => Response::json([]), $operation), InvalidArgumentException::class);
    raises(static fn () => $api->add('GET', '/api/docs', static fn (): Response => Response::json([]), $operation), InvalidArgumentException::class);
    raises(static fn () => $api->add('GET', '/api/missing-summary', static fn (): Response => Response::json([]), []), InvalidArgumentException::class);
});

test('API rate limit allows exactly 120 requests per IP per minute', static function (): void {
    $application = apiApplication();
    $expires = (intdiv(time(), 60) + 1) * 60;
    $bucket = hash('sha256', 'rate-test|' . $expires);
    $application->db->execute('INSERT INTO api_limits (bucket, attempts, expires_at) VALUES (?, ?, ?)', [$bucket, 119, $expires]);
    same(200, $application->handle(new Request('GET', '/api/ping', [], [], [], 'rate-test'))->status);
    $response = $application->handle(new Request('GET', '/api/ping', [], [], [], 'rate-test'));
    same(429, $response->status);
    check((int) $response->headers['Retry-After'] >= 1 && (int) $response->headers['Retry-After'] <= 60);
    same(200, $application->handle(new Request('GET', '/api/ping', [], [], [], 'another-client'))->status);
    same(200, $application->handle(new Request('GET', '/api/openapi.json', [], [], [], 'rate-test'))->status);
});

test('Swagger has local assets and an isolated CSP and is disabled by default in production', static function () use ($root, $config): void {
    $application = new Application($root, $config);
    $docs = $application->handle(new Request('GET', '/api/docs'));
    same(200, $docs->status);
    check(str_contains($docs->body, 'swagger-ui-bundle.js'));
    check(!str_contains($docs->body, 'https://'));
    check(str_contains($docs->headers['Content-Security-Policy'], "script-src 'self'"));
    check(!str_contains($docs->headers['Content-Security-Policy'], "'unsafe-eval'"));
    $production = new Application($root, array_replace($config, ['environment' => 'production']));
    same(404, $production->handle(new Request('GET', '/api/docs'))->status);
    same(404, $production->handle(new Request('GET', '/api/openapi.json'))->status);
    $production = new Application($root, array_replace($config, ['environment' => 'production', 'api_docs' => true]));
    same(200, $production->handle(new Request('GET', '/api/docs'))->status);
    same(405, $application->handle(new Request('POST', '/api/docs'))->status);
});

test('CLI creates a working API controller, explicit routes, and OpenAPI together', static function () use ($root, $temp, $config): void {
    $fixture = $temp . '/api scaffold';
    mkdir($fixture . '/routes', 0700, true);
    file_put_contents($fixture . '/routes/web.php', '<?php');
    copy($root . '/routes/api.php', $fixture . '/routes/api.php');
    $application = new Application($fixture, $config);
    $migration = require $root . '/database/migrations/20261113160000_create_auth.php';
    $migration->up($application->db->pdo());
    $migration = require $root . '/database/migrations/20261113170000_create_api_tokens.php';
    $migration->up($application->db->pdo());
    $application->db->execute('INSERT INTO users (name, email, password_hash, email_verified_at, created_at) VALUES (?, ?, ?, ?, ?)', ['Scaffold', 'scaffold@example.test', 'unused', time(), time()]);
    $output = console($application, ['make:api', 'Widgets']);
    check(str_contains($output, '/api/widgets'));
    $controller = $fixture . '/app/controllers/api/WidgetsApiController.php';
    $module = $fixture . '/routes/api/widgets.php';
    token_get_all(file_get_contents($controller), TOKEN_PARSE);
    token_get_all(file_get_contents($module), TOKEN_PARSE);
    $registry = file_get_contents($fixture . '/routes/api.php');
    check(str_contains($registry, "require \$app->root . '/routes/api/widgets.php';"));
    $application->routes();
    $issued = $application->api->tokens->create(1);
    foreach ([['GET', '/api/widgets'], ['POST', '/api/widgets'], ['GET', '/api/widgets/2'], ['PUT', '/api/widgets/2'], ['DELETE', '/api/widgets/2']] as [$method, $path]) {
        same(401, $application->handle(new Request($method, $path))->status);
        same(501, $application->handle(new Request($method, $path, [], [], ['authorization' => 'Bearer ' . $issued['token']]))->status);
    }
    $fresh = new Application($fixture, $config);
    $spec = json_decode(console($fresh, ['api:openapi']), true, 512, JSON_THROW_ON_ERROR);
    same(['get', 'post'], array_keys($spec['paths']['/api/widgets']));
    check(isset($spec['paths']['/api/widgets/{id}']['put']['parameters'][0]));
    raises(static fn () => console($application, ['make:api', 'Widgets']), RuntimeException::class);
    raises(static fn () => console($application, ['make:api', 'WIDGETS']), RuntimeException::class);
    check(!file_exists($fixture . '/app/controllers/api/WIDGETSApiController.php') || PHP_OS_FAMILY === 'Windows');
    same($registry, file_get_contents($fixture . '/routes/api.php'));
    foreach (['../Outside', 'CON', 'Class', 'Ping', 'Me', 'Token', 'Docs'] as $name) {
        raises(static fn () => console($application, ['make:api', $name]), InvalidArgumentException::class);
    }
    $routeOutput = console(new Application($fixture, $config), ['routes']);
    check(str_contains($routeOutput, '/api/widgets/{id}'));
});

test('CLI manages API tokens without showing hashes or existing tokens', static function (): void {
    $application = apiApplication();
    $result = console($application, ['api:token', '1', 'deploy', '2']);
    preg_match('/"token": "(pbz_[a-f0-9]{64})"/', $result, $match);
    check(isset($match[1]));
    $token = $match[1];
    $rows = json_decode(console($application, ['api:tokens', '1']), true, 512, JSON_THROW_ON_ERROR);
    same('deploy', $rows[0]['name']);
    check(!str_contains(json_encode($rows, JSON_THROW_ON_ERROR), $token));
    check(!isset($rows[0]['token_hash']));
    console($application, ['api:revoke', (string) $rows[0]['id']]);
    raises(static fn () => $application->api->tokens->authenticate('Bearer ' . $token), HttpException::class, 401);
    foreach ([['api:token'], ['api:token', '0'], ['api:token', '1', 'name', '366'], ['api:token', '1', 'name', '1', 'extra'], ['api:tokens', '1', 'extra'], ['api:revoke', 'x'], ['api:openapi', 'extra'], ['api:token', '1', '--unknown'], ['api:token', '1', '--force']] as $arguments) {
        raises(static fn () => console($application, $arguments), InvalidArgumentException::class);
    }
});
