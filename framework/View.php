<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use RuntimeException;

final class View
{
    private readonly string $cacheDirectory;

    public function __construct(private readonly string $directory, ?string $cacheDirectory = null)
    {
        $this->cacheDirectory = $cacheDirectory ?? dirname($directory, 2) . '/storage/views';
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = []): string
    {
        return (new TemplateContext($this))->render($name, $data);
    }

    /** @internal @param array<string, mixed> $data */
    public function evaluate(string $name, array $data, TemplateContext $engine): string
    {
        $file = $this->resolve($name);
        if (str_ends_with($file, '.breeze.php')) {
            $file = $this->compiled($file, $name);
        }
        return (static function (string $__file, array $__data, TemplateContext $__engine): string {
            extract(array_filter(
                $__data,
                static fn (mixed $key): bool => is_string($key) && !str_starts_with($key, '__')
                    && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $key) === 1,
                ARRAY_FILTER_USE_KEY,
            ), EXTR_SKIP);
            ob_start();
            require $__file;
            return (string) ob_get_clean();
        })($file, $data, $engine);
    }

    public static function text(mixed $value): string
    {
        if ($value !== null && !is_scalar($value) && !$value instanceof \Stringable) {
            throw new InvalidArgumentException('Template-uitvoer moet een scalar, null of Stringable zijn.');
        }
        return (string) $value;
    }

    public static function escape(mixed $value): string
    {
        return e(self::text($value));
    }

    public function clearCache(): int
    {
        $files = glob($this->cacheDirectory . '/breeze-*.php');
        if ($files === false) {
            throw new RuntimeException('Kan de templatecache niet lezen.');
        }
        $count = 0;
        foreach ($files as $file) {
            if (!preg_match('/^breeze-[a-f0-9]{64}\.php$/D', basename($file))) {
                continue;
            }
            if (!unlink($file)) {
                throw new RuntimeException('Kan een gecompileerde template niet verwijderen.');
            }
            $count++;
        }
        return $count;
    }

    private function resolve(string $name): string
    {
        Filesystem::portableName($name);
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*$/D', $name)) {
            throw new InvalidArgumentException('Ongeldige viewnaam.');
        }
        $base = $this->directory . '/' . str_replace('.', '/', $name);
        foreach (['.breeze.php', '.php'] as $extension) {
            if (is_file($base . $extension)) {
                return $base . $extension;
            }
        }
        throw new RuntimeException("View bestaat niet: $name");
    }

    private function compiled(string $file, string $name): string
    {
        $source = file_get_contents($file);
        if ($source === false) {
            throw new RuntimeException("Kan template niet lezen: $name");
        }
        $hash = hash('sha256', TemplateCompiler::VERSION . "\0" . $file . "\0" . $source);
        $target = $this->cacheDirectory . '/breeze-' . $hash . '.php';
        if (is_file($target)) {
            return $target;
        }
        $compiled = (new TemplateCompiler())->compile($source, $name);
        if (!is_dir($this->cacheDirectory)) {
            set_error_handler(static function (int $severity, string $message): never {
                throw new RuntimeException('Kan de templatecachedirectory niet aanmaken: ' . $message);
            });
            try {
                if (!mkdir($this->cacheDirectory, 0700, true) && !is_dir($this->cacheDirectory)) {
                    throw new RuntimeException('Kan de templatecachedirectory niet aanmaken.');
                }
            } catch (RuntimeException $error) {
                if (!is_dir($this->cacheDirectory)) {
                    throw $error;
                }
            } finally {
                restore_error_handler();
            }
        }
        $lock = fopen($this->cacheDirectory . '/.compile.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Kan het templatecompilatieslot niet openen.');
        }
        $temporary = $this->cacheDirectory . '/compile-' . bin2hex(random_bytes(16)) . '.tmp';
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Kan het templatecompilatieslot niet verkrijgen.');
            }
            if (is_file($target)) {
                return $target;
            }
            if (file_put_contents($temporary, $compiled, LOCK_EX) !== strlen($compiled)) {
                throw new RuntimeException("Kan gecompileerde template niet schrijven: $name");
            }
            Filesystem::permissions($temporary, 0600);
            // Content-addressed namen hoeven nooit overschreven te worden, ook niet op Windows.
            if (!rename($temporary, $target)) {
                throw new RuntimeException("Kan gecompileerde template niet publiceren: $name");
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            if (is_file($temporary) && !unlink($temporary)) {
                throw new RuntimeException('Kan tijdelijk templatebestand niet opruimen.');
            }
        }
        return $target;
    }
}
