<?php
declare(strict_types=1);

test('Request accessors expose injected data without reading globals or merging sources', static function (): void {
    $request = new \PBreeze\Request('PATCH', '/items', ['name' => 'query', 'page' => '2'],
        ['name' => 'body', 'user' => ['active' => false]], ['Authorization' => 'Bearer test',
            'Content-Type' => 'Application/JSON; charset=UTF-8', 'X-Forwarded-For' => '198.51.100.9'],
        '127.0.0.1', cookies: ['session' => 'test-cookie']);
    same('body', $request->input('name'));
    same('query', $request->query('name'));
    same('2', $request->query('page'));
    same('default', $request->input('missing', 'default'));
    same('Bearer test', $request->header('AUTHORIZATION'));
    same(null, $request->header('Missing'));
    same('test-cookie', $request->cookie('session'));
    same('default', $request->cookie('missing', 'default'));
    same('PATCH', $request->method());
    same($request->method, $request->method());
    same('127.0.0.1', $request->ip());
    same($request->clientIp(), $request->ip());
    same(null, $request->file('document'));
    check($request->isJson());
    same($request->all(), $request->json());
    $form = new \PBreeze\Request('POST', '/', body: ['name' => 'form']);
    same(false, $form->isJson());
    same(null, $form->json());
    raises(static fn () => new \PBreeze\Request('GET', '/', headers: ['X-Test' => []]), InvalidArgumentException::class);
});

test('Request capture snapshots cookies, normalizes headers and ignores forwarded IPs', static function (): void {
    $original = [$_SERVER, $_GET, $_POST, $_FILES, $_COOKIE];
    try {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/hello?page=3',
            'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer forwarded'];
        $_GET = ['page' => '3'];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = ['session' => 'captured'];
        $request = \PBreeze\Request::capture();
        $_COOKIE['session'] = 'changed';
        same('captured', $request->cookie('session'));
        same('3', $request->query('page'));
        same('GET', $request->method());
        same('127.0.0.1', $request->ip());
        same('Bearer forwarded', $request->header('Authorization'));
        same(null, $request->json());
    } finally {
        [$_SERVER, $_GET, $_POST, $_FILES, $_COOKIE] = $original;
    }
});

test('Response factories create inspectable HTML, JSON and local redirect values', static function (): void {
    $response = \PBreeze\Response::html('<p>Hello</p>', 201);
    same('<p>Hello</p>', $response->body);
    same(201, $response->status);
    same('text/html; charset=UTF-8', $response->headers['Content-Type']);
    same(['ok' => true], json_decode(\PBreeze\Response::json(['ok' => true])->body, true, 32, JSON_THROW_ON_ERROR));
    same('/login', \PBreeze\Response::redirect('/login')->headers['Location']);
    raises(static fn () => \PBreeze\Response::html('', 600), InvalidArgumentException::class);
    raises(static fn () => \PBreeze\Response::json(['bad' => "\xff"]), JsonException::class);
});

test('Local file responses expose metadata, safe UTF-8 disposition and explicit input failures', static function (): void {
    global $temp;
    $path = $temp . '/http-response.txt';
    file_put_contents($path, 'Response file contents.');
    $download = \PBreeze\Response::download($path, 'rapport été.txt');
    same('', $download->body);
    same(200, $download->status);
    same('text/plain', $download->headers['Content-Type']);
    same('23', $download->headers['Content-Length']);
    same('attachment; filename="download"; filename*=UTF-8\'\'rapport%20%C3%A9t%C3%A9.txt', $download->headers['Content-Disposition']);
    $inline = \PBreeze\Response::file($path, 'application/octet-stream');
    same('application/octet-stream', $inline->headers['Content-Type']);
    check(str_starts_with($inline->headers['Content-Disposition'], 'inline;'));
    check(str_contains($inline->headers['Content-Security-Policy'], 'sandbox'));
    foreach (['', "bad\0.txt", 'php://input', 'https://example.com/file', 'C:relative.txt'] as $bad) {
        raises(static fn () => \PBreeze\Response::file($bad), InvalidArgumentException::class);
    }
    raises(static fn () => \PBreeze\Response::file($temp . '/not-there.txt'), \PBreeze\HttpException::class, 404);
    raises(static fn () => \PBreeze\Response::file($temp), \PBreeze\HttpException::class, 404);
    foreach (['', '../secret', "bad\r\nname.txt", "\xff", str_repeat('a', 256)] as $name) {
        raises(static fn () => \PBreeze\Response::download($path, $name), InvalidArgumentException::class);
    }
    raises(static fn () => \PBreeze\Response::file($path, "text/plain\r\nX-Test: bad"), InvalidArgumentException::class);
    $empty = $temp . '/http-empty.txt';
    file_put_contents($empty, '');
    same('0', \PBreeze\Response::file($empty)->headers['Content-Length']);
});

test('File responses stream large files with bounded memory and detect removed files before output', static function (): void {
    global $root, $temp;
    $path = $temp . '/http-large.bin';
    $stream = fopen($path, 'wb');
    check(is_resource($stream));
    try {
        for ($index = 0; $index < 128; $index++) {
            same(65536, fwrite($stream, str_repeat("\0\xff\r\n", 16384)));
        }
    } finally {
        fclose($stream);
    }
    $source = 'require ' . var_export($root . '/framework/Http.php', true) . ';'
        . '$hash = hash_init("sha256");'
        . 'ob_start(static function ($chunk) use ($hash) { hash_update($hash, $chunk); return ""; }, 4096);'
        . '$before = memory_get_peak_usage(true);'
        . '\\PBreeze\\Response::download(' . var_export($path, true) . ', mimeType: "application/octet-stream")->send();'
        . 'ob_end_clean();'
        . 'echo json_encode(["hash" => hash_final($hash), "growth" => memory_get_peak_usage(true) - $before]);';
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
    same('', $errors);
    $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    same(hash_file('sha256', $path), $result['hash']);
    check($result['growth'] <= 2097152, 'Streaming must not buffer the 8 MiB response in memory.');
    $vanishing = $temp . '/http-removed.txt';
    file_put_contents($vanishing, 'temporary');
    $response = \PBreeze\Response::file($vanishing);
    unlink($vanishing);
    raises(static fn () => $response->send(), RuntimeException::class);
});

test('Application maps pre-output file delivery failures to a logged error response', static function (): void {
    global $root, $temp, $config;
    $directory = $temp . '/http-application';
    mkdir($directory, 0700);
    mkdir($directory . '/routes', 0700);
    mkdir($directory . '/storage', 0700);
    $path = $directory . '/vanishing.txt';
    file_put_contents($path, 'must not be sent');
    file_put_contents($directory . '/routes/web.php', '<?php $router->get("/health", static function (): \\PBreeze\\Response {'
        . '$path = ' . var_export($path, true) . ';'
        . '$response = \\PBreeze\\Response::file($path); unlink($path); return $response; });');
    $source = 'require ' . var_export($root . '/bootstrap.php', true) . ';'
        . '$_SERVER = ["REQUEST_METHOD" => "GET", "REQUEST_URI" => "/health"];'
        . '$_GET = $_POST = $_FILES = $_COOKIE = [];'
        . '$app = new \\PBreeze\\Application(' . var_export($directory, true) . ', ' . var_export($config, true) . ');'
        . 'ob_start(); $app->run(); $body = ob_get_clean();'
        . 'echo json_encode(["status" => http_response_code(), "body" => $body]);';
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
    same('', $errors);
    $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    same(500, $result['status']);
    same(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']],
        json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR));
    $logs = glob($directory . '/storage/logs/app-*.jsonl');
    check($logs !== false && count($logs) > 0);
    $entry = json_decode(trim(file_get_contents($logs[0])), true, 32, JSON_THROW_ON_ERROR);
    same('error', $entry['level']);
    same('Unhandled application exception.', $entry['message']);
    same(RuntimeException::class, $entry['context']['exception']['class']);
    check(str_ends_with($entry['context']['exception']['file'], '/framework/Http.php'));
});

function requestResponseHttp(string $base): void
{
    test('HTTP exception handler delivers safe production JSON and opt-in plain-text diagnostics', static function () use ($base): void {
        foreach (['error' => 500, 'unavailable' => 503] as $route => $status) {
            $response = http($base, '/__http/' . $route);
            same($status, $response['status']);
            same(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']],
                json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR));
            check(in_array('Content-Type: application/json; charset=UTF-8', $response['headers'], true));
            check(in_array('Cache-Control: no-store', $response['headers'], true));
            if ($status === 503) {
                check(in_array('Retry-After: 60', $response['headers'], true));
            }
            same('', http($base, '/__http/' . $route, 'HEAD')['body']);
        }
        $response = http($base, '/__http/debug', 'GET', '', ['Cookie: session=http-cookie-secret']);
        same(500, $response['status']);
        check(in_array('Content-Type: text/plain; charset=UTF-8', $response['headers'], true));
        check(str_contains($response['body'], 'Message: http-private-secret'));
        check(str_contains($response['body'], 'Method: GET'));
        check(!str_contains($response['body'], 'http-cookie-secret'));
    });

    test('HTTP request accessors parse JSON, forms, query, headers and cookies consistently', static function () use ($base): void {
        $response = http($base, '/__http/request?page=2&name=query', 'POST', '{"name":"Ada","nested":{"active":true}}', [
            'Content-Type: application/json; charset=UTF-8', 'Authorization: Bearer test',
            'Cookie: session=cookie-value', 'X-Forwarded-For: 198.51.100.9',
        ]);
        same(200, $response['status']);
        $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        same('Ada', $data['name']);
        same('2', $data['page']);
        same('Bearer test', $data['authorization']);
        same('cookie-value', $data['cookie']);
        same('POST', $data['method']);
        same('127.0.0.1', $data['ip']);
        same(true, $data['is_json']);
        same(['name' => 'Ada', 'nested' => ['active' => true]], $data['json']);
        $response = http($base, '/__http/request?name=query', 'POST', 'name=Form', ['Content-Type: application/x-www-form-urlencoded']);
        $data = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        same('Form', $data['name']);
        same(false, $data['is_json']);
        same(null, $data['json']);
        foreach (['{bad}', '[]', 'null', ''] as $body) {
            same(400, http($base, '/__http/request', 'POST', $body, ['Content-Type: application/json'])['status']);
        }
        same(413, http($base, '/__http/request', 'POST', str_repeat('x', 1048577), ['Content-Type: application/json'])['status']);
    });

    test('HTTP file and HTML factories preserve bytes, security headers, HEAD and no-body statuses', static function () use ($base): void {
        foreach (['download' => 'attachment;', 'file' => 'inline;'] as $route => $disposition) {
            $response = http($base, '/__http/' . $route);
            same(200, $response['status']);
            same('Response file contents.', $response['body']);
            check(in_array('Content-Length: 23', $response['headers'], true));
            check(in_array('X-Content-Type-Options: nosniff', $response['headers'], true));
            check(in_array('Cache-Control: no-store', $response['headers'], true));
            check(count(array_filter($response['headers'], static fn ($header): bool =>
                str_starts_with($header, 'Content-Disposition: ' . $disposition))) === 1);
            $head = http($base, '/__http/' . $route, 'HEAD');
            same(200, $head['status']);
            same('', $head['body']);
            check(in_array('Content-Length: 23', $head['headers'], true));
        }
        same('', http($base, '/__http/empty')['body']);
        same(404, http($base, '/__http/missing')['status']);
        same('<p>Hello</p>', http($base, '/__http/html')['body']);
        same('', http($base, '/__http/html', 'HEAD')['body']);
        $empty = http($base, '/__http/no-content');
        same(204, $empty['status']);
        same('', $empty['body']);
    });
}
