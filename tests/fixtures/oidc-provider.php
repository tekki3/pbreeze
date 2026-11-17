<?php
declare(strict_types=1);

final class OidcProvider
{
    public array $claims = [];
    public array $header = [];
    public array $requests = [];
    public array $metadata;
    public array $keys;
    public bool $tamper = false;
    public ?string $raw = null;
    private readonly OpenSSLAsymmetricKey $key;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new RuntimeException('Cannot generate the isolated OIDC signing key.');
        }
        $this->key = $key;
        $details = openssl_pkey_get_details($key);
        $this->keys = ['keys' => [[
            'kty' => 'RSA', 'kid' => 'test-key', 'alg' => 'RS256', 'use' => 'sig',
            'n' => self::encode($details['rsa']['n']), 'e' => self::encode($details['rsa']['e']),
        ]]];
        $this->metadata = [
            'issuer' => 'https://id.example.test/tenant',
            'authorization_endpoint' => 'https://id.example.test/authorize',
            'token_endpoint' => 'https://id.example.test/token',
            'jwks_uri' => 'https://id.example.test/keys',
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
        ];
    }

    public function settings(array $overrides = []): array
    {
        return array_replace([
            'enabled' => true, 'name' => '<Enterprise login>', 'issuer' => $this->metadata['issuer'],
            'client_id' => 'test-client', 'client_secret' => 'test-secret',
        ], $overrides);
    }

    public function client(PBreeze\Session $session, array $settings = []): PBreeze\Oidc
    {
        return new PBreeze\Oidc($session, $this->settings($settings), 'https://app.example.test', $this->request(...));
    }

    public function request(string $url, array $headers, ?string $body): string
    {
        $this->requests[] = [$url, $headers, $body];
        if ($this->raw !== null) {
            return $this->raw;
        }
        if (str_ends_with($url, '/.well-known/openid-configuration')) {
            return json_encode($this->metadata, JSON_THROW_ON_ERROR);
        }
        if ($url === 'https://id.example.test/keys') {
            return json_encode($this->keys, JSON_THROW_ON_ERROR);
        }
        if ($url !== 'https://id.example.test/token') {
            throw new RuntimeException('Unexpected OIDC fixture URL.');
        }
        $pending = $_SESSION['_oidc_fixture_pending'];
        $header = array_replace(['alg' => 'RS256', 'kid' => 'test-key'], $this->header);
        $claims = array_replace([
            'iss' => 'https://id.example.test/tenant', 'sub' => 'subject-123',
            'aud' => 'test-client', 'iat' => time(), 'exp' => time() + 300, 'nonce' => $pending['nonce'],
            'email' => 'sso@example.test', 'email_verified' => true, 'name' => '<SSO User>',
        ], $this->claims);
        $input = self::encode(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
            . self::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        if (!openssl_sign($input, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Cannot sign the isolated ID token.');
        }
        if ($this->tamper) {
            $signature[0] = chr(ord($signature[0]) ^ 1);
        }
        return json_encode(['id_token' => $input . '.' . self::encode($signature), 'access_token' => 'test-access-token'], JSON_THROW_ON_ERROR);
    }

    public function start(PBreeze\Oidc $client): array
    {
        $url = $client->begin();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $_SESSION['_oidc_fixture_pending'] = $_SESSION['_oidc'];
        return ['state' => $query['state'], 'code' => 'test-code'];
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
