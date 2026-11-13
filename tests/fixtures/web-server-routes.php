<?php
declare(strict_types=1);

use PBreeze\HttpException;
use PBreeze\Request;
use PBreeze\Response;

$router->get('/__server/route/{value}', static fn (Request $request, string $value): Response => Response::json([
    'value' => $value, 'query' => $request->query('value'), 'path' => $request->path,
]));
$router->get('/__server/token', static fn (): Response => Response::json(['token' => $app->session->token()]));
$router->get('/__server/redirect', static fn (): Response => Response::redirect('/__server/route/redirected?value=kept'));
$router->get('/__server/failure', static fn () => throw new RuntimeException('Private web server fixture failure.'));
$router->post('/__server/upload', static function (Request $request) use ($app): Response {
    $file = $request->file('document') ?? throw new HttpException(422, 'Choose a file.');
    $stored = $app->uploads->store($file, ['txt']);
    return Response::json(['key' => $stored->key, 'size' => $stored->size], 201);
});
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    $app->api->add($method, '/api/server-test', static fn (Request $request): Response => Response::json([
        'method' => $request->method, 'value' => $request->input('value'),
    ]), ['summary' => 'Server integration fixture', 'operationId' => 'serverTest' . $method,
        'responses' => ['200' => ['description' => 'OK']]], false);
}
