<?php
declare(strict_types=1);

use PBreeze\Env;

$integer = static function (string $name, int $default, int $min, int $max): int {
    $value = filter_var(Env::get($name, (string) $default), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max],
    ]);
    if ($value === false) {
        throw new RuntimeException("$name must be between $min and $max.");
    }
    return $value;
};

return [
    'driver' => Env::get('STORAGE_DRIVER', 'local'),
    'max_bytes' => $integer('STORAGE_MAX_BYTES', 16777216, 1, 268435456),
    'timeout' => $integer('STORAGE_TIMEOUT', 30, 1, 300),
    'ca_file' => Env::get('STORAGE_CA_FILE'),
    'sftp' => [
        'host' => Env::get('STORAGE_SFTP_HOST'),
        'port' => $integer('STORAGE_SFTP_PORT', 22, 1, 65535),
        'username' => Env::get('STORAGE_SFTP_USERNAME'),
        'password' => Env::get('STORAGE_SFTP_PASSWORD'),
        'root' => Env::get('STORAGE_SFTP_ROOT'),
        'fingerprint' => Env::get('STORAGE_SFTP_FINGERPRINT'),
        'public_key' => Env::get('STORAGE_SFTP_PUBLIC_KEY'),
        'private_key' => Env::get('STORAGE_SFTP_PRIVATE_KEY'),
        'passphrase' => Env::get('STORAGE_SFTP_PASSPHRASE'),
    ],
    's3' => [
        'endpoint' => Env::get('STORAGE_S3_ENDPOINT'),
        'bucket' => Env::get('STORAGE_S3_BUCKET'),
        'region' => Env::get('STORAGE_S3_REGION'),
        'access_key' => Env::get('STORAGE_S3_ACCESS_KEY'),
        'secret_key' => Env::get('STORAGE_S3_SECRET_KEY'),
        'session_token' => Env::get('STORAGE_S3_SESSION_TOKEN'),
    ],
    'azure' => [
        'account' => Env::get('STORAGE_AZURE_ACCOUNT'),
        'key' => Env::get('STORAGE_AZURE_KEY'),
        'container' => Env::get('STORAGE_AZURE_CONTAINER'),
        'endpoint' => Env::get('STORAGE_AZURE_ENDPOINT'),
    ],
];
