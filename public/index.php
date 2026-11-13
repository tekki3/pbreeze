<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    $app = require dirname(__DIR__) . '/bootstrap.php';
    $app->run();
} catch (Throwable $error) {
    error_log((string) $error);
    http_response_code(500);
    $path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]);
    $api = $path === '/api' || str_starts_with($path, '/api/');
    header('Content-Type: ' . ($api ? 'application/json; charset=UTF-8' : 'text/plain; charset=UTF-8'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        echo $api
            ? '{"error":{"status":500,"message":"De applicatie kon niet starten. Controleer het serverlog."}}'
            : 'De applicatie kon niet starten. Controleer het serverlog.';
    }
}
