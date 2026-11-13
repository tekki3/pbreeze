<?php
declare(strict_types=1);

namespace App\Controllers;

use PBreeze\Application;
use PBreeze\AuthException;
use PBreeze\Request;
use PBreeze\Response;

final class AuthController
{
    private const PAGES = [
        'login' => ['Inloggen', 'Welkom terug. Log in met je e-mailadres en wachtwoord.', '/login', 'Inloggen'],
        'register' => ['Maak je account', 'Na registratie bevestig je eerst je e-mailadres.', '/register', 'Account aanmaken'],
        'forgot' => ['Wachtwoord vergeten?', 'Vul je e-mailadres in. Als er een account bestaat, ontvang je een herstellink.', '/forgot-password', 'Herstellink aanvragen'],
        'reset' => ['Kies een nieuw wachtwoord', 'Na het wijzigen worden alle bestaande inlogsessies beëindigd.', '/reset-password', 'Wachtwoord wijzigen'],
        'verify' => ['Bevestig je e-mailadres', 'Bevestig hieronder dat dit e-mailadres van jou is.', '/verify-email', 'E-mailadres bevestigen'],
        'notice' => ['Controleer je inbox', 'Bevestig je e-mailadres via de link in onze e-mail om verder te gaan.', '/email/verification-notification', 'Nieuwe verificatiemail aanvragen'],
        'account' => ['Je account', 'Je bent ingelogd en je e-mailadres is bevestigd.', '', ''],
    ];

    public function __construct(private readonly Application $app)
    {
    }

    public function loginForm(Request $request): Response
    {
        return $this->app->auth->user() === null ? $this->page('login') : Response::redirect('/account');
    }

    public function registerForm(Request $request): Response
    {
        return $this->app->auth->user() === null ? $this->page('register') : Response::redirect('/account');
    }

    public function forgotForm(Request $request): Response
    {
        return $this->page('forgot');
    }

    public function resetForm(Request $request): Response
    {
        return $this->page('reset', ['token' => $this->token($request->query('token'))]);
    }

    public function verificationLink(Request $request): Response
    {
        // GET/HEAD consumeert niets: mail-scanners mogen de link niet opgebruiken.
        return $this->page('verify', ['token' => $this->token($request->query('token'))]);
    }

    public function verificationNotice(Request $request): Response
    {
        $user = $this->app->auth->user();
        return $user !== null && $user['email_verified_at'] !== null ? Response::redirect('/account') : $this->page('notice');
    }

    public function account(Request $request): Response
    {
        return $this->page('account');
    }

    public function register(Request $request): Response
    {
        return $this->submit($request, 'register', fn () => $this->app->auth->register($request->all(), $request->clientIp()),
            'Als registratie mogelijk is, staat de verificatiemail klaar voor verzending. Controleer je inbox en log daarna in.');
    }

    public function login(Request $request): Response
    {
        return $this->submit($request, 'login', fn () => $this->app->auth->login($request->all(), $request->clientIp()), redirect: '/account');
    }

    public function logout(Request $request): Response
    {
        $this->app->auth->logout();
        return Response::redirect('/login');
    }

    public function forgot(Request $request): Response
    {
        return $this->submit($request, 'forgot', fn () => $this->app->auth->forgotPassword($request->all(), $request->clientIp()),
            'Als er een account bestaat, staat een herstellink klaar voor verzending. Controleer je inbox.');
    }

    public function reset(Request $request): Response
    {
        return $this->submit($request, 'reset', fn () => $this->app->auth->resetPassword($request->all(), $request->clientIp()),
            'Je wachtwoord is gewijzigd. Je kunt nu opnieuw inloggen.');
    }

    public function verify(Request $request): Response
    {
        return $this->submit($request, 'verify', fn () => $this->app->auth->verifyEmail($request->input('token'), $request->clientIp()),
            'Je e-mailadres is bevestigd. Je kunt verder naar je account.');
    }

    public function resend(Request $request): Response
    {
        return $this->submit($request, 'notice', fn () => $this->app->auth->resendVerification($request->clientIp()),
            'Als verificatie nog nodig is, staat een nieuwe verificatiemail klaar voor verzending. Eerdere links zijn niet meer geldig.');
    }

    private function submit(Request $request, string $mode, callable $work, ?string $message = null, ?string $redirect = null): Response
    {
        $data = [
            'oldName' => is_string($request->input('name')) ? $request->input('name') : '',
            'oldEmail' => is_string($request->input('email')) ? $request->input('email') : '',
            'token' => $this->token($request->input('token')),
        ];
        if (in_array($mode, ['register', 'login', 'forgot', 'notice'], true)) {
            if ($request->input('_captcha_refresh') === '1') {
                $this->app->botProtection->forget($request, $mode);
                return $this->page($mode, $data + ['captchaRefreshed' => true]);
            }
            $errors = $this->app->botProtection->errors($request, $mode);
            if ($errors !== []) {
                return $this->page($mode, $data + ['errors' => $errors], 422);
            }
        }
        try {
            $work();
        } catch (AuthException $error) {
            return $this->page($mode, $data + ['errors' => $error->errors], $error->status, $error->retryAfter);
        }
        return $redirect !== null ? Response::redirect($redirect) : $this->page($mode, ['message' => $message]);
    }

    private function token(mixed $value): string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1 ? $value : '';
    }

    /** @param array<string, mixed> $data */
    private function page(string $mode, array $data = [], int $status = 200, int $retryAfter = 0): Response
    {
        [$title, $description, $action, $button] = self::PAGES[$mode];
        $user = $this->app->auth->user();
        $captcha = in_array($mode, ['register', 'login', 'forgot', 'notice'], true) && empty($data['message'])
            ? $this->app->botProtection->challenge($mode) : null;
        return new Response($this->app->views->render('auth.page', $data + [
            'name' => $this->app->config['name'], 'session' => $this->app->session,
            'mode' => $mode, 'title' => $title, 'description' => $description,
            'action' => $action, 'button' => $button, 'user' => $user,
            'errors' => [], 'message' => null, 'oldName' => '', 'oldEmail' => '', 'token' => '',
            'captcha' => $captcha, 'captchaRefreshed' => false,
        ]), $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ] + ($retryAfter > 0 ? ['Retry-After' => (string) $retryAfter] : []));
    }
}
