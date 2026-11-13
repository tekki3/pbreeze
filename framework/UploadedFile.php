<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use RuntimeException;

final class UploadedFile
{
    /** @var array<string, list<string>> */
    private const TYPES = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'gif' => ['image/gif'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'],
        'txt' => ['text/plain'], 'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ];

    private function __construct(
        public readonly string $originalName,
        public readonly string $clientMediaType,
        public readonly int $error,
        private readonly string $temporaryPath,
    ) {
    }

    /** @internal @param array<string, mixed> $files @return array<string, list<self>> */
    public static function capture(array $files): array
    {
        $result = [];
        foreach ($files as $field => $data) {
            if (!is_string($field) || !is_array($data)
                || !isset($data['name'], $data['type'], $data['tmp_name'], $data['error'], $data['size'])) {
                throw new HttpException(400, 'Malformed uploaded file data.');
            }
            $multiple = is_array($data['name']);
            if ($multiple) {
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                    if (!is_array($data[$key]) || array_keys($data[$key]) !== array_keys($data['name'])) {
                        throw new HttpException(400, 'Malformed uploaded file list.');
                    }
                }
            }
            $entries = $multiple ? array_keys($data['name']) : [null];
            $result[$field] = [];
            foreach ($entries as $index) {
                $entry = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                    $entry[$key] = $multiple ? $data[$key][$index] : $data[$key];
                }
                if (!is_string($entry['name']) || !is_string($entry['type']) || !is_string($entry['tmp_name'])
                    || !is_int($entry['error']) || !is_int($entry['size']) || $entry['size'] < 0
                    || !in_array($entry['error'], [0, 1, 2, 3, 4, 6, 7, 8], true)) {
                    throw new HttpException(400, 'Malformed uploaded file metadata; nested file fields are not supported.');
                }
                if ($entry['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $name = basename(str_replace('\\', '/', $entry['name']));
                if ($name === '' || in_array($name, ['.', '..'], true) || strlen($name) > 255
                    || preg_match('//u', $name) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
                    throw new HttpException(422, 'The upload filename must be valid UTF-8 without control characters and at most 255 bytes.');
                }
                $result[$field][] = new self($name, $entry['type'], $entry['error'], $entry['tmp_name']);
            }
        }
        return $result;
    }

    /**
     * @param list<string> $allowedTypes Explicit filename extensions from the built-in type list.
     * @return array{extension: string, mime: string, size: int}
     */
    public function validate(array $allowedTypes, int $maxBytes = 2097152): array
    {
        if ($allowedTypes === [] || !array_is_list($allowedTypes) || $maxBytes < 1) {
            throw new InvalidArgumentException('Choose allowed upload types and a positive maximum byte size.');
        }
        foreach ($allowedTypes as $type) {
            if (!is_string($type) || !isset(self::TYPES[$type])) {
                throw new InvalidArgumentException('Supported upload types: jpg, jpeg, png, gif, webp, pdf, txt, csv, docx, xlsx.');
            }
        }
        match ($this->error) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw new HttpException(413, 'The uploaded file exceeds the permitted size.'),
            UPLOAD_ERR_PARTIAL => throw new HttpException(422, 'The file was only partially uploaded. Please try again.'),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => throw new RuntimeException('PHP could not receive the uploaded file (error ' . $this->error . ').'),
            default => throw new HttpException(422, 'No file was uploaded.'),
        };
        if (!is_uploaded_file($this->temporaryPath)) {
            throw new HttpException(422, 'The file is not a valid HTTP upload.');
        }
        $size = filesize($this->temporaryPath);
        if ($size === false) {
            throw new RuntimeException('Cannot determine the uploaded file size.');
        }
        if ($size > $maxBytes) {
            throw new HttpException(413, 'The uploaded file must not exceed ' . $maxBytes . ' bytes.');
        }
        if ($size === 0) {
            throw new HttpException(422, 'Empty uploads are not allowed.');
        }
        $extension = strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedTypes, true)) {
            throw new HttpException(422, 'This file extension is not allowed.');
        }
        if (!extension_loaded('fileinfo')) {
            throw new RuntimeException('Upload validation requires the native fileinfo extension.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($this->temporaryPath);
        if ($mime === false) {
            throw new RuntimeException('Cannot inspect the uploaded file media type.');
        }
        if (!in_array($mime, self::TYPES[$extension], true)) {
            throw new HttpException(422, 'The file contents do not match an allowed file type.');
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            set_error_handler(static function (int $severity, string $message): never {
                throw new HttpException(422, 'The uploaded image header is invalid.');
            });
            try {
                $image = getimagesize($this->temporaryPath);
            } finally {
                restore_error_handler();
            }
            if ($image === false || $image['mime'] !== $mime) {
                throw new HttpException(422, 'The uploaded image header is invalid.');
            }
        }
        if (in_array($extension, ['docx', 'xlsx'], true)) {
            $this->office($extension);
        }
        return ['extension' => $extension, 'mime' => self::mediaType($extension), 'size' => $size];
    }

    /** @internal */
    public function moveTo(string $path): void
    {
        if (!is_uploaded_file($this->temporaryPath) || !move_uploaded_file($this->temporaryPath, $path)) {
            throw new RuntimeException('Cannot move the HTTP upload into private storage.');
        }
    }

    /** @internal */
    public static function mediaType(string $extension): string
    {
        if (!isset(self::TYPES[$extension])) {
            throw new InvalidArgumentException('Unsupported stored upload extension.');
        }
        return match ($extension) {
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            default => self::TYPES[$extension][0],
        };
    }

    private function office(string $extension): void
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('Office upload validation requires the native zip extension.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($this->temporaryPath, \ZipArchive::CHECKCONS) !== true) {
            throw new HttpException(422, 'The uploaded Office package is invalid.');
        }
        try {
            $total = 0;
            if ($zip->numFiles > 1000) {
                throw new HttpException(422, 'The Office package contains too many entries.');
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new HttpException(422, 'Cannot inspect the uploaded Office package.');
                }
                $total += $stat['size'];
                if ($total > 20971520 || $stat['size'] > 10485760
                    || ($stat['encryption_method'] ?? 0) !== 0
                    || preg_match('~(?:^|/)\.\.(?:/|$)|[\\\\:\x00-\x1f]|^/|vbaProject~i', $stat['name'])) {
                    throw new HttpException(422, 'The Office package contains unsafe or oversized entries.');
                }
            }
            $main = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('_rels/.rels') === false
                || $zip->locateName($main) === false) {
                throw new HttpException(422, 'The Office package does not match the filename extension.');
            }
        } finally {
            if (!$zip->close()) {
                throw new RuntimeException('Cannot close the uploaded Office package.');
            }
        }
    }
}
