<?php
declare(strict_types=1);

namespace PBreeze;

use RuntimeException;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?string $lockedId = null;
    private ?string $resource = null;
    /** @var resource|null */
    private $file = null;

    public function __construct(
        private readonly Database $db,
        private readonly string $root,
        private readonly string $storagePath,
        private readonly int $lifetime = 7200,
        private readonly int $lockTimeout = 10,
    ) {
        if ($lifetime < 1 || $lifetime > 31536000 || $lockTimeout < 1 || $lockTimeout > 60) {
            throw new \InvalidArgumentException('Invalid database session lifetime or lock timeout.');
        }
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        if ($this->lockedId === null) {
            return true;
        }
        if ($this->file !== null) {
            try {
                if (!flock($this->file, LOCK_UN)) {
                    throw new RuntimeException('Cannot release the session lock.');
                }
            } finally {
                fclose($this->file);
                $this->file = null;
            }
        } elseif ($this->db->driver() === 'mysql') {
            if ((int) $this->db->execute('SELECT RELEASE_LOCK(?)', [$this->resource])->fetchColumn() !== 1) {
                throw new RuntimeException('Cannot release the session lock.');
            }
        } elseif ($this->db->driver() === 'pgsql') {
            $released = $this->db->execute('SELECT pg_advisory_unlock(CAST(? AS INTEGER), CAST(? AS INTEGER))',
                $this->postgresKeys())->fetchColumn();
            if (!in_array($released, [true, 1, '1', 't'], true)) {
                throw new RuntimeException('Cannot release the session lock.');
            }
        } else {
            $statement = $this->db->execute("SET NOCOUNT ON; DECLARE @result int;
                EXEC @result = sys.sp_releaseapplock @Resource = ?, @LockOwner = 'Session';
                SET NOCOUNT OFF; SELECT @result", [$this->resource]);
            $result = $statement->fetchColumn();
            $statement->closeCursor();
            if ($result === false || (int) $result < 0) {
                throw new RuntimeException('Cannot release the session lock.');
            }
        }
        $this->lockedId = $this->resource = null;
        return true;
    }

    public function validateId(string $id): bool
    {
        if (!preg_match('/^[a-zA-Z0-9,-]{16,256}$/D', $id)) {
            return false;
        }
        $this->lock($id);
        $valid = $this->db->execute('SELECT expires_at FROM sessions WHERE id = ?', [$this->key($id)])->fetchColumn();
        if ($valid !== false && (int) $valid > time()) {
            return true;
        }
        $this->close();
        return false;
    }

    public function read(string $id): string|false
    {
        $this->lock($id);
        $row = $this->db->execute('SELECT payload, expires_at FROM sessions WHERE id = ?', [$this->key($id)])->fetch();
        if ($row === false || (int) $row['expires_at'] <= time()) {
            return '';
        }
        $payload = base64_decode($row['payload'], true);
        if ($payload === false) {
            throw new RuntimeException('Invalid stored session payload.');
        }
        return $payload;
    }

    public function write(string $id, string $data): bool
    {
        $this->lock($id);
        $key = $this->key($id);
        $now = time();
        $this->db->transaction(function () use ($key, $data, $now): void {
            $this->db->execute('DELETE FROM sessions WHERE id = ?', [$key])->closeCursor();
            $this->db->execute('INSERT INTO sessions (id, payload, last_activity, expires_at) VALUES (?, ?, ?, ?)',
                [$key, base64_encode($data), $now, $now + $this->lifetime])->closeCursor();
        });
        return true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        $this->lock($id);
        $this->db->execute('DELETE FROM sessions WHERE id = ?', [$this->key($id)])->closeCursor();
        return $this->close();
    }

    public function gc(int $max_lifetime): int|false
    {
        // Expiry is also checked on every read; garbage collection only reclaims space.
        return $this->db->execute('DELETE FROM sessions WHERE expires_at <= ?', [time()])->rowCount();
    }

    private function key(string $id): string
    {
        if (!preg_match('/^[a-zA-Z0-9,-]{16,256}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid session ID.');
        }
        return hash('sha256', $id);
    }

    /** @return list<int> */
    private function postgresKeys(): array
    {
        $parts = unpack('Nfirst/Nsecond', hex2bin(substr($this->resource, -64, 16)));
        return array_map(static fn (int $value): int => $value > 2147483647 ? $value - 4294967296 : $value, array_values($parts));
    }

    private function lock(string $id): void
    {
        $key = $this->key($id);
        if ($this->lockedId === $id) {
            return;
        }
        $this->close();
        $this->resource = 'pbreeze:session:' . $key;
        $deadline = hrtime(true) + $this->lockTimeout * 1000000000;
        if ($this->db->driver() === 'sqlite') {
            $directory = Filesystem::privateDirectory($this->root, $this->storagePath, 'sessions');
            // Bounded lock stripes avoid an unbounded collection of abandoned lock files.
            $path = $directory . '/' . substr($key, 0, 2) . '.lock';
            if (is_link($path)) {
                throw new RuntimeException('Session locks must not be symlinks.');
            }
            $this->file = fopen($path, 'c');
            if ($this->file === false) {
                $this->file = null;
                throw new RuntimeException('Cannot open the session lock.');
            }
            Filesystem::permissions($path, 0600);
        } elseif ($this->db->driver() === 'mysql') {
            $name = $this->db->execute('SELECT DATABASE()')->fetchColumn();
            $this->resource = 'pb:s:' . substr(hash('sha256', $name . ':' . $key), 0, 59);
        }
        do {
            $acquired = match ($this->db->driver()) {
                'sqlite' => flock($this->file, LOCK_EX | LOCK_NB),
                'mysql' => $this->mysqlLock(),
                'pgsql' => in_array($this->db->execute('SELECT pg_try_advisory_lock(CAST(? AS INTEGER), CAST(? AS INTEGER))',
                    $this->postgresKeys())->fetchColumn(), [true, 1, '1', 't'], true),
                'sqlsrv' => $this->sqlServerLock(),
                default => throw new RuntimeException('Unsupported database session driver.'),
            };
            if ($acquired) {
                $this->lockedId = $id;
                return;
            }
            usleep(10000);
        } while (hrtime(true) < $deadline);
        if ($this->file !== null) {
            fclose($this->file);
            $this->file = null;
        }
        $this->resource = null;
        throw new RuntimeException('Timed out waiting for the session lock.');
    }

    private function sqlServerLock(): bool
    {
        $statement = $this->db->execute("SET NOCOUNT ON; DECLARE @result int;
            EXEC @result = sys.sp_getapplock @Resource = ?, @LockMode = 'Exclusive',
                @LockOwner = 'Session', @LockTimeout = 0;
            SET NOCOUNT OFF; SELECT @result", [$this->resource]);
        $result = $statement->fetchColumn();
        $statement->closeCursor();
        if ($result === false || (int) $result < -1) {
            throw new RuntimeException('Cannot acquire the session lock.');
        }
        return (int) $result >= 0;
    }

    private function mysqlLock(): bool
    {
        $result = $this->db->execute('SELECT GET_LOCK(?, 0)', [$this->resource])->fetchColumn();
        if ($result === null || $result === false) {
            throw new RuntimeException('Cannot acquire the session lock.');
        }
        return (int) $result === 1;
    }
}
