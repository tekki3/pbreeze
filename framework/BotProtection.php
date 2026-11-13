<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;

final class BotProtection
{
    private const GLYPHS = [
        '2' => ['01110', '10001', '00001', '00010', '00100', '01000', '11111'],
        '3' => ['11110', '00001', '00001', '01110', '00001', '00001', '11110'],
        '4' => ['00010', '00110', '01010', '10010', '11111', '00010', '00010'],
        '5' => ['11111', '10000', '10000', '11110', '00001', '00001', '11110'],
        '6' => ['01110', '10000', '10000', '11110', '10001', '10001', '01110'],
        '7' => ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
        '8' => ['01110', '10001', '10001', '01110', '10001', '10001', '01110'],
        '9' => ['01110', '10001', '10001', '01111', '00001', '00001', '01110'],
        'A' => ['01110', '10001', '10001', '11111', '10001', '10001', '10001'],
        'B' => ['11110', '10001', '10001', '11110', '10001', '10001', '11110'],
        'C' => ['01111', '10000', '10000', '10000', '10000', '10000', '01111'],
        'D' => ['11110', '10001', '10001', '10001', '10001', '10001', '11110'],
        'E' => ['11111', '10000', '10000', '11110', '10000', '10000', '11111'],
        'F' => ['11111', '10000', '10000', '11110', '10000', '10000', '10000'],
        'G' => ['01111', '10000', '10000', '10111', '10001', '10001', '01111'],
        'H' => ['10001', '10001', '10001', '11111', '10001', '10001', '10001'],
        'J' => ['00111', '00010', '00010', '00010', '10010', '10010', '01100'],
        'K' => ['10001', '10010', '10100', '11000', '10100', '10010', '10001'],
        'L' => ['10000', '10000', '10000', '10000', '10000', '10000', '11111'],
        'M' => ['10001', '11011', '10101', '10101', '10001', '10001', '10001'],
        'N' => ['10001', '11001', '10101', '10011', '10001', '10001', '10001'],
        'P' => ['11110', '10001', '10001', '11110', '10000', '10000', '10000'],
        'Q' => ['01110', '10001', '10001', '10001', '10101', '10010', '01101'],
        'R' => ['11110', '10001', '10001', '11110', '10100', '10010', '10001'],
        'S' => ['01111', '10000', '10000', '01110', '00001', '00001', '11110'],
        'T' => ['11111', '00100', '00100', '00100', '00100', '00100', '00100'],
        'U' => ['10001', '10001', '10001', '10001', '10001', '10001', '01110'],
        'V' => ['10001', '10001', '10001', '10001', '10001', '01010', '00100'],
        'W' => ['10001', '10001', '10001', '10101', '10101', '10101', '01010'],
        'X' => ['10001', '10001', '01010', '00100', '01010', '10001', '10001'],
        'Y' => ['10001', '10001', '01010', '00100', '00100', '00100', '00100'],
        'Z' => ['11111', '00001', '00010', '00100', '01000', '10000', '11111'],
    ];

    public function __construct(private readonly Session $session)
    {
    }

    /** @return array{id: string, image: string} */
    public function challenge(string $purpose): array
    {
        $this->purpose($purpose);
        $window = $this->session->get('_captcha_window', ['started' => time(), 'count' => 0]);
        if ($window['started'] <= time() - 60) {
            $window = ['started' => time(), 'count' => 0];
        }
        if ($window['count'] >= 60) {
            throw new HttpException(429, 'Te veel beveiligingscodes aangevraagd. Wacht een minuut en probeer opnieuw.', [
                'Retry-After' => (string) max(1, $window['started'] + 60 - time()),
            ]);
        }
        $window['count']++;
        $this->session->put('_captcha_window', $window);
        $challenges = $this->active();
        while (count($challenges) >= 12) {
            array_shift($challenges);
        }
        $alphabet = array_keys(self::GLYPHS);
        $answer = '';
        for ($i = 0; $i < 6; $i++) {
            $answer .= $alphabet[random_int(0, count($alphabet) - 1)];
        }
        $id = bin2hex(random_bytes(16));
        $challenges[$id] = [
            'purpose' => $purpose, 'hash' => hash('sha256', $answer), 'expires' => time() + 300,
            'svg' => $this->svg($answer),
        ];
        $this->session->put('_captchas', $challenges);
        return ['id' => $id, 'image' => '/captcha/' . $id];
    }

    /** @return array<string, list<string>> */
    public function errors(Request $request, string $purpose): array
    {
        $this->purpose($purpose);
        $challenges = $this->active();
        $id = $request->input('_captcha_id');
        $challenge = is_string($id) ? ($challenges[$id] ?? null) : null;
        if (is_string($id)) {
            unset($challenges[$id]);
        }
        // Ook een onjuist antwoord of gevulde honeypot verbruikt de uitdaging.
        $this->session->put('_captchas', $challenges);
        $answer = $request->input('_captcha_answer');
        $trap = $request->input('website');
        if (!is_array($challenge) || $challenge['purpose'] !== $purpose || !is_string($answer)
            || preg_match('/^[a-zA-Z2-9]{6}$/D', trim($answer)) !== 1
            || !is_string($trap) || $trap !== ''
            || !hash_equals($challenge['hash'], hash('sha256', strtoupper(trim($answer))))) {
            return ['captcha' => ['De beveiligingscontrole is niet gelukt. Vul de nieuwe code in en probeer opnieuw.']];
        }
        return [];
    }

    public function forget(Request $request, string $purpose): void
    {
        $this->purpose($purpose);
        $challenges = $this->active();
        $id = $request->input('_captcha_id');
        if (is_string($id) && ($challenges[$id]['purpose'] ?? null) === $purpose) {
            unset($challenges[$id]);
        }
        $this->session->put('_captchas', $challenges);
    }

    public function image(string $id): Response
    {
        $challenges = $this->active();
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || !isset($challenges[$id])) {
            throw new HttpException(404, 'Beveiligingscode niet beschikbaar. Vernieuw het formulier.');
        }
        return new Response($challenges[$id]['svg'], 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, private, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** @return array<string, array{purpose: string, hash: string, expires: int, svg: string}> */
    private function active(): array
    {
        $challenges = array_filter(
            $this->session->get('_captchas', []),
            static fn (array $challenge): bool => $challenge['expires'] > time(),
        );
        $this->session->put('_captchas', $challenges);
        return $challenges;
    }

    private function purpose(string $purpose): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $purpose) !== 1) {
            throw new InvalidArgumentException('Gebruik een vaste formuliernaam voor botbescherming.');
        }
    }

    private function svg(#[\SensitiveParameter] string $answer): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="80" viewBox="0 0 240 80">'
            . '<rect width="240" height="80" fill="#edf4ef"/>';
        for ($i = 0; $i < 9; $i++) {
            $svg .= '<path d="M' . random_int(0, 240) . ' ' . random_int(0, 80) . 'L'
                . random_int(0, 240) . ' ' . random_int(0, 80) . '" stroke="#a4b9ac" fill="none"/>';
        }
        foreach (str_split($answer) as $index => $character) {
            $path = '';
            foreach (self::GLYPHS[$character] as $y => $row) {
                foreach (str_split($row) as $x => $pixel) {
                    if ($pixel === '1') {
                        $path .= "M$x $y" . 'h1v1h-1z';
                    }
                }
            }
            $svg .= '<g transform="translate(' . (15 + $index * 36) . ' ' . random_int(21, 28)
                . ') rotate(' . random_int(-9, 9) . ' 12 17) scale(5)" fill="#1d3029"><path d="' . $path . '"/></g>';
        }
        return $svg . '</svg>';
    }
}
