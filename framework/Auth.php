<?php
declare(strict_types=1);

namespace PBreeze;

use PBreeze\Mail\Mailer;
use PDOException;
use RuntimeException;

final class AuthException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors, public readonly int $status = 422, public readonly int $retryAfter = 0)
    {
        parent::__construct('Authentication request rejected.');
    }
}

final class Auth
{
    private const DUMMY_HASH = '$2y$12$YMP.MQkPJkcdb88aANF7PO19Iy.qHE776ziQIClMRwmRTwUJq/Hn6';

    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        private readonly Mailer $mail,
        private readonly string $url,
        private readonly bool $production = false,
    ) {
    }

    /** @return array{id: int, name: string, email: string, email_verified_at: ?int}|null */
    public function user(): ?array
    {
        $state = $this->session->get('_auth');
        if (!is_array($state) || !isset($state['id'], $state['version'], $state['issued'], $state['seen'])) {
            return null;
        }
        if ($state['issued'] <= time() - 43200 || $state['seen'] <= time() - 1800) {
            $this->logout();
            return null;
        }
        $user = $this->db->execute(
            'SELECT id, name, email, email_verified_at, session_version FROM users WHERE id = ?', [$state['id']],
        )->fetch();
        if ($user === false || (int) $user['session_version'] !== $state['version']) {
            $this->logout();
            return null;
        }
        $state['seen'] = time();
        $this->session->put('_auth', $state);
        return [
            'id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'],
            'email_verified_at' => $user['email_verified_at'] === null ? null : (int) $user['email_verified_at'],
        ];
    }

    public function protect(callable $handler, bool $verified = true): \Closure
    {
        return function (Request $request, string ...$parameters) use ($handler, $verified): Response {
            $user = $this->user();
            if ($user === null) {
                return Response::redirect('/login');
            }
            if ($verified && $user['email_verified_at'] === null) {
                return Response::redirect('/email/verify');
            }
            return $handler($request, ...$parameters);
        };
    }

    /** @param array<string, mixed> $data */
    public function register(#[\SensitiveParameter] array $data, string $ip): void
    {
        $this->limit('register-ip', $ip, 5, 3600);
        $data = $this->credentials($data, true);
        $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->transaction(function () use ($data, $hash): void {
            // The unique email index also handles concurrent registrations.
            try {
                if ($this->db->driver() === 'pgsql') {
                    // A uniqueness exception would abort PostgreSQL's entire transaction.
                    $statement = $this->db->execute(
                        'INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)
                         ON CONFLICT (email) DO NOTHING RETURNING id',
                        [$data['name'], $data['email'], $hash, time()],
                    );
                    $id = $statement->fetchColumn();
                    $statement->closeCursor();
                    if ($id === false) {
                        return;
                    }
                } else {
                    $id = $this->db->insert('users', [
                        'name' => $data['name'], 'email' => $data['email'],
                        'password_hash' => $hash, 'created_at' => time(),
                    ]);
                }
            } catch (PDOException $error) {
                if ($this->db->driver() !== 'pgsql' && in_array((string) $error->getCode(), ['23000', '23505'], true)
                    && $this->db->execute('SELECT id FROM users WHERE email = ?', [$data['email']])->fetchColumn() !== false) {
                    return;
                }
                throw $error;
            }
            $this->issueToken((int) $id, $data['email'], 'verify');
        });
    }

    /** @param array<string, mixed> $data */
    public function login(#[\SensitiveParameter] array $data, string $ip): void
    {
        $this->limit('login-ip', $ip, 40, 900);
        $data = $this->credentials($data);
        $this->limit('login-email', $data['email'], 10, 900);
        $user = $this->db->execute('SELECT * FROM users WHERE email = ?', [$data['email']])->fetch();
        $valid = password_verify($data['password'], $user === false ? self::DUMMY_HASH : $user['password_hash']);
        if (!$valid || $user === false) {
            throw new AuthException(['email' => ['The email address or password is incorrect.']]);
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ? AND password_hash = ?', [
                password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]), $user['id'], $user['password_hash'],
            ]);
        }
        $this->session->reset();
        $this->session->put('_auth', [
            'id' => (int) $user['id'], 'version' => (int) $user['session_version'],
            'issued' => time(), 'seen' => time(),
        ]);
    }

    public function logout(): void
    {
        $this->session->reset();
    }

    /** @param array<string, mixed> $data */
    public function forgotPassword(#[\SensitiveParameter] array $data, string $ip): void
    {
        $this->limit('forgot-ip', $ip, 20, 3600);
        $email = self::email($data['email'] ?? null);
        $this->limit('forgot-email', $email, 5, 3600);
        $this->db->transaction(function () use ($email): void {
            $user = $this->db->execute('SELECT id FROM users WHERE email = ?', [$email])->fetch();
            if ($user !== false) {
                $this->issueToken((int) $user['id'], $email, 'reset');
            }
        });
    }

    public function resendVerification(string $ip): void
    {
        $user = $this->user();
        if ($user === null) {
            throw new HttpException(401, 'Log in first.');
        }
        $this->limit('verify-ip', $ip, 20, 3600);
        $this->limit('verify-user', (string) $user['id'], 5, 3600);
        if ($user['email_verified_at'] === null) {
            $this->db->transaction(fn () => $this->issueToken($user['id'], $user['email'], 'verify'));
        }
    }

    public function verifyEmail(#[\SensitiveParameter] mixed $token, string $ip): void
    {
        $this->limit('verify-token-ip', $ip, 30, 900);
        $this->db->transaction(function () use ($token): void {
            $id = $this->consumeToken($token, 'verify');
            $this->db->execute('UPDATE users SET email_verified_at = ? WHERE id = ? AND email_verified_at IS NULL', [time(), $id]);
        });
    }

    /** @param array<string, mixed> $data */
    public function resetPassword(#[\SensitiveParameter] array $data, string $ip): void
    {
        $this->limit('reset-ip', $ip, 20, 3600);
        self::password($data);
        $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->transaction(function () use ($data, $hash): void {
            $id = $this->consumeToken($data['token'] ?? null, 'reset');
            $this->db->execute('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [$hash, $id]);
        });
        $this->logout();
    }

    private function issueToken(int $id, string $email, string $purpose): void
    {
        $token = bin2hex(random_bytes(32));
        $this->db->execute('DELETE FROM auth_tokens WHERE (user_id = ? AND purpose = ?) OR expires_at <= ?', [$id, $purpose, time()]);
        $this->db->execute('INSERT INTO auth_tokens (token_hash, user_id, purpose, expires_at) VALUES (?, ?, ?, ?)', [
            hash('sha256', $token), $id, $purpose, time() + ($purpose === 'reset' ? 1800 : 86400),
        ]);
        $path = $purpose === 'reset' ? '/reset-password' : '/verify-email';
        $link = $this->baseUrl() . $path . '?token=' . $token;
        $subject = $purpose === 'reset' ? 'Reset your password' : 'Verify your email address';
        $duration = $purpose === 'reset' ? '30 minutes' : '24 hours';
        $this->mail->queue($this->mail->compose([
            'to' => [$email], 'subject' => $subject,
            'text' => "$subject\n\nOpen this link:\n$link\n\nThis link is valid for $duration and can only be used once. "
                . 'If you did not request this, ignore this email.',
        ]));
    }

    private function consumeToken(#[\SensitiveParameter] mixed $token, string $purpose): int
    {
        if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new AuthException(['token' => ['This link is invalid or has expired. Request a new link.']], 400);
        }
        $hash = hash('sha256', $token);
        $row = $this->db->execute(
            'SELECT user_id FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND expires_at > ?',
            [$hash, $purpose, time()],
        )->fetch();
        if ($row === false || $this->db->execute(
            'DELETE FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND expires_at > ?', [$hash, $purpose, time()],
        )->rowCount() !== 1) {
            throw new AuthException(['token' => ['This link is invalid or has expired. Request a new link.']], 400);
        }
        return (int) $row['user_id'];
    }

    private function limit(string $action, string $identity, int $maximum, int $seconds): void
    {
        $end = (intdiv(time(), $seconds) + 1) * $seconds;
        $key = hash('sha256', "$action|$identity|$end");
        $this->db->execute('DELETE FROM auth_limits WHERE expires_at <= ?', [time()]);
        $this->db->ensureRateLimit('auth_limits', $key, $end);
        if ($this->db->execute('UPDATE auth_limits SET attempts = attempts + 1 WHERE bucket = ? AND attempts < ?', [$key, $maximum])->rowCount() !== 1) {
            throw new AuthException(['form' => ['Too many attempts. Try again after the rate limit window expires.']], 429, max(1, $end - time()));
        }
    }

    /** @param array<string, mixed> $data
     *  @return array{name: string, email: string, password: string}
     */
    private function credentials(#[\SensitiveParameter] array $data, bool $register = false): array
    {
        $email = self::email($data['email'] ?? null);
        if ($register) {
            if (is_string($data['name'] ?? null)) {
                $data['name'] = trim($data['name']);
            }
            $errors = Validator::errors($data, ['name' => 'required|string|min:2|max:80']);
            if ($errors !== []) {
                throw new AuthException($errors);
            }
            self::password($data);
        } elseif (!is_string($data['password'] ?? null) || $data['password'] === '' || strlen($data['password']) > 72 || str_contains($data['password'], "\0")) {
            throw new AuthException(['password' => ['Enter a valid password (up to 72 bytes).']]);
        }
        return ['name' => $register ? trim($data['name']) : '', 'email' => $email, 'password' => $data['password']];
    }

    private static function email(mixed $email): string
    {
        if (!is_string($email) || strlen($email) > 254 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)
            || preg_match('/[^\x21-\x7e]/', trim($email))) {
            throw new AuthException(['email' => ['Enter a valid email address.']]);
        }
        return strtolower(trim($email));
    }

    /** @param array<string, mixed> $data */
    private static function password(#[\SensitiveParameter] array $data): void
    {
        $errors = Validator::errors($data, ['password' => 'required|string|min:12|confirmed']);
        if (is_string($data['password'] ?? null) && (strlen($data['password']) > 72 || str_contains($data['password'], "\0"))) {
            $errors['password'][] = 'Use no more than 72 bytes, without NUL characters.';
        }
        if ($errors !== []) {
            throw new AuthException($errors);
        }
    }

    private function baseUrl(): string
    {
        $parts = parse_url($this->url);
        if ($parts === false || !filter_var($this->url, FILTER_VALIDATE_URL) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)
            || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || ($parts['scheme'] === 'http' && ($this->production || !in_array($parts['host'], ['127.0.0.1', 'localhost', '[::1]'], true)))) {
            throw new RuntimeException('APP_URL must be an HTTPS origin; HTTP on localhost is allowed only for local development.');
        }
        return rtrim($this->url, '/');
    }
}
