<?php
declare(strict_types=1);

namespace PBreeze;

use RuntimeException;
use Throwable;

final class Diagnostics
{
    public function __construct(private readonly Application $app)
    {
    }

    public function ready(): void
    {
        $this->storage();
        $db = $this->app->db;
        if ($db->driver() === 'sqlite') {
            $path = Filesystem::databasePath($this->app->root, $this->app->config['database']['database']);
            if ($path === ':memory:' || !is_file($path) || !is_readable($path) || !is_writable($path) || !is_writable(dirname($path))) {
                throw new RuntimeException('Readiness requires an existing writable persistent SQLite database.');
            }
        }
        $db->execute('SELECT 1')->closeCursor();
        $tables = ['users', 'auth_tokens', 'auth_limits', 'api_tokens', 'api_limits', 'emails'];
        if (($this->app->config['session']['driver'] ?? 'native') === 'database') {
            $tables[] = 'sessions';
        }
        foreach ($tables as $table) {
            $db->execute($db->limit('SELECT 1 FROM ' . Query::identifier($table, $db->driver()), 1))->closeCursor();
        }
        $files = glob($this->app->root . '/database/migrations/*.php');
        if ($files === false || $files === []) {
            throw new RuntimeException('Readiness requires the deployed migration files.');
        }
        $expected = array_map(static fn (string $file): string => basename($file, '.php'), $files);
        $applied = $db->execute('SELECT name FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
        if (array_diff($expected, $applied) !== [] || array_diff($applied, $expected) !== []) {
            throw new RuntimeException('Database migrations do not match the deployed release.');
        }
    }

    /** @return list<array{name: string, status: 'pass'|'warn'|'fail', message: string}> */
    public function check(): array
    {
        $config = $this->app->config;
        $checks = [];
        $add = static function (string $name, string $status, string $message) use (&$checks): void {
            $checks[] = ['name' => $name, 'status' => $status, 'message' => $message];
        };
        $production = $config['environment'] === 'production';
        $add('environment', $production ? 'pass' : 'fail', 'APP_ENV must be production for deployment.');
        $add('debug', !$config['debug'] ? 'pass' : 'fail', 'APP_DEBUG must be false.');
        $add('cookies', $config['secure_cookie'] ? 'pass' : 'warn', 'Set SESSION_SECURE_COOKIE=true behind TLS termination; enforce HTTPS at the web server.');
        $parts = parse_url($config['url'] ?? '');
        $validUrl = is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true) && filter_var($config['url'] ?? '', FILTER_VALIDATE_URL) !== false;
        $add('origin', $validUrl ? 'pass' : 'fail', 'APP_URL must be a trusted HTTPS origin without credentials, path, query, or fragment.');
        $docs = $config['api_docs'] ?? !$production;
        $add('api-docs', $docs ? 'warn' : 'pass', $docs ? 'API docs are enabled; protect them at the edge or disable them.' : 'Public API documentation is disabled.');
        $add('extensions', array_diff(['PDO', 'session', 'json', 'filter', 'openssl', 'intl', 'mbstring', 'fileinfo'], get_loaded_extensions()) === []
            ? 'pass' : 'fail', 'Bundled services require PDO, session, JSON, filter, OpenSSL, Intl, mbstring, and fileinfo.');
        $add('optional-extensions', extension_loaded('curl') && extension_loaded('zip') && extension_loaded('iconv') ? 'pass' : 'warn',
            'AI needs curl; Office files need zip; PDF/email need iconv.');
        $cacheDriver = $config['cache']['driver'] ?? 'none';
        $add('cache', $cacheDriver === 'none' ? 'pass' : (extension_loaded($cacheDriver) ? 'warn' : 'fail'),
            $cacheDriver === 'none' ? 'Shared cache is disabled; sessions and rate limits are unchanged.'
                : 'Selected cache needs its native PHP extension. Manually verify connectivity, isolation, credentials, and network protection; no cache connection is attempted here.');
        $storageDriver = $config['storage']['driver'] ?? 'local';
        $storageExtension = $storageDriver === 'sftp' ? 'ssh2' : 'curl';
        $add('file-storage', $storageDriver === 'local' ? 'pass' : (extension_loaded($storageExtension) ? 'warn' : 'fail'),
            $storageDriver === 'local' ? 'File storage uses the private files directory; no remote connection is needed.'
                : 'Selected file storage needs its native extension. Verify remote permissions, host/TLS identity, and connectivity manually; no remote connection is attempted.');
        try {
            $this->storage();
            $add('storage', 'pass', 'Configured private storage and required subdirectories exist and are writable.');
        } catch (Throwable) {
            $add('storage', 'fail', 'Prepare safe private storage with php breeze storage:prepare using the deployment identity.');
        }
        try {
            $this->ready();
            $add('database', 'pass', 'Database is reachable, bundled tables exist, and migration names match this release.');
        } catch (Throwable) {
            $add('database', 'fail', 'Readiness failed. Check private storage, database credentials/TLS/schema, and deployed migrations.');
        }
        $key = $config['key'] ?? '';
        if ($key === '') {
            $add('encryption', 'warn', 'APP_KEY is unset; configure and back up a key before using encryption.');
        } else {
            try {
                $encrypted = $this->app->encryption->encrypt('deployment-check', 'doctor');
                if ($this->app->encryption->decrypt($encrypted, 'doctor') !== 'deployment-check') {
                    throw new RuntimeException('Encryption round trip failed.');
                }
                $add('encryption', 'pass', 'Encryption key is valid; retain a separate protected backup.');
            } catch (Throwable) {
                $add('encryption', 'fail', 'APP_KEY is invalid or native encryption is unavailable.');
            }
        }
        $smtp = $config['mail']['smtp'] ?? [];
        $from = $config['mail']['from'] ?? '';
        $mailReady = ($smtp['host'] ?? '') !== '' && in_array($smtp['encryption'] ?? '', ['tls', 'starttls'], true)
            && filter_var($from, FILTER_VALIDATE_EMAIL) !== false && !str_ends_with(strtolower($from), '@example.test');
        $add('mail', $mailReady ? 'warn' : 'fail', $mailReady
            ? 'SMTP is configured but untested. Verify delivery and supervise scheduled mail:work runs.'
            : 'Bundled authentication requires a real sender and encrypted SMTP configuration.');
        $databaseSessions = ($config['session']['driver'] ?? 'native') === 'database';
        $add('sessions', $databaseSessions ? 'pass' : (ini_get('session.save_handler') === 'files' ? 'warn' : 'pass'),
            $databaseSessions ? 'Database sessions selected; readiness checks the sessions table. Verify expiry, lock permissions, and concurrency on the deployed database.'
                : 'Native PHP sessions selected. Verify the web SAPI handler/path, expiry, permissions, and multi-node sharing or sticky routing.');
        $add('logging', in_array($this->app->log->minimumLevel, ['critical', 'alert', 'emergency'], true) ? 'warn' : 'pass',
            'Log levels above error suppress automatic exception records; monitor PHP and application logs.');
        $add('operations', 'warn', 'Manually verify TLS/HSTS, public-only document root, client-IP restoration, backups/restores, worker alerts, and load tests.');
        return $checks;
    }

    private function storage(): void
    {
        foreach (['', 'logs', 'uploads', 'views'] as $child) {
            $path = Filesystem::privateDirectory($this->app->root, $this->app->storagePath, $child, false);
            if (!is_readable($path) || !is_writable($path)) {
                throw new RuntimeException('Private storage is not readable and writable.');
            }
        }
    }
}
