<?php
declare(strict_types=1);

namespace PBreeze\Storage;

interface DriverInterface
{
    public function put(string $key, #[\SensitiveParameter] string $content): void;
    public function get(string $key): string;
    public function exists(string $key): bool;
    public function delete(string $key): bool;
}
