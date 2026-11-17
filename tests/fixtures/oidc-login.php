<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';
require __DIR__ . '/oidc-provider.php';

function assertOidc(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('OIDC account regression failed.');
    }
}

$directory = $argv[1];
ini_set('session.save_path', $directory);
$root = dirname(__DIR__, 2);
$app = new PBreeze\Application($root, [
    'name' => 'SSO fixture', 'environment' => 'testing', 'debug' => false, 'secure_cookie' => false,
    'key' => PBreeze\Encryption::generateKey(), 'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
]);
$app->session->start();
try {
    foreach (['20261113160000_create_auth.php', '20261116220000_create_auth_mfa.php', '20261118000000_create_auth_identities.php'] as $file) {
        (require $root . '/database/migrations/' . $file)->up($app->db->pdo());
    }
    $provider = new OidcProvider();
    $client = $provider->client($app->session);
    $login = static function () use ($app, $client, $provider): void {
        $app->auth->loginWithOidc($client, $provider->start($client), '192.0.2.1');
    };
    $before = session_id();
    $csrf = $app->session->token();
    $login();
    $user = $app->auth->user();
    assertOidc($user !== null && $user['email_verified_at'] !== null && $user['email'] === 'sso@example.test');
    assertOidc(session_id() !== $before && $app->session->token() !== $csrf);
    assertOidc((int) $app->db->execute('SELECT COUNT(*) FROM auth_identities')->fetchColumn() === 1);
    $app->auth->logout();
    $provider->claims = ['email' => 'changed@example.test', 'email_verified' => false];
    $login();
    assertOidc($app->auth->user()['id'] === $user['id'] && $app->auth->user()['email'] === 'sso@example.test');
    $app->auth->logout();
    foreach ([['sub' => 'unverified', 'email_verified' => false], ['sub' => 'collision']] as $claims) {
        $provider->claims = $claims;
        try {
            $login();
            throw new RuntimeException('SSO account rejection was expected.');
        } catch (PBreeze\AuthException $error) {
            assertOidc($error->status === 403 && $app->auth->user() === null);
        }
    }
    assertOidc((int) $app->db->execute('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
    $app->db->execute("CREATE TRIGGER reject_identity BEFORE INSERT ON auth_identities BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    $provider->claims = ['sub' => 'rollback', 'email' => 'rollback@example.test'];
    try {
        $login();
        throw new RuntimeException('Identity write failure must roll back provisioning.');
    } catch (PDOException) {
        assertOidc((int) $app->db->execute('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
        assertOidc((int) $app->db->execute('SELECT COUNT(*) FROM auth_identities')->fetchColumn() === 1);
    } finally {
        $app->db->execute('DROP TRIGGER reject_identity');
    }
    $issuer = $provider->settings()['issuer'];
    $app->db->insert('auth_identities', [
        'identity_hash' => hash('sha256', json_encode([$issuer, 'prelinked'], JSON_THROW_ON_ERROR)),
        'issuer' => $issuer, 'subject' => 'prelinked', 'user_id' => $user['id'],
    ], null);
    $provider->claims = ['sub' => 'prelinked', 'email' => null, 'email_verified' => false];
    $login();
    assertOidc($app->auth->user()['id'] === $user['id']);
    $app->auth->logout();
    $provider->claims = [];
    $app->db->execute('INSERT INTO auth_mfa (user_id, secret, last_counter) VALUES (?, ?, ?)', [
        $user['id'], $app->encryption->encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'auth:mfa:' . $user['id']), -1,
    ]);
    $code = '0123456789abcdef0123';
    $app->db->execute('INSERT INTO auth_mfa_recovery (user_id, code_hash) VALUES (?, ?)', [$user['id'], hash('sha256', $code)]);
    $login();
    assertOidc($app->auth->user() === null && $app->auth->mfaPending());
    $before = session_id();
    $app->auth->completeMfaLogin(['code' => $code], '192.0.2.1');
    assertOidc($app->auth->user()['id'] === $user['id'] && session_id() !== $before && !$app->auth->mfaPending());
    $app->auth->logout();
    $login();
    $app->db->execute('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$user['id']]);
    try {
        $app->auth->completeMfaLogin(['code' => $code], '192.0.2.1');
        throw new RuntimeException('Revoked pending SSO login must fail.');
    } catch (PBreeze\AuthException $error) {
        assertOidc($error->status === 401 && $app->auth->user() === null);
    }
    $app->db->execute('DELETE FROM users WHERE id = ?', [$user['id']]);
    assertOidc((int) $app->db->execute('SELECT COUNT(*) FROM auth_identities')->fetchColumn() === 0);
    for ($attempt = 0; $attempt < 41; $attempt++) {
        try {
            $app->auth->loginWithOidc($client, [], '192.0.2.2');
            throw new RuntimeException('Missing OIDC state must fail.');
        } catch (PBreeze\AuthException $error) {
            assertOidc($error->status === ($attempt < 40 ? 400 : 429));
        }
    }
    $app->session->reset();
    echo 'OIDC account and MFA checks passed';
} finally {
    $path = $directory . '/sess_' . session_id();
    $app->session->close();
    if (is_file($path)) {
        unlink($path);
    }
}
