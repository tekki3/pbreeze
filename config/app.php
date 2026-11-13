<?php
declare(strict_types=1);

use PBreeze\Env;

$environment = Env::get('APP_ENV', 'production');
if (!in_array($environment, ['local', 'testing', 'production'], true)) {
    throw new RuntimeException('APP_ENV must be local, testing, or production.');
}
$uploadMaxBytes = filter_var(Env::get('UPLOAD_MAX_BYTES', '2097152'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($uploadMaxBytes === false) {
    throw new RuntimeException('UPLOAD_MAX_BYTES must be a positive integer byte size.');
}

return [
    'name' => Env::get('APP_NAME', 'PBreeze'),
    'locale' => Env::get('APP_LOCALE', 'en-US'),
    'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
    'url' => Env::get('APP_URL', 'http://127.0.0.1:8000'),
    'key' => Env::get('APP_KEY'),
    'environment' => $environment,
    'debug' => Env::bool('APP_DEBUG'),
    'secure_cookie' => Env::bool('SESSION_SECURE_COOKIE'),
    'api_docs' => Env::bool('API_DOCS_ENABLED', $environment !== 'production'),
    'upload_max_bytes' => $uploadMaxBytes,
    'database' => [
        'driver' => Env::get('DB_CONNECTION', 'sqlite'),
        'database' => Env::get('DB_DATABASE', 'database/app.sqlite'),
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::get('DB_PORT', '3306'),
        'username' => Env::get('DB_USERNAME', 'root'),
        'password' => Env::get('DB_PASSWORD'),
    ],
];
