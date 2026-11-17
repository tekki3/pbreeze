<?php
declare(strict_types=1);

use PBreeze\Env;

$timeout = filter_var(Env::get('SSO_TIMEOUT', '10'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 60],
]);
if ($timeout === false) {
    throw new RuntimeException('SSO_TIMEOUT must be 1..60 seconds.');
}

return [
    'enabled' => Env::bool('SSO_ENABLED', false),
    'name' => Env::get('SSO_NAME', 'Single sign-on'),
    'issuer' => Env::get('SSO_ISSUER'),
    'client_id' => Env::get('SSO_CLIENT_ID'),
    'client_secret' => Env::get('SSO_CLIENT_SECRET'),
    'timeout' => $timeout,
    'ca_file' => Env::get('SSO_CA_FILE'),
];
