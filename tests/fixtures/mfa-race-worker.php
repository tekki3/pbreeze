<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$settings = json_decode((string) fgets(STDIN), true, 16, JSON_THROW_ON_ERROR);
ini_set('session.save_path', $argv[1]);
$app = new PBreeze\Application(dirname(__DIR__, 2), [
    'name' => 'MFA race', 'environment' => 'testing', 'debug' => false, 'secure_cookie' => false,
    'key' => $settings['key'], 'database' => ['driver' => 'sqlite', 'database' => $argv[2]],
]);
$app->session->start();
try {
    $app->db->pdo();
    $operation = $argv[3];
    $_SESSION = $operation === 'login'
        ? ['_auth_mfa_pending' => ['id' => 1, 'version' => 1, 'issued' => time()]]
        : ['_auth' => ['id' => 1, 'version' => 1, 'issued' => time(), 'seen' => time()]];
    $before = session_id();
    $csrf = $app->session->token();
    fwrite(STDOUT, "ready\n");
    if (fgets(STDIN) !== "go\n") {
        throw new RuntimeException('Missing MFA race start signal.');
    }
    $hashes = [];
    try {
        $code = $operation === 'disable' ? '1123456789abcdef0123' : '0123456789abcdef0123';
        if ($operation === 'regenerate') {
            $codes = $app->auth->regenerateMfaRecoveryCodes('A long password 42!', $code, '192.0.2.1');
            $hashes = array_map(static fn (string $code): string => hash('sha256', $code), $codes);
        } elseif ($operation === 'disable') {
            $app->auth->disableMfa('A long password 42!', $code, '192.0.2.2');
        } elseif ($operation === 'login') {
            $app->auth->completeMfaLogin(['code' => $code], '192.0.2.3');
        } else {
            throw new RuntimeException('Unknown MFA race operation.');
        }
        $status = 200;
    } catch (PBreeze\AuthException|PBreeze\HttpException $error) {
        $status = $error->status;
    }
    echo json_encode([
        'operation' => $operation, 'status' => $status, 'hashes' => $hashes,
        'authenticated' => $app->session->get('_auth') !== null,
        'version' => $app->session->get('_auth')['version'] ?? null,
        'rotated' => session_id() !== $before && $app->session->token() !== $csrf,
    ], JSON_THROW_ON_ERROR);
} finally {
    $path = $argv[1] . '/sess_' . session_id();
    $app->session->close();
    if (is_file($path)) {
        unlink($path);
    }
}
