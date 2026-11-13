<?php
declare(strict_types=1);

use PBreeze\Database;

test('Alle gekozen publieke formulieren weigeren ontbrekende CAPTCHA en een gevulde honeypot zonder neveneffecten', static function () use ($root, $temp, $base): void {
    $db = new Database($root, ['driver' => 'sqlite', 'database' => $temp . '/http.sqlite']);
    $client = new AuthBrowser($base);
    $client->solveCaptcha = false;
    $users = (int) $db->execute('SELECT COUNT(*) FROM users')->fetchColumn();
    $emails = (int) $db->execute('SELECT COUNT(*) FROM emails')->fetchColumn();
    foreach (['/register', '/login', '/forgot-password', '/greet'] as $path) {
        $formPath = $path === '/greet' ? '/' : $path;
        $form = $client->request($formPath);
        check(str_contains($form['body'], 'name="website"'));
        check(str_contains($form['body'], 'name="_captcha_id"'));
        same(422, $client->request($path, 'POST', authData('bots@example.test'))['status']);
        $form = $client->request($formPath);
        $fields = captchaFields($client->cookie, $form['body']);
        $response = $client->request($path, 'POST', authData('bots@example.test') + array_replace($fields, ['website' => 'spam']));
        same(422, $response['status']);
        check(str_contains($response['body'], 'De beveiligingscontrole is niet gelukt.'));
        check(!str_contains($response['body'], 'Hallo,'));
        same(303, $client->request('/account')['status']);
    }
    same($users, (int) $db->execute('SELECT COUNT(*) FROM users')->fetchColumn());
    same($emails, (int) $db->execute('SELECT COUNT(*) FROM emails')->fetchColumn());
});

test('CAPTCHA-afbeeldingen vereisen dezelfde sessie, HEAD consumeert niets en vernieuwen trekt alleen de oude code in', static function () use ($base): void {
    $client = new AuthBrowser($base);
    $client->solveCaptcha = false;
    $form = $client->request('/register');
    $fields = captchaFields($client->cookie, $form['body']);
    $path = '/captcha/' . $fields['_captcha_id'];
    $image = $client->request($path);
    same(200, $image['status']);
    check(str_contains(implode("\n", $image['headers']), 'image/svg+xml'));
    check(str_contains(implode("\n", $image['headers']), 'no-store'));
    same(200, $client->request($path, 'HEAD')['status']);
    same('', $client->request($path, 'HEAD')['body']);
    same($image['body'], $client->request($path)['body']);
    same(404, http($base, $path)['status']);
    same(404, (new AuthBrowser($base))->request($path)['status']);
    $response = $client->request('/register', 'POST', authData('refresh@example.test') + $fields + ['_captcha_refresh' => '1']);
    same(200, $response['status']);
    check(str_contains($response['body'], 'Nieuwe code geladen.'));
    check(!str_contains($response['body'], 'staat de verificatiemail klaar'));
    check(!str_contains($response['body'], authData()['password']));
    check(str_contains($response['body'], 'value="refresh@example.test"'));
    same(404, $client->request($path)['status']);
    same(422, $client->request('/register', 'POST', authData('refresh@example.test') + $fields)['status']);
    $form = $client->request('/register');
    $fields = captchaFields($client->cookie, $form['body']);
    same(419, $client->request('/register', 'POST', $fields + ['_token' => 'bad', '_captcha_refresh' => '1'])['status']);
});

test('Voorbeeldformulier vernieuwt zonder begroeting en verbruikt oplossingen ook bij invoerfouten', static function () use ($base): void {
    $client = new AuthBrowser($base);
    $client->solveCaptcha = false;
    $form = $client->request('/');
    $fields = captchaFields($client->cookie, $form['body']);
    $response = $client->request('/greet', 'POST', $fields + ['_captcha_refresh' => '1', 'name' => '<Ada>']);
    same(200, $response['status']);
    check(str_contains($response['body'], 'Nieuwe code geladen.'));
    check(!str_contains($response['body'], 'Hallo,'));
    check(str_contains($response['body'], 'value="&lt;Ada&gt;"'));
    $fields = captchaFields($client->cookie, $response['body']);
    $response = $client->request('/greet', 'POST', $fields + ['name' => 'A']);
    same(422, $response['status']);
    check(str_contains($response['body'], 'Gebruik minimaal 2 tekens.'));
    same(422, $client->request('/greet', 'POST', $fields + ['name' => 'Ada'])['status']);
    $fields = captchaFields($client->cookie, $response['body']);
    $response = $client->request('/greet', 'POST', $fields + ['name' => '<Ada>']);
    same(200, $response['status']);
    check(str_contains($response['body'], 'Hallo, &lt;Ada&gt;!'));
    same(422, $client->request('/greet', 'POST', $fields + ['name' => 'Ada'])['status']);
});

test('Verificatiemail opnieuw aanvragen vereist beide botcontroles en vernieuwen verstuurt geen mail', static function () use ($root, $temp, $base): void {
    $db = new Database($root, ['driver' => 'sqlite', 'database' => $temp . '/http.sqlite']);
    $db->execute('DELETE FROM auth_limits');
    $client = new AuthBrowser($base);
    same(200, $client->request('/register', 'POST', authData('captcha-resend@example.test'))['status']);
    same(303, $client->request('/login', 'POST', authData('captcha-resend@example.test'))['status']);
    $client->solveCaptcha = false;
    $count = (int) $db->execute('SELECT COUNT(*) FROM emails')->fetchColumn();
    $path = '/email/verification-notification';
    $form = $client->request('/email/verify');
    same(422, $client->request($path, 'POST')['status']);
    $fields = captchaFields($client->cookie, $form['body']);
    same(422, $client->request($path, 'POST', array_replace($fields, ['website' => 'spam']))['status']);
    $form = $client->request('/email/verify');
    $fields = captchaFields($client->cookie, $form['body']);
    same(200, $client->request($path, 'POST', $fields + ['_captcha_refresh' => '1'])['status']);
    same($count, (int) $db->execute('SELECT COUNT(*) FROM emails')->fetchColumn());
    $form = $client->request('/email/verify');
    $fields = captchaFields($client->cookie, $form['body']);
    same(200, $client->request($path, 'POST', $fields)['status']);
    same($count + 1, (int) $db->execute('SELECT COUNT(*) FROM emails')->fetchColumn());
    same(422, $client->request($path, 'POST', $fields)['status']);
});

test('Een verlopen inlogsessie roteert voordat een nieuwe bruikbare CAPTCHA wordt uitgegeven', static function () use ($temp, $base): void {
    $client = new AuthBrowser($base);
    same(303, $client->request('/login', 'POST', authData('captcha-resend@example.test'))['status']);
    $form = $client->request('/email/verify');
    $old = captchaFields($client->cookie, $form['body']);
    $file = $temp . '/http-sessions/sess_' . substr($client->cookie, strlen('pbreeze_session='));
    $state = unserialize(file_get_contents($file), ['allowed_classes' => false]);
    $state['_auth']['seen'] = time() - 1800;
    file_put_contents($file, serialize($state));
    $cookie = $client->cookie;
    $form = $client->request('/forgot-password');
    same(200, $form['status']);
    check($cookie !== $client->cookie);
    $fields = captchaFields($client->cookie, $form['body']);
    same(200, $client->request('/captcha/' . $fields['_captcha_id'])['status']);
    same(404, $client->request('/captcha/' . $old['_captcha_id'])['status']);
});
