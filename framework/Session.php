<?php
declare(strict_types=1);

namespace PBreeze;

use RuntimeException;

final class Session
{
    public function start(bool $secure = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('pbreeze_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Kan sessie niet starten.');
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function regenerate(): void
    {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Kan sessie-ID niet vernieuwen.');
        }
    }

    public function token(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->put('_csrf', $token);
        }
        return $token;
    }

    public function verifyCsrf(Request $request): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $token = $request->header('x-csrf-token') ?? $request->input('_token');
        if (!is_string($token) || !hash_equals($this->token(), $token)) {
            throw new HttpException(419, 'Sessiecontrole mislukt. Vernieuw de pagina en probeer opnieuw.');
        }
    }
}
