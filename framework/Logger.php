<?php
declare(strict_types=1);

namespace PBreeze;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Logger
{
    private const LEVELS = [
        'debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300,
        'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600,
    ];

    public function __construct(
        private readonly string $root,
        public readonly int $retentionDays = 5,
        public readonly string $minimumLevel = 'debug',
    ) {
        if ($retentionDays < 1 || $retentionDays > 3650) {
            throw new InvalidArgumentException('Log retention must be between 1 and 3650 days.');
        }
        self::level($minimumLevel);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        self::level($level);
        if (self::LEVELS[$level] < self::LEVELS[$this->minimumLevel]) {
            return;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $line = json_encode([
            'timestamp' => $now->format('Y-m-d\TH:i:s.u\Z'),
            'level' => $level,
            'message' => $message,
            'context' => self::context($context, 0),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($line) > 65536) {
            throw new InvalidArgumentException('A log entry must not exceed 64 KiB.');
        }
        $directory = $this->directory();
        $lockPath = $directory . '/.write.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('The log lock must not be a symlink.');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the log lock.');
        }
        try {
            Filesystem::permissions($lockPath, 0600);
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot acquire the log lock.');
            }
            $path = $directory . '/app-' . $now->format('Y-m-d') . '.jsonl';
            if (is_link($path)) {
                throw new RuntimeException('Log files must not be symlinks.');
            }
            $stream = fopen($path, 'ab');
            if ($stream === false) {
                throw new RuntimeException('Cannot open the daily log.');
            }
            try {
                Filesystem::permissions($path, 0600);
                $offset = 0;
                while ($offset < strlen($line)) {
                    $written = fwrite($stream, substr($line, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Cannot write the log entry.');
                    }
                    $offset += $written;
                }
                if (!fflush($stream)) {
                    throw new RuntimeException('Cannot flush the log entry.');
                }
            } finally {
                fclose($stream);
            }
            $cutoff = $now->setTime(0, 0)->modify('-' . ($this->retentionDays - 1) . ' days')->format('Y-m-d');
            $files = glob($directory . '/app-*.jsonl');
            if ($files === false) {
                throw new RuntimeException('Cannot read the log directory for retention.');
            }
            foreach ($files as $file) {
                if (preg_match('/^app-(\d{4}-\d{2}-\d{2})\.jsonl$/D', basename($file), $match)
                    && $match[1] < $cutoff && !is_link($file) && is_file($file) && !unlink($file)) {
                    throw new RuntimeException('Cannot remove an expired log file.');
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void { $this->log('debug', $message, $context); }
    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void { $this->log('info', $message, $context); }
    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void { $this->log('notice', $message, $context); }
    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void { $this->log('warning', $message, $context); }
    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void { $this->log('error', $message, $context); }
    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void { $this->log('critical', $message, $context); }
    /** @param array<string, mixed> $context */
    public function alert(string $message, array $context = []): void { $this->log('alert', $message, $context); }
    /** @param array<string, mixed> $context */
    public function emergency(string $message, array $context = []): void { $this->log('emergency', $message, $context); }

    public static function report(Throwable $error, ?self $logger = null): void
    {
        if ($logger !== null) {
            try {
                $logger->error('Unhandled application exception.', ['exception' => $error]);
                return;
            } catch (Throwable $loggingError) {
                error_log('PBreeze file logging failed (' . $loggingError::class . '); original exception: '
                    . $error::class . ' at ' . $error->getFile() . ':' . $error->getLine());
                return;
            }
        }
        error_log('PBreeze exception: ' . $error::class . ' at ' . $error->getFile() . ':' . $error->getLine());
    }

    private static function level(string $level): void
    {
        if (!isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException('Use debug, info, notice, warning, error, critical, alert, or emergency log levels.');
        }
    }

    private static function context(mixed $value, int $depth): mixed
    {
        if ($depth > 8) {
            throw new InvalidArgumentException('Log context must not exceed 8 nested levels.');
        }
        if ($value instanceof Throwable) {
            // Exception messages, arguments, and traces can contain credentials or request data.
            return ['class' => $value::class, 'file' => $value->getFile(), 'line' => $value->getLine()];
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $sensitive = is_string($key) && preg_match('/password|passwd|secret|token|authorization|cookie|api.?key|private.?key|credential/i', $key);
                $result[$key] = $sensitive ? '[REDACTED]' : self::context($item, $depth + 1);
            }
            return $result;
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        throw new InvalidArgumentException('Log context supports only arrays, scalars, null, and exceptions.');
    }

    private function directory(): string
    {
        $root = realpath($this->root);
        if ($root === false) {
            throw new RuntimeException('The application root does not exist.');
        }
        $directory = $root;
        foreach (['storage', 'logs'] as $segment) {
            $directory .= '/' . $segment;
            if (is_link($directory)) {
                throw new RuntimeException('Log directories must not be symlinks.');
            }
            if (!is_dir($directory)) {
                set_error_handler(static function (int $severity, string $message): never {
                    throw new RuntimeException('Cannot create private log storage: ' . $message);
                });
                try {
                    if (!mkdir($directory, 0700) && !is_dir($directory)) {
                        throw new RuntimeException('Cannot create private log storage.');
                    }
                } catch (RuntimeException $error) {
                    if (!is_dir($directory) || is_link($directory)) {
                        throw $error;
                    }
                } finally {
                    restore_error_handler();
                }
            }
        }
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve private log storage.');
        }
        $public = realpath($root . '/public');
        if ($public !== false) {
            $normalize = static fn (string $path): string => PHP_OS_FAMILY === 'Windows'
                ? strtolower(str_replace('\\', '/', $path)) : $path;
            $target = $normalize($resolved);
            $public = $normalize($public);
            if ($target === $public || str_starts_with($target, $public . '/')) {
                throw new RuntimeException('Logs must be outside the public directory.');
            }
        }
        return $directory;
    }
}
