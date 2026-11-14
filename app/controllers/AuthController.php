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
        'login' => ['Log in', 'Welcome back. Log in with your email address and password.', '/login', 'Log in'],
        'register' => ['Create your account', 'After registering, verify your email address to continue.', '/register', 'Create account'],
        'forgot' => ['Forgot your password?', 'Enter your email address. If an account exists, you will receive a reset link.', '/forgot-password', 'Request reset link'],
        'reset' => ['Choose a new password', 'Changing your password ends all existing login sessions and invalidates API tokens.', '/reset-password', 'Change password'],
        'verify' => ['Verify your email address', 'Choose and confirm the password you will use to log in. This replaces the registration password and ends existing login sessions.', '/verify-email', 'Verify email address'],
        'notice' => ['Check your inbox', 'To continue, verify your email address using the link in our email.', '/email/verification-notification', 'Request new verification email'],
        'account' => ['Your account', 'You are logged in and your email address is verified.', '', ''],
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
        // GET/HEAD must not consume the link when email scanners open it.
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
            'If registration is possible, a verification email has been queued. Check your inbox, then log in.');
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
            'If an account exists, a reset link has been queued. Check your inbox.');
    }

    public function reset(Request $request): Response
    {
        return $this->submit($request, 'reset', fn () => $this->app->auth->resetPassword($request->all(), $request->clientIp()),
            'Your password has been changed. You can now log in again.');
    }

    public function verify(Request $request): Response
    {
        return $this->submit($request, 'verify', fn () => $this->app->auth->verifyEmail($request->input('token'), $request->clientIp(), $request->all()),
            'Your email address has been verified and your password has been set. Log in with your chosen password.');
    }

    public function resend(Request $request): Response
    {
        return $this->submit($request, 'notice', fn () => $this->app->auth->resendVerification($request->clientIp()),
            'If verification is still needed, a new verification email has been queued. Previous links are no longer valid.');
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
