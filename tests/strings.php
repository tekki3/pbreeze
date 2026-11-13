<?php
declare(strict_types=1);

use PBreeze\Strings;

test('Strings support UTF-8 casing, Unicode whitespace and grapheme-safe slicing', static function (): void {
    $strings = new Strings();
    same('élève', $strings->lower('ÉLÈVE'));
    same('STRASSE', $strings->upper('Straße'));
    same('Hello World', $strings->title('hello WORLD'));
    same('Ada', $strings->trim("\u{00a0}\t Ada \u{2003}\n"));
    same(3, $strings->length("Ae\u{0301}👨‍👩‍👧‍👦"));
    same("e\u{0301}", $strings->slice("Ae\u{0301}👨‍👩‍👧‍👦", 1, 1));
    same('👨‍👩‍👧‍👦', $strings->slice("Ae\u{0301}👨‍👩‍👧‍👦", -1));
    same('ab', $strings->slice('abc', 0, -1));
    same('', $strings->slice('abc', 99));
    same('', $strings->slice('', 0));
    same('abc', $strings->slice('abc', PHP_INT_MIN));
    same('', $strings->slice('abc', PHP_INT_MAX));
    same('', $strings->slice('abc', 0, PHP_INT_MIN));
    same(0, $strings->length(''));
});

test('Strings literal matching, delimiter extraction and replacement have explicit edge behavior', static function (): void {
    $strings = new Strings();
    check($strings->contains('Hello café', 'café'));
    check(!$strings->contains('Hello', 'hello'));
    check($strings->contains('Hello', ''));
    check($strings->startsWith('café noir', 'café'));
    check($strings->endsWith('café noir', 'noir'));
    check($strings->startsWith('', '') && $strings->endsWith('', ''));
    same('one', $strings->before('one/two/three', '/'));
    same('two/three', $strings->after('one/two/three', '/'));
    same('whole', $strings->before('whole', '/'));
    same('whole', $strings->after('whole', '/'));
    same('', $strings->before('/one', '/'));
    same('', $strings->after('one/', '/'));
    same('Ada & Ada', $strings->replace('Lin & Lin', 'Lin', 'Ada'));
    same('ab', $strings->replace('a-b', '-', ''));
    raises(static fn () => $strings->before('text', ''), InvalidArgumentException::class);
    raises(static fn () => $strings->after('text', ''), InvalidArgumentException::class);
    raises(static fn () => $strings->replace('text', '', 'x'), InvalidArgumentException::class);
});

test('Strings truncate within total grapheme limits and generate slugs and naming cases', static function (): void {
    $strings = new Strings();
    same('Hello...', $strings->truncate('Hello world', 8));
    same('..', $strings->truncate('Hello world', 2));
    same('', $strings->truncate('Hello', 0));
    same('Hello', $strings->truncate('Hello', 5));
    same('He', $strings->truncate('Hello', 2, ''));
    same('👨‍👩‍👧‍👦!', $strings->truncate('👨‍👩‍👧‍👦abc', 2, '!'));
    same('creme-brulee', $strings->slug(' Crème brûlée! '));
    same('hello_world', $strings->slug('Hello World', '_'));
    same('', $strings->slug('!!!'));
    same('http_server_id', $strings->snake('HTTPServerID'));
    same('hello_world', $strings->snake('hello-world'));
    same('ÉlèveName', $strings->studly('élève_name'));
    same('httpServerId', $strings->camel('HTTP_server_ID'));
    same('', $strings->camel('---'));
    raises(static fn () => $strings->truncate('Hello', -1), InvalidArgumentException::class);
    raises(static fn () => $strings->slug('Hello', '/'), InvalidArgumentException::class);
});

test('Strings reject invalid UTF-8 and generate bounded cryptographic random strings', static function (): void {
    $strings = new Strings();
    foreach (['lower', 'upper', 'title', 'trim', 'length', 'slice', 'slug', 'snake', 'camel', 'studly', 'truncate'] as $method) {
        raises(static fn () => $method === 'slice' ? $strings->slice("\xff", 0) : $strings->$method("\xff"), InvalidArgumentException::class);
    }
    raises(static fn () => $strings->contains('valid', "\xff"), InvalidArgumentException::class);
    raises(static fn () => $strings->replace('valid', 'a', "\xff"), InvalidArgumentException::class);
    raises(static fn () => $strings->truncate('valid', 2, "\xff"), InvalidArgumentException::class);
    $first = $strings->random();
    same(32, strlen($first));
    check(preg_match('/^[A-Za-z0-9]{32}$/D', $first) === 1);
    check($first !== $strings->random());
    same(65536, strlen($strings->random(65536, 'abc')));
    check(preg_match('/^[01]{1024}$/D', $strings->random(1024, '01')) === 1);
    foreach ([[0, 'ab'], [65537, 'ab'], [5, 'a'], [5, 'aa'], [5, 'a '], [5, 'éa']] as [$length, $alphabet]) {
        raises(static fn () => $strings->random($length, $alphabet), InvalidArgumentException::class);
    }
});

test('Strings service is shared and reserved in PHP and Breeze templates with normal escaping', static function (): void {
    global $app, $temp;
    check($app->strings === $app->views->strings);
    file_put_contents($temp . '/app/views/strings-plain.php', '<?= e($strings->upper($name)) ?>');
    file_put_contents($temp . '/app/views/strings-breeze.breeze.php', '{{ $strings->upper($name) }}');
    foreach (['strings-plain', 'strings-breeze'] as $view) {
        same('&lt;ADA&gt;', $app->views->render($view, ['name' => '<Ada>', 'strings' => 'cannot override']));
    }
});
