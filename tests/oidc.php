<?php
declare(strict_types=1);

use PBreeze\AuthException;
use PBreeze\Oidc;

require_once __DIR__ . '/fixtures/oidc-provider.php';

test('OIDC binds state, nonce, PKCE, redirect URI, issuer, and client authentication without retaining tokens', static function (): void {
    $saved = $_SESSION ?? [];
    try {
        $provider = new OidcProvider();
        $client = $provider->client(new PBreeze\Session());
        $callback = $provider->start($client);
        $pending = $_SESSION['_oidc'];
        $identity = $client->complete($callback);
        same(['issuer' => 'https://id.example.test/tenant', 'subject' => 'subject-123',
            'email' => 'sso@example.test', 'name' => '<SSO User>'], $identity);
        same(null, $_SESSION['_oidc']);
        [$url, $headers, $body] = $provider->requests[2];
        same('https://id.example.test/token', $url);
        check(in_array('Authorization: Basic ' . base64_encode('test-client:test-secret'), $headers, true));
        parse_str($body, $parameters);
        same('authorization_code', $parameters['grant_type']);
        same('https://app.example.test/sso/callback', $parameters['redirect_uri']);
        same($pending['verifier'], $parameters['code_verifier']);
        check(!str_contains(json_encode($_SESSION, JSON_THROW_ON_ERROR), 'test-access-token'));
        raises(fn () => $client->complete($callback), AuthException::class);
        foreach (['client_secret_post', 'none'] as $method) {
            $provider->metadata['token_endpoint_auth_methods_supported'] = [$method];
            $client = $provider->client(new PBreeze\Session(), ['client_secret' => $method === 'none' ? '' : 'test-secret']);
            $client->complete($provider->start($client));
            parse_str($provider->requests[count($provider->requests) - 2][2], $parameters);
            same($method === 'none' ? null : 'test-secret', $parameters['client_secret'] ?? null);
        }
        $url = $client->begin();
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        same('query', $parameters['response_mode']);
        same('openid email profile', $parameters['scope']);
        same('S256', $parameters['code_challenge_method']);
        same(rtrim(strtr(base64_encode(hash('sha256', $_SESSION['_oidc']['verifier'], true)), '+/', '-_'), '='), $parameters['code_challenge']);
    } finally {
        $_SESSION = $saved;
    }
});

test('OIDC rejects missing, expired, cross-session, mismatched and denied callbacks before token exchange', static function (): void {
    $saved = $_SESSION ?? [];
    try {
        $provider = new OidcProvider();
        $client = $provider->client(new PBreeze\Session());
        foreach (['missing', 'state', 'array', 'expired', 'future', 'issuer', 'denied', 'code', 'binding'] as $case) {
            $query = $provider->start($client);
            match ($case) {
                'missing' => $_SESSION['_oidc'] = null,
                'state' => $query['state'] = 'wrong',
                'array' => $query['state'] = [],
                'expired' => $_SESSION['_oidc']['issued'] = time() - 600,
                'future' => $_SESSION['_oidc']['issued'] = time() + 30,
                'issuer' => $query['iss'] = 'https://other.example.test',
                'denied' => $query['error'] = 'access_denied',
                'code' => $query['code'] = [],
                'binding' => $_SESSION['_oidc']['binding'] = 'changed',
            };
            $before = count($provider->requests);
            raises(fn () => $client->complete($query), AuthException::class);
            same($before, count($provider->requests));
            same(null, $_SESSION['_oidc']);
        }
    } finally {
        $_SESSION = $saved;
    }
});

test('OIDC verifies RSA signatures and strict identity, audience, time, nonce, and optional hash claims', static function (): void {
    $saved = $_SESSION ?? [];
    try {
        $provider = new OidcProvider();
        $client = $provider->client(new PBreeze\Session());
        $invalid = [
            ['iss' => 'https://other.example.test'], ['sub' => ''], ['sub' => []],
            ['sub' => str_repeat('x', 256)], ['aud' => 'other-client'], ['aud' => ['test-client', 1]],
            ['aud' => ['test-client', 'other']], ['azp' => 'other-client'],
            ['exp' => time() - 1], ['exp' => '9999999999'], ['iat' => time() + 120],
            ['iat' => time() - 601], ['nbf' => time() + 120], ['nonce' => 'wrong'],
            ['at_hash' => 'wrong'], ['c_hash' => 'wrong'],
        ];
        foreach ($invalid as $claims) {
            $provider->claims = $claims;
            raises(fn () => $client->complete($provider->start($client)), AuthException::class);
        }
        $provider->claims = ['aud' => ['test-client', 'other'], 'azp' => 'test-client'];
        same('subject-123', $client->complete($provider->start($client))['subject']);
        $hash = static fn (string $value): string => rtrim(strtr(base64_encode(substr(hash('sha256', $value, true), 0, 16)), '+/', '-_'), '=');
        $provider->claims = ['at_hash' => $hash('test-access-token'), 'c_hash' => $hash('test-code')];
        same('subject-123', $client->complete($provider->start($client))['subject']);
        $provider->claims = [];
        foreach ([['alg' => 'none'], ['alg' => 'HS256'], ['kid' => 'unknown'], ['crit' => ['extension']], ['b64' => false]] as $header) {
            $provider->header = $header;
            raises(fn () => $client->complete($provider->start($client)), AuthException::class);
        }
        $provider->header = [];
        $provider->tamper = true;
        raises(fn () => $client->complete($provider->start($client)), AuthException::class);
        $provider->tamper = false;
        $provider->keys['keys'][] = $provider->keys['keys'][0];
        raises(fn () => $client->complete($provider->start($client)), AuthException::class);
        array_pop($provider->keys['keys']);
        foreach ([false, 'true', null] as $verified) {
            $provider->claims = ['email_verified' => $verified];
            same(null, $client->complete($provider->start($client))['email']);
        }
    } finally {
        $_SESSION = $saved;
    }
});

test('OIDC fails closed on insecure configuration, discovery substitution, unsupported PKCE, and oversized responses', static function () use ($root): void {
    $saved = $_SESSION ?? [];
    try {
        $provider = new OidcProvider();
        $session = new PBreeze\Session();
        $client = $provider->client($session);
        foreach (['http://id.example.test', 'https://user:secret@id.example.test', 'https://id.example.test#fragment',
            'https://id.example.test?query=1', "https://id.example.test/\r\n"] as $issuer) {
            raises(fn () => $provider->client($session, ['issuer' => $issuer]), InvalidArgumentException::class);
        }
        raises(fn () => $provider->client($session, ['client_id' => '']), InvalidArgumentException::class);
        raises(fn () => $provider->client($session, ['timeout' => 61]), InvalidArgumentException::class);
        raises(fn () => new Oidc($session, $provider->settings(), 'http://app.example.test'), InvalidArgumentException::class);
        raises(fn () => (new Oidc($session))->begin(), PBreeze\HttpException::class, 404);
        $metadata = $provider->metadata;
        foreach (['issuer' => 'https://other.example.test', 'id_token_signing_alg_values_supported' => ['HS256'],
            'code_challenge_methods_supported' => ['plain']] as $key => $value) {
            $provider->metadata = array_replace($metadata, [$key => $value]);
            raises(fn () => $client->begin(), RuntimeException::class);
        }
        $provider->metadata = array_replace($metadata, ['token_endpoint' => 'http://id.example.test/token']);
        raises(fn () => $client->begin(), InvalidArgumentException::class);
        foreach (['{', '[]', str_repeat('x', 1048577)] as $raw) {
            $provider->raw = $raw;
            raises(fn () => $client->begin(), RuntimeException::class);
        }
        $previous = getenv('SSO_TIMEOUT');
        try {
            foreach (['0', '61', '', 'invalid'] as $value) {
                putenv('SSO_TIMEOUT=' . $value);
                raises(static fn () => require $root . '/config/sso.php', RuntimeException::class);
            }
        } finally {
            putenv($previous === false ? 'SSO_TIMEOUT' : 'SSO_TIMEOUT=' . $previous);
        }
    } finally {
        $_SESSION = $saved;
    }
});

test('OIDC provisions atomically, refuses email linking, preserves subject identity, and enforces local MFA', static function () use ($root, $temp): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/oidc-login.php', $temp],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    check(is_resource($process));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    check($exit === 0, 'OIDC account fixture failed: ' . $error . $output);
    same('', $error);
    same('OIDC account and MFA checks passed', $output);
});

test('OIDC native HTTPS enforces TLS trust, response boundaries, deadlines, statuses, and no redirects', static function (): void {
    $request = new ReflectionMethod(Oidc::class, 'fetch');
    $transcript = aiHttpFixture(['body' => str_repeat('x', 1048576)], static function ($url, $certificate) use ($request): void {
        $client = new Oidc(new PBreeze\Session(), ['ca_file' => $certificate]);
        same(1048576, strlen($request->invoke($client, $url, ['Content-Type: application/x-www-form-urlencoded'], 'code=fixture-code')));
    });
    check(str_contains($transcript['headers'], 'POST /v1/test'));
    same('code=fixture-code', $transcript['body']);
    foreach ([
        ['body' => str_repeat('x', 1048577)], ['status' => 400, 'body' => 'private-provider-details'],
        ['status' => 302, 'location' => 'https://127.0.0.1:1/leaked-secret'],
        ['delay' => 2], ['truncate' => true], ['untrusted' => true],
    ] as $options) {
        aiHttpFixture($options, static function ($url, $certificate) use ($request, $options): void {
            $client = new Oidc(new PBreeze\Session(), [
                'ca_file' => isset($options['untrusted']) ? '' : $certificate, 'timeout' => 1,
            ]);
            try {
                $request->invoke($client, $url, [], 'client_secret=fixture-secret');
                throw new LogicException('Expected a failed SSO HTTPS request.');
            } catch (RuntimeException $error) {
                check(!str_contains($error->getMessage(), 'fixture-secret'));
                check(!str_contains($error->getMessage(), 'private-provider-details'));
            }
        });
    }
});

test('SSO browser routes preserve CSRF, reject HEAD callbacks, escape provider names, and stay optional', static function () use ($root, $temp, $config): void {
    require_once $root . '/app/controllers/AuthController.php';
    $saved = $_SESSION ?? [];
    try {
        $_SESSION = [];
        $provider = new OidcProvider();
        $app = new PBreeze\Application($root, array_replace($config, [
            'url' => 'https://app.example.test', 'sso' => $provider->settings(), 'storage_path' => $temp . '/oidc-ui',
        ]));
        $app->routes();
        $page = $app->handle(new PBreeze\Request('GET', '/login'));
        same(200, $page->status);
        check(str_contains($page->body, 'Continue with &lt;Enterprise login&gt;'));
        check(str_contains($page->body, 'action="/sso/login"'));
        check(str_contains($page->body, 'action="/login"'));
        raises(fn () => $app->handle(new PBreeze\Request('POST', '/sso/login')), PBreeze\HttpException::class, 419);
        $_SESSION['_oidc'] = ['preserve' => true];
        raises(fn () => $app->handle(new PBreeze\Request('HEAD', '/sso/callback')), PBreeze\HttpException::class, 405);
        same(['preserve' => true], $_SESSION['_oidc']);
        $disabled = new PBreeze\Application($root, array_replace($config, ['storage_path' => $temp . '/oidc-disabled', 'sso' => ['enabled' => false]]));
        $disabled->routes();
        check(!str_contains($disabled->handle(new PBreeze\Request('GET', '/login'))->body, 'action="/sso/login"'));
        raises(fn () => $disabled->handle(new PBreeze\Request('POST', '/sso/login', body: ['_token' => $disabled->session->token()])), PBreeze\HttpException::class, 404);
        raises(fn () => $disabled->handle(new PBreeze\Request('GET', '/sso/callback')), PBreeze\HttpException::class, 404);
    } finally {
        $_SESSION = $saved;
    }
});
