<?php
declare(strict_types=1);

use PBreeze\BotProtection;
use PBreeze\Request;
use PBreeze\Session;

/** @return array{_captcha_id: string, _captcha_answer: string, website: string} */
function captchaFields(string $cookie, string $html): array
{
    global $temp;
    check(preg_match('/(?:^|; )pbreeze_session=([a-zA-Z0-9,-]+)/', $cookie, $session) === 1);
    check(preg_match('/name="_captcha_id" value="([a-f0-9]{32})"/', $html, $match) === 1, 'CAPTCHA ontbreekt in het formulier.');
    $file = $temp . '/http-sessions/sess_' . $session[1];
    $state = unserialize(file_get_contents($file), ['allowed_classes' => false]);
    check(is_array($state) && isset($state['_captchas'][$match[1]]));
    // Alleen de geisoleerde testclient kent een vaste oplossing; de app heeft geen test-bypass.
    $state['_captchas'][$match[1]]['hash'] = hash('sha256', 'TEST23');
    file_put_contents($file, serialize($state));
    return ['_captcha_id' => $match[1], '_captcha_answer' => 'TEST23', 'website' => ''];
}

function botFixture(callable $work): void
{
    $before = $_SESSION ?? [];
    $_SESSION = [];
    try {
        $session = new Session();
        $work(new BotProtection($session), $session);
    } finally {
        $_SESSION = $before;
    }
}

/** @return array{_captcha_id: string, _captcha_answer: string, website: string} */
function botAnswer(BotProtection $bot, Session $session, string $purpose = 'register'): array
{
    $challenge = $bot->challenge($purpose);
    $state = $session->get('_captchas');
    $state[$challenge['id']]['hash'] = hash('sha256', 'TEST23');
    $session->put('_captchas', $state);
    return ['_captcha_id' => $challenge['id'], '_captcha_answer' => 'TEST23', 'website' => ''];
}

test('CAPTCHA is sessiegebonden, formuliergebonden, hoofdletterongevoelig en eenmalig', static function (): void {
    botFixture(static function (BotProtection $bot, Session $session): void {
        $data = botAnswer($bot, $session);
        $data['_captcha_answer'] = ' test23 ';
        same([], $bot->errors(new Request('POST', '/register', [], $data), 'register'));
        check($bot->errors(new Request('POST', '/register', [], $data), 'register') !== []);
        $data = botAnswer($bot, $session);
        check($bot->errors(new Request('POST', '/login', [], $data), 'login') !== []);
        check($bot->errors(new Request('POST', '/register', [], $data), 'register') !== []);
        $data = botAnswer($bot, $session);
        $state = $session->get('_captchas');
        $session->put('_captchas', []);
        check($bot->errors(new Request('POST', '/register', [], $data), 'register') !== []);
        $session->put('_captchas', $state);
        same([], $bot->errors(new Request('POST', '/register', [], $data), 'register'));
    });
});

test('Honeypot en ongeldige invoertypes falen en verbruiken ook een correct opgeloste CAPTCHA', static function (): void {
    botFixture(static function (BotProtection $bot, Session $session): void {
        foreach ([
            ['website' => 'https://spam.example.test'], ['website' => ' '], ['website' => ['']],
            ['_captcha_answer' => ['TEST23']], ['_captcha_answer' => 'WRONG2'], ['_captcha_answer' => str_repeat('a', 10000)],
            ['_captcha_id' => []],
        ] as $override) {
            $data = botAnswer($bot, $session);
            check($bot->errors(new Request('POST', '/', [], array_replace($data, $override)), 'register') !== []);
            if (!array_key_exists('_captcha_id', $override)) {
                check($bot->errors(new Request('POST', '/', [], $data), 'register') !== []);
            }
        }
        $data = botAnswer($bot, $session);
        unset($data['website']);
        check($bot->errors(new Request('POST', '/', [], $data), 'register') !== []);
        check($bot->errors(new Request('POST', '/'), 'register') !== []);
    });
});

test('CAPTCHA verloopt exact na vijf minuten en ontbrekende afbeeldingen geven 404', static function (): void {
    botFixture(static function (BotProtection $bot, Session $session): void {
        $data = botAnswer($bot, $session);
        $state = $session->get('_captchas');
        $state[$data['_captcha_id']]['expires'] = time();
        $session->put('_captchas', $state);
        raises(fn () => $bot->image($data['_captcha_id']), PBreeze\HttpException::class, 404);
        check($bot->errors(new Request('POST', '/', [], $data), 'register') !== []);
        raises(fn () => $bot->image(str_repeat('0', 32)), PBreeze\HttpException::class, 404);
        raises(fn () => $bot->image('../bad'), PBreeze\HttpException::class, 404);
    });
});

test('SVG bevat alleen lokale vectorpaden en beeld ophalen verbruikt de uitdaging niet', static function (): void {
    botFixture(static function (BotProtection $bot, Session $session): void {
        $data = botAnswer($bot, $session);
        $image = $bot->image($data['_captcha_id']);
        same(200, $image->status);
        same('image/svg+xml; charset=UTF-8', $image->headers['Content-Type']);
        check(str_contains($image->headers['Cache-Control'], 'no-store'));
        check(str_contains($image->headers['Content-Security-Policy'], "default-src 'none'"));
        check(str_contains($image->body, '<svg'));
        same(6, substr_count($image->body, '<g transform='));
        foreach (['<text', '<script', '<foreignObject', 'href=', 'TEST23', 'data:'] as $forbidden) {
            check(!str_contains($image->body, $forbidden));
        }
        same($image->body, $bot->image($data['_captcha_id'])->body);
        same([], $bot->errors(new Request('POST', '/', [], $data), 'register'));
        raises(fn () => $bot->image($data['_captcha_id']), PBreeze\HttpException::class, 404);
    });
});

test('Meerdere tabbladen blijven onafhankelijk en sessieopslag en aanmaakfrequentie zijn begrensd', static function (): void {
    botFixture(static function (BotProtection $bot, Session $session): void {
        $first = botAnswer($bot, $session);
        $second = botAnswer($bot, $session);
        same([], $bot->errors(new Request('POST', '/', [], $first), 'register'));
        same([], $bot->errors(new Request('POST', '/', [], $second), 'register'));
        for ($i = 0; $i < 58; $i++) {
            $bot->challenge('greet');
        }
        same(12, count($session->get('_captchas')));
        raises(fn () => $bot->challenge('greet'), PBreeze\HttpException::class, 429);
        $session->put('_captcha_window', ['started' => time() - 60, 'count' => 60]);
        $bot->challenge('greet');
        same(12, count($session->get('_captchas')));
        raises(fn () => $bot->challenge('../bad'), InvalidArgumentException::class);
    });
});

test('Vernieuwen verwijdert alleen de oude uitdaging voor hetzelfde formulier', static function (): void {
    botFixture(static function (BotProtection $bot, Session $session): void {
        $data = botAnswer($bot, $session);
        $other = botAnswer($bot, $session, 'login');
        $bot->forget(new Request('POST', '/', [], $data), 'register');
        check($bot->errors(new Request('POST', '/', [], $data), 'register') !== []);
        same([], $bot->errors(new Request('POST', '/', [], $other), 'login'));
    });
});
