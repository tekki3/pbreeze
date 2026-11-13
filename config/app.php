<?php
declare(strict_types=1);

use PBreeze\Env;

return [
    'name' => Env::get('APP_NAME', 'PBreeze'),
    'environment' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG'),
    'secure_cookie' => Env::bool('SESSION_SECURE_COOKIE'),
    'database' => [
        'driver' => Env::get('DB_CONNECTION', 'sqlite'),
        'database' => Env::get('DB_DATABASE', 'database/app.sqlite'),
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::get('DB_PORT', '3306'),
        'username' => Env::get('DB_USERNAME', 'root'),
        'password' => Env::get('DB_PASSWORD'),
    ],
];
