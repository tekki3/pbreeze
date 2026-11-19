<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Database;

$root = dirname(__DIR__, 2);
require $root . '/bootstrap.php';

$app = new Application($root, [
    'name' => 'Test', 'environment' => 'testing', 'debug' => false, 'secure_cookie' => false,
    'url' => 'https://example.test', 'database' => ['driver' => 'sqlite', 'database' => $argv[1]],
    'mail' => ['from' => 'noreply@example.test'],
]);
$created = false;
// Wait until the parent has started both workers.
stream_get_contents(STDIN);
$app->auth->register([
    'name' => 'Ada', 'email' => 'ada@example.test',
    'password' => 'A long password 42!', 'password_confirmation' => 'A long password 42!',
], $argv[2], static function (int $id, Database $db) use (&$created): void {
    $db->insert('registration_details', ['user_id' => $id], null);
    $created = true;
});
echo $created ? 'inserted' : 'duplicate';
