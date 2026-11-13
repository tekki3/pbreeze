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
    public function __construct(private readonly string $root, public readonly int $maxBytes = 2097152, private readonly ?string $storagePath = null)
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
        if (!$create) {
            $base = Filesystem::databasePath($this->root, $this->storagePath ?? 'storage');
            if (!is_dir($base) || !is_dir($base . '/uploads')) {
                throw new HttpException(404, 'The uploaded file was not found.');
            }
        }
        return Filesystem::privateDirectory($this->root, $this->storagePath, 'uploads', $create);
    }
}
