<?php
declare(strict_types=1);

$base = rtrim($argv[1] ?? '', '/');
$fixture = $argv[2] ?? '';
if (!preg_match('~^http://(?:127\.0\.0\.1|localhost):[0-9]+$~D', $base) || !is_file($fixture . '/server-test.json')) {
    throw new RuntimeException('Usage: php tests/web-server-smoke.php http://127.0.0.1:PORT /absolute/disposable/fixture');
}
$credentials = json_decode(file_get_contents($fixture . '/server-test.json'), true, 32, JSON_THROW_ON_ERROR);
$assert = static function (bool $valid, string $message): void {
    if (!$valid) {
        throw new RuntimeException($message);
    }
};
$request = static function (string $path, string $method = 'GET', string $body = '', array $headers = []) use ($base): array {
    $context = stream_context_create(['http' => [
        'method' => $method, 'content' => $body, 'header' => implode("\r\n", $headers),
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15,
    ]]);
    $stream = fopen($base . $path, 'r', false, $context);
    if ($stream === false) {
        throw new RuntimeException('Server request failed.');
    }
    try {
        $body = stream_get_contents($stream);
        $responseHeaders = stream_get_meta_data($stream)['wrapper_data'];
    } finally {
        fclose($stream);
    }
    preg_match('/^HTTP\/\S+ (\d+)/', $responseHeaders[0], $match);
    $headerMap = [];
    foreach (array_slice($responseHeaders, 1) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headerMap[strtolower($name)][] = trim($value);
        }
    }
    return ['status' => (int) $match[1], 'body' => $body, 'headers' => $headerMap];
};
foreach (['/health', '/ready'] as $path) {
    $response = $request($path);
    $assert($response['status'] === 200, $path . ' failed: ' . $response['status']);
    $assert(!isset($response['headers']['set-cookie']), 'Probes started a session.');
    $head = $request($path, 'HEAD');
    $assert($head['status'] === 200 && $head['body'] === '', 'HEAD probe failed.');
}
$response = $request('/__server/route/caf%C3%A9?value=a%2Bb%26c');
$data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
$assert($response['status'] === 200 && $data['value'] === "caf\u{e9}" && $data['query'] === 'a+b&c', 'Original URI or query string was lost.');
$response = $request('/__server/redirect');
$assert($response['status'] === 303 && ($response['headers']['location'][0] ?? '') === '/__server/route/redirected?value=kept', 'Redirect failed.');
$assert($request('/')['status'] === 200, 'Home page failed.');
foreach (['/fixture.css', '/assets/css/welcome.css', '/assets/js/api-docs.js', '/assets/swagger-ui/css/swagger-ui.css', '/assets/swagger-ui/js/swagger-ui-bundle.js'] as $path) {
    $response = $request($path);
    $assert($response['status'] === 200 && $response['body'] !== '', 'Static asset failed: ' . $path);
    $assert(!isset($response['headers']['set-cookie']), 'Static file started a PHP session.');
    $type = $response['headers']['content-type'][0] ?? '';
    $assert(str_ends_with($path, '.css') ? str_contains($type, 'text/css') : str_contains($type, 'javascript'), 'Incorrect static MIME type.');
}
foreach (['/.env', '/.private.css', '/leak.env', '/leak.sqlite', '/leak.log', '/leak.ini', '/leak.sql', '/leak.bak',
    '/leak.txt', '/web.config', '/router.php', '/other.php', '/other.php/extra', '/empty-directory/', '/assets/', '/does-not-exist'] as $path) {
    $response = $request($path);
    $assert(in_array($response['status'], [403, 404], true), 'Private/nonexistent path was exposed: ' . $path . ' status ' . $response['status']);
    $assert(!str_contains($response['body'], 'PBREEZE_PRIVATE_SENTINEL') && !str_contains($response['body'], 'PBREEZE_SCRIPT_EXECUTED'), 'Private data or an extra PHP script was exposed.');
}
$response = $request('/api/me');
$assert($response['status'] === 401 && str_contains($response['headers']['content-type'][0] ?? '', 'application/json'), 'API 401 was replaced by the server.');
$response = $request('/api/me', headers: ['Authorization: Bearer ' . $credentials['token']]);
$data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
$assert($response['status'] === 200 && ($data['data']['email'] ?? '') === 'server@example.test', 'Bearer Authorization was not forwarded.');
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    $response = $request('/api/server-test', $method, '{"value":"kept"}', ['Content-Type: application/json']);
    $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
    $assert($response['status'] === 200 && $data === ['method' => $method, 'value' => 'kept'], 'API request method/body was changed.');
}
$response = $request('/api/server-test', 'POST', '{"value":"' . str_repeat('a', 1048564) . '"}', ['Content-Type: application/json']);
$assert($response['status'] === 200, 'The exact 1 MiB JSON limit was rejected.');
$response = $request('/api/server-test', 'POST', '{"value":"' . str_repeat('a', 1048565) . '"}', ['Content-Type: application/json']);
$assert($response['status'] === 413 && str_contains($response['headers']['content-type'][0] ?? '', 'application/json'), 'Framework JSON 413 was replaced or bypassed.');
$response = $request('/api/server-test', 'GET');
$assert($response['status'] === 405 && isset($response['headers']['allow']), 'HTTP 405 or Allow header was replaced.');
$response = $request('/__server/token');
$csrf = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR)['token'];
$cookie = explode(';', $response['headers']['set-cookie'][0] ?? '', 2)[0];
$assert($cookie !== '', 'Session cookie was not set.');
$boundary = 'pbreeze-server-boundary';
foreach ([14, 2097152, 2097153] as $size) {
    $content = substr(str_repeat("Native server upload.\n", (int) ceil($size / 22)), 0, $size);
    $upload = "--$boundary\r\nContent-Disposition: form-data; name=\"_token\"\r\n\r\n$csrf\r\n"
        . "--$boundary\r\nContent-Disposition: form-data; name=\"document\"; filename=\"sample.txt\"\r\nContent-Type: text/plain\r\n\r\n$content\r\n--$boundary--\r\n";
    $response = $request('/__server/upload', 'POST', $upload, ['Cookie: ' . $cookie, 'Content-Type: multipart/form-data; boundary=' . $boundary]);
    $assert($response['status'] === ($size > 2097152 ? 413 : 201), 'Multipart upload/CSRF failed at size ' . $size . ': ' . $response['status']);
    if ($size <= 2097152) {
        $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        $assert(($data['size'] ?? 0) === $size, 'The uploaded file size was changed.');
    }
}
$response = $request('/__server/upload', 'POST');
$assert($response['status'] === 419, 'CSRF protection was bypassed.');
$response = $request('/__server/failure');
$assert($response['status'] === 500 && !str_contains($response['body'], 'Private web server fixture'), 'Application error was exposed or replaced.');
$assert(($response['headers']['x-content-type-options'][0] ?? '') === 'nosniff', 'Application response headers were removed.');
echo "PASS $base: routes, UTF-8/query strings, assets, private paths, PHP isolation, bearer auth, methods, JSON limits, sessions/CSRF, uploads, probes, redirects, and errors.\n";
