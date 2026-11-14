<?php
declare(strict_types=1);

namespace PBreeze\Storage;

use InvalidArgumentException;
use PBreeze\Storage;
use RuntimeException;

final class SftpDriver implements DriverInterface
{
    /** @var resource|null */
    private $connection = null;
    /** @var resource|null */
    private $sftp = null;
    private readonly string $root;

    /** @param array<string, mixed> $config */
    public function __construct(
        #[\SensitiveParameter] private readonly array $config,
        private readonly int $maxBytes,
    ) {
        if (!is_string($config['host'] ?? null) || !preg_match('/^[A-Za-z0-9.-]+$/D', $config['host'])
            || !is_int($config['port'] ?? 22) || ($config['port'] ?? 22) < 1 || ($config['port'] ?? 22) > 65535
            || !is_string($config['username'] ?? null) || $config['username'] === ''
            || preg_match('/[\x00-\x20\x7f]/', $config['username'])) {
            throw new InvalidArgumentException('SFTP storage needs a hostname, port 1..65535, and username.');
        }
        if (!is_string($config['fingerprint'] ?? null) || !preg_match('/^[a-fA-F0-9]{40}$/D', $config['fingerprint'])) {
            throw new InvalidArgumentException('SFTP requires an independently verified 40-hex SHA-1 host-key fingerprint.');
        }
        if (!is_string($config['root'] ?? null) || !is_string($config['passphrase'] ?? '')) {
            throw new InvalidArgumentException('SFTP root and passphrase must be strings.');
        }
        $this->root = rtrim($config['root'], '/');
        if (!str_starts_with($this->root, '/') || $this->root === '' || preg_match('//u', $this->root) !== 1
            || preg_match('~[\x00-\x1f\x7f\\\\?#]|(?:^|/)\.\.?(?:/|$)|//~', $this->root)) {
            throw new InvalidArgumentException('SFTP requires an existing absolute remote directory, not the filesystem root.');
        }
        $password = $config['password'] ?? '';
        $public = $config['public_key'] ?? '';
        $private = $config['private_key'] ?? '';
        if (!is_string($password) || !is_string($public) || !is_string($private)
            || ($password === '' && ($public === '' || $private === ''))
            || ($password !== '' && ($public !== '' || $private !== ''))) {
            throw new InvalidArgumentException('Configure either SFTP password authentication or both public/private key files.');
        }
    }

    public function put(string $key, #[\SensitiveParameter] string $content): void
    {
        $path = $this->path($key, true);
        $temporary = dirname($path) . '/write-' . bin2hex(random_bytes(16));
        $stream = fopen($this->url($temporary), 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot create the SFTP temporary file.');
        }
        $complete = false;
        $backup = null;
        try {
            if (!ssh2_sftp_chmod($this->sftp, $temporary, 0600)) {
                throw new RuntimeException('Cannot restrict the SFTP temporary file permissions.');
            }
            for ($offset = 0, $length = strlen($content); $offset < $length; $offset += $written) {
                $written = fwrite($stream, substr($content, $offset, 1048576));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write the SFTP file.');
                }
            }
            if (!fflush($stream)) {
                throw new RuntimeException('Cannot flush the SFTP file.');
            }
            fclose($stream);
            $stream = null;
            if ($this->path($key, false) !== null) {
                $backupPath = dirname($path) . '/backup-' . bin2hex(random_bytes(16));
                if (!ssh2_sftp_rename($this->sftp, $path, $backupPath)) {
                    throw new RuntimeException('Cannot stage the previous SFTP file for replacement.');
                }
                $backup = $backupPath;
            }
            if (!ssh2_sftp_rename($this->sftp, $temporary, $path)) {
                throw new RuntimeException('Cannot publish the SFTP file.');
            }
            $complete = true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($backup !== null) {
                if ($complete ? !ssh2_sftp_unlink($this->sftp, $backup) : !ssh2_sftp_rename($this->sftp, $backup, $path)) {
                    throw new RuntimeException('Cannot clean up or restore the previous SFTP file.');
                }
            }
            if (!$complete && !ssh2_sftp_unlink($this->sftp, $temporary)) {
                throw new RuntimeException('Cannot remove the incomplete SFTP file.');
            }
        }
    }

    public function get(string $key): string
    {
        $path = $this->path($key, false);
        if ($path === null) {
            throw new RuntimeException('Storage file not found.');
        }
        $stream = fopen($this->url($path), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot open the SFTP file.');
        }
        try {
            $content = stream_get_contents($stream, $this->maxBytes + 1);
            $metadata = stream_get_meta_data($stream);
            if ($content === false || $metadata['timed_out'] || (!feof($stream) && strlen($content) <= $this->maxBytes)) {
                throw new RuntimeException('SFTP read failed or timed out.');
            }
            if (strlen($content) > $this->maxBytes) {
                throw new RuntimeException('Storage file exceeds the configured maximum byte size.');
            }
            return $content;
        } finally {
            fclose($stream);
        }
    }

    public function exists(string $key): bool
    {
        return $this->path($key, false) !== null;
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key, false);
        if ($path === null) {
            return false;
        }
        if (!ssh2_sftp_unlink($this->sftp, $path)) {
            throw new RuntimeException('Cannot delete the SFTP file.');
        }
        return true;
    }

    private function connect(): void
    {
        if ($this->sftp !== null) {
            return;
        }
        if (!extension_loaded('ssh2')) {
            throw new RuntimeException('SFTP storage requires the native ssh2 extension.');
        }
        $connection = ssh2_connect($this->config['host'], $this->config['port'] ?? 22);
        if ($connection === false) {
            throw new RuntimeException('Cannot connect to SFTP storage.');
        }
        $fingerprint = ssh2_fingerprint($connection, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
        if (!is_string($fingerprint) || !hash_equals(strtolower($this->config['fingerprint']), strtolower($fingerprint))) {
            throw new RuntimeException('SFTP host-key verification failed.');
        }
        if (($this->config['password'] ?? '') !== '') {
            $authenticated = ssh2_auth_password($connection, $this->config['username'], $this->config['password']);
        } else {
            foreach (['public_key', 'private_key'] as $name) {
                if (!is_file($this->config[$name]) || !is_readable($this->config[$name])) {
                    throw new RuntimeException('SFTP authentication requires readable local key files.');
                }
            }
            $pem = file_get_contents($this->config['private_key'], false, null, 0, 65537);
            if ($pem === false || strlen($pem) > 65536 || !str_contains($pem, '-----BEGIN RSA PRIVATE KEY-----')) {
                throw new InvalidArgumentException('SFTP key authentication requires an RSA private key in PEM format (maximum 64 KiB).');
            }
            $authenticated = ssh2_auth_pubkey_file($connection, $this->config['username'],
                $this->config['public_key'], $this->config['private_key'], $this->config['passphrase'] ?? '');
        }
        if (!$authenticated) {
            throw new RuntimeException('SFTP authentication failed.');
        }
        $sftp = ssh2_sftp($connection);
        if ($sftp === false) {
            throw new RuntimeException('Cannot initialize SFTP storage.');
        }
        $this->connection = $connection;
        $this->sftp = $sftp;
    }

    private function path(string $key, bool $create): ?string
    {
        Storage::key($key);
        $this->connect();
        $ancestor = '';
        foreach (explode('/', substr($this->root, 1)) as $segment) {
            $ancestor .= '/' . $segment;
            $this->type($ancestor, true);
        }
        $path = $this->root;
        $segments = explode('/', $key);
        foreach ($segments as $index => $segment) {
            $directory = opendir($this->url($path));
            if ($directory === false) {
                throw new RuntimeException('Cannot list the SFTP storage directory.');
            }
            $found = false;
            try {
                while (($entry = readdir($directory)) !== false) {
                    if ($entry === $segment) {
                        $found = true;
                        break;
                    }
                }
            } finally {
                closedir($directory);
            }
            $path .= '/' . $segment;
            $last = $index === count($segments) - 1;
            if ($found) {
                $this->type($path, !$last);
            } elseif (!$create) {
                return null;
            } elseif (!$last && !ssh2_sftp_mkdir($this->sftp, $path, 0700)) {
                throw new RuntimeException('Cannot create the SFTP storage directory.');
            }
        }
        return $path;
    }

    private function type(string $path, bool $directory): void
    {
        $stat = ssh2_sftp_lstat($this->sftp, $path);
        if ($stat === false || ($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000)) {
            throw new RuntimeException('SFTP paths must be real directories and regular files, never symlinks.');
        }
    }

    private function url(string $path): string
    {
        return 'ssh2.sftp://' . (int) $this->sftp . $path;
    }
}
