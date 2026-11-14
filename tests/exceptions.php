<?php
declare(strict_types=1);

use PBreeze\ExceptionHandler;

test('Exception handler hides all server error details outside explicit local debug mode', static function (): void {
    global $temp;
    $directory = $temp . '/exception-production';
    mkdir($directory, 0700);
    $logger = new \PBreeze\Logger($directory);
    $request = new \PBreeze\Request('POST', '/private', body: ['password' => 'body-secret'],
        headers: ['Authorization' => 'Bearer header-secret'], cookies: ['session' => 'cookie-secret']);
    foreach ([['production', false], ['production', true], ['local', false], ['staging', true]] as [$environment, $debug]) {
        $handler = new ExceptionHandler($environment, $debug, $logger);
        foreach ([new RuntimeException('sql-password-secret'),
            new Error('native-secret'),
            new RuntimeException('outer-secret', 0, new RuntimeException('previous-secret')),
            new \PBreeze\HttpException(503, 'sql-password-secret', ['X-Secret' => 'header-secret', 'Retry-After' => '60'])] as $error) {
            $response = $handler->render($error, $request);
            same($error instanceof \PBreeze\HttpException ? 503 : 500, $response->status);
            same(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']],
                json_decode($response->body, true, 32, JSON_THROW_ON_ERROR));
            same('application/json; charset=UTF-8', $response->headers['Content-Type']);
            check(!isset($response->headers['X-Secret']));
            if ($error instanceof \PBreeze\HttpException) {
                same('60', $response->headers['Retry-After']);
            }
        }
    }
    $logs = file($directory . '/storage/logs/app-' . gmdate('Y-m-d') . '.jsonl');
    same(16, count($logs));
    check(!str_contains(implode('', $logs), 'sql-password-secret'));
    check(!str_contains(implode('', $logs), 'previous-secret'));
});

test('Local debug diagnostics include exception location, trace and limited request context without arguments or payloads', static function (): void {
    global $temp;
    $directory = $temp . '/exception-debug';
    mkdir($directory, 0700);
    $handler = new ExceptionHandler('local', true, new \PBreeze\Logger($directory));
    $request = new \PBreeze\Request('POST', '/api/example', body: ['password' => 'body-secret'],
        headers: ['Authorization' => 'Bearer header-secret'], ip: '127.0.0.1',
        cookies: ['session' => 'cookie-secret']);
    try {
        (static function (string $argument): never {
            throw new RuntimeException('Diagnostic message.');
        })('trace-argument-secret');
    } catch (Throwable $error) {
        $response = $handler->render($error, $request);
        same(500, $response->status);
        same('text/plain; charset=UTF-8', $response->headers['Content-Type']);
        foreach (['Exception: RuntimeException', 'Message: Diagnostic message.', 'File: ' . __FILE__,
            'Line:', 'Stack trace:', '#0', 'Request:', 'Method: POST', 'Path: /api/example', 'IP: 127.0.0.1'] as $text) {
            check(str_contains($response->body, $text), 'Missing diagnostic field: ' . $text);
        }
        foreach (['body-secret', 'header-secret', 'cookie-secret', 'trace-argument-secret'] as $secret) {
            check(!str_contains($response->body, $secret));
        }
        check(str_contains($handler->render($error)->body, 'failure before request capture'));
    }
});

test('Central handler retains expected status, validation details, authentication and required headers', static function (): void {
    $handler = new ExceptionHandler();
    $response = $handler->render(new \PBreeze\HttpException(405, 'HTTP method not allowed.', ['Allow' => 'GET, HEAD']));
    same(405, $response->status);
    same('HTTP method not allowed.', $response->body);
    same('GET, HEAD', $response->headers['Allow']);
    $request = new \PBreeze\Request('POST', '/api/example');
    $response = $handler->render(new \PBreeze\AuthException(['email' => ['Invalid credentials.']], 429, 30), $request);
    same(429, $response->status);
    same('30', $response->headers['Retry-After']);
    $data = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
    same('HTTP_429', $data['error']['code']);
    same(429, $data['error']['status']);
    same(['email' => ['Invalid credentials.']], $data['error']['details']);
    $error = new \PBreeze\ValidationException(new \PBreeze\ValidationErrors(['name' => ['Required.']]));
    $response = $handler->render($error, $request);
    same(422, $response->status);
    same(['name' => ['Required.']], json_decode($response->body, true, 32, JSON_THROW_ON_ERROR)['error']['details']);
    $response = $handler->render(new \PBreeze\RecordNotFoundException('private database identifier'), $request);
    same(404, $response->status);
    check(!str_contains($response->body, 'private database identifier'));
    $request = new \PBreeze\Request('POST', '/form', headers: ['Content-Type' => 'application/json']);
    same('application/json; charset=UTF-8', $handler->render(new \PBreeze\HttpException(400, 'Invalid JSON body.'), $request)->headers['Content-Type']);
});

test('Application API dispatch shares configured local diagnostics and production privacy', static function (): void {
    global $temp, $config;
    foreach (['local', 'production'] as $environment) {
        $directory = $temp . '/exception-api-' . $environment;
        mkdir($directory, 0700);
        $app = new \PBreeze\Application($directory, array_replace($config, ['environment' => $environment, 'debug' => true]));
        $app->db->execute('CREATE TABLE api_limits (bucket VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, expires_at INTEGER NOT NULL)');
        $app->api->add('GET', '/api/error-test', static function (): never {
            throw new RuntimeException('private-api-secret');
        }, ['summary' => 'Test exception handler', 'operationId' => 'errorTest', 'responses' => ['500' => ['description' => 'Failure']]], false);
        $response = $app->handle(new \PBreeze\Request('GET', '/api/error-test', ip: '127.0.0.1'));
        same(500, $response->status);
        if ($environment === 'local') {
            check(str_contains($response->body, 'Message: private-api-secret'));
            check(str_contains($response->body, 'Path: /api/error-test'));
        } else {
            same(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.', 'status' => 500]],
                json_decode($response->body, true, 32, JSON_THROW_ON_ERROR));
        }
        $lines = file($directory . '/storage/logs/app-' . gmdate('Y-m-d') . '.jsonl');
        same(1, count($lines));
    }
});

test('Front controller startup failures use safe JSON before or after the handler is loaded', static function (): void {
    global $root, $temp;
    foreach ([false, true] as $loaded) {
        $directory = $temp . '/exception-startup-' . (int) $loaded;
        mkdir($directory, 0700);
        mkdir($directory . '/public', 0700);
        copy($root . '/public/index.php', $directory . '/public/index.php');
        file_put_contents($directory . '/bootstrap.php', '<?php '
            . ($loaded ? 'require ' . var_export($root . '/bootstrap.php', true) . ';' : '')
            . 'throw new RuntimeException("startup-secret");');
        foreach (['GET', 'HEAD'] as $method) {
            $source = '$_SERVER = ["REQUEST_METHOD" => ' . var_export($method, true) . ', "REQUEST_URI" => "/"];'
                . 'ob_start(); require ' . var_export($directory . '/public/index.php', true) . ';'
                . '$body = ob_get_clean(); echo json_encode(["status" => http_response_code(), "body" => $body]);';
            $process = proc_open([PHP_BINARY, '-r', $source], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes);
            check(is_resource($process));
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            same(0, proc_close($process));
            check(!str_contains($errors, 'startup-secret'));
            $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            same(500, $result['status']);
            if ($method === 'HEAD') {
                same('', $result['body']);
            } else {
                same(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']],
                    json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR));
            }
        }
    }
});
