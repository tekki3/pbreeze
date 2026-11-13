<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Request;
use PBreeze\Validator;

test('Framework validation and API diagnostics use US English', static function (): void {
    same([
        'name' => ['This field is required.'],
        'email' => ['Enter a valid email address.'],
        'age' => ['Enter an integer.'],
        'short' => ['Use at least 2 characters.'],
        'long' => ['Use no more than 2 characters.'],
        'value' => ['Enter text.'],
        'password' => ['The confirmation does not match.'],
    ], Validator::errors([
        'email' => 'invalid', 'age' => 'invalid', 'short' => 'A', 'long' => 'ABC', 'value' => [], 'password' => 'example',
    ], [
        'name' => 'required', 'email' => 'email', 'age' => 'integer', 'short' => 'min:2',
        'long' => 'max:2', 'value' => 'string', 'password' => 'confirmed',
    ]));
    $application = apiApplication();
    $response = $application->handle(new Request('GET', '/api/me'));
    same('A valid bearer token is required.', json_decode($response->body, true, 512, JSON_THROW_ON_ERROR)['error']['message']);
});

test('Authentication emails use English subjects, instructions, and expiration text', static function () use ($root, $config): void {
    $application = new Application($root, $config + ['mail' => ['from' => 'noreply@example.test']]);
    (new PBreeze\Migrator($application->db, $root))->migrate();
    $application->auth->register([
        'name' => 'English Example', 'email' => 'english@example.test',
        'password' => 'English-password-42', 'password_confirmation' => 'English-password-42',
    ], 'english-test');
    $application->auth->forgotPassword(['email' => 'english@example.test'], 'english-test');
    $emails = $application->db->execute('SELECT subject, text_body FROM emails ORDER BY id')->fetchAll();
    same(2, count($emails));
    same('Verify your email address', $emails[0]['subject']);
    same('Reset your password', $emails[1]['subject']);
    check(str_contains($emails[0]['text_body'], 'This link is valid for 24 hours and can only be used once.'));
    check(str_contains($emails[1]['text_body'], 'This link is valid for 30 minutes and can only be used once.'));
    foreach ($emails as $email) {
        check(str_contains($email['text_body'], 'Open this link:'));
        check(str_contains($email['text_body'], 'If you did not request this, ignore this email.'));
    }
});

test('CLI help and generated scaffolds use English without changing command names', static function () use ($root, $temp, $config): void {
    $fixture = $temp . '/english-scaffolds';
    mkdir($fixture . '/routes', 0700, true);
    file_put_contents($fixture . '/routes/web.php', '<?php');
    copy($root . '/routes/api.php', $fixture . '/routes/api.php');
    $application = new Application($fixture, $config);
    check(str_contains(console($application, ['help']), 'Usage: php breeze <command> [name] [--force]'));
    foreach ([
        ['make:controller', 'EnglishExample', '/app/controllers/EnglishExampleController.php', 'Replace this with your own action.'],
        ['make:view', 'english.example', '/app/views/english/example.php', 'New page'],
        ['make:template', 'english.compiled', '/app/views/english/compiled.breeze.php', 'New page'],
        ['make:seeder', 'EnglishExampleSeeder', '/database/seeders/EnglishExampleSeeder.php', 'Implement the seeder first.'],
        ['make:api', 'EnglishResources', '/app/controllers/api/EnglishResourcesApiController.php', 'including input validation and authorization.'],
    ] as [$command, $name, $path, $expected]) {
        check(str_contains(console($application, [$command, $name]), 'Created: '));
        check(str_contains(file_get_contents($fixture . $path), $expected));
    }
    console($application, ['make:migration', 'create_english_examples']);
    $migrations = glob($fixture . '/database/migrations/*_create_english_examples.php');
    same(1, count($migrations));
    $source = file_get_contents($migrations[0]);
    check(str_contains($source, 'Implement up() first.'));
    check(str_contains($source, 'Implement down() first.'));
});

function englishHttp(string $base): void
{
    test('HTTP pages declare US English and provide English navigation, forms, and feedback', static function () use ($base): void {
        foreach ([
            '/' => ['A fresh start.', 'Send your name', 'Show another code', 'Security check'],
            '/login' => ['Log in', 'Email address', 'Password', 'Forgot your password?'],
            '/register' => ['Create your account', 'Confirm your password', 'Create account'],
            '/forgot-password' => ['Forgot your password?', 'Request reset link'],
            '/reset-password?token=invalid' => ['Choose a new password', 'Change password', 'Request a new link'],
            '/verify-email?token=invalid' => ['Verify your email address', 'Verify email address'],
            '/api/docs' => ['API reference', 'Download OpenAPI'],
        ] as $path => $phrases) {
            $response = http($base, $path);
            same(200, $response['status']);
            check(str_contains($response['body'], '<html lang="en-US">'));
            foreach ($phrases as $phrase) {
                check(str_contains($response['body'], $phrase), "Missing English text on $path: $phrase");
            }
        }
        $client = new AuthBrowser($base);
        $client->solveCaptcha = false;
        $response = $client->request('/greet', 'POST', ['name' => 'Ada']);
        same(422, $response['status']);
        check(str_contains($response['body'], 'The security check failed. Enter the new code and try again.'));
        $response = $client->request('/greet', 'POST', ['_captcha_refresh' => '1']);
        same(200, $response['status']);
        check(str_contains($response['body'], 'New code loaded. Enter the code and submit the form again.'));
        $client->solveCaptcha = true;
        $response = $client->request('/greet', 'POST', ['name' => 'Ada']);
        same(200, $response['status']);
        check(str_contains($response['body'], 'Hello, Ada!'));
    });
}
