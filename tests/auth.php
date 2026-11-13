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
    foreach (['/register', '/login', '/logout', '/forgot-password', '/reset-password', '/verify-email', '/email/verification-notification'] as $path) {
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
    same(200, $client->request('/verify-email', 'POST', ['token' => $verifyToken])['status']);
    same(400, $client->request('/verify-email', 'POST', ['token' => $verifyToken])['status']);
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
    same(200, $client->request('/verify-email', 'POST', ['token' => $new])['status']);
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

$authDb = null;
