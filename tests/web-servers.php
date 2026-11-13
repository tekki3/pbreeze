<?php
declare(strict_types=1);

test('Deployment templates consistently isolate the public root and single PHP front controller', static function () use ($root): void {
    $apache = file_get_contents($root . '/deploy/apache.conf');
    $nginx = file_get_contents($root . '/deploy/nginx.conf');
    $franken = file_get_contents($root . '/deploy/Caddyfile');
    $rewrite = file_get_contents($root . '/public/.htaccess');
    check(str_contains($apache, 'DocumentRoot "/srv/pbreeze/public"'));
    check(str_contains($apache, 'CGIPassAuth On'));
    check(str_contains($apache, '<Files "index.php">'));
    check(str_contains($rewrite, 'E=HTTP_AUTHORIZATION:'));
    check(str_contains($rewrite, 'RewriteRule ^index\.php$ - [END]'));
    check(!str_contains($rewrite, '!-d'));
    check(str_contains($nginx, 'root /srv/pbreeze/public;'));
    check(str_contains($nginx, 'location = /index.php'));
    check(str_contains($nginx, 'fastcgi_param HTTP_AUTHORIZATION $http_authorization;'));
    check(!str_contains($nginx, '$uri/'));
    check(str_contains($franken, 'PBREEZE_PUBLIC_ROOT:/srv/pbreeze/public'));
    check(str_contains($franken, 'rewrite * /index.php'));
    check(preg_match('/^\s*worker\s/m', $franken) !== 1);
    foreach ([$rewrite, $nginx, $franken] as $config) {
        check(str_contains($config, 'phtml|phar'));
        check(str_contains($config, 'woff2?'));
    }
});

test('IIS configuration preserves framework errors, rewrites queries, and blocks nonpublic scripts', static function () use ($root): void {
    $xml = new DOMDocument();
    check($xml->load($root . '/public/web.config', LIBXML_NONET));
    $xpath = new DOMXPath($xml);
    same('PassThrough', $xpath->evaluate('string(/configuration/system.webServer/httpErrors/@existingResponse)'));
    same('false', $xpath->evaluate('string(/configuration/system.webServer/directoryBrowse/@enabled)'));
    same('8388608', $xpath->evaluate('string(//requestLimits/@maxAllowedContentLength)'));
    same('true', $xpath->evaluate('string(//rule[@name="PBreeze routes"]/action/@appendQueryString)'));
    same('index.php', $xpath->evaluate('string(//rule[@name="PBreeze routes"]/action/@url)'));
    $matches = static function (string $rule, string $path) use ($xpath): bool {
        $pattern = $xpath->evaluate('string(//rule[@name="' . $rule . '"]/match/@url)');
        return preg_match('~' . str_replace('~', '\~', $pattern) . '~iD', $path) === 1;
    };
    foreach (['.env', 'assets/.private.css', 'leak.sqlite', 'leak.config', 'leak.bak'] as $path) {
        check($matches('PBreeze private paths', $path));
    }
    foreach (['router.php', 'other.PHP', 'other.php/extra', 'script.phtml', 'app.phar'] as $path) {
        check($matches('PBreeze block other PHP scripts', $path));
    }
    foreach (['assets/css/welcome.css', 'assets/js/api-docs.js', 'image.svg', 'font.woff2'] as $path) {
        check($matches('PBreeze static assets', $path));
    }
    check(!$matches('PBreeze static assets', 'leak.txt'));
    same(0, $xpath->query('//handlers')->length);
});

test('FastCGI request capture preserves the original URI and Apache authorization fallback', static function (): void {
    $server = $_SERVER;
    $get = $_GET;
    $post = $_POST;
    $files = $_FILES;
    try {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/route/caf%C3%A9?value=a%2Bb',
            'SCRIPT_NAME' => '/index.php', 'PATH_INFO' => '/untrusted-rewrite',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer forwarded', 'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_ORIGINAL_URL' => '/do-not-trust-client-headers',
        ];
        $_GET = ['value' => 'a+b'];
        $_POST = $_FILES = [];
        $request = \PBreeze\Request::capture();
        same("/route/caf\u{e9}", $request->path);
        same('a+b', $request->query('value'));
        same('Bearer forwarded', $request->header('authorization'));
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer direct';
        same('Bearer direct', \PBreeze\Request::capture()->header('authorization'));
    } finally {
        $_SERVER = $server;
        $_GET = $get;
        $_POST = $post;
        $_FILES = $files;
    }
});
