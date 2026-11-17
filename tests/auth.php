<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Database;
use PBreeze\Migrator;

final class AuthBrowser
{
    public string $cookie = '';
    public string $csrf = '';
    public bool $solveCaptcha = true;

    public function __construct(private readonly string $base)
    {
        same(200, $this->request('/login')['status']);
    }

    /** @param array<string, mixed> $data
     *  @param list<string> $extraHeaders
     *  @return array{status: int, headers: list<string>, body: string}
     */
    public function request(string $path, string $method = 'GET', array $data = [], array $extraHeaders = []): array
    {
        $forms = [
            '/register' => '/register', '/login' => '/login', '/forgot-password' => '/forgot-password',
            '/email/verification-notification' => '/email/verify', '/greet' => '/',
        ];
        if ($method === 'POST' && $this->solveCaptcha && isset($forms[$path]) && !array_key_exists('_captcha_id', $data)) {
            $form = $this->request($forms[$path]);
            if ($form['status'] === 200) {
                $data += captchaFields($this->cookie, $form['body']);
            }
        }
        $headers = ['Cookie: ' . $this->cookie, ...$extraHeaders];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $data += ['_token' => $this->csrf];
        }
        $response = http($this->base, $path, $method, $method === 'POST' ? http_build_query($data) : '', $headers);
        foreach ($response['headers'] as $header) {
            if (preg_match('/^Set-Cookie: (pbreeze_session=[^;]*)/i', $header, $match)) {
                $this->cookie = $match[1];
            }
        }
        if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $response['body'], $match)) {
            $this->csrf = $match[1];
        }
        return $response;
    }
}

/** @return array{name: string, email: string, password: string, password_confirmation: string} */
function authData(string $email = 'ada@example.test'): array
{
    return ['name' => '<Ada>', 'email' => $email, 'password' => 'A long password 42!', 'password_confirmation' => 'A long password 42!'];
}

function authToken(Database $db, string $email, string $purpose): string
{
    $subject = $purpose === 'reset' ? 'Reset your password' : 'Verify your email address';
    $body = $db->execute('SELECT text_body FROM emails WHERE to_addresses = ? AND subject = ? ORDER BY id DESC', [
        json_encode([$email], JSON_THROW_ON_ERROR), $subject,
    ])->fetchColumn();
    check(is_string($body), 'No authentication email in the queue.');
    check(preg_match('/\?token=([a-f0-9]{64})/', $body, $match) === 1);
    return $match[1];
}

$authDb = new Database($root, ['driver' => 'sqlite', 'database' => $temp . '/http.sqlite']);
(new Migrator($authDb, $root))->migrate();

test('Registration validates input and does not store invalid accounts', static function () use ($base, $authDb): void {
    $client = new AuthBrowser($base);
    $invalid = authData();
    $invalid['name'] = ['not text'];
    $invalid['password'] = 'short';
    same(422, $client->request('/register', 'POST', $invalid)['status']);
    $invalid = authData();
    $invalid['password_confirmation'] = 'different';
    same(422, $client->request('/register', 'POST', $invalid)['status']);
    $invalid = authData();
    $invalid['password'] = $invalid['password_confirmation'] = str_repeat('a', 73);
    same(422, $client->request('/register', 'POST', $invalid)['status']);
    same(0, (int) $authDb->execute('SELECT COUNT(*) FROM users')->fetchColumn());
});

test('All authentication actions require CSRF, including verification and logout', static function () use ($base): void {
    foreach (['/register', '/login', '/logout', '/forgot-password', '/reset-password', '/verify-email', '/email/verification-notification',
        '/login/mfa', '/account/mfa/setup', '/account/mfa/confirm', '/account/mfa/disable', '/sso/login'] as $path) {
        same(419, http($base, $path, 'POST')['status']);
    }
});

test('Registration stores bcrypt and hashed tokens with an atomically queued verification email', static function () use ($base, $authDb, &$verifyToken): void {
    $client = new AuthBrowser($base);
    $data = authData();
    $data['email'] = ' ADA@EXAMPLE.TEST ';
    $response = $client->request('/register', 'POST', $data, ['Host: evil.example.test', 'X-Forwarded-Host: evil.example.test']);
    same(200, $response['status']);
    check(str_contains($response['body'], 'a verification email has been queued'));
    check(!str_contains($response['body'], $data['password']));
    $user = $authDb->execute('SELECT * FROM users WHERE email = ?', ['ada@example.test'])->fetch();
    check(is_array($user));
    check(password_verify($data['password'], $user['password_hash']));
    same(null, $user['email_verified_at']);
    $verifyToken = authToken($authDb, 'ada@example.test', 'verify');
    $body = $authDb->execute('SELECT text_body FROM emails')->fetchColumn();
    check(str_contains($body, 'https://auth.example.test/verify-email?token='));
    check(!str_contains($body, 'evil.example.test'));
    $token = $authDb->execute('SELECT * FROM auth_tokens')->fetch();
    same(hash('sha256', $verifyToken), $token['token_hash']);
    check($token['expires_at'] > time() + 86000);
    same('queued', $authDb->execute('SELECT status FROM emails')->fetchColumn());
    $account = $client->request('/account');
    same(303, $account['status']);
    check(in_array('Location: /login', $account['headers'], true));
});

test('Existing registration returns the same message without duplicates or new email', static function () use ($base, $authDb): void {
    $client = new AuthBrowser($base);
    $before = (int) $authDb->execute('SELECT COUNT(*) FROM emails')->fetchColumn();
    $response = $client->request('/register', 'POST', authData());
    same(200, $response['status']);
    check(str_contains($response['body'], 'a verification email has been queued'));
    same(1, (int) $authDb->execute('SELECT COUNT(*) FROM users')->fetchColumn());
    same($before, (int) $authDb->execute('SELECT COUNT(*) FROM emails')->fetchColumn());
});

test('Login returns generic errors, rotates session and CSRF, and blocks unverified accounts', static function () use ($base, &$authClient): void {
    $authClient = new AuthBrowser($base);
    $bad = authData();
    $bad['password'] = 'Incorrect password';
    $known = $authClient->request('/login', 'POST', $bad);
    same(422, $known['status']);
    $bad['email'] = 'missing@example.test';
    $unknown = $authClient->request('/login', 'POST', $bad);
    same(422, $unknown['status']);
    check(str_contains($known['body'], 'The email address or password is incorrect.'));
    check(str_contains($unknown['body'], 'The email address or password is incorrect.'));
    $cookie = $authClient->cookie;
    $csrf = $authClient->csrf;
    same(303, $authClient->request('/login', 'POST', authData())['status']);
    check($authClient->cookie !== $cookie);
    $denied = $authClient->request('/account');
    same(303, $denied['status']);
    check(in_array('Location: /email/verify', $denied['headers'], true));
    same(200, $authClient->request('/email/verify')['status']);
    check($authClient->csrf !== $csrf);
    same(419, $authClient->request('/logout', 'POST', ['_token' => $csrf])['status']);
    same(405, $authClient->request('/logout')['status']);
});

test('Verification does not consume on GET or HEAD, rejects invalid tokens, and works once', static function () use ($base, $authDb, &$verifyToken, &$authClient): void {
    $client = new AuthBrowser($base);
    $path = '/verify-email?token=' . $verifyToken;
    same(200, $client->request($path)['status']);
    same(200, $client->request($path, 'HEAD')['status']);
    same(null, $authDb->execute('SELECT email_verified_at FROM users')->fetchColumn());
    same(400, $client->request('/verify-email', 'POST', ['token' => str_repeat('0', 64)])['status']);
    same(422, $client->request('/verify-email', 'POST', ['token' => $verifyToken])['status']);
    same(null, $authDb->execute('SELECT email_verified_at FROM users')->fetchColumn());
    same(200, $client->request('/verify-email', 'POST', authData() + ['token' => $verifyToken])['status']);
    same(200, $client->request('/login')['status']);
    same(400, $client->request('/verify-email', 'POST', ['token' => $verifyToken])['status']);
    same(303, $authClient->request('/account')['status']);
    same(303, $authClient->request('/login', 'POST', authData())['status']);
    $account = $authClient->request('/account');
    same(200, $account['status']);
    check(str_contains($account['body'], '&lt;Ada&gt;'));
    check(!str_contains($account['body'], '<Ada>'));
    check(!str_contains($account['body'], 'password_hash'));
    check(in_array('Referrer-Policy: no-referrer', $account['headers'], true));
    $home = $authClient->request('/');
    same(200, $home['status']);
    check(str_contains($home['body'], 'href="/account"'));
    check(!str_contains($home['body'], 'href="/login"'));
    same(303, $authClient->request('/email/verify')['status']);
});

test('Password recovery is generic, replaces previous tokens, and does not send passwords', static function () use ($base, $authDb, &$resetToken): void {
    $client = new AuthBrowser($base);
    $before = (int) $authDb->execute('SELECT COUNT(*) FROM emails')->fetchColumn();
    $unknown = $client->request('/forgot-password', 'POST', ['email' => 'unknown@example.test']);
    same(200, $unknown['status']);
    same($before, (int) $authDb->execute('SELECT COUNT(*) FROM emails')->fetchColumn());
    $known = $client->request('/forgot-password', 'POST', ['email' => 'ADA@example.test']);
    same(200, $known['status']);
    same($unknown['body'], $known['body']);
    $old = authToken($authDb, 'ada@example.test', 'reset');
    same(200, $client->request('/forgot-password', 'POST', ['email' => 'ada@example.test'])['status']);
    $resetToken = authToken($authDb, 'ada@example.test', 'reset');
    check($old !== $resetToken);
    $row = $authDb->execute("SELECT * FROM auth_tokens WHERE purpose = 'reset'")->fetch();
    same(hash('sha256', $resetToken), $row['token_hash']);
    check($row['expires_at'] <= time() + 1800);
    same(400, $client->request('/verify-email', 'POST', ['token' => $resetToken])['status']);
    $data = authData() + ['token' => $old];
    same(400, $client->request('/reset-password', 'POST', $data)['status']);
    same(1, (int) $authDb->execute("SELECT COUNT(*) FROM auth_tokens WHERE purpose = 'reset'")->fetchColumn());
});

test('Reset rejects expired tokens and confirmation errors without consuming the token', static function () use ($base, $authDb, &$resetToken): void {
    $client = new AuthBrowser($base);
    $data = authData() + ['token' => $resetToken];
    $data['password_confirmation'] = 'different';
    same(422, $client->request('/reset-password', 'POST', $data)['status']);
    $authDb->execute("UPDATE auth_tokens SET expires_at = ? WHERE purpose = 'reset'", [time() - 1]);
    $data = authData() + ['token' => $resetToken];
    same(400, $client->request('/reset-password', 'POST', $data)['status']);
    same(200, $client->request('/forgot-password', 'POST', ['email' => 'ada@example.test'])['status']);
    $resetToken = authToken($authDb, 'ada@example.test', 'reset');
});

test('Reset changes the password once and invalidates all existing sessions', static function () use ($base, $authDb, &$resetToken, &$authClient): void {
    $other = new AuthBrowser($base);
    same(303, $other->request('/login', 'POST', authData())['status']);
    same(200, $other->request('/account')['status']);
    $client = new AuthBrowser($base);
    same(200, $client->request('/reset-password?token=' . $resetToken)['status']);
    $data = authData() + ['token' => $resetToken];
    $data['password'] = $data['password_confirmation'] = 'A completely new password!';
    same(200, $client->request('/reset-password', 'POST', $data)['status']);
    same(200, $client->request('/reset-password?token=' . $resetToken)['status']);
    same(400, $client->request('/reset-password', 'POST', $data)['status']);
    same(303, $authClient->request('/account')['status']);
    same(303, $other->request('/account')['status']);
    same(422, $client->request('/login', 'POST', authData())['status']);
    same(303, $client->request('/login', 'POST', $data)['status']);
    same(200, $client->request('/account')['status']);
    $cookie = $client->cookie;
    same(303, $client->request('/logout', 'POST')['status']);
    check($cookie !== $client->cookie);
    same(303, $client->request('/account')['status']);
    same(0, (int) $authDb->execute("SELECT COUNT(*) FROM auth_tokens WHERE purpose = 'reset'")->fetchColumn());
});

test('Resend requires login and leaves only the latest verification link valid', static function () use ($base, $authDb): void {
    $authDb->execute('DELETE FROM auth_limits');
    $client = new AuthBrowser($base);
    same(303, $client->request('/email/verification-notification', 'POST')['status']);
    same(200, $client->request('/register', 'POST', authData('grace@example.test'))['status']);
    $old = authToken($authDb, 'grace@example.test', 'verify');
    same(303, $client->request('/login', 'POST', authData('grace@example.test'))['status']);
    same(200, $client->request('/email/verify')['status']);
    same(200, $client->request('/email/verification-notification', 'POST')['status']);
    $new = authToken($authDb, 'grace@example.test', 'verify');
    check($new !== $old);
    same(400, $client->request('/verify-email', 'POST', ['token' => $old])['status']);
    $authDb->execute("UPDATE auth_tokens SET expires_at = ? WHERE purpose = 'verify'", [time() - 1]);
    same(400, $client->request('/verify-email', 'POST', ['token' => $new])['status']);
    same(200, $client->request('/email/verification-notification', 'POST')['status']);
    $new = authToken($authDb, 'grace@example.test', 'verify');
    same(200, $client->request('/verify-email', 'POST', authData('grace@example.test') + ['token' => $new])['status']);
});

test('The guard rejects expired sessions, old versions, and deleted users', static function () use ($base, $authDb, $temp): void {
    foreach (['idle', 'absolute', 'version', 'deleted'] as $case) {
        $client = new AuthBrowser($base);
        same(303, $client->request('/login', 'POST', authData('grace@example.test'))['status']);
        same(200, $client->request('/account')['status']);
        $cookie = $client->cookie;
        if ($case === 'idle' || $case === 'absolute') {
            $id = substr($cookie, strlen('pbreeze_session='));
            check(preg_match('/^[a-zA-Z0-9,-]+$/D', $id) === 1);
            $file = $temp . '/http-sessions/sess_' . $id;
            $state = unserialize(file_get_contents($file), ['allowed_classes' => false]);
            check(is_array($state));
            $state['_auth'][$case === 'idle' ? 'seen' : 'issued'] = time() - ($case === 'idle' ? 1800 : 43200);
            file_put_contents($file, serialize($state));
        } elseif ($case === 'version') {
            $authDb->execute('UPDATE users SET session_version = session_version + 1 WHERE email = ?', ['grace@example.test']);
        } else {
            $authDb->execute('DELETE FROM users WHERE email = ?', ['grace@example.test']);
        }
        $response = $client->request('/account');
        same(303, $response['status']);
        check(in_array('Location: /login', $response['headers'], true));
        check($client->cookie !== $cookie);
    }
});

test('Rate limits persist across sessions and do not trust forged proxy headers', static function () use ($base, $authDb): void {
    $authDb->execute('DELETE FROM auth_limits');
    for ($i = 0; $i < 5; $i++) {
        $client = new AuthBrowser($base);
        same(200, $client->request('/forgot-password', 'POST', ['email' => 'notfound@example.test'])['status']);
    }
    $client = new AuthBrowser($base);
    $response = $client->request('/forgot-password', 'POST', ['email' => 'notfound@example.test'], ['X-Forwarded-For: 192.0.2.1']);
    same(429, $response['status']);
    check(preg_match('/Retry-After: [1-9][0-9]*/', implode("\n", $response['headers'])) === 1);
    $authDb->execute('DELETE FROM auth_limits');
    $data = authData('ratelimit@example.test');
    for ($i = 0; $i < 10; $i++) {
        same(422, $client->request('/login', 'POST', $data)['status']);
    }
    same(429, (new AuthBrowser($base))->request('/login', 'POST', $data)['status']);
    $authDb->execute('DELETE FROM auth_limits');
});

test('Verification defeats pre-registration hijacking and revokes attacker sessions and outstanding tokens', static function () use ($base, $authDb): void {
    $authDb->execute('DELETE FROM auth_limits');
    $attacker = new AuthBrowser($base);
    $owner = new AuthBrowser($base);
    $attackerData = authData('verification-owner@example.test');
    $attackerData['password'] = $attackerData['password_confirmation'] = 'Attacker chosen password!';
    $ownerData = authData('verification-owner@example.test');
    $ownerData['password'] = $ownerData['password_confirmation'] = 'Mailbox owner chosen password!';
    same(200, $attacker->request('/register', 'POST', $attackerData)['status']);
    same(200, $owner->request('/register', 'POST', $ownerData)['status']);
    same(303, $attacker->request('/login', 'POST', $attackerData)['status']);
    same(200, $attacker->request('/email/verify')['status']);
    $id = (int) $authDb->execute('SELECT id FROM users WHERE email = ?', [$ownerData['email']])->fetchColumn();
    $version = (int) $authDb->execute('SELECT session_version FROM users WHERE id = ?', [$id])->fetchColumn();
    $token = authToken($authDb, $ownerData['email'], 'verify');
    same(200, $owner->request('/forgot-password', 'POST', ['email' => $ownerData['email']])['status']);
    $reset = authToken($authDb, $ownerData['email'], 'reset');
    $legacyToken = 'pbz_' . bin2hex(random_bytes(32));
    $authDb->execute(
        'INSERT INTO api_tokens (user_id, name, token_hash, session_version, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$id, 'legacy', hash('sha256', $legacyToken), $version, time(), time() + 3600],
    );
    $path = '/verify-email?token=' . $token;
    $form = $owner->request($path);
    check(str_contains($form['body'], 'name="password"'));
    check(str_contains($form['body'], 'name="password_confirmation"'));
    check(str_contains($form['body'], 'replaces the registration password'));
    same(200, $owner->request($path, 'HEAD')['status']);
    $ownerCookie = $owner->cookie;
    $ownerCsrf = $owner->csrf;
    foreach ([
        [],
        ['password' => 'short', 'password_confirmation' => 'short'],
        ['password' => $ownerData['password'], 'password_confirmation' => 'different'],
        ['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)],
    ] as $invalid) {
        $response = $owner->request('/verify-email', 'POST', $invalid + ['token' => $token]);
        same(422, $response['status']);
        check(!str_contains($response['body'], 'value="' . $ownerData['password'] . '"'));
        same(null, $authDb->execute('SELECT email_verified_at FROM users WHERE id = ?', [$id])->fetchColumn());
        same(1, (int) $authDb->execute('SELECT COUNT(*) FROM auth_tokens WHERE token_hash = ?', [hash('sha256', $token)])->fetchColumn());
    }
    $response = $owner->request('/verify-email', 'POST', $ownerData + ['token' => $token]);
    same(200, $response['status']);
    check(str_contains($response['body'], 'Log in with your chosen password.'));
    check($owner->cookie !== $ownerCookie);
    same(200, $owner->request('/login')['status']);
    check($owner->csrf !== $ownerCsrf);
    $user = $authDb->execute('SELECT * FROM users WHERE id = ?', [$id])->fetch();
    check($user['email_verified_at'] !== null);
    check(password_verify($ownerData['password'], $user['password_hash']));
    check(!password_verify($attackerData['password'], $user['password_hash']));
    same($version + 1, (int) $user['session_version']);
    same(0, (int) $authDb->execute('SELECT COUNT(*) FROM auth_tokens WHERE user_id = ?', [$id])->fetchColumn());
    same(0, (int) $authDb->execute('SELECT COUNT(*) FROM api_tokens WHERE user_id = ?', [$id])->fetchColumn());
    $blocked = $attacker->request('/account');
    same(303, $blocked['status']);
    check(in_array('Location: /login', $blocked['headers'], true));
    same(422, $attacker->request('/login', 'POST', $attackerData)['status']);
    same(303, $owner->request('/account')['status']);
    same(303, $owner->request('/login', 'POST', $ownerData)['status']);
    same(200, $owner->request('/account')['status']);
    same(400, $owner->request('/verify-email', 'POST', $ownerData + ['token' => $token])['status']);
    same(400, $owner->request('/reset-password', 'POST', $attackerData + ['token' => $reset])['status']);
    same(401, http($base, '/api/me', 'GET', '', ['Authorization: Bearer ' . $legacyToken])['status']);
    $stale = bin2hex(random_bytes(32));
    $authDb->execute(
        'INSERT INTO auth_tokens (token_hash, user_id, purpose, expires_at) VALUES (?, ?, ?, ?)',
        [hash('sha256', $stale), $id, 'verify', time() + 3600],
    );
    same(400, $owner->request('/verify-email', 'POST', $attackerData + ['token' => $stale])['status']);
    same($user['password_hash'], $authDb->execute('SELECT password_hash FROM users WHERE id = ?', [$id])->fetchColumn());
    same(200, $owner->request('/account')['status']);
    $authDb->execute('DELETE FROM auth_tokens WHERE user_id = ?', [$id]);
    $authDb->execute('DELETE FROM auth_limits');
});

test('Mail queue failure rolls back registration and unsafe APP_URL is rejected', static function () use ($root): void {
    $config = [
        'name' => 'Test', 'environment' => 'testing', 'debug' => false, 'secure_cookie' => false,
        'url' => 'https://example.test', 'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'mail' => ['from' => 'noreply@example.test'],
    ];
    $app = new Application($root, $config);
    $migration = require $root . '/database/migrations/20261113160000_create_auth.php';
    $migration->up($app->db->pdo());
    raises(fn () => $app->auth->register(authData(), '192.0.2.3'), PDOException::class);
    same(0, (int) $app->db->execute('SELECT COUNT(*) FROM users')->fetchColumn());
    same(0, (int) $app->db->execute('SELECT COUNT(*) FROM auth_tokens')->fetchColumn());
    $mailMigration = require $root . '/database/migrations/20261113150000_create_emails.php';
    $mailMigration->up($app->db->pdo());
    foreach (['http://evil.test', 'https://user:pass@example.test', 'https://example.test/path', 'https://example.test?host=evil'] as $url) {
        $bad = new PBreeze\Auth($app->db, $app->session, $app->mail, $url);
        raises(fn () => $bad->register(authData(), '192.0.2.4'), RuntimeException::class);
        same(0, (int) $app->db->execute('SELECT COUNT(*) FROM users')->fetchColumn());
    }
    $production = new PBreeze\Auth($app->db, $app->session, $app->mail, 'http://localhost:8000', true);
    raises(fn () => $production->register(authData(), '192.0.2.5'), RuntimeException::class);
    same(0, (int) $app->db->execute('SELECT COUNT(*) FROM users')->fetchColumn());
    $app->auth->register(authData(), '192.0.2.6');
    $app->auth->forgotPassword(['email' => 'ada@example.test'], '192.0.2.6');
    $hash = $app->db->execute("SELECT token_hash FROM auth_tokens WHERE purpose = 'reset'")->fetchColumn();
    $mailMigration->down($app->db->pdo());
    raises(fn () => $app->auth->forgotPassword(['email' => 'ada@example.test'], '192.0.2.6'), PDOException::class);
    same($hash, $app->db->execute("SELECT token_hash FROM auth_tokens WHERE purpose = 'reset'")->fetchColumn());
    $migration->down($app->db->pdo());
});

function authMfaCode(string $secret, int $timestamp): string
{
    $bits = '';
    foreach (str_split($secret) as $character) {
        $bits .= sprintf('%05b', strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $character));
    }
    $key = '';
    foreach (str_split($bits, 8) as $byte) {
        $key .= chr(bindec($byte));
    }
    $digest = hash_hmac('sha1', pack('N2', 0, intdiv($timestamp, 30)), $key, true);
    $offset = ord(substr($digest, -1)) & 0x0f;
    $number = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;
    return sprintf('%06d', $number % 1000000);
}

test('TOTP matches RFC 6238 SHA-1 vectors, adjacent windows, and strict input boundaries', static function (): void {
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924',
        2000000000 => '279037', 20000000000 => '353130'] as $timestamp => $code) {
        $counter = intdiv($timestamp, 30);
        same($counter, PBreeze\Totp::matchCounter($secret, $code, $timestamp));
        same($counter, PBreeze\Totp::matchCounter($secret, $code, $timestamp + 30));
        same($counter, PBreeze\Totp::matchCounter($secret, $code, $timestamp - 30));
        same(null, PBreeze\Totp::matchCounter($secret, $code, $timestamp + 60));
    }
    foreach (['', '81804', '081804 ', '081804' . "\n", 'abcdef', '1234567'] as $invalid) {
        same(null, PBreeze\Totp::matchCounter($secret, $invalid, 1111111109));
    }
    raises(fn () => PBreeze\Totp::matchCounter('invalid', '123456', 59), InvalidArgumentException::class);
    raises(fn () => PBreeze\Totp::matchCounter($secret, '123456', -1), InvalidArgumentException::class);
});

test('MFA setup and verification fail explicitly when encryption keys are missing or ciphertext is damaged', static function () use ($root): void {
    $savedSession = $_SESSION;
    try {
        foreach (['', PBreeze\Encryption::generateKey()] as $key) {
            $app = new Application($root, [
                'name' => 'MFA test', 'environment' => 'testing', 'debug' => false, 'secure_cookie' => false, 'key' => $key,
                'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ]);
            foreach (['20261113160000_create_auth.php', '20261116220000_create_auth_mfa.php'] as $file) {
                (require $root . '/database/migrations/' . $file)->up($app->db->pdo());
            }
            $data = authData();
            $id = (int) $app->db->insert('users', [
                'name' => $data['name'], 'email' => $data['email'], 'created_at' => time(), 'email_verified_at' => time(),
                'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            ]);
            if ($key === '') {
                $_SESSION = ['_auth' => ['id' => $id, 'version' => 1, 'issued' => time(), 'seen' => time()]];
                raises(fn () => $app->auth->beginMfaSetup($data['password'], '192.0.2.1'), PBreeze\EncryptionException::class);
                same(null, $app->session->get('_auth_mfa_setup'));
                same(0, (int) $app->db->execute('SELECT COUNT(*) FROM auth_mfa')->fetchColumn());
            } else {
                $app->db->execute('INSERT INTO auth_mfa (user_id, secret, last_counter) VALUES (?, ?, ?)', [$id, 'damaged', -1]);
                $_SESSION = ['_auth_mfa_pending' => ['id' => $id, 'version' => 1, 'issued' => time()]];
                raises(fn () => $app->auth->completeMfaLogin(['code' => '123456'], '192.0.2.1'), PBreeze\EncryptionException::class);
                same(null, $app->auth->user());
                same(-1, (int) $app->db->execute('SELECT last_counter FROM auth_mfa')->fetchColumn());
            }
        }
    } finally {
        $_SESSION = $savedSession;
    }
});

test('MFA enrollment requires a verified session, password, and code, and revokes older sessions and API tokens', static function () use ($base, $authDb, $temp, &$mfaFixture): void {
    $authDb->execute('DELETE FROM auth_limits');
    $guest = new AuthBrowser($base);
    same(303, $guest->request('/account/mfa')['status']);
    same(303, $guest->request('/account/mfa/setup', 'POST', ['password' => authData()['password']])['status']);
    $data = authData('mfa@example.test');
    $id = (int) $authDb->insert('users', [
        'name' => 'MFA User', 'email' => $data['email'], 'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
        'email_verified_at' => null, 'created_at' => time(),
    ]);
    $client = new AuthBrowser($base);
    $other = new AuthBrowser($base);
    same(303, $client->request('/login', 'POST', $data)['status']);
    $unverified = $client->request('/account/mfa');
    same(303, $unverified['status']);
    check(in_array('Location: /email/verify', $unverified['headers'], true));
    same(200, $client->request('/email/verify')['status']);
    same(303, $client->request('/account/mfa/setup', 'POST', ['password' => $data['password']])['status']);
    $authDb->execute('UPDATE users SET email_verified_at = ? WHERE id = ?', [time(), $id]);
    same(303, $other->request('/login', 'POST', $data)['status']);
    $token = (new PBreeze\ApiTokens($authDb))->create($id);
    same(200, $client->request('/account/mfa')['status']);
    same(422, $client->request('/account/mfa/setup', 'POST', ['password' => 'wrong'])['status']);
    same(400, $client->request('/account/mfa/confirm', 'POST', ['code' => '123456'])['status']);
    $setup = $client->request('/account/mfa/setup', 'POST', ['password' => $data['password']]);
    same(200, $setup['status']);
    check(str_contains($setup['body'], 'name="code"') && str_contains($setup['body'], 'autocomplete="one-time-code"'));
    check(preg_match('/<code>([A-Z2-7]{32})<\/code>/', $setup['body'], $match) === 1);
    $secret = $match[1];
    check(str_contains($setup['body'], 'otpauth://totp/auth.example.test%3Amfa%40example.test?'));
    check(preg_match('/<div class="mfa-qr">(<svg.*?<\/svg>)<\/div>/s', $setup['body'], $qr) === 1);
    check(preg_match('/href="(otpauth:[^"]+)"/', $setup['body'], $uri) === 1);
    same(PBreeze\QrCode::svg(html_entity_decode($uri[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')), $qr[1]);
    check(!str_contains($qr[1], $secret));
    check(in_array('Cache-Control: no-store', $setup['headers'], true));
    $csp = implode("\n", $setup['headers']);
    check(str_contains($csp, "img-src 'self'") && str_contains($csp, "script-src 'none'"));
    $file = $temp . '/http-sessions/sess_' . substr($client->cookie, strlen('pbreeze_session='));
    $state = unserialize(file_get_contents($file), ['allowed_classes' => false]);
    check(!str_contains(file_get_contents($file), $secret));
    check(str_starts_with($state['_auth_mfa_setup']['secret'], 'pbreeze:v1:'));
    $state['_auth_mfa_setup']['issued'] = time() - 600;
    file_put_contents($file, serialize($state));
    same(400, $client->request('/account/mfa/confirm', 'POST', ['code' => authMfaCode($secret, time())])['status']);
    same(0, (int) $authDb->execute('SELECT COUNT(*) FROM auth_mfa WHERE user_id = ?', [$id])->fetchColumn());
    $setup = $client->request('/account/mfa/setup', 'POST', ['password' => $data['password']]);
    same(200, $setup['status']);
    check(preg_match('/<code>([A-Z2-7]{32})<\/code>/', $setup['body'], $match) === 1);
    check($secret !== $match[1]);
    $secret = $match[1];
    same(422, $client->request('/account/mfa/confirm', 'POST', ['code' => ['invalid']])['status']);
    $response = $client->request('/account/mfa/confirm', 'POST', ['code' => authMfaCode($secret, time())]);
    same(200, $response['status']);
    check(preg_match_all('/<code>([a-f0-9]{20})<\/code>/', $response['body'], $codes) === 10);
    $encrypted = $authDb->execute('SELECT secret FROM auth_mfa WHERE user_id = ?', [$id])->fetchColumn();
    check(str_starts_with($encrypted, 'pbreeze:v1:') && !str_contains($encrypted, $secret));
    $stored = $authDb->execute('SELECT code_hash FROM auth_mfa_recovery WHERE user_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN);
    same(10, count($stored));
    check(in_array(hash('sha256', $codes[1][0]), $stored, true));
    check(!in_array($codes[1][0], $stored, true));
    same(200, $client->request('/account')['status']);
    $settings = $client->request('/account/mfa');
    check(str_contains($settings['body'], 'Disable multi-factor authentication'));
    check(str_contains($settings['body'], 'name="password"') && str_contains($settings['body'], 'name="code"'));
    check(!str_contains($settings['body'], $secret) && !str_contains($settings['body'], $codes[1][0]));
    check(!str_contains($settings['body'], 'class="mfa-qr"'));
    same(303, $other->request('/account')['status']);
    same(401, http($base, '/api/me', 'GET', '', ['Authorization: Bearer ' . $token['token']])['status']);
    same(400, $client->request('/account/mfa/confirm', 'POST', ['code' => authMfaCode($secret, time())])['status']);
    $mfaFixture = ['id' => $id, 'data' => $data, 'secret' => $secret, 'codes' => $codes[1]];
    $authDb->execute('DELETE FROM auth_limits');
});

test('MFA login isolates pending sessions, rejects replay, consumes recovery codes once, and rotates identity', static function () use ($base, $authDb, &$mfaFixture): void {
    ['id' => $id, 'data' => $data, 'secret' => $secret, 'codes' => $codes] = $mfaFixture;
    $first = new AuthBrowser($base);
    $second = new AuthBrowser($base);
    $cookie = $first->cookie;
    $login = $first->request('/login', 'POST', $data);
    same(303, $login['status']);
    check(in_array('Location: /login/mfa', $login['headers'], true));
    check($cookie !== $first->cookie);
    same(303, $first->request('/account')['status']);
    same(303, $first->request('/account/mfa')['status']);
    $form = $first->request('/login/mfa');
    same(200, $form['status']);
    check(str_contains($form['body'], 'name="code"') && str_contains($form['body'], 'maxlength="20"'));
    check(!str_contains($form['body'], $secret));
    $enrollmentCounter = (int) $authDb->execute('SELECT last_counter FROM auth_mfa WHERE user_id = ?', [$id])->fetchColumn();
    same(422, $first->request('/login/mfa', 'POST', ['code' => authMfaCode($secret, $enrollmentCounter * 30)])['status']);
    same(303, $second->request('/login', 'POST', $data)['status']);
    same(200, $second->request('/login/mfa')['status']);
    $cookie = $first->cookie;
    $csrf = $first->csrf;
    same(303, $first->request('/login/mfa', 'POST', ['code' => $codes[0]])['status']);
    check($cookie !== $first->cookie);
    same(200, $first->request('/account')['status']);
    check($csrf !== $first->csrf);
    same(419, $first->request('/logout', 'POST', ['_token' => $csrf])['status']);
    same(401, $first->request('/login/mfa', 'POST', ['code' => $codes[1]])['status']);
    same(422, $second->request('/login/mfa', 'POST', ['code' => $codes[0]])['status']);
    same(9, (int) $authDb->execute('SELECT COUNT(*) FROM auth_mfa_recovery WHERE user_id = ?', [$id])->fetchColumn());
    $authDb->execute('DELETE FROM auth_limits');
    $freshTime = time() + 30;
    $freshCode = authMfaCode($secret, $freshTime);
    same(303, $second->request('/login/mfa', 'POST', ['code' => $freshCode])['status']);
    same(200, $second->request('/account')['status']);
    $third = new AuthBrowser($base);
    same(303, $third->request('/login', 'POST', $data)['status']);
    same(200, $third->request('/login/mfa')['status']);
    same(422, $third->request('/login/mfa', 'POST', ['code' => $freshCode])['status']);
    same(303, $third->request('/logout', 'POST')['status']);
    same(200, $third->request('/login')['status']);
    same(401, $third->request('/login/mfa', 'POST', ['code' => $codes[1]])['status']);
    $authDb->execute('DELETE FROM auth_limits');
});

test('Expired and revoked MFA challenges fail and MFA attempts are limited across browser sessions', static function () use ($base, $authDb, $temp, &$mfaFixture): void {
    ['id' => $id, 'data' => $data, 'codes' => $codes] = $mfaFixture;
    $client = new AuthBrowser($base);
    same(303, $client->request('/login', 'POST', $data)['status']);
    same(200, $client->request('/login/mfa')['status']);
    $file = $temp . '/http-sessions/sess_' . substr($client->cookie, strlen('pbreeze_session='));
    $state = unserialize(file_get_contents($file), ['allowed_classes' => false]);
    $state['_auth_mfa_pending']['issued'] = time() - 300;
    file_put_contents($file, serialize($state));
    same(401, $client->request('/login/mfa', 'POST', ['code' => $codes[1]])['status']);
    same(303, $client->request('/login', 'POST', $data)['status']);
    same(200, $client->request('/login/mfa')['status']);
    $authDb->execute('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
    same(401, $client->request('/login/mfa', 'POST', ['code' => $codes[1]])['status']);
    same(200, $client->request('/login')['status']);
    $authDb->execute('DELETE FROM auth_limits');
    for ($i = 0; $i < 5; $i++) {
        $client = new AuthBrowser($base);
        same(303, $client->request('/login', 'POST', $data)['status']);
        same(200, $client->request('/login/mfa')['status']);
        same(422, $client->request('/login/mfa', 'POST', ['code' => 'invalid'])['status']);
    }
    $response = $client->request('/login/mfa', 'POST', ['code' => $codes[1]]);
    same(429, $response['status']);
    check(preg_match('/Retry-After: [1-9][0-9]*/', implode("\n", $response['headers'])) === 1);
    $authDb->execute('DELETE FROM auth_limits');
});

test('Password reset preserves MFA and disabling requires both factors and revokes existing sessions', static function () use ($base, $authDb, &$mfaFixture): void {
    ['id' => $id, 'data' => $data, 'codes' => $codes] = $mfaFixture;
    $pending = new AuthBrowser($base);
    same(303, $pending->request('/login', 'POST', $data)['status']);
    same(200, $pending->request('/login/mfa')['status']);
    $client = new AuthBrowser($base);
    same(200, $client->request('/forgot-password', 'POST', ['email' => $data['email']])['status']);
    $token = authToken($authDb, $data['email'], 'reset');
    same(200, $client->request('/reset-password', 'POST', $data + ['token' => $token])['status']);
    same(401, $pending->request('/login/mfa', 'POST', ['code' => $codes[1]])['status']);
    same(303, $client->request('/login', 'POST', $data)['status']);
    same(200, $client->request('/login/mfa')['status']);
    same(303, $client->request('/login/mfa', 'POST', ['code' => $codes[1]])['status']);
    same(200, $client->request('/account/mfa')['status']);
    same(422, $client->request('/account/mfa/disable', 'POST', ['password' => 'wrong', 'code' => $codes[2]])['status']);
    same(422, $client->request('/account/mfa/disable', 'POST', ['password' => $data['password'], 'code' => 'invalid'])['status']);
    same(1, (int) $authDb->execute('SELECT COUNT(*) FROM auth_mfa WHERE user_id = ?', [$id])->fetchColumn());
    $other = new AuthBrowser($base);
    same(303, $other->request('/login', 'POST', $data)['status']);
    same(200, $other->request('/login/mfa')['status']);
    same(303, $other->request('/login/mfa', 'POST', ['code' => $codes[2]])['status']);
    same(200, $other->request('/account')['status']);
    same(303, $client->request('/account/mfa/disable', 'POST', ['password' => $data['password'], 'code' => $codes[3]])['status']);
    same(0, (int) $authDb->execute('SELECT COUNT(*) FROM auth_mfa WHERE user_id = ?', [$id])->fetchColumn());
    same(0, (int) $authDb->execute('SELECT COUNT(*) FROM auth_mfa_recovery WHERE user_id = ?', [$id])->fetchColumn());
    same(200, $client->request('/account')['status']);
    same(303, $other->request('/account')['status']);
    same(303, $client->request('/logout', 'POST')['status']);
    same(200, $client->request('/login')['status']);
    $response = $client->request('/login', 'POST', $data);
    check(in_array('Location: /account', $response['headers'], true));
    same(200, $client->request('/account')['status']);
    $authDb->execute('DELETE FROM users WHERE id = ?', [$id]);
    $authDb->execute('DELETE FROM auth_limits');
    $mfaFixture = null;
});

$authDb = null;
