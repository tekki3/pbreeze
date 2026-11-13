<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\HttpException;
use PBreeze\Request;
use PBreeze\Response;

$configured = require dirname(__DIR__, 2) . '/bootstrap.php';
$root = getenv('PBREEZE_TEST_UPLOAD_ROOT');
if (!is_string($root) || !is_dir($root)) {
    throw new RuntimeException('An isolated upload fixture root is required.');
}
$config = $configured->config;
if (($_GET['configured'] ?? '') === '1') {
    $config['upload_max_bytes'] = 16;
}
$app = new Application($root, $config);
$app->router->get('/__uploads/token', static fn (): Response => Response::json(['token' => $app->session->token()]));
$app->router->post('/__uploads', static function (Request $request) use ($app): Response {
    $files = $request->query('multiple') === '1' ? $request->files('documents') : [$request->file('document')];
    if ($files === [] || in_array(null, $files, true)) {
        throw new HttpException(422, 'Choose at least one file.');
    }
    $allowed = match ($request->query('kind')) {
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'office' => ['docx', 'xlsx'],
        'pdf' => ['pdf'],
        default => ['txt', 'csv'],
    };
    $maxBytes = $request->query('small') === '1' ? 16 : null;
    foreach ($files as $file) {
        $app->uploads->validate($file, $allowed, $maxBytes);
    }
    $owned = $app->session->get('uploads', []);
    $saved = [];
    foreach ($files as $file) {
        $stored = $app->uploads->store($file, $allowed, $maxBytes);
        $owned[$stored->key] = $stored->originalName;
        $saved[] = [
            'key' => $stored->key, 'name' => $stored->originalName,
            'mime' => $stored->mimeType, 'size' => $stored->size,
        ];
    }
    $app->session->put('uploads', $owned);
    return Response::json(['files' => $saved], 201);
});
$authorize = static function (string $key) use ($app): string {
    $owned = $app->session->get('uploads', []);
    if (!isset($owned[$key])) {
        throw new HttpException(403, 'You do not own this upload.');
    }
    return $owned[$key];
};
$app->router->get('/__uploads/{key}', static function (Request $request, string $key) use ($app, $authorize): Response {
    return $app->uploads->download($key, $authorize($key));
});
$app->router->add('DELETE', '/__uploads/{key}', static function (Request $request, string $key) use ($app, $authorize): Response {
    $authorize($key);
    $app->uploads->delete($key);
    return new Response('', 204);
});
try {
    $app->session->start();
    $response = $app->handle(Request::capture());
} catch (Throwable $error) {
    $response = Application::errorResponse($error);
}
$response->send(($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD');
