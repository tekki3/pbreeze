<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Translator;

test('Translations use explicit locales, fallback, and literal replacements', static function () use ($temp, $config): void {
    $root = $temp . '/translations';
    mkdir($root . '/app/lang', 0700, true);
    mkdir($root . '/app/views', 0700, true);
    file_put_contents($root . '/app/lang/en-US.json', '{"greeting":"Hello, {name}!","only.english":"Fallback","empty":""}');
    file_put_contents($root . '/app/lang/nl-NL.json', '{"greeting":"Hallo, {name}!"}');
    $app = new Application($root, array_replace($config, ['locale' => 'nl-NL']));

    same('Hallo, Ada!', $app->translator->get('greeting', ['name' => 'Ada']));
    same('Hello, Ada!', $app->translator->get('greeting', ['name' => 'Ada'], 'en-US'));
    same('Fallback', $app->translator->get('only.english'));
    same('', $app->translator->get('empty', locale: 'fr-FR'));
    same('Hallo, {other}!', $app->translator->get('greeting', ['name' => '{other}', 'other' => 'not expanded']));
    same($app->translator, $app->views->translator);

    file_put_contents($root . '/app/views/child.breeze.php', '{{ $translator->get("greeting", ["name" => $name]) }}');
    file_put_contents($root . '/app/views/page.breeze.php', '@include("child")');
    same('Hallo, &lt;Ada&gt;!', $app->views->render('page', ['name' => '<Ada>', 'translator' => 'not a service']));
    file_put_contents($root . '/app/views/plain.php', '<?= e($translator->get("greeting", ["name" => $name])) ?>');
    same('Hallo, &lt;Ada&gt;!', $app->views->render('plain', ['name' => '<Ada>']));

    $standalone = new Translator($root . '/app/lang', 'fr-FR', 'nl-NL');
    same('Hallo, 42!', $standalone->get('greeting', ['name' => 42]));
});

test('Translations reject invalid input, catalogs, and missing keys', static function () use ($temp): void {
    $directory = $temp . '/invalid-translations';
    mkdir($directory, 0700, true);
    $translator = new Translator($directory);
    foreach (['', '../en-US', "en-US\n", 'en-US-extra'] as $locale) {
        raises(static fn () => new Translator($directory, $locale), InvalidArgumentException::class);
        raises(static fn () => $translator->get('greeting', locale: $locale), InvalidArgumentException::class);
    }
    raises(static fn () => $translator->get(''), InvalidArgumentException::class);
    raises(static fn () => $translator->get('missing'), RuntimeException::class);
    raises(static fn () => $translator->get('greeting', ['invalid-name' => 'x']), InvalidArgumentException::class);
    raises(static fn () => $translator->get('greeting', ['name' => []]), InvalidArgumentException::class);
    file_put_contents($directory . '/not-a-directory', 'file');
    raises(static fn () => (new Translator($directory . '/not-a-directory'))->get('greeting'), RuntimeException::class);
    file_put_contents($directory . '/fr-FR.json', '{invalid');
    raises(static fn () => $translator->get('greeting', locale: 'fr-FR'), JsonException::class);
    foreach (['[]', '{"greeting":42}', '{"nested":{"key":"value"}}'] as $invalid) {
        file_put_contents($directory . '/fr-FR.json', $invalid);
        raises(static fn () => $translator->get('greeting', locale: 'fr-FR'), RuntimeException::class);
    }
});
