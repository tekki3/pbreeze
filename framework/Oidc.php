<?php
declare(strict_types=1);

namespace PBreeze;

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class Oidc
{
    private readonly string $redirectUri;
    private readonly int $timeout;

    /**
     * @param array{enabled?: bool, name?: string, issuer?: string, client_id?: string, client_secret?: string, timeout?: int, ca_file?: string} $config
     * @param Closure(string, list<string>, ?string): string|null $transport Trusted HTTPS replacement for isolated tests.
     */
    public function __construct(
        private readonly Session $session,
        #[\SensitiveParameter] private readonly array $config = [],
        string $url = 'http://127.0.0.1:8000',
        private readonly ?Closure $transport = null,
    ) {
        $this->timeout = $config['timeout'] ?? 10;
        if ($this->timeout < 1 || $this->timeout > 60) {
            throw new InvalidArgumentException('SSO timeout must be 1..60 seconds.');
        }
        $this->redirectUri = rtrim($url, '/') . '/sso/callback';
        if (!$this->enabled()) {
            return;
        }
        $this->httpsUrl($config['issuer'] ?? '', false);
        $this->httpsUrl($url, false);
        if (!in_array(parse_url($url, PHP_URL_PATH), [null, '', '/'], true)) {
            throw new InvalidArgumentException('SSO APP_URL must be an HTTPS origin without a path.');
        }
        if (($config['client_id'] ?? '') === '' || strlen($config['client_id']) > 1024
            || preg_match('/[\x00-\x20\x7f]/', $config['client_id'])
            || trim($this->name()) === '' || mb_strlen($this->name()) > 80) {
            throw new InvalidArgumentException('SSO requires a client ID and a display name of 1..80 characters.');
        }
    }

    public function enabled(): bool
    {
        return $this->config['enabled'] ?? false;
    }

    public function name(): string
    {
        return $this->config['name'] ?? 'Single sign-on';
    }

    public function begin(): string
    {
        $metadata = $this->discovery();
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $verifier = self::encode(random_bytes(32));
        $this->session->put('_oidc', [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'issued' => time(),
            'binding' => $this->binding(),
        ]);
        return $metadata['authorization_endpoint']
            . (str_contains($metadata['authorization_endpoint'], '?') ? '&' : '?')
            . http_build_query([
                'response_type' => 'code', 'response_mode' => 'query', 'scope' => 'openid email profile',
                'client_id' => $this->config['client_id'], 'redirect_uri' => $this->redirectUri,
                'state' => $state, 'nonce' => $nonce,
                'code_challenge' => self::encode(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{issuer: string, subject: string, email: ?string, name: ?string}
     */
    public function complete(#[\SensitiveParameter] array $query): array
    {
        $pending = $this->session->get('_oidc');
        $this->session->put('_oidc', null);
        if (!$this->enabled() || !is_array($pending)
            || !is_string($query['state'] ?? null) || !hash_equals($pending['state'], $query['state'])
            || $pending['issued'] <= time() - 600 || $pending['issued'] > time()
            || !hash_equals($this->binding(), $pending['binding'])
            || isset($query['error']) || !is_string($query['code'] ?? null)
            || $query['code'] === '' || strlen($query['code']) > 4096
            || (isset($query['iss']) && $query['iss'] !== $this->config['issuer'])) {
            throw new AuthException(['sso' => ['Single sign-on could not be completed. Start login again.']], 400);
        }
        $metadata = $this->discovery();
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $data = [
            'grant_type' => 'authorization_code', 'code' => $query['code'],
            'redirect_uri' => $this->redirectUri, 'code_verifier' => $pending['verifier'],
            'client_id' => $this->config['client_id'],
        ];
        $secret = $this->config['client_secret'] ?? '';
        $methods = $metadata['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        if (!is_array($methods)) {
            throw new RuntimeException('Invalid SSO token authentication metadata.');
        }
        if ($secret !== '') {
            if (in_array('client_secret_basic', $methods, true)) {
                $headers[] = 'Authorization: Basic ' . base64_encode(urlencode($data['client_id']) . ':' . urlencode($secret));
            } elseif (in_array('client_secret_post', $methods, true)) {
                $data['client_secret'] = $secret;
            } else {
                throw new RuntimeException('SSO requires client_secret_basic or client_secret_post support.');
            }
        } elseif (!in_array('none', $methods, true)) {
            throw new RuntimeException('Configure an SSO client secret for this provider.');
        }
        $tokens = $this->json($this->fetch($metadata['token_endpoint'], $headers, http_build_query($data, '', '&')));
        if (isset($tokens['error']) || !is_string($tokens['id_token'] ?? null)) {
            throw new AuthException(['sso' => ['The identity provider rejected this login. Try again.']], 400);
        }
        $claims = $this->verify($tokens['id_token'], $metadata['jwks_uri'], $pending['nonce']);
        foreach (['at_hash' => $tokens['access_token'] ?? null, 'c_hash' => $query['code']] as $claim => $value) {
            if (isset($claims[$claim]) && (!is_string($claims[$claim]) || !is_string($value)
                || !hash_equals(self::encode(substr(hash('sha256', $value, true), 0, 16)), $claims[$claim]))) {
                throw new AuthException(['sso' => ['The identity provider returned an invalid token.']], 400);
            }
        }
        $email = $claims['email'] ?? null;
        $email = ($claims['email_verified'] ?? false) === true && is_string($email)
            && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? strtolower($email) : null;
        $name = $claims['name'] ?? null;
        return [
            'issuer' => $claims['iss'], 'subject' => $claims['sub'], 'email' => $email,
            'name' => is_string($name) && trim($name) !== '' && mb_strlen($name) <= 80 ? $name : null,
        ];
    }

    private function binding(): string
    {
        return hash('sha256', json_encode([$this->config['issuer'], $this->config['client_id'],
            $this->redirectUri], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function discovery(): array
    {
        if (!$this->enabled()) {
            throw new HttpException(404, 'Single sign-on is not enabled.');
        }
        $metadata = $this->json($this->fetch(rtrim($this->config['issuer'], '/') . '/.well-known/openid-configuration'));
        if (($metadata['issuer'] ?? null) !== $this->config['issuer']
            || !is_array($metadata['id_token_signing_alg_values_supported'] ?? null)
            || !in_array('RS256', $metadata['id_token_signing_alg_values_supported'], true)
            || (isset($metadata['code_challenge_methods_supported'])
                && (!is_array($metadata['code_challenge_methods_supported'])
                    || !in_array('S256', $metadata['code_challenge_methods_supported'], true)))) {
            throw new RuntimeException('SSO discovery does not match the configured issuer or required RS256/PKCE support.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            if (!is_string($metadata[$key] ?? null)) {
                throw new RuntimeException('SSO discovery is missing a required endpoint.');
            }
            $this->httpsUrl($metadata[$key]);
        }
        return $metadata;
    }

    /** @return array<string, mixed> */
    private function verify(#[\SensitiveParameter] string $jwt, string $jwksUri, string $nonce): array
    {
        $reject = static fn (): AuthException => new AuthException(['sso' => ['The identity provider returned an invalid ID token.']], 400);
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || strlen($jwt) > 65536) {
            throw $reject();
        }
        $header = $this->json($this->decode($parts[0]));
        $claims = $this->json($this->decode($parts[1]));
        if (($header['alg'] ?? null) !== 'RS256' || isset($header['crit']) || isset($header['b64'])
            || (isset($header['kid']) && !is_string($header['kid']))) {
            throw $reject();
        }
        $jwks = $this->json($this->fetch($jwksUri));
        if (!is_array($jwks['keys'] ?? null) || count($jwks['keys']) > 100) {
            throw new RuntimeException('Invalid SSO signing key set.');
        }
        $keys = array_values(array_filter($jwks['keys'], static fn ($key): bool => is_array($key)
            && ($key['kty'] ?? null) === 'RSA' && ($key['use'] ?? 'sig') === 'sig'
            && ($key['alg'] ?? 'RS256') === 'RS256'
            && (!isset($header['kid']) || ($key['kid'] ?? null) === $header['kid'])
            && (!isset($key['key_ops']) || (is_array($key['key_ops']) && in_array('verify', $key['key_ops'], true)))));
        if (count($keys) !== 1 || !is_string($keys[0]['n'] ?? null) || !is_string($keys[0]['e'] ?? null)) {
            throw $reject();
        }
        $modulus = $this->decode($keys[0]['n']);
        $exponent = $this->decode($keys[0]['e']);
        if (strlen($modulus) < 256 || strlen($modulus) > 1024 || strlen($exponent) > 8) {
            throw $reject();
        }
        $integer = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\0");
            return self::der(2, (ord($bytes[0] ?? "\0") >= 128 ? "\0" : '') . $bytes);
        };
        $rsa = self::der(48, $integer($modulus) . $integer($exponent));
        $spki = self::der(48, hex2bin('300d06092a864886f70d0101010500') . self::der(3, "\0" . $rsa));
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false || openssl_verify($parts[0] . '.' . $parts[1], $this->decode($parts[2]), $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw $reject();
        }
        $audience = $claims['aud'] ?? null;
        $audiences = is_string($audience) ? [$audience] : $audience;
        $now = time();
        if (($claims['iss'] ?? null) !== $this->config['issuer']
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255
            || preg_match('/[^\x21-\x7e]/', $claims['sub'])
            || !is_array($audiences) || !array_is_list($audiences) || $audiences === []
            || count(array_filter($audiences, 'is_string')) !== count($audiences)
            || !in_array($this->config['client_id'], $audiences, true)
            || (count($audiences) > 1 && !isset($claims['azp']))
            || (isset($claims['azp']) && $claims['azp'] !== $this->config['client_id'])
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > $now + 60 || $claims['iat'] < $now - 600
            || $claims['exp'] <= $claims['iat']
            || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now + 60))
            || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            throw $reject();
        }
        return $claims;
    }

    private static function der(int $tag, string $value): string
    {
        $length = strlen($value);
        $bytes = ltrim(pack('N', $length), "\0");
        return chr($tag) . ($length < 128 ? chr($length) : chr(128 + strlen($bytes)) . $bytes) . $value;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(#[\SensitiveParameter] string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new AuthException(['sso' => ['The identity provider returned invalid token encoding.']], 400);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || self::encode($decoded) !== $value) {
            throw new AuthException(['sso' => ['The identity provider returned invalid token encoding.']], 400);
        }
        return $decoded;
    }

    /** @return array<string, mixed> */
    private function json(#[\SensitiveParameter] string $value): array
    {
        try {
            $data = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('The identity provider returned invalid JSON.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('The identity provider must return a JSON object.');
        }
        return $data;
    }

    private function httpsUrl(string $url, bool $query = true): void
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (!$query && isset($parts['query'])) || strlen($url) > 2048
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('SSO requires trusted absolute HTTPS URLs without credentials or fragments.');
        }
    }

    /** @param list<string> $headers */
    private function fetch(string $url, #[\SensitiveParameter] array $headers = [], #[\SensitiveParameter] ?string $body = null): string
    {
        $this->httpsUrl($url);
        $headers[] = 'Accept: application/json';
        if ($this->transport !== null) {
            $response = ($this->transport)($url, $headers, $body);
            if (strlen($response) > 1048576) {
                throw new RuntimeException('SSO response exceeds the 1 MiB limit.');
            }
            return $response;
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('SSO requires the native curl extension.');
        }
        $ca = $this->config['ca_file'] ?? '';
        if ($ca !== '' && (!is_file($ca) || !is_readable($ca))) {
            throw new InvalidArgumentException('The SSO CA file must be a readable local file.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Cannot initialize the SSO HTTPS request.');
        }
        $response = '';
        try {
            $options = [
                CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout), CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 1048576) {
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ];
            if ($body !== null) {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            if ($ca !== '') {
                $options[CURLOPT_CAINFO] = $ca;
            }
            if (!curl_setopt_array($handle, $options) || curl_exec($handle) === false) {
                throw new RuntimeException('SSO HTTPS request failed. Check connectivity, certificates, and response size.');
            }
            if (curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new RuntimeException('The identity provider returned an unsuccessful HTTP status.');
            }
        } finally {
            unset($handle);
        }
        return $response;
    }
}
