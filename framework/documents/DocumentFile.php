<?php
declare(strict_types=1);

namespace PBreeze\Documents;

use InvalidArgumentException;
use PBreeze\Filesystem;
use PBreeze\Response;
use RuntimeException;

final class DocumentFile
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
        public readonly string $extension,
    ) {
        if (!preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~D', $mimeType)
            || !preg_match('/^[a-z0-9]+$/D', $extension)) {
            throw new InvalidArgumentException('Invalid document media type or extension.');
        }
    }

    public function save(string $path): void
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) || str_contains($path, '://')) {
            throw new InvalidArgumentException('Use a local file path without control characters.');
        }
        $path = str_replace('\\', '/', $path);
        if (preg_match('~^[A-Za-z]:(?![/\\\\])~', $path)) {
            throw new InvalidArgumentException('Use a full Windows path, not a drive-relative path.');
        }
        $name = basename(str_replace('\\', '/', $path));
        if ($name === '' || preg_match('/[<>:"|?*]|[. ]$/D', $name)) {
            throw new InvalidArgumentException('Use a portable document filename.');
        }
        Filesystem::portableName($name);
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('The document file already exists.');
        }
        $stream = fopen($path, 'x+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot create the document file.');
        }
        $complete = false;
        try {
            Filesystem::permissions($path, 0600);
            $length = strlen($this->contents);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($stream, substr($this->contents, $offset, 1048576));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write the document file.');
                }
                $offset += $written;
            }
            if (!fflush($stream)) {
                throw new RuntimeException('Cannot flush the document file.');
            }
            $complete = true;
        } finally {
            fclose($stream);
            if (!$complete && !unlink($path)) {
                throw new RuntimeException('Cannot remove the incomplete document file.');
            }
        }
    }

    public function download(string $filename): Response
    {
        if ($filename === '' || preg_match('//u', $filename) !== 1
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $filename) || in_array($filename, ['.', '..'], true)) {
            throw new InvalidArgumentException('Use a UTF-8 download filename without paths or control characters.');
        }
        return new Response($this->contents, 200, [
            'Content-Type' => $this->mimeType,
            'Content-Length' => (string) strlen($this->contents),
            'Content-Disposition' => 'attachment; filename="download.' . $this->extension
                . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
