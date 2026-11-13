<?php
declare(strict_types=1);

use PBreeze\TemplateCompiler;
use PBreeze\View;

/** @param array<string, string> $templates */
function templateViews(array $templates): View
{
    global $temp;
    $directory = $temp . '/templates-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    foreach ($templates as $name => $source) {
        $file = $directory . '/' . str_replace('.', '/', $name) . '.breeze.php';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, $source);
    }
    return new View($directory, $directory . '/cache');
}

test('Breeze escapes HTML, quotes, Unicode, and Stringable values by default', static function (): void {
    $views = templateViews(['index' => '{{ $value }}|{{ null }}|{{ 42 }}|{{ true }}|{{ $object }}']);
    $object = new class implements Stringable {
        public function __toString(): string { return '<strong>Ada</strong>'; }
    };
    same('&lt;script&gt;&quot;&#039;&amp;Zoë||42|1|&lt;strong&gt;Ada&lt;/strong&gt;', $views->render('index', [
        'value' => '<script>"\'&Zoë', 'object' => $object,
    ]));
    raises(static fn () => $views->render('index', ['value' => [], 'object' => $object]), InvalidArgumentException::class);
});

test('Breeze raw output is explicit and template comments are removed', static function (): void {
    $views = templateViews(['index' => '{{-- {{ $missing }} --}}{!! $html !!}|@@if(true)|mail@example.test']);
    same('<b>Vertrouwd</b>|@if(true)|mail@example.test', $views->render('index', ['html' => '<b>Vertrouwd</b>']));
});

test('Compiler understands nested parentheses, quotes, and delimiters in expressions', static function (): void {
    $views = templateViews([
        'index' => '{{ implode("}}", [strtoupper("a"), "b"]) }}|{{ (static fn () => ["x" => "(ok)"])()["x"] }}|{{ "quote\\"}}inside" }}',
    ]);
    same('A}}b|(ok)|quote&quot;}}inside', $views->render('index'));
});

test('Compiler supports comments in expressions without confusing delimiters', static function (): void {
    $views = templateViews(['index' => "{{ /* }} ) */ 'ok' }}|@if(strlen(')') === 1)yes@endif"]);
    same('ok|yes', $views->render('index'));
});

test('Breeze handles if, elseif, else, foreach, and for', static function (): void {
    // Whitespace separates directive names from following literal letters.
    $compiler = new TemplateCompiler();
    raises(static fn () => $compiler->compile('@elseother', 'invalid'), RuntimeException::class);
    $views = templateViews([
        'index' => '@if($value === 1)one@elseif($value === 2)two@else other@endif|@foreach($items as $item){{ $item }}@endforeach|@for($i = 0; $i < 3; $i++){{ $i }}@endfor',
    ]);
    same('one|&lt;a&gt;b|012', $views->render('index', ['value' => 1, 'items' => ['<a>', 'b']]));
    same('two||012', $views->render('index', ['value' => 2, 'items' => []]));
    same(' other||012', $views->render('index', ['value' => 3, 'items' => []]));
});

test('Layouts render sections and escape yield defaults', static function (): void {
    $views = templateViews([
        'page' => "@extends('layouts.app')@section('title'){{ \$title }}@endsection@section('content')<p>{{ \$body }}</p>@endsection",
        'layouts.app' => "<title>@yield('title')</title><main>@yield('content')</main><aside>@yield('missing', '<none>')</aside>",
    ]);
    same('<title>&lt;Ada&gt;</title><main><p>&lt;body&gt;</p></main><aside>&lt;none&gt;</aside>', $views->render('page', [
        'title' => '<Ada>', 'body' => '<body>',
    ]));
});

test('Multilevel layouts preserve sections from the most specific template', static function (): void {
    $views = templateViews([
        'page' => "@extends('layouts.middle')@section('title')Child@endsection",
        'layouts.middle' => "@extends('layouts.base')@section('title')Parent@endsection@section('content')<p>Middle</p>@endsection",
        'layouts.base' => "<title>@yield('title')</title>@yield('content')",
    ]);
    same('<title>Child</title><p>Middle</p>', $views->render('page'));
});

test('Includes receive current loop variables with explicit data overrides', static function (): void {
    $views = templateViews([
        'index' => "@foreach(\$items as \$item)@include('partials.item', ['title' => strtoupper(\$title)])@endforeach{{ \$title }}",
        'partials.item' => '{{ $title }}:{{ $item }};',
    ]);
    same('ADA:&lt;one&gt;;ADA:two;ada', $views->render('index', ['title' => 'ada', 'items' => ['<one>', 'two']]));
});

test('Components receive only props and safe prerendered slots', static function (): void {
    $views = templateViews([
        'index' => "@component('components.panel', ['title' => '<Title>'])@slot('footer')<footer>{{ \$footer }}</footer>@endslot<p>{{ \$body }}</p>@endcomponent",
        'components.panel' => '<section><h2>{{ $title }}</h2>{!! $slot !!}{!! $footer !!}|{{ isset($private) ? "leak" : "isolated" }}</section>',
    ]);
    same('<section><h2>&lt;Title&gt;</h2><p>&lt;Body&gt;</p><footer>&lt;Footer&gt;</footer>|isolated</section>', $views->render('index', [
        'body' => '<Body>', 'footer' => '<Footer>', 'private' => 'secret',
    ]));
});

test('Nested components and includes do not mix their slots', static function (): void {
    $views = templateViews([
        'index' => "@component('outer')O@component('inner')I@endcomponent Z@endcomponent",
        'outer' => '<outer>{!! $slot !!}</outer>',
        'inner' => '<inner>{!! $slot !!}</inner>',
    ]);
    same('<outer>O<inner>I</inner> Z</outer>', $views->render('index'));
});

test('Each render gets fresh sections, even after an exception', static function (): void {
    $views = templateViews([
        'page' => "@extends('layout')@section('content'){{ \$value }}@endsection",
        'layout' => "@yield('content', 'empty')",
        'broken' => "@section('content'){{ throw new RuntimeException('broken') }}@endsection",
    ]);
    same('first', $views->render('page', ['value' => 'first']));
    same('empty', $views->render('layout'));
    $level = ob_get_level();
    raises(static fn () => $views->render('broken'), RuntimeException::class);
    same($level, ob_get_level());
    same('second', $views->render('page', ['value' => 'second']));
});

test('Compiler rejects invalid syntax without publishing a cache file', static function (): void {
    $compiler = new TemplateCompiler();
    foreach ([
        '{{ }}', '{{ $x ', '{!! $x ', '{{-- comment', '@if(true)', '@endif', '@else',
        '@if(true)@else @elseif(false)@endif', '@foreach([] as $x)@endif',
        '@include', '@unknown("x")', '{{ [1) }}', '{{ `whoami` }}',
        '<?php echo "bad"; ?>', '{{ ( }}', '{{ $x + }}',
    ] as $source) {
        try {
            $compiler->compile($source, 'broken');
            throw new LogicException('Ongeldige template is geaccepteerd: ' . $source);
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'broken'));
        }
    }
    $views = templateViews(['index' => '@if(true)']);
    raises(static fn () => $views->render('index'), RuntimeException::class);
    same(0, $views->clearCache());
});

test('Runtime rejects cyclic layouts, includes, and components without leaking buffers', static function (): void {
    $views = templateViews([
        'layoutCycle' => "@extends('layoutCycle')",
        'includeCycle' => "@include('includeCycle')",
        'componentCycle' => "@component('componentCycle')x@endcomponent",
        'doubleLayout' => "@extends('a')@extends('b')",
        'unsectioned' => "@extends('base')Oops",
        'base' => 'base',
        'slotOutside' => "@slot('title')Title@endslot",
        'duplicateSlot' => "@component('base')@slot('title')a@endslot@slot('title')b@endslot@endcomponent",
        'duplicateSection' => "@section('title')a@endsection@section('title')b@endsection",
        'invalidSection' => "@section('')a@endsection",
    ]);
    $level = ob_get_level();
    foreach (['layoutCycle', 'includeCycle', 'componentCycle', 'doubleLayout', 'unsectioned', 'slotOutside', 'duplicateSlot', 'duplicateSection', 'invalidSection'] as $name) {
        raises(static fn () => $views->render($name), LogicException::class);
        same($level, ob_get_level());
    }
});

test('Concurrent processes publish a complete template cache without write races', static function () use ($root, $temp): void {
    $directory = $temp . '/concurrent-templates';
    mkdir($directory);
    file_put_contents($directory . '/index.breeze.php', '{{ $value }}');
    $processes = [];
    try {
        for ($index = 0; $index < 4; $index++) {
            $process = proc_open([PHP_BINARY, $root . '/tests/fixtures/template.php', $root . '/bootstrap.php', $directory], [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['file', $directory . "/output-$index", 'w'],
                2 => ['file', $directory . "/error-$index", 'w'],
            ], $pipes, $root, null, ['bypass_shell' => true]);
            check(is_resource($process), 'Cannot start the template worker.');
            $processes[$index] = $process;
        }
        foreach ($processes as $index => $process) {
            $exit = proc_close($process);
            unset($processes[$index]);
            same('', file_get_contents($directory . "/error-$index"));
            same(0, $exit);
            same('&lt;concurrent&gt;', file_get_contents($directory . "/output-$index"));
        }
        same(1, count(glob($directory . '/compiled/breeze-*.php')));
        same([], glob($directory . '/compiled/*.tmp'));
    } finally {
        foreach ($processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
    }
});

test('Template paths and internal engine variables cannot be overwritten by view data', static function (): void {
    $views = templateViews(['index' => '{{ $value }}']);
    same('ok', $views->render('index', ['value' => 'ok', '__engine' => 'bad', '__file' => '/missing', '__data' => []]));
    foreach (['../escape', 'C:\\escape', 'dir/name', 'con'] as $name) {
        raises(static fn () => $views->render($name), InvalidArgumentException::class);
    }
    raises(static fn () => $views->render('missing'), RuntimeException::class);
});

test('Template cache is reused and refreshed on content changes without depending on mtime', static function (): void {
    global $temp;
    $directory = $temp . '/cache-test';
    mkdir($directory);
    $source = $directory . '/index.breeze.php';
    file_put_contents($source, '{{ $value }}');
    $views = new View($directory, $directory . '/compiled');
    same('first', $views->render('index', ['value' => 'first']));
    $files = glob($directory . '/compiled/breeze-*.php');
    same(1, count($files));
    same('second', $views->render('index', ['value' => 'second']));
    same($files, glob($directory . '/compiled/breeze-*.php'));
    $time = filemtime($source);
    file_put_contents($source, 'new {{ $value }}');
    touch($source, $time);
    same('new third', $views->render('index', ['value' => 'third']));
    same(2, count(glob($directory . '/compiled/breeze-*.php')));
    same([], glob($directory . '/compiled/*.tmp'));
    if (PHP_OS_FAMILY !== 'Windows') {
        same(0600, fileperms($files[0]) & 0777);
        same(0700, fileperms($directory . '/compiled') & 0777);
    }
    file_put_contents($directory . '/compiled/unrelated.php', 'keep');
    same(2, $views->clearCache());
    same(0, $views->clearCache());
    check(is_file($directory . '/compiled/unrelated.php'));
    same('new fourth', $views->render('index', ['value' => 'fourth']));
});

test('Breeze takes precedence over PHP views while existing PHP views remain usable', static function (): void {
    global $temp;
    $directory = $temp . '/mixed-views';
    mkdir($directory);
    file_put_contents($directory . '/plain.php', '<?php echo e($value);');
    file_put_contents($directory . '/both.php', 'legacy');
    file_put_contents($directory . '/both.breeze.php', 'compiled');
    $views = new View($directory, $directory . '/compiled');
    same('&lt;Ada&gt;', $views->render('plain', ['value' => '<Ada>']));
    same('compiled', $views->render('both'));
});

test('CSRF directive generates the same token as native PHP', static function (): void {
    $session = new \PBreeze\Session();
    $views = templateViews(['index' => '@csrf($session)']);
    same(csrf_field($session), $views->render('index', ['session' => $session]));
});

test('CLI generates templates, blocks conflicts, and clears only the template cache', static function () use ($app, $temp): void {
    console($app, ['make:template', 'pages.example']);
    $file = $temp . '/app/views/pages/example.breeze.php';
    check(is_file($file));
    check(str_contains($app->views->render('pages.example', ['title' => '<Title>']), '&lt;Title&gt;'));
    raises(static fn () => console($app, ['make:view', 'pages.example']), RuntimeException::class);
    raises(static fn () => console($app, ['make:template', 'pages.example']), RuntimeException::class);
    console($app, ['make:view', 'legacy.example']);
    raises(static fn () => console($app, ['make:template', 'legacy.example']), RuntimeException::class);
    raises(static fn () => console($app, ['make:template', '../escape']), InvalidArgumentException::class);
    raises(static fn () => console($app, ['make:template', 'con']), InvalidArgumentException::class);
    check(str_contains(console($app, ['view:clear']), 'Compiled templates removed: 1'));
    check(str_contains(console($app, ['view:clear']), 'Compiled templates removed: 0'));
    check(is_file($file));
});
