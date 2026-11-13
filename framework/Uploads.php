<?php
declare(strict_types=1);

namespace PBreeze;

use PBreeze\Documents\DocumentFile;
use RuntimeException;

final class StoredUpload
{
    public function __construct(
        public readonly string $key,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $size,
    ) {
    }
}

final class Uploads
{
    public function __construct(private readonly string $root, public readonly int $maxBytes = 2097152)
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('The default upload limit must be a positive byte size.');
        }
    }

    /** @param list<string> $allowedTypes @return array{extension: string, mime: string, size: int} */
    public function validate(UploadedFile $file, array $allowedTypes, ?int $maxBytes = null): array
    {
        return $file->validate($allowedTypes, $maxBytes ?? $this->maxBytes);
    }

    /** @param list<string> $allowedTypes */
    public function store(UploadedFile $file, array $allowedTypes, ?int $maxBytes = null): StoredUpload
    {
        $metadata = $this->validate($file, $allowedTypes, $maxBytes);
        $directory = $this->directory(true);
        $id = bin2hex(random_bytes(32));
        $target = $directory . '/' . $id;
        if (!mkdir($target, 0700)) {
            throw new RuntimeException('Cannot reserve a private upload directory.');
        }
        $path = $target . '/file.' . $metadata['extension'];
        $complete = false;
        try {
            $file->moveTo($path);
            Filesystem::permissions($path, 0600);
            $stored = new StoredUpload($id . '.' . $metadata['extension'], $file->originalName, $metadata['mime'], $metadata['size']);
            $complete = true;
            return $stored;
        } finally {
            if (!$complete) {
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException('Cannot remove the incomplete uploaded file.');
                }
                if (!rmdir($target)) {
                    throw new RuntimeException('Cannot remove the incomplete upload directory.');
                }
            }
        }
    }

    public function download(string $key, ?string $filename = null): Response
    {
        [$path, $extension] = $this->resolve($key);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read the stored upload.');
        }
        return (new DocumentFile($contents, UploadedFile::mediaType($extension), $extension))
            ->download($filename ?? 'download.' . $extension);
    }

    public function delete(string $key): void
    {
        [$path] = $this->resolve($key);
        if (!unlink($path)) {
            throw new RuntimeException('Cannot delete the stored upload.');
        }
        if (!rmdir(dirname($path))) {
            throw new RuntimeException('Cannot remove the stored upload directory.');
        }
    }

    /** @return array{string, string} */
    private function resolve(string $key): array
    {
        if (!preg_match('/^([a-f0-9]{64})\.(jpg|jpeg|png|gif|webp|pdf|txt|csv|docx|xlsx)$/D', $key, $match)) {
            throw new HttpException(404, 'The uploaded file was not found.');
        }
        $directory = $this->directory(false) . '/' . $match[1];
        $path = $directory . '/file.' . $match[2];
        if (is_link($directory) || is_link($path) || !is_file($path)) {
            throw new HttpException(404, 'The uploaded file was not found.');
        }
        return [$path, $match[2]];
    }

    private function directory(bool $create): string
    {
        $root = realpath($this->root);
        if ($root === false) {
            throw new RuntimeException('The application root does not exist.');
        }
        $directory = $root;
        foreach (['storage', 'uploads'] as $segment) {
            $directory .= '/' . $segment;
            if (is_link($directory)) {
                throw new RuntimeException('Private upload storage must not use symlinked directories.');
            }
            if (!is_dir($directory)) {
                if (!$create) {
                    throw new HttpException(404, 'The uploaded file was not found.');
                }
                set_error_handler(static function (int $severity, string $message): never {
                    throw new RuntimeException('Cannot create private upload storage: ' . $message);
                });
                try {
                    if (!mkdir($directory, 0700) && !is_dir($directory)) {
                        throw new RuntimeException('Cannot create private upload storage.');
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
        $public = realpath($root . '/public');
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve private upload storage.');
        }
        $normalized = str_replace('\\', '/', $resolved);
        if ($public !== false) {
            $public = str_replace('\\', '/', $public);
            if (PHP_OS_FAMILY === 'Windows') {
                $public = strtolower($public);
                $normalized = strtolower($normalized);
            }
            if ($public === $normalized || str_starts_with($normalized, $public . '/')) {
                throw new RuntimeException('Upload storage must be outside the public directory.');
            }
        }
        return $directory;
    }
}
