<?php
declare(strict_types=1);

// Alleen dit document root wordt door de ontwikkelserver bediend.
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0]);
$file = str_contains($path, "\0") ? false : realpath(__DIR__ . $path);
if ($path !== '/' && !str_contains($path, "\0") && $file !== false
    && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)
    && is_file($file) && preg_match('/\.(?:css|js|png|jpg|jpeg|gif|webp|ico|svg|woff2?)$/i', $file)
    && !preg_match('~(?:^|/)\.~', $path)) {
    return false;
}
require __DIR__ . '/index.php';
