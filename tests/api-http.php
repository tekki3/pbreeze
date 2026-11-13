<?php
declare(strict_types=1);

use PBreeze\ApiTokens;

$apiHttpDb = new PBreeze\Database($root, ['driver' => 'sqlite', 'database' => $temp . '/http.sqlite']);

test('HTTP API uses JSON without session cookies, including capture errors', static function () use ($base): void {
    foreach ([
        ['/api/ping', 'GET', '', [], 200],
        ['/api/me', 'GET', '', [], 401],
        ['/api/unknown', 'GET', '', [], 404],
        ['/api/me', 'POST', '', [], 405],
        ['/api/me', 'POST', '{', ['Content-Type: application/json'], 400],
        ['/api/me', 'POST', '[]', ['Content-Type: application/json'], 400],
        ['/api/me', 'POST', 'name=Ada', ['Content-Type: application/x-www-form-urlencoded'], 415],
        ['/api/me', 'POST', "--fixture\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nAda\r\n--fixture--\r\n", ['Content-Type: multipart/form-data; boundary=fixture'], 415],
        ['/api/me', 'POST', '{"x":"' . str_repeat('a', 1048576) . '"}', ['Content-Type: application/json'], 413],
        ['/api/%ZZ', 'GET', '', [], 400],
    ] as [$path, $method, $body, $headers, $status]) {
        $response = http($base, $path, $method, $body, $headers);
        same($status, $response['status']);
        $responseHeaders = strtolower(implode("\n", $response['headers']));
        check(str_contains($responseHeaders, 'content-type: application/json'));
        check(!str_contains($responseHeaders, 'set-cookie:'));
        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if ($status >= 400) {
            same($status, $data['error']['status']);
        }
    }
    same('', http($base, '/api/ping', 'HEAD')['body']);
    same(419, http($base, '/logout', 'POST')['status']);
});

test('HTTP bearer tokens work without CSRF or cookies; DELETE revokes the token', static function () use ($base, $apiHttpDb): void {
    $apiHttpDb->execute('INSERT INTO users (name, email, password_hash, email_verified_at, created_at) VALUES (?, ?, ?, ?, ?)', ['HTTP API', 'api-http@example.test', 'unused', time(), time()]);
    $id = (int) $apiHttpDb->pdo()->lastInsertId();
    $tokens = new ApiTokens($apiHttpDb);
    $issued = $tokens->create($id, 'http');
    same(401, http($base, '/api/me?token=' . $issued['token'])['status']);
    $headers = ['Authorization: Bearer ' . $issued['token']];
    $response = http($base, '/api/me', 'GET', '', $headers);
    same(200, $response['status']);
    same($id, json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['data']['id']);
    check(!str_contains(strtolower(implode("\n", $response['headers'])), 'set-cookie:'));
    $deleted = http($base, '/api/token', 'DELETE', '', $headers);
    same(204, $deleted['status']);
    same('', $deleted['body']);
    same(401, http($base, '/api/me', 'GET', '', $headers)['status']);
    $issued = $tokens->create($id, 'password-reset');
    $apiHttpDb->execute('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
    same(401, http($base, '/api/me', 'GET', '', ['Authorization: Bearer ' . $issued['token']])['status']);
});

test('HTTP Swagger loads local assets without weakening the CSP of regular web pages', static function () use ($base): void {
    $docs = http($base, '/api/docs');
    same(200, $docs['status']);
    $headers = strtolower(implode("\n", $docs['headers']));
    check(str_contains($headers, "script-src 'self'"));
    check(!str_contains($headers, 'set-cookie:'));
    foreach (['/assets/swagger-ui/swagger-ui-bundle.js', '/assets/swagger-ui/swagger-ui.css', '/assets/api-docs.js', '/assets/api-docs.css'] as $asset) {
        same(200, http($base, $asset)['status']);
    }
    $spec = json_decode(http($base, '/api/openapi.json')['body'], true, 512, JSON_THROW_ON_ERROR);
    same('3.0.3', $spec['openapi']);
    check(isset($spec['paths']['/api/me']['get']['responses']['401']));
    $web = http($base, '/');
    check(str_contains(strtolower(implode("\n", $web['headers'])), "script-src 'none'"));
});

test('Real HTTP password reset revokes previously issued API tokens', static function () use ($base, $apiHttpDb): void {
    $client = new AuthBrowser($base);
    $id = (int) $apiHttpDb->execute('SELECT id FROM users WHERE email = ?', ['api-http@example.test'])->fetchColumn();
    $issued = (new ApiTokens($apiHttpDb))->create($id, 'reset-integration');
    $headers = ['Authorization: Bearer ' . $issued['token']];
    same(200, http($base, '/api/me', 'GET', '', $headers)['status']);
    same(200, $client->request('/forgot-password', 'POST', ['email' => 'api-http@example.test'])['status']);
    $reset = authToken($apiHttpDb, 'api-http@example.test', 'reset');
    $client->request('/reset-password?token=' . $reset);
    same(200, $client->request('/reset-password', 'POST', [
        'token' => $reset, 'password' => 'Reset-API-password-29', 'password_confirmation' => 'Reset-API-password-29',
    ])['status']);
    same(401, http($base, '/api/me', 'GET', '', $headers)['status']);
});

$apiHttpDb = null;
