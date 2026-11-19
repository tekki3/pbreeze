<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';
require __DIR__ . '/oidc-provider.php';

use PBreeze\Application;
use PBreeze\Auth;
use PBreeze\AuthException;
use PBreeze\HttpException;

function assertMfa(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejectMfa(callable $work, int $status): void
{
    try {
        $work();
    } catch (AuthException|HttpException $error) {
        assertMfa($error->status === $status, 'Unexpected MFA rejection status.');
        if ($status === 429) {
            assertMfa($error instanceof AuthException && $error->retryAfter > 0, 'Missing retry interval.');
        }
        return;
    }
    throw new RuntimeException('Expected MFA rejection.');
}

function mfaApp(array $policy = [], string $database = ':memory:'): Application
{
    $root = dirname(__DIR__, 2);
    $app = new Application($root, [
        'name' => 'MFA fixture', 'environment' => 'testing', 'debug' => false, 'secure_cookie' => false,
        'url' => 'https://app.example.test', 'key' => PBreeze\Encryption::generateKey(),
        'database' => ['driver' => 'sqlite', 'database' => $database], 'auth' => $policy,
    ]);
    foreach (['20261113160000_create_auth.php', '20261113170000_create_api_tokens.php',
        '20261116220000_create_auth_mfa.php', '20261118000000_create_auth_identities.php'] as $file) {
        (require $root . '/database/migrations/' . $file)->up($app->db->pdo());
    }
    $app->db->insert('users', [
        'name' => 'MFA User', 'email' => 'sso@example.test', 'created_at' => time(), 'email_verified_at' => time(),
        'password_hash' => password_hash('A long password 42!', PASSWORD_BCRYPT, ['cost' => 12]),
    ]);
    $app->db->insert('auth_mfa', [
        'user_id' => 1, 'secret' => $app->encryption->encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'auth:mfa:1'),
        'last_counter' => -1,
    ], null);
    foreach (['0123456789abcdef0123', '1123456789abcdef0123', '2123456789abcdef0123'] as $code) {
        $app->db->insert('auth_mfa_recovery', ['user_id' => 1, 'code_hash' => hash('sha256', $code)], null);
    }
    $_SESSION = ['_auth' => ['id' => 1, 'version' => 1, 'issued' => time(), 'seen' => time()]];
    return $app;
}

function fixtureMfaCode(): string
{
    $digest = hash_hmac('sha1', pack('N2', 0, intdiv(time(), 30)), '12345678901234567890', true);
    $offset = ord(substr($digest, -1)) & 0x0f;
    return sprintf('%06d', (unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff) % 1000000);
}

$directory = $argv[1];
ini_set('session.save_path', $directory);
$session = new PBreeze\Session();
$session->start();
$password = 'A long password 42!';
$recovery = '0123456789abcdef0123';
$ip = '192.0.2.1';
try {
    if ($argv[2] === 'policy') {
        $provider = new OidcProvider();
        foreach ([true, false] as $enrollment) {
            foreach ([true, false] as $enforcement) {
                $app = mfaApp(['mfa_enrollment_enabled' => $enrollment, 'mfa_enforcement_enabled' => $enforcement]);
                $beforeMfa = $app->db->execute('SELECT * FROM auth_mfa')->fetchAll();
                $beforeCodes = $app->db->execute('SELECT * FROM auth_mfa_recovery')->fetchAll();
                $app->auth->logout();
                $before = session_id();
                $csrf = $app->session->token();
                rejectMfa(fn () => $app->auth->login(['email' => 'sso@example.test', 'password' => 'wrong'], $ip), 422);
                $app->auth->login(['email' => 'sso@example.test', 'password' => $password], $ip);
                assertMfa($app->auth->mfaPending() === $enforcement, 'Password login ignored MFA enforcement.');
                assertMfa(($app->auth->user() === null) === $enforcement, 'Wrong password login authorization.');
                assertMfa(session_id() !== $before && $app->session->token() !== $csrf, 'Password login did not rotate session/CSRF.');
                $issuer = $provider->settings()['issuer'];
                $app->db->insert('auth_identities', [
                    'identity_hash' => hash('sha256', json_encode([$issuer, 'subject-123'], JSON_THROW_ON_ERROR)),
                    'issuer' => $issuer, 'subject' => 'subject-123', 'user_id' => 1,
                ], null);
                $app->auth->logout();
                $client = $provider->client($app->session);
                $before = session_id();
                $csrf = $app->session->token();
                $app->auth->loginWithOidc($client, $provider->start($client), $ip);
                assertMfa($app->auth->mfaPending() === $enforcement, 'OIDC login ignored MFA enforcement.');
                assertMfa(($app->auth->user() === null) === $enforcement, 'Wrong OIDC login authorization.');
                assertMfa(session_id() !== $before && $app->session->token() !== $csrf, 'OIDC login did not rotate session/CSRF.');
                assertMfa($beforeMfa === $app->db->execute('SELECT * FROM auth_mfa')->fetchAll(), 'Policy changed enrolled secret/counter.');
                assertMfa($beforeCodes === $app->db->execute('SELECT * FROM auth_mfa_recovery')->fetchAll(), 'Policy changed recovery codes.');
                if ($enforcement) {
                    $emergency = new Auth($app->db, $app->session, $app->mail, 'https://app.example.test', mfaEnforcementEnabled: false);
                    assertMfa($emergency->user() === null && $emergency->mfaPending(), 'Policy promoted an existing pending login.');
                    $app->auth->completeMfaLogin(['code' => $recovery], $ip);
                } else {
                    $enforced = new Auth($app->db, $app->session, $app->mail, 'https://app.example.test');
                    assertMfa($enforced->user() !== null, 'Re-enabling enforcement unexpectedly revoked an existing session.');
                    $current = $_SESSION;
                    $enforced->logout();
                    $enforced->login(['email' => 'sso@example.test', 'password' => $password], $ip);
                    assertMfa($enforced->mfaPending() && $enforced->user() === null, 'Restored enforcement did not challenge the next login.');
                    $_SESSION = $current;
                }
                assertMfa($app->auth->mfaEnabled(), 'Enrollment status depended on policy.');
                $codes = $app->auth->regenerateMfaRecoveryCodes($password, '1123456789abcdef0123', $ip);
                $app->auth->disableMfa($password, $codes[0], $ip);
                assertMfa(!$app->auth->mfaEnabled(), 'Policy blocked disabling MFA.');
                $app->auth->logout();
                $app->auth->login(['email' => 'sso@example.test', 'password' => $password], $ip);
                assertMfa(!$app->auth->mfaPending() && $app->auth->user() !== null, 'Policy challenged an unenrolled account.');
                rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes($password, $codes[1], $ip), 422);
                $allowed = new Auth($app->db, $app->session, $app->mail, 'https://app.example.test');
                $setup = $allowed->beginMfaSetup($password, $ip);
                if (!$enrollment) {
                    rejectMfa(fn () => $app->auth->beginMfaSetup($password, $ip), 403);
                    rejectMfa(fn () => $app->auth->confirmMfaSetup('123456', $ip), 403);
                    assertMfa($app->db->table('auth_mfa')->count() === 0, 'Disabled enrollment persisted setup.');
                    assertMfa($app->session->get('_auth_mfa_setup') !== null, 'Enrollment policy unexpectedly deleted setup.');
                } else {
                    assertMfa(isset($setup['secret'], $setup['uri']), 'Default enrollment failed.');
                }
            }
        }
    } elseif ($argv[2] === 'regeneration') {
        $app = mfaApp();
        $oldState = $_SESSION;
        $api = new PBreeze\ApiTokens($app->db);
        $token = $api->create(1);
        $secret = $app->db->execute('SELECT secret FROM auth_mfa')->fetchColumn();
        rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes('wrong', $recovery, $ip), 422);
        rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes($password, 'invalid', $ip), 422);
        assertMfa($app->db->table('auth_mfa_recovery')->count() === 3, 'Invalid factors consumed recovery codes.');
        $before = session_id();
        $csrf = $app->session->token();
        $codes = $app->auth->regenerateMfaRecoveryCodes($password, $recovery, $ip);
        assertMfa(count($codes) === 10 && count(array_unique($codes)) === 10, 'Wrong regenerated recovery-code count.');
        foreach ($codes as $code) {
            assertMfa(preg_match('/^[a-f0-9]{20}$/D', $code) === 1, 'Wrong recovery-code format.');
        }
        $stored = $app->db->execute('SELECT code_hash FROM auth_mfa_recovery')->fetchAll(PDO::FETCH_COLUMN);
        $hashes = array_map(static fn (string $code): string => hash('sha256', $code), $codes);
        sort($stored);
        sort($hashes);
        assertMfa($stored === $hashes, 'Recovery table did not contain exactly the new hashes.');
        assertMfa(session_id() !== $before && $app->session->token() !== $csrf, 'Regeneration did not rotate session/CSRF.');
        assertMfa($app->auth->user() !== null && $_SESSION['_auth']['version'] === 2, 'Current login did not survive regeneration.');
        rejectMfa(fn () => $api->authenticate('Bearer ' . $token['token']), 401);
        $current = $_SESSION;
        $_SESSION = $oldState;
        assertMfa($app->auth->user() === null, 'Old session survived regeneration.');
        $_SESSION = ['_auth_mfa_pending' => ['id' => 1, 'version' => 1, 'issued' => time()]];
        rejectMfa(fn () => $app->auth->completeMfaLogin(['code' => $codes[0]], $ip), 401);
        $_SESSION = $current;
        $app->db->execute('DELETE FROM auth_limits');
        foreach ([$recovery, '1123456789abcdef0123', '2123456789abcdef0123'] as $oldCode) {
            rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes($password, $oldCode, $ip), 422);
        }
        $app->db->execute('DELETE FROM auth_limits');
        $code = fixtureMfaCode();
        $newCodes = $app->auth->regenerateMfaRecoveryCodes($password, $code, $ip);
        rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes($password, $code, $ip), 422);
        assertMfa($secret === $app->db->execute('SELECT secret FROM auth_mfa')->fetchColumn(), 'Regeneration replaced the authenticator.');
        $app->auth->logout();
        $app->auth->login(['email' => 'sso@example.test', 'password' => $password], $ip);
        rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes($password, $newCodes[0], $ip), 403);
        $app->auth->completeMfaLogin(['code' => $newCodes[0]], $ip);
        assertMfa($app->auth->user() !== null, 'Regenerated recovery code could not authenticate.');
        rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes($password, $newCodes[0], $ip), 422);
        $app->db->execute('UPDATE users SET email_verified_at = NULL WHERE id = 1');
        rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes($password, $newCodes[1], $ip), 403);
    } elseif ($argv[2] === 'rollback') {
        $app = mfaApp();
        foreach ([$recovery, fixtureMfaCode()] as $code) {
            $beforeCodes = $app->db->execute('SELECT * FROM auth_mfa_recovery ORDER BY code_hash')->fetchAll();
            $beforeMfa = $app->db->execute('SELECT * FROM auth_mfa')->fetchAll();
            $beforeState = $_SESSION;
            $before = session_id();
            $csrf = $app->session->token();
            $app->db->execute("CREATE TRIGGER reject_recovery BEFORE INSERT ON auth_mfa_recovery
                WHEN (SELECT COUNT(*) FROM auth_mfa_recovery) = 1 BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
            try {
                $app->auth->regenerateMfaRecoveryCodes($password, $code, $ip);
                throw new RuntimeException('Failed recovery-code insert was accepted.');
            } catch (PDOException) {
                assertMfa($beforeCodes === $app->db->execute('SELECT * FROM auth_mfa_recovery ORDER BY code_hash')->fetchAll(), 'Recovery hashes did not roll back.');
                assertMfa($beforeMfa === $app->db->execute('SELECT * FROM auth_mfa')->fetchAll(), 'Authenticator counter did not roll back.');
                assertMfa($_SESSION['_auth']['id'] === $beforeState['_auth']['id']
                    && $_SESSION['_auth']['version'] === $beforeState['_auth']['version'], 'Failed regeneration changed session identity.');
                assertMfa(session_id() === $before && $app->session->token() === $csrf, 'Failed regeneration rotated session/CSRF.');
                assertMfa((int) $app->db->execute('SELECT session_version FROM users')->fetchColumn() === 1, 'Version did not roll back.');
            } finally {
                $app->db->execute('DROP TRIGGER reject_recovery');
            }
        }
        $app->auth->regenerateMfaRecoveryCodes($password, $recovery, $ip);
    } elseif ($argv[2] === 'limits') {
        $app = mfaApp();
        for ($i = 0; $i < 5; $i++) {
            rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes($password, 'invalid', $ip), 422);
        }
        $emergency = new Auth($app->db, $app->session, $app->mail, 'https://app.example.test', mfaEnforcementEnabled: false);
        $emergency->logout();
        $emergency->login(['email' => 'sso@example.test', 'password' => $password], $ip);
        rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes($password, $recovery, '192.0.2.2'), 429);
        rejectMfa(fn () => $app->auth->disableMfa($password, $recovery, $ip), 429);
        assertMfa($app->db->table('auth_mfa_recovery')->count() === 3, 'Rate limiting consumed a recovery code.');
        $app->db->execute('DELETE FROM auth_limits');
        for ($i = 0; $i < 10; $i++) {
            rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes('wrong', $recovery, $ip), 422);
        }
        rejectMfa(fn () => $app->auth->regenerateMfaRecoveryCodes($password, $recovery, '192.0.2.2'), 429);
        assertMfa((int) $app->db->execute('SELECT session_version FROM users')->fetchColumn() === 1, 'Rate limiting changed session version.');
    } elseif (str_starts_with($argv[2], 'race-')) {
        $operations = match ($argv[2]) {
            'race-regenerate' => ['regenerate', 'regenerate'],
            'race-disable' => ['regenerate', 'disable'],
            'race-login' => ['login', 'login'],
            default => throw new RuntimeException('Unknown MFA race scenario.'),
        };
        for ($round = 0; $round < 3; $round++) {
            $database = $directory . '/' . $argv[2] . '-' . $round . '.sqlite';
            $app = mfaApp(database: $database);
            $workers = [];
            try {
                foreach ($operations as $operation) {
                    $process = proc_open([PHP_BINARY, __DIR__ . '/mfa-race-worker.php', $directory, $database, $operation],
                        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, null, ['bypass_shell' => true]);
                    assertMfa(is_resource($process), 'Cannot start MFA race worker.');
                    $workers[] = [$process, $pipes];
                    fwrite($pipes[0], json_encode(['key' => $app->config['key']], JSON_THROW_ON_ERROR) . "\n");
                }
                foreach ($workers as [, $pipes]) {
                    assertMfa(fgets($pipes[1]) === "ready\n", 'MFA race worker did not reach the start barrier.');
                }
                foreach ($workers as [, $pipes]) {
                    fwrite($pipes[0], "go\n");
                    fclose($pipes[0]);
                }
                $results = [];
                foreach ($workers as [$process, $pipes]) {
                    $output = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    assertMfa(proc_close($process) === 0, 'MFA race worker failed: ' . $output);
                    $results[] = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
                }
                $winners = array_values(array_filter($results, static fn (array $result): bool => $result['status'] === 200));
                assertMfa(count($winners) === 1, 'Competing MFA operations did not have exactly one winner.');
                $winner = $winners[0];
                assertMfa($winner['authenticated'] && $winner['rotated'], 'Winning MFA operation did not rotate and retain identity.');
                foreach ($results as $result) {
                    if ($result['status'] !== 200) {
                        assertMfa(in_array($result['status'], [401, 403, 409, 422], true), 'Unexpected losing MFA operation status.');
                        if ($result['authenticated']) {
                            assertMfa($result['version'] === 1, 'Losing operation adopted the winning session version.');
                        }
                    }
                }
                $version = (int) $app->db->execute('SELECT session_version FROM users WHERE id = 1')->fetchColumn();
                $hashes = $app->db->execute('SELECT code_hash FROM auth_mfa_recovery')->fetchAll(PDO::FETCH_COLUMN);
                if ($winner['operation'] === 'login') {
                    assertMfa($version === 1 && count($hashes) === 2, 'Competing login consumed more than one recovery code.');
                    assertMfa(!in_array(hash('sha256', $recovery), $hashes, true), 'Successful login retained its recovery code.');
                    foreach ($results as $result) {
                        assertMfa($result['authenticated'] === ($result['status'] === 200), 'Losing challenge authenticated a user.');
                    }
                } elseif ($winner['operation'] === 'disable') {
                    assertMfa($version === 2 && $hashes === [] && $app->db->table('auth_mfa')->count() === 0, 'Concurrent disabling left MFA data behind.');
                } else {
                    sort($hashes);
                    sort($winner['hashes']);
                    assertMfa($version === 2 && count($hashes) === 10 && $hashes === $winner['hashes'], 'Regeneration did not persist exactly the winning hashes.');
                    assertMfa($app->db->table('auth_mfa')->count() === 1, 'Losing disable removed the enrollment.');
                }
            } finally {
                foreach ($workers as [$process, $pipes]) {
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    if (is_resource($process)) {
                        proc_terminate($process);
                        proc_close($process);
                    }
                }
                $app = null;
                if (is_file($database)) {
                    unlink($database);
                }
            }
        }
    } else {
        throw new RuntimeException('Unknown MFA management fixture scenario.');
    }
    echo 'MFA management checks passed';
} finally {
    $path = $directory . '/sess_' . session_id();
    $session->close();
    if (is_file($path)) {
        unlink($path);
    }
}
