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

    /** @var (\Closure('reset'|'verify', string, int): array{subject: string, text: string, html: string})|null */
    private readonly ?\Closure $emailComposer;

    /** @param (callable('reset'|'verify', string, int): array{subject: string, text: string, html: string})|null $emailComposer */
    public function __construct(
        private readonly Database $db,
        private readonly Session $session,
        private readonly Mailer $mail,
        private readonly string $url,
        private readonly bool $production = false,
        ?callable $emailComposer = null,
        private readonly bool $mfaEnrollmentEnabled = true,
        private readonly bool $mfaEnforcementEnabled = true,
    ) {
        $this->emailComposer = $emailComposer === null ? null : \Closure::fromCallable($emailComposer);
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

    /**
     * @param array<string, mixed> $data
     * @param (callable(int, Database): void)|null $onCreated Runs before commit, only for a new user.
     */
    public function register(#[\SensitiveParameter] array $data, string $ip, ?callable $onCreated = null): void
    {
        $this->limit('register-ip', $ip, 5, 3600);
        $data = $this->credentials($data, true);
        $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->transaction(function () use ($data, $hash, $onCreated): void {
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
            if ($onCreated !== null) {
                $onCreated((int) $id, $this->db);
            }
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
        $this->finishLogin((int) $user['id'], (int) $user['session_version']);
    }

    /** @param array<string, mixed> $query */
    public function loginWithOidc(Oidc $oidc, #[\SensitiveParameter] array $query, string $ip): void
    {
        if (!$oidc->enabled()) {
            throw new HttpException(404, 'Single sign-on is not enabled.');
        }
        $this->limit('sso-ip', $ip, 40, 900);
        $identity = $oidc->complete($query);
        $hash = hash('sha256', json_encode([$identity['issuer'], $identity['subject']], JSON_THROW_ON_ERROR));
        $find = fn () => $this->db->execute(
            'SELECT u.id, u.session_version FROM users u JOIN auth_identities i ON i.user_id = u.id WHERE i.identity_hash = ?',
            [$hash],
        )->fetch();
        $user = $find();
        if ($user === false) {
            if ($identity['email'] === null) {
                throw new AuthException(['sso' => ['This identity needs an administrator-linked account or a verified provider email.']], 403);
            }
            $conflict = static fn (): AuthException => new AuthException([
                'sso' => ['This account cannot be created through single sign-on. Use local login or contact your administrator.'],
            ], 403);
            if ($this->db->execute('SELECT id FROM users WHERE email = ?', [$identity['email']])->fetchColumn() !== false) {
                throw $conflict();
            }
            try {
                $user = $this->db->transaction(function () use ($identity, $hash): array {
                    $id = $this->db->insert('users', [
                        'name' => $identity['name'] ?? 'SSO user', 'email' => $identity['email'],
                        'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]),
                        'email_verified_at' => time(), 'created_at' => time(),
                    ]);
                    $this->db->insert('auth_identities', [
                        'identity_hash' => $hash, 'issuer' => $identity['issuer'],
                        'subject' => $identity['subject'], 'user_id' => $id,
                    ], null);
                    return ['id' => (int) $id, 'session_version' => 1];
                });
            } catch (PDOException $error) {
                if (!in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                    throw $error;
                }
                $user = $find();
                if ($user === false) {
                    if ($this->db->execute('SELECT id FROM users WHERE email = ?', [$identity['email']])->fetchColumn() !== false) {
                        throw $conflict();
                    }
                    throw $error;
                }
            }
        }
        $this->finishLogin((int) $user['id'], (int) $user['session_version']);
    }

    private function finishLogin(int $id, int $version): void
    {
        if ($this->mfaEnforcementEnabled
            && $this->db->execute('SELECT user_id FROM auth_mfa WHERE user_id = ?', [$id])->fetchColumn() !== false) {
            $this->session->reset();
            $this->session->put('_auth_mfa_pending', [
                'id' => $id, 'version' => $version, 'issued' => time(),
            ]);
            return;
        }
        $this->establishSession($id, $version);
    }

    private function establishSession(int $id, int $version): void
    {
        $this->session->reset();
        $this->session->put('_auth', [
            'id' => $id, 'version' => $version,
            'issued' => time(), 'seen' => time(),
        ]);
    }

    public function mfaPending(): bool
    {
        $pending = $this->session->get('_auth_mfa_pending');
        return is_array($pending) && isset($pending['id'], $pending['version'], $pending['issued'])
            && is_int($pending['id']) && is_int($pending['version']) && is_int($pending['issued'])
            && $pending['issued'] > time() - 300 && $pending['issued'] <= time();
    }

    /** @param array<string, mixed> $data */
    public function completeMfaLogin(#[\SensitiveParameter] array $data, string $ip): void
    {
        $this->limit('mfa-ip', $ip, 30, 300);
        if (!$this->mfaPending()) {
            $this->session->put('_auth_mfa_pending', null);
            throw new AuthException(['code' => ['Your login challenge has expired. Log in again.']], 401);
        }
        $pending = $this->session->get('_auth_mfa_pending');
        $this->limit('mfa-user', (string) $pending['id'], 5, 300);
        $this->db->transaction(function () use ($pending, $data): void {
            $version = $this->db->execute('SELECT session_version FROM users WHERE id = ?', [$pending['id']])->fetchColumn();
            if ($version === false || (int) $version !== $pending['version']) {
                $this->session->put('_auth_mfa_pending', null);
                throw new AuthException(['code' => ['Your login challenge has expired. Log in again.']], 401);
            }
            $this->consumeMfaCode($pending['id'], $data['code'] ?? null);
        });
        $this->establishSession($pending['id'], $pending['version']);
    }

    public function mfaEnabled(): bool
    {
        $user = $this->mfaUser();
        return $this->db->execute('SELECT user_id FROM auth_mfa WHERE user_id = ?', [$user['id']])->fetchColumn() !== false;
    }

    /** @return array{secret: string, uri: string} */
    public function beginMfaSetup(#[\SensitiveParameter] mixed $password, string $ip): array
    {
        $user = $this->mfaUser();
        $this->checkMfaEnrollment();
        $this->checkMfaPassword($user['id'], $password, $ip);
        if ($this->mfaEnabled()) {
            throw new AuthException(['form' => ['Multi-factor authentication is already enabled.']]);
        }
        $secret = Totp::generateSecret();
        $state = $this->session->get('_auth');
        $this->session->put('_auth_mfa_setup', [
            'id' => $user['id'], 'version' => $state['version'], 'issued' => time(),
            'secret' => $this->db->encryption->encrypt($secret, 'auth:mfa:' . $user['id']),
        ]);
        $issuer = parse_url($this->baseUrl(), PHP_URL_HOST);
        return [
            'secret' => $secret,
            'uri' => 'otpauth://totp/' . rawurlencode($issuer . ':' . $user['email']) . '?' . http_build_query([
                'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30,
            ], '', '&', PHP_QUERY_RFC3986),
        ];
    }

    /** @return list<string> */
    public function confirmMfaSetup(#[\SensitiveParameter] mixed $code, string $ip): array
    {
        $user = $this->mfaUser();
        $this->checkMfaEnrollment();
        $this->limit('mfa-ip', $ip, 30, 300);
        $this->limit('mfa-user', (string) $user['id'], 5, 300);
        $setup = $this->session->get('_auth_mfa_setup');
        $state = $this->session->get('_auth');
        if (!is_array($setup) || ($setup['id'] ?? null) !== $user['id']
            || ($setup['version'] ?? null) !== $state['version'] || ($setup['issued'] ?? 0) <= time() - 600) {
            throw new AuthException(['code' => ['Authenticator setup has expired. Start setup again.']], 400);
        }
        $secret = $this->db->encryption->decrypt($setup['secret'], 'auth:mfa:' . $user['id']);
        $counter = is_string($code) ? Totp::matchCounter($secret, $code, time()) : null;
        if ($counter === null) {
            throw new AuthException(['code' => ['Enter a valid six-digit authenticator code.']]);
        }
        $codes = $this->db->transaction(function () use ($user, $state, $setup, $counter): array {
            $this->advanceMfaVersion($user['id'], $state['version']);
            $this->db->execute('INSERT INTO auth_mfa (user_id, secret, last_counter) VALUES (?, ?, ?)', [
                $user['id'], $setup['secret'], $counter,
            ]);
            return $this->createMfaRecoveryCodes($user['id']);
        });
        $this->establishSession($user['id'], $state['version'] + 1);
        return $codes;
    }

    /** @return list<string> */
    public function regenerateMfaRecoveryCodes(#[\SensitiveParameter] mixed $password, #[\SensitiveParameter] mixed $code, string $ip): array
    {
        $user = $this->mfaUser();
        $this->checkMfaPassword($user['id'], $password, $ip);
        $this->limit('mfa-ip', $ip, 30, 300);
        $this->limit('mfa-user', (string) $user['id'], 5, 300);
        $state = $this->session->get('_auth');
        $codes = $this->db->transaction(function () use ($user, $state, $code): array {
            $this->advanceMfaVersion($user['id'], $state['version']);
            $this->consumeMfaCode($user['id'], $code);
            $this->db->execute('DELETE FROM auth_mfa_recovery WHERE user_id = ?', [$user['id']])->closeCursor();
            return $this->createMfaRecoveryCodes($user['id']);
        });
        $this->establishSession($user['id'], $state['version'] + 1);
        return $codes;
    }

    public function disableMfa(#[\SensitiveParameter] mixed $password, #[\SensitiveParameter] mixed $code, string $ip): void
    {
        $user = $this->mfaUser();
        $this->checkMfaPassword($user['id'], $password, $ip);
        $this->limit('mfa-ip', $ip, 30, 300);
        $this->limit('mfa-user', (string) $user['id'], 5, 300);
        $state = $this->session->get('_auth');
        $this->db->transaction(function () use ($user, $state, $code): void {
            $this->advanceMfaVersion($user['id'], $state['version']);
            $this->consumeMfaCode($user['id'], $code);
            $this->db->execute('DELETE FROM auth_mfa WHERE user_id = ?', [$user['id']])->closeCursor();
        });
        $this->establishSession($user['id'], $state['version'] + 1);
    }

    private function checkMfaEnrollment(): void
    {
        if (!$this->mfaEnrollmentEnabled) {
            throw new AuthException(['form' => ['New multi-factor authentication enrollment is disabled.']], 403);
        }
    }

    /** @return list<string> */
    private function createMfaRecoveryCodes(int $id): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = bin2hex(random_bytes(10));
            $codes[] = $code;
            $this->db->execute('INSERT INTO auth_mfa_recovery (user_id, code_hash) VALUES (?, ?)', [
                $id, hash('sha256', $code),
            ]);
        }
        return $codes;
    }

    /** @return array{id: int, name: string, email: string, email_verified_at: ?int} */
    private function mfaUser(): array
    {
        $user = $this->user();
        if ($user === null || $user['email_verified_at'] === null) {
            throw new HttpException(403, 'Log in with a verified email address first.');
        }
        return $user;
    }

    private function checkMfaPassword(int $id, #[\SensitiveParameter] mixed $password, string $ip): void
    {
        $this->limit('mfa-password-ip', $ip, 30, 900);
        $this->limit('mfa-password-user', (string) $id, 10, 900);
        $hash = $this->db->execute('SELECT password_hash FROM users WHERE id = ?', [$id])->fetchColumn();
        if (!is_string($password) || strlen($password) > 72 || !is_string($hash) || !password_verify($password, $hash)) {
            throw new AuthException(['password' => ['Your current password is incorrect.']]);
        }
    }

    private function advanceMfaVersion(int $id, int $version): void
    {
        if ($this->db->execute('UPDATE users SET session_version = session_version + 1 WHERE id = ? AND session_version = ?', [
            $id, $version,
        ])->rowCount() !== 1) {
            throw new AuthException(['form' => ['Your account changed. Log in again before changing MFA.']], 409);
        }
    }

    private function consumeMfaCode(int $id, #[\SensitiveParameter] mixed $code): void
    {
        $mfa = $this->db->execute('SELECT secret, last_counter FROM auth_mfa WHERE user_id = ?', [$id])->fetch();
        if ($mfa !== false && is_string($code)) {
            if (preg_match('/^[a-f0-9]{20}$/D', $code) === 1) {
                if ($this->db->execute('DELETE FROM auth_mfa_recovery WHERE user_id = ? AND code_hash = ?', [
                    $id, hash('sha256', $code),
                ])->rowCount() === 1) {
                    return;
                }
            } else {
                $secret = $this->db->encryption->decrypt($mfa['secret'], 'auth:mfa:' . $id);
                $counter = Totp::matchCounter($secret, $code, time());
                if ($counter !== null && $counter > (int) $mfa['last_counter']
                    && $this->db->execute('UPDATE auth_mfa SET last_counter = ? WHERE user_id = ? AND last_counter < ?', [
                        $counter, $id, $counter,
                    ])->rowCount() === 1) {
                    return;
                }
            }
        }
        throw new AuthException(['code' => ['The authenticator or recovery code is invalid or has already been used.']]);
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

    /** @param array<string, mixed> $data */
    public function verifyEmail(#[\SensitiveParameter] mixed $token, string $ip, #[\SensitiveParameter] array $data = []): void
    {
        $this->limit('verify-token-ip', $ip, 30, 900);
        $this->db->transaction(function () use ($token, $data): void {
            $id = $this->consumeToken($token, 'verify');
            // Email ownership must also establish credentials, not activate a registrant's password.
            self::password($data);
            $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            if ($this->db->execute(
                'UPDATE users SET email_verified_at = ?, password_hash = ?, session_version = session_version + 1
                 WHERE id = ? AND email_verified_at IS NULL',
                [time(), $hash, $id],
            )->rowCount() !== 1) {
                throw new AuthException(['token' => ['This link is invalid or has expired. Request a new link.']], 400);
            }
            $this->db->execute('DELETE FROM auth_tokens WHERE user_id = ?', [$id])->closeCursor();
            $this->db->execute('DELETE FROM api_tokens WHERE user_id = ?', [$id])->closeCursor();
        });
        $this->logout();
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
        $validFor = $purpose === 'reset' ? 1800 : 86400;
        $this->db->execute('DELETE FROM auth_tokens WHERE (user_id = ? AND purpose = ?) OR expires_at <= ?', [$id, $purpose, time()]);
        $this->db->execute('INSERT INTO auth_tokens (token_hash, user_id, purpose, expires_at) VALUES (?, ?, ?, ?)', [
            hash('sha256', $token), $id, $purpose, time() + $validFor,
        ]);
        $path = $purpose === 'reset' ? '/reset-password' : '/verify-email';
        $link = $this->baseUrl() . $path . '?token=' . $token;
        $subject = $purpose === 'reset' ? 'Reset your password' : 'Verify your email address';
        $duration = $purpose === 'reset' ? '30 minutes' : '24 hours';
        $content = $this->emailComposer === null ? [
            'subject' => $subject,
            'text' => "$subject\n\nOpen this link:\n$link\n\nThis link is valid for $duration and can only be used once. "
                . 'If you did not request this, ignore this email.',
            'html' => '',
        ] : ($this->emailComposer)($purpose, $link, $validFor);
        if (!is_array($content) || count($content) !== 3
            || !is_string($content['subject'] ?? null) || !is_string($content['text'] ?? null)
            || !is_string($content['html'] ?? null)) {
            throw new \InvalidArgumentException('Authentication email composer must return exactly subject, text, and html strings.');
        }
        $this->mail->queue($this->mail->compose(['to' => [$email]] + $content));
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
