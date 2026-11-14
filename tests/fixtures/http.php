<?php
declare(strict_types=1);

use PBreeze\Request;
use PBreeze\Response;

require dirname(__DIR__, 2) . '/bootstrap.php';

try {
    $request = Request::capture();
    $directory = getenv('PBREEZE_TEST_UPLOAD_ROOT');
    if ($directory === false || $directory === '') {
        throw new RuntimeException('Missing HTTP test directory.');
    }
    $response = match ($request->path) {
        '/__http/request' => Response::json([
            'name' => $request->input('name'), 'page' => $request->query('page'),
            'authorization' => $request->header('Authorization'), 'cookie' => $request->cookie('session'),
            'method' => $request->method(), 'ip' => $request->ip(),
            'is_json' => $request->isJson(), 'json' => $request->json(),
        ]),
        '/__http/download' => Response::download($directory . '/http-response.txt', 'rapport été.txt'),
        '/__http/file' => Response::file($directory . '/http-response.txt'),
        '/__http/empty' => Response::file($directory . '/http-empty.txt'),
        '/__http/missing' => Response::file($directory . '/not-there.txt'),
        '/__http/html' => Response::html('<p>Hello</p>'),
        '/__http/no-content' => Response::html('must not be emitted', 204),
        '/__http/error', '/__http/debug' => throw new RuntimeException('http-private-secret'),
        '/__http/unavailable' => throw new \PBreeze\HttpException(503, 'http-private-secret', ['Retry-After' => '60']),
        default => throw new \PBreeze\HttpException(404, 'Not found.'),
    };
    $response->send($request->method() === 'HEAD');
} catch (Throwable $error) {
    $debug = isset($request) && $request->path === '/__http/debug';
    (new \PBreeze\ExceptionHandler($debug ? 'local' : 'production', $debug))
        ->render($error, $request ?? null)->send(($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
}
