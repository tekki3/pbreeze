<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$directory = $argv[1];
$action = $argv[2];
$app = new \PBreeze\Application($directory, [
    'name' => 'Session tests', 'environment' => 'testing', 'debug' => false, 'secure_cookie' => false,
    'session' => ['driver' => 'database', 'lifetime' => 60, 'lock_timeout' => 2],
    'database' => ['driver' => 'sqlite', 'database' => 'database/app.sqlite'],
]);
if (isset($argv[3])) {
    session_id($argv[3]);
}
if (str_starts_with($action, 'response-')) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/' . substr($action, strlen('response-'));
    ob_start();
    $app->run();
    $body = ob_get_clean();
    echo json_encode(['status' => http_response_code(), 'body' => $body], JSON_THROW_ON_ERROR);
    exit;
}
$app->session->start();
$before = session_id();
$token = $app->session->token();
if ($action === 'increment') {
    $count = $app->session->get('count', 0);
    usleep(150000);
    $app->session->put('count', $count + 1);
} elseif ($action === 'reset') {
    $app->session->reset();
} elseif ($action !== 'read') {
    throw new RuntimeException('Unknown session test action.');
}
$result = ['id' => session_id(), 'before' => $before, 'token' => $token, 'count' => $app->session->get('count', 0)];
$app->session->close();
echo json_encode($result, JSON_THROW_ON_ERROR);
