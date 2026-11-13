<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;

final class ApiTokens
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{id: int, token: string, expires_at: int} */
    public function create(int $userId, string $name = 'cli', int $days = 30): array
    {
        if ($userId < 1 || trim($name) === '' || strlen($name) > 80 || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name)
            || $days < 1 || $days > 365) {
            throw new InvalidArgumentException('Use a positive user ID, a token name of 1-80 bytes, and 1-365 days.');
        }
        $user = $this->db->execute('SELECT session_version, email_verified_at FROM users WHERE id = ?', [$userId])->fetch();
        if ($user === false || $user['email_verified_at'] === null) {
            throw new InvalidArgumentException('API tokens require an existing user with a verified email address.');
        }
        $token = 'pbz_' . bin2hex(random_bytes(32));
        $expires = time() + $days * 86400;
        $this->db->execute(
            'INSERT INTO api_tokens (user_id, name, token_hash, session_version, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, trim($name), hash('sha256', $token), $user['session_version'], time(), $expires],
        );
        return ['id' => (int) $this->db->pdo()->lastInsertId(), 'token' => $token, 'expires_at' => $expires];
    }

    /** @return array{id: int, name: string, email: string, token_id: int} */
    public function authenticate(#[\SensitiveParameter] ?string $authorization): array
    {
        if ($authorization === null || preg_match('/^Bearer (pbz_[a-f0-9]{64})$/iD', $authorization, $match) !== 1) {
            throw $this->unauthorized();
        }
        $row = $this->db->execute(
            'SELECT u.id, u.name, u.email, t.id AS token_id FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.expires_at > ? AND t.session_version = u.session_version
             AND u.email_verified_at IS NOT NULL',
            [hash('sha256', $match[1]), time()],
        )->fetch();
        if ($row === false) {
            throw $this->unauthorized();
        }
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email'], 'token_id' => (int) $row['token_id']];
    }

    /** @return list<array<string, mixed>> */
    public function listing(int $userId): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('User ID must be positive.');
        }
        return $this->db->execute(
            'SELECT id, user_id, name, created_at, expires_at FROM api_tokens WHERE user_id = ? ORDER BY id DESC', [$userId],
        )->fetchAll();
    }

    public function revoke(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Token ID must be positive.');
        }
        if ($this->db->execute('DELETE FROM api_tokens WHERE id = ?', [$id])->rowCount() !== 1) {
            throw new RecordNotFoundException('API token does not exist.');
        }
    }

    private function unauthorized(): HttpException
    {
        return new HttpException(401, 'A valid bearer token is required.', ['WWW-Authenticate' => 'Bearer realm="api"']);
    }
}
