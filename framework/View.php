<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class View
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = []): string
    {
        Filesystem::portableName($name);
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z][a-zA-Z0-9_-]*)*$/D', $name)) {
            throw new InvalidArgumentException('Ongeldige viewnaam.');
        }
        $file = $this->directory . '/' . str_replace('.', '/', $name) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View bestaat niet: $name");
        }
        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
                return (string) ob_get_clean();
            } catch (Throwable $error) {
                ob_end_clean();
                throw $error;
            }
        })($file, $data);
    }
}
