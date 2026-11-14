<?php
declare(strict_types=1);

use PBreeze\Html;
use PBreeze\HtmlString;

test('Html escaping reuses existing UTF-8 output rules and never trusts Stringable values implicitly', static function (): void {
    foreach (['<script>"\'&', "Caf\xc3\xa9", "\xff", null, 123, 1.5, true, false] as $value) {
        same(\PBreeze\View::escape($value), Html::escape($value));
    }
    same('&lt;b&gt;trusted?&lt;/b&gt;', Html::escape(Html::raw('<b>trusted?</b>')));
    $value = new class implements Stringable {
        public function __toString(): string { return '<img src=x onerror=alert(1)>'; }
    };
    same('<p>&lt;img src=x onerror=alert(1)&gt;</p>', Html::tag('p', $value));
    raises(static fn () => Html::escape([]), InvalidArgumentException::class);
    raises(static fn () => Html::escape(new stdClass()), InvalidArgumentException::class);
});

test('Html tags escape content by default and require explicit raw markup for composition', static function (): void {
    same('<div class="container active" data-id="123">Hello</div>',
        Html::tag('div', 'Hello', ['class' => ['container', 'active'], 'data-id' => 123]));
    same('<p>&lt;strong&gt;Ada&lt;/strong&gt;</p>', Html::tag('p', Html::tag('strong', 'Ada')));
    same('<p><strong>Ada &amp; Lin</strong></p>', Html::tag('p', Html::raw(Html::tag('strong', 'Ada & Lin'))));
    check(Html::raw('<b>x</b>') instanceof HtmlString);
    same('<b>x</b>', (string) new HtmlString('<b>x</b>'));
    same('<div>0</div>', Html::tag('DIV', 0));
    same('<title>&lt;b&gt;x&lt;/b&gt;</title>', Html::tag('title', Html::raw('<b>x</b>')));
    foreach (['div onclick="x"', 'div><script', 'script', 'style', 'iframe', 'svg', 'math', 'plaintext', 'object',
        'xmp', 'noscript', 'meta', 'base', 'link', 'my-component'] as $name) {
        raises(static fn () => Html::tag($name, 'content'), InvalidArgumentException::class);
    }
});

test('Html renders void elements without closing tags and rejects accidental content', static function (): void {
    foreach (['area', 'br', 'col', 'hr', 'img', 'input', 'param', 'source', 'track', 'wbr'] as $name) {
        same("<$name>", Html::tag($name));
        same("<$name>", Html::tag($name, null));
        raises(static fn () => Html::tag($name, 'content'), InvalidArgumentException::class);
        raises(static fn () => Html::tag($name, Html::raw('')), InvalidArgumentException::class);
    }
});

test('Html attributes handle booleans, null, class arrays, data and aria values without injection', static function (): void {
    same(' required class="field active" data-id="123" aria-hidden="false" data-enabled="true" value="0" title="&quot;&lt;&amp;&#039;"',
        Html::attributes(['required' => true, 'disabled' => false, 'hidden' => null, 'class' => ['field', 'active' => true],
            'data-id' => 123, 'aria-hidden' => false, 'data-enabled' => true, 'value' => 0, 'title' => '"<&\'']));
    same('', Html::attributes([]));
    same(' readonly', Html::attributes(['READONLY' => true]));
    same(' download', Html::attributes(['download' => true]));
    same('', Html::attributes(['download' => false, 'capture' => false]));
    same(' download="report.csv" capture="user"', Html::attributes(['download' => 'report.csv', 'capture' => 'user']));
    same(' title="&amp;quot;"', Html::attributes(['title' => '&quot;']));
    foreach ([['onclick' => 'alert(1)'], ['ONERROR' => 'alert(1)'], ['style' => 'background:url(x)'], ['srcdoc' => '<script>'],
        ['srcset' => 'x 1x'], ['ping' => '/track'], ['xlink:href' => '/x'], ['foo" onload' => 'x'], [0 => 'disabled'],
        ['class' => 'a', 'CLASS' => 'b'], ['required' => 'false'], ['disabled' => 1], ['title' => []],
        ['title' => Html::raw('" onclick="x')], ['xmlns' => 'urn:x'], ['is' => 'custom-element']] as $attributes) {
        raises(static fn () => Html::attributes($attributes), InvalidArgumentException::class);
    }
});

test('Html class lists support nested and conditional classes, preserve order and remove duplicates', static function (): void {
    same('container active nested enabled', Html::classes(['container active', 'active', ['nested', 'enabled' => true, 'off' => false], null, false]));
    same('one two', Html::classes(" one\ttwo\none "));
    same('', Html::classes([]));
    same('0', Html::classes('0'));
    same('<div class="&quot; onmouseover=&quot;x"></div>', Html::tag('div', '', ['class' => ['" onmouseover="x']]));
    raises(static fn () => Html::classes(['active' => 1]), InvalidArgumentException::class);
    raises(static fn () => Html::classes([new stdClass()]), InvalidArgumentException::class);
    raises(static fn () => Html::classes([true]), InvalidArgumentException::class);
    $cycle = [];
    $cycle[] = &$cycle;
    raises(static fn () => Html::classes($cycle), InvalidArgumentException::class);
});

test('Html links and resource URLs accept explicit safe schemes and reject browser scheme obfuscation', static function (): void {
    foreach (['/users', '../users', 'users/1', '?page=2&name=Ada', '#section', '', 'https://example.test/a?x=1&y=2',
        'HTTP://example.test/', 'mailto:hello@example.test', 'tel:+123456', '/caf%C3%A9', '/café'] as $url) {
        same('<a href="' . Html::escape($url) . '">Users &amp; guests</a>', Html::a($url, 'Users & guests'));
    }
    foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,<script>alert(1)</script>', 'vbscript:msgbox(1)',
        "java\nscript:alert(1)", "\tjavascript:alert(1)", ' javascript:alert(1)', '//evil.test', '\\\\evil.test',
        "https://good.test\\@evil.test", 'https:evil.test', 'https:///evil.test', 'https://user:pass@example.test/',
        'file:///etc/passwd', 'blob:https://example.test/x', "java\0script:1", "https://example.test/\xff"] as $url) {
        raises(static fn () => Html::a($url, 'link'), InvalidArgumentException::class);
        raises(static fn () => Html::img($url), InvalidArgumentException::class);
        raises(static fn () => Html::tag('form', '', ['action' => $url]), InvalidArgumentException::class);
    }
    raises(static fn () => Html::img('mailto:x@example.test'), InvalidArgumentException::class);
    raises(static fn () => Html::tag('button', 'Go', ['formaction' => 'javascript:1']), InvalidArgumentException::class);
    same('<a href="javascript&amp;colon;alert(1)">Text</a>', Html::a('javascript&colon;alert(1)', 'Text'));
    same('<img src="/logo.png" alt="Logo &amp; icon">', Html::img('/logo.png', ['alt' => 'Logo & icon']));
});

test('Html form controls escape values and keep positional arguments authoritative', static function (): void {
    same('<input type="text" name="name" required>', Html::input('text', 'name', ['required' => true]));
    same('<textarea name="description"></textarea>', Html::textarea('description'));
    same("<textarea name=\"description\">\n\nfirst line</textarea>", Html::textarea('description', "\nfirst line"));
    same('<textarea name="description" rows="3">&lt;/textarea&gt;&lt;script&gt;x&lt;/script&gt;</textarea>',
        Html::textarea('description', '</textarea><script>x</script>', ['rows' => 3]));
    same('<textarea name="text">&lt;b&gt;raw is not text&lt;/b&gt;</textarea>', Html::textarea('text', Html::raw('<b>raw is not text</b>')));
    same('<input type="checkbox" name="active" value="1" checked>', Html::checkbox('active', 1, true));
    same('<input type="checkbox" name="active" value="1">', Html::checkbox('active'));
    same('<input type="radio" name="type" value="admin">', Html::radio('type', 'admin'));
    same('<input type="radio" name="type" value="admin" checked>', Html::radio('type', 'admin', true));
    same('<button type="button">Save</button>', Html::button('Save'));
    same('<button type="submit">Save &amp; close</button>', Html::button('Save & close', ['type' => 'submit']));
    same('<button type="submit">Save</button>', Html::button('Save', ['TYPE' => 'submit']));
    raises(static fn () => Html::button('Save', ['type' => null]), InvalidArgumentException::class);
    same('<label for="email">Email address</label>', Html::label('email', 'Email address'));
    raises(static fn () => Html::a('/safe', 'Link', ['href' => '/other']), InvalidArgumentException::class);
    raises(static fn () => Html::img('/safe', ['SRC' => '/other']), InvalidArgumentException::class);
    raises(static fn () => Html::input('text', 'name', ['type' => 'hidden']), InvalidArgumentException::class);
    raises(static fn () => Html::checkbox('name', 1, true, ['checked' => false]), InvalidArgumentException::class);
    raises(static fn () => Html::label('id', 'text', ['for' => 'other']), InvalidArgumentException::class);
});

test('Html select uses strict string matching, supports multiple selections and escapes option labels', static function (): void {
    same('<select name="status"><option value="active" selected>Active</option><option value="inactive">Inactive</option></select>',
        Html::select('status', ['active' => 'Active', 'inactive' => 'Inactive'], 'active'));
    same('<select name="number"><option value="0" selected>Zero</option><option value="01">Leading zero</option></select>',
        Html::select('number', [0 => 'Zero', '01' => 'Leading zero'], 0));
    same('<select name="roles[]" multiple><option value="admin" selected>Admin</option><option value="reader" selected>Reader</option></select>',
        Html::select('roles[]', ['admin' => 'Admin', 'reader' => 'Reader'], ['admin', 'reader'], ['multiple' => true]));
    same('<select name="empty"></select>', Html::select('empty', []));
    same('<select name="empty" multiple></select>', Html::select('empty', [], [], ['MULTIPLE' => true]));
    same('<select name="x"><option value="&quot; onclick=&quot;x">&lt;script&gt;x&lt;/script&gt;</option></select>',
        Html::select('x', ['" onclick="x' => '<script>x</script>']));
    same('<select name="x"><option value="a">&lt;b&gt;text&lt;/b&gt;</option></select>',
        Html::select('x', ['a' => Html::raw('<b>text</b>')]));
    raises(static fn () => Html::select('x', [], ['a']), InvalidArgumentException::class);
    raises(static fn () => Html::select('x', [], [new stdClass()], ['multiple' => true]), InvalidArgumentException::class);
    raises(static fn () => Html::select('x', ['a' => ['nested']]), InvalidArgumentException::class);
});

test('Html CSRF delegates to existing token generation and verification without starting sessions', static function (): void {
    $before = $_SESSION ?? null;
    try {
        $_SESSION = [];
        $session = new \PBreeze\Session();
        $html = Html::csrf($session);
        check($html instanceof HtmlString);
        same(csrf_field($session), (string) $html);
        same(64, strlen($session->token()));
        $session->verifyCsrf(new \PBreeze\Request('POST', '/submit', [], ['_token' => $session->token()]));
        same('<form method="post">' . csrf_field($session) . '</form>', Html::tag('form', $html, ['method' => 'post']));
        raises(static fn () => $session->verifyCsrf(new \PBreeze\Request('POST', '/submit', [], ['_token' => 'bad'])), \PBreeze\HttpException::class, 419);
    } finally {
        if ($before === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $before;
        }
    }
});

test('Html output remains safe when parsed and works in both PHP and Breeze views without changing autoescaping', static function (): void {
    global $temp;
    $payload = '"><img src=x onerror=alert(1)>';
    $html = Html::tag('div', $payload, ['title' => $payload, 'data-value' => $payload]);
    $document = new DOMDocument();
    check($document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>'));
    same(0, $document->getElementsByTagName('img')->length);
    $div = $document->getElementsByTagName('div')->item(0);
    same($payload, $div->textContent);
    same($payload, $div->getAttribute('title'));
    same($payload, $div->getAttribute('data-value'));
    same(2, $div->attributes->length);
    file_put_contents($temp . '/app/views/html-helper.php', '<?php echo \\PBreeze\\Html::a("/users", $label);');
    file_put_contents($temp . '/app/views/html-helper.breeze.php', '{!! \\PBreeze\\Html::a("/users", $label) !!}{{ $raw }}');
    $view = new \PBreeze\View($temp . '/app/views', $temp . '/storage/html-views');
    same('<a href="/users">&lt;Users&gt;</a>&lt;b&gt;x&lt;/b&gt;', $view->render('html-helper', ['label' => '<Users>', 'raw' => Html::raw('<b>x</b>')]));
    unlink($temp . '/app/views/html-helper.breeze.php');
    same('<a href="/users">&lt;Users&gt;</a>', $view->render('html-helper', ['label' => '<Users>']));
});
