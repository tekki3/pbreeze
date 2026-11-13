<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sourceRoot = str_replace('\\', '/', $root);
if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--check')) {
    throw new InvalidArgumentException('Usage: php docs/build.php [--check]');
}
require $root . '/bootstrap.php';
require_once $root . '/framework/Console.php';
$reference = require __DIR__ . '/reference.php';

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function anchor(string $value): string
{
    return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value)), '-');
}

function code(string $value, string $language = 'php'): string
{
    return '<pre tabindex="0"><code data-language="' . html($language) . '">' . html($value) . '</code></pre>';
}

function inline(string $value): string
{
    $parts = preg_split('/(`[^`]+`|\[[^\]]+\]\([^)]+\)|<https?:\/\/[^>]+>)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result = '';
    foreach ($parts as $part) {
        if (str_starts_with($part, '`') && str_ends_with($part, '`')) {
            $result .= '<code>' . html(substr($part, 1, -1)) . '</code>';
        } elseif (preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/D', $part, $match)) {
            $url = $match[2];
            if (!preg_match('~^(https?://|#|[A-Za-z0-9_./-]+$)~D', $url)) {
                throw new RuntimeException('Unsafe documentation link.');
            }
            if (str_starts_with($url, '#')) {
                $url = '#guide-' . substr($url, 1);
            } elseif (!str_contains($url, '://')) {
                $url = '../' . $url;
            }
            $result .= '<a href="' . html($url) . '">' . html($match[1]) . '</a>';
        } elseif (preg_match('~^<(https?://[^>]+)>$~D', $part, $match)) {
            $result .= '<a href="' . html($match[1]) . '">' . html($match[1]) . '</a>';
        } else {
            $text = html($part);
            $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
            $result .= $text;
        }
    }
    return $result;
}

/** @param array<string, int> $ids */
function markdown(string $source, array &$ids): string
{
    $lines = explode("\n", $source);
    $out = '';
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^```([a-zA-Z0-9_-]*)/', $line, $match)) {
            $body = [];
            $closed = false;
            while (++$i < count($lines)) {
                if (str_starts_with($lines[$i], '```')) {
                    $closed = true;
                    break;
                }
                $body[] = $lines[$i];
            }
            if (!$closed) {
                throw new RuntimeException('Unclosed documentation code block.');
            }
            $out .= code(implode("\n", $body), $match[1] ?: 'text');
        } elseif (preg_match('/^(#{2,6}) (.+)$/', $line, $match)) {
            $base = 'guide-' . anchor($match[2]);
            $ids[$base] = ($ids[$base] ?? 0) + 1;
            $id = $base . ($ids[$base] > 1 ? '-' . $ids[$base] : '');
            $level = strlen($match[1]);
            $out .= '<h' . $level . ' id="' . $id . '">' . inline($match[2]) . '</h' . $level . '>';
        } elseif (str_starts_with($line, '|')) {
            $rows = [];
            while ($i < count($lines) && str_starts_with($lines[$i], '|')) {
                $row = trim($lines[$i], "| \r");
                if (!preg_match('/^[\s|:-]+$/D', $row)) {
                    $rows[] = array_map('trim', preg_split('/\|(?=(?:[^`]*`[^`]*`)*[^`]*$)/', $row));
                }
                $i++;
            }
            $i--;
            $out .= '<div class="table-wrap" tabindex="0"><table><thead><tr>';
            foreach (array_shift($rows) ?? [] as $cell) {
                $out .= '<th scope="col">' . inline($cell) . '</th>';
            }
            $out .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $out .= '<tr>';
                foreach ($row as $cell) {
                    $out .= '<td>' . inline($cell) . '</td>';
                }
                $out .= '</tr>';
            }
            $out .= '</tbody></table></div>';
        } elseif (preg_match('/^(?:[-*]|\d+\.) (.*)$/', $line, $match)) {
            $ordered = preg_match('/^\d+\./', $line) === 1;
            $tag = $ordered ? 'ol' : 'ul';
            $out .= '<' . $tag . '>';
            do {
                preg_match('/^(?:[-*]|\d+\.) (.*)$/', $lines[$i], $item);
                $text = $item[1];
                while (isset($lines[$i + 1]) && preg_match('/^ {2,}\S/', $lines[$i + 1])) {
                    $text .= ' ' . trim($lines[++$i]);
                }
                $out .= '<li>' . inline($text) . '</li>';
                $i++;
            } while (isset($lines[$i]) && preg_match($ordered ? '/^\d+\. /' : '/^[-*] /', $lines[$i]));
            $i--;
            $out .= '</' . $tag . '>';
        } else {
            $text = $line;
            while (isset($lines[$i + 1]) && trim($lines[$i + 1]) !== ''
                && !preg_match('/^(?:#{1,6} |```|\||[-*] |\d+\. )/', $lines[$i + 1])) {
                $text .= ' ' . $lines[++$i];
            }
            $out .= '<p>' . inline($text) . '</p>';
        }
    }
    return $out;
}

function defaultValue(ReflectionParameter $parameter): string
{
    if (!$parameter->isDefaultValueAvailable()) {
        return 'Required';
    }
    $value = $parameter->getDefaultValue();
    return $value === [] ? '[]' : str_replace("\n", ' ', var_export($value, true));
}

function signature(ReflectionFunctionAbstract $function): string
{
    $parameters = [];
    foreach ($function->getParameters() as $parameter) {
        $parameters[] = ($parameter->hasType() ? $parameter->getType() . ' ' : '')
            . ($parameter->isPassedByReference() ? '&' : '')
            . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName()
            . ($parameter->isDefaultValueAvailable() ? ' = ' . defaultValue($parameter) : '');
    }
    $prefix = $function instanceof ReflectionMethod
        ? ($function->isProtected() ? 'protected ' : 'public ') . ($function->isStatic() ? 'static ' : '') : '';
    return $prefix . $function->getName() . '(' . implode(', ', $parameters) . ')'
        . ($function->hasReturnType() ? ': ' . $function->getReturnType() : '');
}

function referenceEntry(string $id, string $label, ReflectionFunctionAbstract $function, array $entry): string
{
    [$description, $example] = $entry;
    if (trim($description) === '' || trim($example) === '') {
        throw new RuntimeException('Missing reference prose or example: ' . $label);
    }
    $parse = str_starts_with($example, 'public function')
        ? 'class Example { ' . $example . ' }' : $example;
    token_get_all('<?php ' . $parse, TOKEN_PARSE);
    $result = '<article class="function-entry" id="' . $id . '" data-search-unit data-title="' . html($label) . '">'
        . '<h3><a href="#' . $id . '">' . html($label) . '</a></h3>'
        . code(signature($function)) . '<p>' . inline($description) . '</p>';
    $comment = $function->getDocComment() ?: '';
    if (str_contains($comment, '@internal') || in_array($function->getName(), ['withApiUser', 'moveTo'], true)) {
        $result .= '<p class="note">Internal integration surface. Prefer the higher-level API described above.</p>';
    }
    if ($function->getParameters() !== []) {
        $result .= '<div class="table-wrap" tabindex="0"><table><thead><tr><th scope="col">Parameter</th>'
            . '<th scope="col">Accepted type</th><th scope="col">Default</th></tr></thead><tbody>';
        foreach ($function->getParameters() as $parameter) {
            $result .= '<tr><td><code>$' . html($parameter->getName()) . '</code></td><td><code>'
                . html((string) $parameter->getType() ?: 'See explanation') . '</code></td><td><code>'
                . html(defaultValue($parameter)) . '</code></td></tr>';
        }
        $result .= '</tbody></table></div>';
    }
    $return = $function->hasReturnType() ? (string) $function->getReturnType() : 'No declared return type; see explanation.';
    $result .= '<p><strong>Returns:</strong> <code>' . html($return) . '</code></p>';
    if ($comment !== '') {
        $contract = trim(preg_replace('/^\s*\* ?/m', '', substr($comment, 3, -2)));
        if ($contract !== '') {
            $result .= '<details><summary>Array shapes and source contract</summary>' . code($contract, 'text') . '</details>';
        }
    }
    $result .= '<h4>Simple example</h4>' . code($example);
    $source = str_replace('\\', '/', substr($function->getFileName(), strlen(dirname(__DIR__)) + 1));
    return $result . '<p class="source">Defined in <a href="../' . html($source) . '">'
        . html($source) . '</a>, line ' . $function->getStartLine() . '.</p></article>';
}

$readme = file_get_contents($root . '/README.md');
if ($readme === false) {
    throw new RuntimeException('Cannot read README.md.');
}
$readme = str_replace(["\r\n", "\r"], "\n", $readme);
$ids = [];
$navigation = '<a href="#start">Start here</a><a href="#tutorial">Build your first feature</a>';
$chapters = '';
preg_match_all('/^## ([^\r\n]+)\r?\n(.*?)(?=^## |\z)/ms', $readme, $matches, PREG_SET_ORDER);
foreach ($matches as $chapter) {
    $id = 'guide-' . anchor($chapter[1]);
    $navigation .= '<a href="#' . $id . '">' . html($chapter[1]) . '</a>';
    $chapters .= '<section class="chapter" data-search-unit data-title="' . html($chapter[1]) . '">'
        . markdown('## ' . $chapter[1] . "\n" . $chapter[2], $ids) . '</section>';
}

$api = '';
$methodCount = 0;
$classCount = 0;
$declared = [];
foreach (get_declared_classes() as $name) {
    $class = new ReflectionClass($name);
    if (str_starts_with($name, 'PBreeze\\') && $class->getFileName()
        && str_starts_with(str_replace('\\', '/', $class->getFileName()), $sourceRoot . '/framework/')) {
        $declared[$name] = $class;
    }
}
uksort($declared, 'strnatcasecmp');
$referenceNavigation = '';
foreach ($declared as $name => $class) {
    if (!isset($reference['classes'][$name])) {
        throw new RuntimeException('Missing class reference: ' . $name);
    }
    $classCount++;
    $entry = $reference['classes'][$name];
    $id = 'class-' . anchor($name);
    $short = substr($name, strlen('PBreeze\\'));
    $referenceNavigation .= '<a href="#' . $id . '">' . html($short) . '</a>';
    $api .= '<section class="chapter api-class" id="' . $id . '"><h2>' . html($name) . '</h2>'
        . '<p>' . inline($entry['description']) . '</p>';
    if ($entry['setup'] !== '') {
        token_get_all('<?php ' . $entry['setup'], TOKEN_PARSE);
        $api .= '<h3>Starting point</h3>' . code($entry['setup']);
    }
    $properties = array_filter($class->getProperties(ReflectionProperty::IS_PUBLIC),
        static fn (ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $name);
    if ($properties !== []) {
        $api .= '<h3>Public properties</h3><ul>';
        foreach ($properties as $property) {
            $api .= '<li><code>' . ($property->isReadOnly() ? 'readonly ' : '')
                . html((string) $property->getType()) . ' $' . html($property->getName()) . '</code></li>';
        }
        $api .= '</ul><p>These are instance properties, not global variables. Their meaning is explained in the class overview and related guide chapter.</p>';
    }
    $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);
    $seen = [];
    foreach ($methods as $function) {
        if ($function->getDeclaringClass()->getName() !== $name
            || ($function->isProtected() && !in_array($function->getName(), ['hasMany', 'belongsTo'], true))) {
            continue;
        }
        $method = $function->getName();
        if (!isset($entry['methods'][$method])) {
            throw new RuntimeException('Missing method reference: ' . $name . '::' . $method);
        }
        $api .= referenceEntry('method-' . anchor($name . '-' . $method), $short . '::' . $method . '()', $function, $entry['methods'][$method]);
        $seen[] = $method;
        $methodCount++;
    }
    if (array_diff(array_keys($entry['methods']), $seen) !== []) {
        throw new RuntimeException('Stale reference methods: ' . $name);
    }
    if ($seen === []) {
        $api .= '<p>No framework-declared public methods. Standard inherited PHP exception methods, such as getMessage(), follow native PHP behavior.</p>';
    }
    $api .= '</section>';
}
if (array_diff(array_keys($reference['classes']), array_keys($declared)) !== []) {
    throw new RuntimeException('Stale class reference metadata.');
}
$api .= '<section class="chapter" id="global-functions"><h2>Global template helpers</h2>';
$functions = get_defined_functions()['user'];
$count = 0;
foreach ($functions as $name) {
    $function = new ReflectionFunction($name);
    if (str_replace('\\', '/', $function->getFileName()) !== $sourceRoot . '/framework/helpers.php') {
        continue;
    }
    if (!isset($reference['functions'][$name])) {
        throw new RuntimeException('Missing global function: ' . $name);
    }
    $api .= referenceEntry('function-' . anchor($name), $name . '()', $function, $reference['functions'][$name]);
    $count++;
}
$api .= '</section>';

$tutorial = require __DIR__ . '/walkthrough.php';
$logo = file_get_contents($root . '/artwork/logo.svg');
if ($logo === false) {
    throw new RuntimeException('Cannot read the documentation logo.');
}
$html = '<!doctype html><html lang="en-US"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="description" content="The complete PBreeze user manual: beginner tutorials, every public function, CLI commands, configuration, and deployment.">'
    . '<title>PBreeze User Manual</title><link rel="stylesheet" href="css/manual.css">'
    . '<script src="js/manual.js" defer></script></head><body>'
    . '<a class="skip-link" href="#main">Skip to manual</a>'
    . '<header class="topbar"><a class="brand" href="#start">' . $logo . 'PBreeze <span>User manual</span></a>'
    . '<a href="../README.md">Project README</a><button type="button" id="print" hidden>Print manual</button></header>'
    . '<div class="layout"><aside class="sidebar"><details class="contents" open><summary>Contents</summary>'
    . '<nav aria-label="Manual chapters">' . $navigation
    . '<a href="#cli-reference">Every CLI command</a><a href="#configuration-reference">Environment reference</a>'
    . '<a href="#api-reference">Function reference</a><a href="#troubleshooting">Troubleshooting</a>'
    . '<a href="#global-functions">Global helpers</a></nav>'
    . '<details class="class-nav"><summary>Browse all ' . $classCount . ' classes</summary>'
    . '<nav aria-label="Classes">' . $referenceNavigation . '</nav></details></details></aside>'
    . '<main id="main"><section class="intro" id="start" data-search-unit data-title="Start here">'
    . '<h1>Build with PBreeze.</h1><p class="lead">A complete, practical guide to the native PHP framework. Start with a working feature, then keep the function reference beside you.</p>'
    . '<div class="search" id="search-panel" hidden><label for="manual-search">Search chapters, functions, commands, and examples</label>'
    . '<div class="search-row"><input id="manual-search" type="search" placeholder="Try moneyMinor, uploads, SMTP, or migrations" autocomplete="off">'
    . '<button type="button" id="clear-search" hidden>Clear</button></div>'
    . '<p id="search-status" role="status" aria-live="polite"></p><ol id="search-results"></ol></div>'
    . '<p>For PHP 8.3+ on Windows and Linux. No Composer, no PSR autoloading, and no external PHP packages. '
    . 'All maintained explanations and examples are US English. This manual opens directly from disk and works offline; JavaScript only enhances search and printing.</p>'
    . '<p><strong>Coverage:</strong> ' . $classCount . ' framework classes, ' . $methodCount
    . ' declared public methods and protected relationship helpers, ' . $count . ' global helpers, all CLI commands, and every example environment setting.</p>'
    . '<h2>How to use this manual</h2><ol><li>Follow <a href="#tutorial">Build your first feature</a> for a complete route, validation, database, and view workflow.</li>'
    . '<li>Use the chapters for full feature guides and production limits.</li>'
    . '<li>Use the <a href="#api-reference">function reference</a> for exact signatures, parameter defaults, return types, and simple examples.</li></ol>'
    . '<p class="note">Examples are learning material, not commands to run blindly against production. Replace example IDs, domains, and owner checks with application-specific values. Back up before schema changes.</p></section>'
    . $tutorial . $chapters
    . '<section class="chapter" id="api-reference" data-search-unit data-title="Using the function reference"><h2>Function reference</h2>'
    . '<p>Every public method declared by the framework is listed below, including constructors, magic methods, and low-level integration surfaces. '
    . 'The two protected ORM relationship helpers are included because application models call them. Private implementation helpers and inherited native PHP exception methods are not application APIs.</p>'
    . '<p>Signatures, parameter defaults, public properties, source locations, and PHPDoc array shapes are read from the actual source when this manual is built. '
    . 'Each function has an authored explanation and example; the builder fails if a declared method is missing. Constructors return an object through <code>new</code>, even though PHP constructor signatures have no return type.</p>'
    . '<p>In snippets, <code>$app</code> is the bootstrapped Application; <code>$request</code> is the handler Request; <code>$date</code> is a DateTimeImmutable; '
    . '<code>$query</code> is a Query; <code>$notes</code>/<code>$note</code> are the model prototype/loaded record from the Model starting point; '
    . '<code>$file</code>, <code>$pdf</code>, <code>$book</code>, <code>$word</code>, and mail variables are introduced by their feature chapter. '
    . 'Snippets using <code>$__engine</code> illustrate compiler internals, not standalone scripts. Examples with a public method declaration belong inside your subclass.</p>'
    . '<p>Unless an explanation says otherwise, invalid arguments throw InvalidArgumentException/TypeError and I/O/database failures throw exceptions. '
    . 'A <code>void</code> function completes without returning application data; chainable builder returns reuse that builder, while Query filters clone it. '
    . 'Do not catch exceptions just to return a success-shaped fallback.</p></section>'
    . $api . '<footer><p>PBreeze User Manual · Native PHP, explicit behavior.</p>'
    . '<p>Maintainers: rebuild with <code>php docs/build.php</code>; verify committed output with <code>php docs/build.php --check</code>. '
    . 'The build reads README.md, docs/reference.php, docs/walkthrough.php, and declared framework APIs; it never includes local environment values in the manual.</p>'
    . '<a href="#start">Back to the beginning</a></footer></main></div></body></html>';
$html = str_replace(["\r\n", "\r"], "\n", $html);

$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true);
try {
    if (!$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('Cannot validate generated HTML.');
    }
} finally {
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
}
$seenIds = [];
foreach (['link' => 'href', 'script' => 'src'] as $tag => $attribute) {
    foreach ($dom->getElementsByTagName($tag) as $element) {
        $asset = $element->getAttribute($attribute);
        if (!preg_match('~^(?:css|js)/[a-zA-Z0-9._-]+$~D', $asset) || !is_file(__DIR__ . '/' . $asset)) {
            throw new RuntimeException('Missing or invalid local manual asset: ' . $asset);
        }
    }
}
foreach ($dom->getElementsByTagName('*') as $element) {
    if ($element->hasAttribute('id')) {
        $id = $element->getAttribute('id');
        if (isset($seenIds[$id])) {
            throw new RuntimeException('Duplicate documentation ID: ' . $id);
        }
        $seenIds[$id] = true;
    }
}
foreach ($dom->getElementsByTagName('a') as $link) {
    $href = $link->getAttribute('href');
    if (str_starts_with($href, '#') && !isset($seenIds[substr($href, 1)])) {
        throw new RuntimeException('Broken manual anchor: ' . $href);
    }
    if (str_starts_with($href, '../') && !is_file(__DIR__ . '/' . explode('#', $href, 2)[0])) {
        throw new RuntimeException('Broken source/document link: ' . $href);
    }
}
$path = __DIR__ . '/index.html';
if (in_array('--check', $argv, true)) {
    if (!is_file($path) || str_replace(["\r\n", "\r"], "\n", file_get_contents($path)) !== $html) {
        throw new RuntimeException('The HTML manual is stale. Run php docs/build.php.');
    }
} elseif (file_put_contents($path, $html) !== strlen($html)) {
    throw new RuntimeException('Cannot write the HTML manual.');
}
echo "Manual verified: $classCount classes, $methodCount methods, $count global helpers, "
    . count($commands) . ' CLI commands, ' . count($settings)
    . " environment settings; reference examples parse and all links resolve.\n";
