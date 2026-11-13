# PBreeze

An approachable, learnable MVC foundation in native PHP 8.3+. No Composer,
external PHP packages, PSR autoloading, container, or hidden file discovery.
Only explicit `require_once` statements and PHP extensions.
Frontend exception: the official Swagger UI is included locally under
Apache-2.0 for interactive API documentation; no CDN or npm required.

Includes native SMTP, IMAP, configurable email logging, and a database queue.
With registration, login/logout, password reset, and required email verification
for protected routes.
Includes a native PDO query builder and optional ORM with models, casts,
and explicit relationships. No global database connection or autoloading.
Native AES-256-GCM encryption is available as a service and through ORM casts.
Registration, login, password-reset requests, verification-email resends,
and the example form have a native SVG CAPTCHA and honeypot.
The application, CLI, and tests are set up for both Windows and Linux.
Includes a stateless JSON API with bearer tokens, OpenAPI 3, and CLI scaffolding.
Creates PDF, Excel (`.xlsx`), Word (`.docx`), JSON, CSV, and XML files through
a native PHP API, with local saving and HTTP downloads.

## Language Convention

All framework-maintained UI, CLI output, diagnostics, emails, generated code,
comments, tests, and documentation use US English. The main web layout and
Swagger UI declare `lang="en-US"`. CLI examples use `page='New page'` as the
default page. Standard messages include `Check your input.`,
`This field is required.`, `Use at least 2 characters.`, and
`Something went wrong. Please try again later.` The reset and verification
email subjects are `Reset your password` and `Verify your email address`.
The example greeting is `Hello, Ada!`. The CAPTCHA refresh action is
`Show another code`, with the success message
`New code loaded. Enter the code and submit the form again.`

## Getting Started

Requirements: PHP 8.3+, PDO, `pdo_sqlite` for SQLite or `pdo_mysql` for MySQL,
and the standard session, json, and filter extensions. The tests also use
native process and socket functions. The CLI uses `proc_open` for the
development server and tests; this function must be available for those tasks.
Encryption requires the native `openssl` extension with AES-256-GCM support.
Excel and Word creation require the native `zip` extension; PDF creation
requires `iconv`. The document tests also use the native `dom` extension.
No package installation is required.

```sh
php breeze init
php breeze migrate
php breeze db:seed
php breeze test
php breeze serve
```

Open <http://127.0.0.1:8000>. The example form demonstrates CSRF,
validation, and safe HTML output. The development server stays on localhost.
Use `php breeze serve 8080` for a different port.
The home/test page has a responsive light design with local CSS
in `public/assets/welcome.css`, a test form with error and success messages,
and expandable CLI examples. No external fonts or scripts are loaded.

`init` creates a local `.env`, but never overwrites an existing one.
The default is SQLite in `database/app.sqlite`; the database is created by
the first database command. The form does not need a database.

## Windows and Linux

Use the same commands on both systems with `php breeze ...`.
On Windows, this works from PowerShell or cmd, without WSL, Bash, or an
executable shebang. Make sure PHP CLI is in `PATH`; check with
`php -v` and use `php --ini` to find the configuration file in use.
PHP for your web server may use a different configuration file.

On Windows, enable the required extensions in `php.ini`: `pdo_sqlite` for SQLite,
`pdo_mysql` for MySQL, and also `openssl` and `iconv` for email.
Enable `zip` for Excel/Word creation and `dom` for the document tests.
Use the correct DLLs for your PHP distribution. OpenSSL requires a valid
CA bundle; set `openssl.cafile` if needed or use the separate
`SMTP_CA_FILE`/`IMAP_CA_FILE` settings. Do not disable certificate verification.

Relative database paths are always relative to the project root. Both
`database/app.sqlite` and `database\app.sqlite` are allowed. Full
Windows drive paths and UNC paths are recognized, for example:

```dotenv
DB_DATABASE="C:/Data/PBreeze/app.sqlite"
```

Backslashes are also allowed and are not treated as `.env` escapes.
Do not use drive-relative paths such as `C:app.sqlite`. UNC paths are
recognized as absolute, but use local storage for SQLite: SMB/NFS file locking
is not a substitute for a database server. Use MySQL for shared databases.

The CLI starts PHP without a shell, so spaces, percent signs, and other
shell characters in project paths are preserved. Generators reject Windows
device names such as `CON`, `NUL`, and `COM1` on both systems. Also keep
the exact capitalization of files and `require_once` paths consistent:
Linux is generally case-sensitive, Windows generally is not.

Linux uses POSIX file permissions: new `.env` and SQLite files
get `0600`. Windows uses existing/inherited NTFS ACLs;
`chmod(0600)` does not provide comparable protection there. Therefore, before
initialization, use Windows folder security to restrict access to the project
folder, data folder, and their backups to the administrator and PHP service user.
Give the service user write access only where needed. On Windows, tests check
read/write access, not Unix permission bits.

For local development, `php breeze serve` works on both systems.
In production, you can use Apache on either system with `public/` as the
document root and the included `.htaccess` with `mod_rewrite`. For IIS,
configure PHP through FastCGI, set the site root to `public/`, and
configure URL Rewrite to `index.php` for nonexistent files.
IIS does not read `.htaccess`. HTTPS and blocking hidden files must also be
configured at the server level there.

For scheduled email delivery, use cron on Linux and Task Scheduler on Windows.
Choose the full path to `php`/`php.exe` as the program, provide the absolute
path to `breeze` and `mail:work 50` as arguments, and set the project folder
as the working directory. Put paths containing spaces in quotes.

The `.github/workflows/tests.yml` workflow runs the full native suite on
`ubuntu-latest` and `windows-latest`, with PHP 8.3 and 8.5, once the project
is on GitHub and Actions is enabled. Adding a workflow does not mean a Windows
test has been run.

## How a Request Flows

1. The web server serves only `public/`.
2. `public/index.php` loads `bootstrap.php`.
3. The bootstrap explicitly loads the framework files and reads the configuration.
4. The application loads `routes/web.php` and the explicit API modules in `routes/api.php`.
5. Web requests start a session and check unsafe methods for CSRF.
   `/api` and `/api/*` use a separate stateless router without sessions or cookie authentication.
6. The router calls your controller, which returns a `Response`.

```text
app/
  controllers/       HTTP actions
  models/            ORM models or custom database logic
  views/             PHP views and compiled .breeze.php templates
    layouts/         Shared page structure
    partials/        Reusable page fragments
    components/      Components with props and slots
config/app.php       Typed application settings in use
database/
  migrations/        Anonymous Migration objects with up/down
  seeders/           Closures for example or initial data
framework/           Small native building blocks
public/              Only public document root
routes/web.php       Explicit imports and route registration
routes/api.php       Explicit API module imports (also updated by CLI)
routes/api/          API routes and OpenAPI metadata in the same definition
storage/             Migration lock; writable by CLI
tests/run.php        Native tests, including real HTTP requests
bootstrap.php        Explicit framework registration
breeze               CLI, run with php breeze
```

## Controllers and Routes

```sh
php breeze make:controller MessageController
php breeze make:view messages.index
php breeze make:model Message
```

Load a new controller **explicitly yourself** in `routes/web.php`:

```php
require_once $app->root . '/app/controllers/MessageController.php';

$controller = new \App\Controllers\MessageController($app);
$router->get('/berichten', [$controller, 'index']);
```

A controller action receives a `Request` first, followed by any route
parameters **in order**, and always returns a `Response`:

```php
$router->get('/berichten/{id}', static function (\PBreeze\Request $request, string $id): \PBreeze\Response {
    return \PBreeze\Response::json(['id' => $id]);
});

$router->post('/berichten', [$controller, 'store']);
$router->add('DELETE', '/berichten/{id}', [$controller, 'destroy']);
```

Register specific routes before parameter routes. Parameters cover one
path segment; custom regex in routes is intentionally not supported. Trailing
slashes are equivalent. HEAD uses GET unless an explicit HEAD route exists.
Unknown paths return 404, and incorrect methods return 405 with an `Allow` header.
There is no automatic OPTIONS/CORS or method-override support.

Read form and JSON input with `$request->input('name')`, all body fields
with `$request->all()`, and query parameters separately with `$request->query('page')`.
JSON must be an object; invalid JSON returns 400. File uploads and other
body formats do not yet have a framework API.

```php
return new \PBreeze\Response($this->app->views->render('messages.index', [
    'title' => 'My messages',
]));

return \PBreeze\Response::json(['saved' => true], 201);
return \PBreeze\Response::redirect('/berichten');
```

Redirects accept only local paths that start with a single `/`.

## Native File Creation

Use `PBreeze\Documents\Documents` after loading `bootstrap.php`; its classes
are explicitly registered, with no Composer, autoloading, external programs,
or PHP packages. This feature is a PHP API, not a set of CLI commands.

| Factory | Output | Supported content |
|---|---|---|
| `Documents::json($data)` | `.json` | JSON-serializable values, pretty-printed UTF-8 |
| `Documents::csv($rows)` | `.csv` | Iterable lists of scalar cells, configurable delimiter |
| `Documents::xml($data, 'report')` | `.xml` | Nested arrays mapped to elements |
| `Documents::pdf()` | `.pdf` | Headings, paragraphs, fixed-width tables, automatic pagination |
| `Documents::excel()` | `.xlsx` | Multiple named worksheets and typed cells |
| `Documents::word()` | `.docx` | Headings, paragraphs, and bordered tables |

JSON, CSV, and XML factories return a `DocumentFile` immediately. PDF, Excel,
and Word factories return builders; call `build()` to obtain a `DocumentFile`.
Builders are mutable and chainable. Calling `build()` does not reset them.
Every file exposes read-only `contents`, `mimeType`, and `extension` properties.

### Saving and Downloading

```php
use PBreeze\Documents\Documents;

$file = Documents::json(['name' => 'Ada', 'active' => true]);
$file->save($app->root . '/storage/people.json');

// Return this from a web controller action.
return $file->download('people.json');
```

`save()` creates a new local file exclusively: existing files and symlinks are
never overwritten. The parent directory must already exist. Relative paths
use PHP's current working directory; use an application-root-based path for
predictable behavior. Both path separators are accepted. Stream-wrapper URLs,
drive-relative Windows paths, control characters, and nonportable basenames
are rejected. Files receive `0600` permissions on Linux; restrict inherited
ACLs on Windows. Incomplete writes are removed, and failures throw exceptions.

`download()` returns a normal `PBreeze\Response` with an attachment disposition,
the correct media type and byte length, `nosniff`, and `no-store`.
UTF-8 filenames use `filename*` with an ASCII fallback; filenames cannot contain
paths or control characters. Give the filename the matching extension.
Return the response rather than printing file bytes yourself, so normal HEAD
handling and response headers remain intact.

Register downloads on ordinary web routes, for example:

```php
$router->get('/reports/people.csv', $app->auth->protect(static function (\PBreeze\Request $request): \PBreeze\Response {
    return \PBreeze\Documents\Documents::csv([
        ['Name', 'Active'],
        ['Ada', true],
    ])->download('people.csv');
}));
```

Apply the appropriate authentication and record-level authorization before
exporting private data. The separate `/api/*` router still requires JSON
responses; it is not a binary-download router.

### JSON, CSV, and XML

```php
use PBreeze\Documents\Documents;

Documents::json(['total' => 12.5, 'active' => true, 'notes' => null])
    ->save($app->root . '/storage/report.json');

$rows = [
    ['Name', 'Total', 'Active'],
    ['Ada', 12.5, true],
    ['Lin', null, false],
];
Documents::csv($rows)->save($app->root . '/storage/report.csv');
Documents::csv($rows, delimiter: ';')->save($app->root . '/storage/report-semicolon.csv');

Documents::xml([
    'title' => 'Sales & totals',
    'people' => [
        ['name' => 'Ada', 'total' => 12.5],
        ['name' => 'Lin', 'total' => null],
    ],
], 'report')->save($app->root . '/storage/report.xml');
```

CSV uses UTF-8 without a BOM, CRLF record endings, doubled double quotes, and
no proprietary backslash escape. Delimiters may be comma, semicolon, tab, or
pipe. Rows must be lists, not associative arrays; generators are accepted.
Cells accept strings, finite integers/floats, booleans (`true`/`false`), and
null (an empty cell). CSV has no native type metadata.

CSV spreadsheet-formula protection is enabled by default. String cells
beginning with `=`, `+`, `-`, or `@`, including after whitespace, or beginning
with a tab or line break, receive a leading apostrophe. This intentionally
changes those text values; numeric cells remain numeric text. Use
`protectFormulas: false` only for trusted data when an exact raw export is
necessary. Importer behavior varies: retain protection when opening untrusted
CSV in spreadsheet software.

XML string keys become element names and integer keys become `<item>`:
the `people` list above becomes `<people><item><name>Ada</name>...</item>...</people>`.
Null produces an empty element. Element names must be unprefixed ASCII XML
names and must not start with `xml` (case-insensitive). Nesting is limited
to 64 levels. Attributes, namespace declarations, raw markup, DTDs, and
entities are not accepted as data features. Text is escaped, not interpreted.

JSON preserves native JSON value types and throws on invalid UTF-8, recursive
data, unsupported values, or nonfinite numbers. CSV, XML, and office-document
text must be valid UTF-8 containing only XML 1.0 characters; unsupported cell
types and nonfinite numbers throw instead of being silently converted.

### Excel Workbooks

```php
use PBreeze\Documents\Documents;

$file = Documents::excel()
    ->sheet('People', [
        ['Name', 'Total', 'Active', 'Identifier'],
        ['Ada', 12.5, true, '00123'],
        ['Lin', null, false, '98765432109876543210'],
    ])
    ->sheet('Summary', [['Count', 2]])
    ->build();

$file->save($app->root . '/storage/people.xlsx');
// Or: return $file->download('people.xlsx');
```

Requires native `zip`. Output is a real Office Open XML ZIP package, not HTML
renamed to `.xls`. At least one worksheet is required. Names must be unique
without case differences and contain 1 to 31 UTF-16 code units; Excel-reserved
characters and leading/trailing apostrophes are rejected. Each worksheet
accepts iterable list rows, up to 1,048,576 rows and 16,384 columns; text cells
are limited to 32,767 UTF-16 code units.

Integers/floats become numeric cells, booleans become boolean cells, null becomes
a blank cell, and strings always remain literal text, even when starting with
`=`. Leading zeros are retained in string identifiers. Excel numeric precision
is approximately 15 significant digits: pass long identifiers as strings.
Dates should be formatted as strings. This baseline does not provide formulas,
charts, styling, date-number formats, macros, or reading existing workbooks.

### Word Documents

```php
use PBreeze\Documents\Documents;

$file = Documents::word()
    ->heading('Sales report')
    ->paragraph("Prepared by the sales team.\nAll totals are in USD.")
    ->heading('People', 2)
    ->table(['Name', 'Total'], [['Ada', 12.5], ['Lin', null]])
    ->build();

return $file->download('report.docx');
```

Requires native `zip`. Output is a real `.docx` Office Open XML package.
Headings support levels 1 through 6; paragraphs preserve line breaks and tabs.
Tables have equal-width columns, borders, a bold repeating header row,
and 1 to 63 columns. Every row must match the number of headers.
Word handles page layout and pagination. Unicode text is supported, subject
to the reader's available fonts. Images, rich inline formatting, page
headers/footers, and editing existing documents are outside this baseline.

### PDF Documents

```php
use PBreeze\Documents\Documents;

$file = Documents::pdf()
    ->heading('Sales report')
    ->paragraph('Prepared by the sales team. All totals are in USD.')
    ->heading('People', 2)
    ->table(['Name', 'Total'], [['Ada', 12.5], ['Lin', null]])
    ->build();

return $file->download('report.pdf');
```

Requires native `iconv`. The writer produces PDF 1.4 with A4 pages, standard
Courier/Courier-Bold fonts, wrapped text, and automatic page breaks.
Headings support levels 1 through 3. Tables use fixed-width text columns
with separators, support 1 to 10 columns, and wrap long cell values.
Table rows may span pages; PDF table headers are not repeated automatically.

The built-in font supports Windows-1252 text, including common Western
European accents and the euro symbol. Other characters throw explicitly
rather than producing broken or substituted text; use Word for full Unicode
content. This is not an HTML-to-PDF renderer and does not provide embedded
fonts, images, complex layouts, encryption, digital signatures, or PDF/A.

All six formats are assembled in memory; iterable input does not make the
result a streaming export. Office packages also use private temporary files,
which are removed after creation. Bound export sizes, keep saved files
outside `public/`, and do not pass arbitrary user-selected filesystem paths.
Do not treat document creation as permission to expose ORM-hidden fields:
select export fields explicitly.

## API and Swagger / OpenAPI

The backend remains entirely native PHP. The API reserves `/api` and `/api/*`;
do not place web forms or cookie-authenticated routes here.
After `php breeze migrate`, the following are available:

| Method | Path | Access |
|---|---|---|
| GET / HEAD | `/api/ping` | Public, `{"data":{"status":"ok"}}` |
| GET / HEAD | `/api/me` | Bearer token of a verified user |
| DELETE | `/api/token` | Revokes the used token, empty HTTP 204 |
| GET / HEAD | `/api/docs` | Local Swagger UI, if enabled |
| GET / HEAD | `/api/openapi.json` | OpenAPI 3.0.3, if enabled |

### Creating, Viewing, and Revoking Tokens

First register at `/register` and verify the email address through the existing
verification email. Tokens are intentionally issued **only through the admin CLI**:
there is no public password/token endpoint, OAuth server, or additional login without CAPTCHA.
Use the numeric ID from the `users` table, not the email address:

```sh
php breeze api:token 1 laptop 30
php breeze api:tokens 1
php breeze api:revoke 12
```

`api:token <user-ID> [name] [days]` displays the new `pbz_...` token
once. The default expiration is 30 days; the allowed range is 1–365 days.
`api:tokens` displays only the ID, user ID, name, and timestamps, including for expired
tokens; `api:revoke` uses the **token ID**, not the user ID.
Store the token in a secret store, not in source code, query strings, shell history,
or shared terminal logs. The database stores only the SHA-256 hash.

For an API client, send the token in the `Authorization` header using bearer authentication.
Cookies, query parameters, and CSRF tokens never authenticate API requests.
A token is valid only while the user exists, the email address is verified,
the expiration date has not passed, and the stored `session_version` matches.
Password reset therefore also invalidates **all existing API tokens**.
Web logout removes only the web session; revoke API tokens separately.
Tokens have no scopes/roles: they represent the user.
Always implement your own per-object authorization; a valid token does not
automatically grant access to other users' data.

### Creating an API with the CLI

```sh
php breeze make:api Products
php breeze routes
php breeze api:openapi > openapi.json
```

This creates:

- `app/controllers/api/ProductsApiController.php`;
- `routes/api/products.php` with GET/POST `/api/products` and
  GET/PUT/DELETE `/api/products/{id}`;
- an **explicit require** in `routes/api.php`.

No directory scans, autoloading, or Composer. The route module registers handlers
and OpenAPI together through `$api->add()`. All generated actions require a
bearer token by default. They intentionally return HTTP **501** until you implement
them: no accidental public CRUD or success message without persistence.
Then also replace the generated 501 response descriptions and generic
requestBody schemas with your actual fields, responses, and validation.
The generator rejects existing files, reserved names, and route conflicts;
the registry is locked during the change. Run generators during development,
not as a replacement for an atomic production deployment.

A custom route definition in an explicitly loaded API module:

```php
$api->add('POST', '/api/echo', static function (\PBreeze\Request $request): \PBreeze\Response {
    $data = \PBreeze\Api::validate($request, [
        'message' => 'required|string|min:2|max:200',
    ]);
    $user = $request->apiUser();
    return \PBreeze\Response::json(['data' => [
        'message' => $data['message'],
        'user_id' => $user['id'],
    ]]);
}, [
    'summary' => 'Echo a validated message',
    'operationId' => 'echoMessage',
    'requestBody' => ['required' => true, 'content' => [
        'application/json' => ['schema' => [
            'type' => 'object',
            'required' => ['message'],
            'properties' => ['message' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 200]],
        ]],
    ]],
    'responses' => ['200' => ['description' => 'Validated message and current user ID']],
]);
```

Pass `false` as the fifth argument only for intentionally public endpoints.
`summary`, a unique `operationId`, and `responses` are required.
Path parameters automatically get a required OpenAPI string definition;
you can specify them further through `parameters`. Bearer security is derived
from the actual route protection, not from manually set metadata.
OpenAPI describes the contract but does not perform validation.
`Api::validate()` uses the existing native Validator, returns only the
specified fields, and reports invalid input with HTTP 422 and field details.
Nested JSON objects remain `stdClass`; validate them explicitly.

### JSON, Errors, and Limits

Nonempty API bodies require `Content-Type: application/json` and a JSON object.
Invalid JSON returns 400, other body types 415, and bodies larger than 1 MiB 413.
Empty bodies without a JSON content type are allowed, for example with DELETE.
Also configure limits in PHP, the web server, and the proxy: PHP may process
uploads before application code runs.
Normally parsed API requests have a fixed database rate limit of
**120 requests per direct client IP per calendar minute**; after that, HTTP 429 with
`Retry-After`. Documentation and OpenAPI are exempt and do not require a
database. `REMOTE_ADDR` is used, not unverified proxy headers.
Clients behind a proxy therefore share the proxy IP; apply additional limits
at the edge. This limit is not DDoS protection.

API errors consistently use:

```json
{"error":{"status":422,"message":"Check your input.","details":{"message":["This field is required."]}}}
```

`details` is present only for validation errors. Unknown routes return JSON 404,
incorrect methods return JSON 405 with `Allow`, and missing/invalid tokens return JSON 401 with
`WWW-Authenticate`. Unexpected errors are logged and return a generic JSON
500, even in debug mode. Use `Response::json()` for custom API responses;
for an empty 204, use an empty `Response` with a JSON content type.
API requests do not start a session or set a session cookie.
The existing web CSRF check remains unchanged.
There is intentionally no automatic CORS or preflight configuration; clients on
a different origin require an explicit, carefully restricted policy.

### Local Swagger UI

Open <http://127.0.0.1:8000/api/docs>. Use **Authorize** with only the
`pbz_...` token; then try GET `/api/me`, for example.
**Try it out** sends real requests to this server; DELETE `/api/token`
therefore actually revokes the entered token.

Documentation is enabled by default in `local`/`testing` and disabled in `production`.
Override this intentionally with `API_DOCS_ENABLED=true` or `false`.
This is an availability setting, not access control: also protect production
documentation at the proxy/web server level if needed.
`api:openapi` remains available for local export even when HTTP docs are disabled.

Swagger UI **5.35.0** is official, local, and pinned in
`public/assets/swagger-ui/`; source, integrity, and checksums are listed in `VERSION`,
with the original `LICENSE`, `NOTICE`, and JavaScript dependency licenses.
No CDN, remote validator, or npm installation is required.
Only the documentation page gets its own CSP with local scripts and the
inline styles Swagger UI needs; regular web pages retain
`script-src 'none'`. Outgoing documentation requests are restricted to the same
origin and `/api/`. The Swagger client does not send session cookies or store
authorization in localStorage/sessionStorage; reloading clears the token.
The included assets remain statically public when docs are disabled.

## Views and Validation

### Breeze Templates

The native template engine supports layouts, sections, includes, components,
slots, conditionals, and loops. No dependencies, `eval()`, or Composer:
templates are compiled to PHP and executed with `require`.

```sh
php breeze make:template messages.index
php breeze make:template layouts.blog
php breeze make:template components.panel
```

`messages.index` refers to `app/views/messages/index.breeze.php`.
Your controller continues to use the same API:

```php
return new \PBreeze\Response($this->app->views->render('messages.index', [
    'name' => 'My application',
    'title' => 'Messages',
    'items' => ['First message', 'Second message'],
    'session' => $this->app->session,
]));
```

Data is available as ordinary PHP variables. Expressions use native
PHP syntax, such as `$title ?? 'Default title'`, `strtoupper($name)`, or
`$item['title']`. All `{{ ... }}` output is automatically HTML-escaped:

```html
<h1>{{ $title }}</h1>
<input name="name" value="{{ $oldName ?? '' }}">
{{-- This comment does not appear in the HTML. --}}
```

Use this for HTML text and quoted attributes. This does not replace
URL validation, CSS escaping, or JavaScript encoding. Scalars, `null`, and
`Stringable` are supported; arrays and other objects produce an error.
Do not also use `e()` inside `{{ }}`, or you will double-escape.

**Unescaped HTML is explicit:** `{!! $html !!}`. Use this only
for trusted markup, such as an already rendered component slot, never
for unsanitized user input or incoming email.

### Layouts and Sections

A layout in `app/views/layouts/blog.breeze.php`:

```html
<!doctype html>
<html lang="en-US">
<head><title>@yield('title', 'My blog')</title></head>
<body>
    @include('partials.header')
    <main>@yield('content')</main>
</body>
</html>
```

And a page in `app/views/messages/index.breeze.php`:

```html
@extends('layouts.blog')

@section('title')
{{ $title }}
@endsection

@section('content')
    <h1>{{ $title }}</h1>
    @if(empty($items))
        <p>There are no messages yet.</p>
    @else
        <ul>
        @foreach($items as $item)
            <li>{{ $item }}</li>
        @endforeach
        </ul>
    @endif
@endsection
```

`@section` is always a block with `@endsection`; the inline form
`@section('title', 'Title')` is not supported.
`@yield` displays an already rendered section without additional escaping.
The optional default value of `@yield` is escaped.
A template with `@extends` may produce only whitespace outside sections.
Layouts may themselves extend another layout; sections from the most
specific page take precedence. A duplicate section in the same template
is an error. Sections are never shared between requests or separate renders.

### Partials and Components

An include inherits the variables in the current scope, including loop variables.
Explicit data overrides inherited values only inside the include:

```html
@include('partials.item', ['title' => $item])
```

Includes have their own section context; define layout sections in the page
itself, not in an include.

Components receive only the explicit props and their rendered slots.
For example, `app/views/components/panel.breeze.php`:

```html
<section>
    <h2>{{ $title }}</h2>
    {!! $slot !!}
    @if(isset($footer))
        <footer>{!! $footer !!}</footer>
    @endif
</section>
```

Usage:

```html
@component('components.panel', ['title' => 'Latest messages'])
    <p>{{ $intro }}</p>
    @slot('footer')
        <a href="/berichten">All messages</a>
    @endslot
@endcomponent
```

`$slot` contains the main content. Named slots such as `$footer` are made
available separately and override any props with the same name. `slot` is
reserved. Slot names are unique variable names without a leading underscore;
an `@slot` must be directly inside a component. You can nest different
components. Variables inside the invocation content come from the page,
not from the component's props.

### Directives and Error Handling

| Syntax | Usage |
|---|---|
| `{{ $value }}` | HTML-escaped output |
| `{!! $html !!}` | Intentionally raw HTML |
| `{{-- ... --}}` | Template comment |
| `@extends('layouts.app')` | Extend a layout |
| `@section('name') ... @endsection` | Define a section |
| `@yield('name', 'default')` | Display a section, with optional default |
| `@include('partial', ['key' => $value])` | Partial with optional data |
| `@component('component', ['key' => $value]) ... @endcomponent` | Component |
| `@slot('name') ... @endslot` | Named component slot |
| `@if(...) ... @elseif(...) ... @else ... @endif` | Conditionals |
| `@foreach($items as $item) ... @endforeach` | Iterate over a collection |
| `@for($i = 0; $i < 3; $i++) ... @endfor` | Counted loop |
| `@csrf($session)` | Secure hidden CSRF field |

Use `@@` for a literal `@`, for example `@@include`.
Separate directives without arguments from following letters with whitespace
or HTML, for example `@else <p>No results</p>`.
You can include regular email addresses as text; if an address happens to
contain a directive name, write it as `{{ 'name@if.example' }}`.
Do not use PHP tags, heredoc, or backtick expressions in Breeze templates;
regular PHP views remain available for exceptions.

Unknown directives, malformed expressions, and incorrectly nested blocks
throw an exception with the template name. Missing views, cyclic layouts,
recursive includes/components, and nesting beyond 32 views also produce an error.
Output buffers are cleaned up after errors, without returning a partial page
as a success. The existing application error handling displays a generic 500
in production and logs the error.

### Compilation and Cache

The cache is in `storage/views/` by default, outside `public/`.
On Linux, the directory and new cache files get `0700` and `0600`;
on Windows, the folder's NTFS ACLs apply. Make sure the PHP service user
can create and read this cache. Align ownership and permissions for the CLI
and web server; never give uploaders write access to this directory.

The cache name contains a hash of the template path, content, and
compiler version. Changed templates are automatically recompiled,
even when their modification time stays the same. Cache files contain only
compiled code, not supplied view data or rendered pages.
A lock and atomic publication protect concurrent compilations.
Cached old content is not used if compilation fails.
Old hashes remain until you clear the cache:

```sh
php breeze view:clear
```

This removes only compiled Breeze cache files, not your
source templates or other files in `storage/`. Run this during deployment
when workers/requests are inactive; do not delete code currently being loaded.
For a custom `View` instance, you can pass the cache directory as the second
constructor argument.

The home page uses `home.breeze.php`, `layouts.app`, two partials, and
`components.feedback` as a working example.

### Existing PHP Views

`php breeze make:view messages.index` continues to generate a regular
`app/views/messages/index.php`. When both formats exist,
`.breeze.php` takes precedence; generators reject such naming conflicts.
In regular PHP views, you still escape values yourself with `e()`:

```php
<h1><?= e($title) ?></h1>
```

**Security boundary:** both template formats are trusted application code,
not a sandbox for visitors. PHP expressions can execute PHP functions;
never let visitors upload or modify templates or choose view paths.
View data names beginning with `__` are reserved for the engine.

### Validation

```php
$errors = \PBreeze\Validator::errors($request->all(), [
    'name' => 'required|string|min:2|max:80',
    'email' => 'required|email',
    'age' => 'integer',
    'password' => 'required|string|min:12|confirmed',
]);
```

`errors()` returns a list of English-language errors per field, or `[]`.
`min` and `max` check the number of Unicode characters in **strings**, not
numeric values. Without `required`, fields may be missing or empty.
`confirmed` compares against, for example, `password_confirmation`.
Unknown or incorrectly configured rules throw an exception.

Check `$errors` before saving your data. The example in
`HomeController` returns status 422 for validation errors, displays errors by field,
and preserves the input. For other forms, choose the same HTML approach yourself
or a JSON response with status 422.

## Sessions and CSRF

All POST, PUT, PATCH, and DELETE requests require a session token.
Only GET, HEAD, and OPTIONS are exempt; do not modify data with them.

```php
<form action="/berichten" method="post">
    <?= csrf_field($session) ?>
    <button type="submit">Save</button>
</form>
```

Pass `$this->app->session` to the view as `session`.
A JSON client can send the same token as `X-CSRF-Token`, along with
the session cookie. An incorrect or missing token returns 419. There are
intentionally no default CSRF exceptions or unprotected API routes.
For a form with bot protection, also send the CAPTCHA fields and empty
honeypot; a CSRF token alone is not sufficient.

```php
$this->app->session->put('notice', 'Saved');
$notice = $this->app->session->get('notice');
$this->app->session->regenerate();
```

Authentication automatically regenerates the session ID and CSRF token on login
and logout. Existing session data is cleared at that time. A CSRF token
does not replace access control; explicitly protect your routes.

## CAPTCHA and Honeypot

The selected forms use **both** controls: registration, login,
forgot password, verification-email resend, and the name form
on the home page. Logout, confirming a verification link, and saving a new
password remain protected by CSRF and their own guards/tokens
and existing limits, without an additional CAPTCHA.

The CAPTCHA is entirely native PHP: no external services, JavaScript,
GD, fonts, Composer, or API key. `GET /captcha/{id}` returns a local
SVG with six random characters, drawn as vector paths with light noise.
The solution is not present as text or metadata in the HTML/SVG; the session
stores its SHA-256 hash, along with the SVG, form name, and expiration time.
Ambiguous characters `0`, `1`, `I`, and `O` are not used.

### User Experience

- Enter the six characters; capitalization does not matter. Leading and
  trailing spaces are ignored.
- A code is valid for five minutes and works only in the same session and
  for the same form purpose.
- Each submission attempt consumes the supplied code, even with an incorrect
  answer, a filled honeypot, or another validation error. The form then displays
  a new code; password fields remain empty.
- **Show another code** refreshes through a CSRF-protected POST without
  performing the form action, logging in, or sending email. The browser does not
  need to validate other fields first. Name/email values are preserved;
  passwords are not. The form's old code is revoked.
- Fetching images or making HEAD requests does not consume a code. Images
  are session-bound and are not cached.
- Up to twelve unexpired codes per session support multiple tabs. If there are
  more, the oldest code expires. Up to sixty new codes per session per minute;
  after that, 429 with `Retry-After` is returned.
- The `website` honeypot must be present as an **empty string**. The field
  is visually hidden, removed from the tab order, and hidden from screen readers;
  autocomplete is disabled. Filled, missing, or incorrectly typed values are rejected.

Bot-check errors return 422 with a generic message and a new form.
Missing/incorrect CSRF remains 419 and is checked earlier.
No success message is displayed for a blocked action.
The existing database-backed authentication limits remain active when
the bot check passes.

**Limitations:** a simple SVG CAPTCHA can be solved by OCR, pattern recognition,
or human-solving services. The honeypot is an additional hurdle, not proof
that a visitor is human. The creation limit is session-bound; new cookies bypass
that limit. Also use request limits per IP and abuse detection on your trusted
web server/proxy. A visual CAPTCHA is not independently usable by blind users:
there is no audio or equivalent accessible verification route yet.
Provide one before offering these forms to an audience for whom visual
verification is inaccessible; do not add an unprotected bypass.

### Custom Forms

The existing `Application` exposes `$app->botProtection`:

```php
// When displaying the form, after any session/auth checks:
$captcha = $app->botProtection->challenge('contact');

// Pass captcha and errors to a Breeze view, plus session for @csrf.
// In the form:
// @csrf($session)
// @include('partials.bot-protection')

// In a POST handler, after the global CSRF check:
$errors = $app->botProtection->errors($request, 'contact');
if ($errors !== []) {
    // Render status 422, with errors and a new challenge('contact').
    // Do not perform the actual form action here.
}
```

Choose a fixed server-side form name yourself, not a purpose from
request input. Reuse that exact name for issuing and checking.
The partial contains `_captcha_id`, `_captcha_answer`, and `website`.
As in the existing views, place the refresh button `_captcha_refresh=1`
with `formnovalidate` **after** your normal submit button, so Enter submits
the form by default instead of only refreshing the code.
Handle refresh separately **before**
the actual action: `$app->botProtection->forget($request, 'contact')`,
create a new challenge, and render the form again.
Your own handlers do not get bot protection automatically.

The existing session storage must be server-side and private. Login/logout
clears all session data, including any open CAPTCHAs. A JSON client
uses the same field names, same cookies, and existing CSRF header;
there is intentionally no test mode or API exception that skips CAPTCHA.
Tests create a known solution only in their isolated, local session fixture;
this does not change production code.

## Authentication

Run `php breeze migrate` to create `users`, `auth_tokens`, and `auth_limits`.
Also set the public origin in your existing `.env`:

```dotenv
APP_URL=http://127.0.0.1:8000
```

In production, this might be `https://app.example.com`: HTTPS is required,
with no path, username, query, or fragment. HTTP is allowed only locally on
localhost. Links are built only from this configuration, never from incoming
Host or proxy headers. If you use a different local port, update `APP_URL`.

### Pages and Flow

| Route | Purpose |
|---|---|
| `GET/POST /register` | Create an account and queue a verification email |
| `GET/POST /login` | Sign in with an email address and password |
| `POST /logout` | Clear the session, regenerate ID and CSRF |
| `GET/POST /forgot-password` | Request a reset link |
| `GET/POST /reset-password` | Choose a new password with a token |
| `GET/POST /verify-email` | Verify an email address with a token |
| `GET /email/verify` | Verification notice for signed-in users |
| `POST /email/verification-notification` | Request a new verification email |
| `GET /account` | Example page requiring login and email verification |

After registration, sign in; unverified accounts are sent to the verification page.
Confirm a verification link through a POST form so that a mail scanner opening
the link with GET or HEAD does not consume it. Confirmation does not sign you
in automatically. Invalid/expired tokens return 400, validation errors 422,
too many attempts 429, and missing/incorrect CSRF 419.

Passwords require at least 12 characters and no more than 72 **bytes** (the native
bcrypt limit), plus a matching confirmation. They are not repopulated in
forms, emailed, or stored other than using `password_hash()`/bcrypt,
cost 12. Login uses `password_verify()` and rehashing when needed.
Email addresses are ASCII and are trimmed and lowercased;
name and other fields are not automatically copied to database fields.

### Sending Email

Authentication emails **always go through the existing database queue**, even if
`MAIL_LOG=false`. Configure `MAIL_FROM` and SMTP as described below, and run
this regularly through cron or Windows Task Scheduler:

```sh
php breeze mail:work 50
```

Successful submission means the email is queued, not that it has been
delivered. Without working SMTP configuration/worker, the user receives no email.
No external email is sent during installation or tests. New accounts
and their verification email are stored together: if queuing fails,
registration is rolled back. Database and configuration errors are logged
as server errors, not displayed as successful submissions.

Tokens contain 32 cryptographically random bytes and are stored only as
SHA-256 hashes in `auth_tokens`. Verification tokens are valid for 24 hours,
reset tokens for 30 minutes, measured from the request. They are single-use;
a new request replaces the previous link for the same purpose. The worker
must therefore run promptly. Reset invalidates all existing login sessions
for the account and signs out the requester. Reset does not automatically verify the email address.

**Warning:** the sendable email in `emails` necessarily contains the
full link. Protect the database, backups, and CLI access as secrets.
Delete old emails according to your retention policy and configure web server/proxy logs
not to retain token-bearing query strings. Authentication pages use
`no-store` and `Referrer-Policy: no-referrer`. Disable debug in production.

### Protecting Custom Routes

```php
$router->get('/dashboard', $app->auth->protect([$controller, 'dashboard']));

// Exception: require login only, for example, on the verification page.
$router->get('/onboarding', $app->auth->protect([$controller, 'onboarding'], false));

$user = $app->auth->user();
// null or: id, name, email, email_verified_at; never a password hash.
```

The guard redirects guests to `/login` and unverified accounts to `/email/verify`.
There is no automatic redirect back to a supplied URL. Also protect your
POST/PUT/PATCH/DELETE routes with the guard. Existing example routes remain
public and work without auth tables as long as there is no signed-in session.
Roles, per-object permissions, MFA, remember-me, and external providers are
not included; add authorization before exposing user data.

Sessions expire after 30 minutes of inactivity or a maximum of 12 hours from login.
Each check reads the current user and session version from the database.
Deleted users and sessions with an outdated version lose access.

Fixed, database-backed time windows limit attempts, including across sessions:

| Action | Limit |
|---|---|
| Registration | 5 per IP per hour |
| Login | 10 per email address and 40 per IP per 15 minutes |
| Reset link | 5 per email address and 20 per IP per hour |
| Submit reset | 20 per IP per hour |
| Resend verification email | 5 per user and 20 per IP per hour |
| Submit verification token | 30 per IP per 15 minutes |

Requests that are invalid in content also count toward the IP limit once the
CAPTCHA and honeypot have passed. Expired limit records are cleaned up on
the next attempt. Only SHA-256 buckets are retained; raw IP addresses are
not stored in `auth_limits`. The source is exclusively `REMOTE_ADDR`;
forwarded headers are not trusted. Users behind a reverse proxy may share
one limit: configure the real client IP only on a trusted web server/proxy,
and also apply rate limiting at the edge.
Registration and reset return the same message for existing and nonexistent
accounts; these messages do not prevent analysis of timing or email delivery.

## Database and Models

```dotenv
DB_CONNECTION=mysql
DB_DATABASE=pbreeze
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=pbreeze
DB_PASSWORD="your own password"
```

Create the MySQL database and a separate user yourself. MySQL uses
`utf8mb4` and real prepared statements; use InnoDB for transactions.
SQLite has foreign keys and a busy timeout enabled.

```php
$rows = $app->db->execute(
    'SELECT id, title FROM notes WHERE title = ?',
    [$title],
)->fetchAll();

$app->db->transaction(static function (\PDO $pdo): void {
    // Database work only; an exception causes a rollback.
});
```

Always bind **values**; never concatenate user input into SQL.
Table names, column names, and sort directions cannot be bound: use
a fixed whitelist for those. Avoid nested transactions and DDL in
`Database::transaction()`.

`app/models/Note.php` preserves the existing small repository API and uses
the query builder internally:

```php
require_once $app->root . '/app/models/Note.php';
$notes = new \App\Models\Note($app->db);
$id = $notes->create('My first note');
$recent = $notes->latest(20);
```

Direct PDO remains available alongside the optional ORM.

## Native ORM and Query Builder

The ORM consists of `framework/Query.php` and `framework/Model.php`, explicitly
loaded in the bootstrap. Each query and model receives the existing
`Database` instance. There are no packages, autoloading, global connections,
schema inspection, or automatic migrations. The SQL is designed for SQLite
and MySQL; the existing cross-platform PDO configuration remains authoritative.

### Table Queries: Arrays

```php
$query = $app->db->table('notes');
$id = $query->insert(['title' => 'My note']);
$note = $query->find($id);          // array of null
$note = $query->findOrFail($id);    // array of RecordNotFoundException

$recent = $query->where('id', '>', 0)
    ->where('title', 'LIKE', '%note%')
    ->orderBy('id', 'desc')
    ->limit(20)
    ->get();

$query->where('id', $id)->update(['title' => 'Updated']); // row count
$query->where('id', $id)->delete();                       // row count
```

Queries are **immutable**: `where`, `orderBy`, `limit`, and `offset` return a
new query. Therefore, retain the result or chain the calls.
All `where` conditions are combined with AND:

```php
$query = $app->db->table('notes');
$filtered = $query->whereIn('id', [1, 2, 3])->whereNotNull('title');
$first = $filtered->first();            // array or null
$first = $filtered->firstOrFail();      // exception if absent
$count = $filtered->count();            // int
$exists = $filtered->exists();          // bool
$rows = $filtered->orderBy('id')->limit(10)->offset(10)->get();
```

Supported operators: `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`,
`NOT LIKE`. `where('column', $value)` uses `=`.
`where('column', null)` and `where('column', '!=', null)` use
`IS NULL` and `IS NOT NULL`, respectively; explicit `whereNull`/`whereNotNull`
are also available. `whereIn` expects a list of up to 500 non-null values; an
empty list finds or modifies **no** rows. LIKE wildcards `%` and `_` retain
their SQL meaning: parameter binding does not make them literal.

Values are bound as int, bool, string, or null; floats are bound as
textual numbers. Arrays, objects, resources, INF, and NAN are rejected.
Table and column names must be simple identifiers
(`letters`, `digits`, or `underscores`, not starting with a digit); they are quoted.
Operators and sort directions are checked. The builder does not accept
SQL fragments, joins, aliases, schema/table prefixes, OR groups, or raw
expressions. For more complex SQL, intentionally use `Database::execute()` with
bindings. Even with valid column names, choose yourself which ones a user may query
or modify: identifier validation is not authorization.

`update` and `delete` without an explicit WHERE are rejected. Writes with
sorting, limits, or offsets are also rejected so conditions are never silently
ignored. `insert`/`create` does not accept filters, sorting, or pagination.
`insert([])` uses database defaults and returns the PDO insert ID (int if
representable, otherwise string); with manual keys, use the supplied key,
not this driver-dependent ID. `get()` retrieves all matching rows unless you
specify a limit. `count()` and `exists()` use the filters and ignore read limits,
offsets, and sorting. `offset` with `get()` requires an explicit `limit`.

### Models: Objects

```sh
php breeze make:model Article
```

The generator creates a `Model` subclass with a snake_case name plus `s` as
the default table name. There is intentionally no language-dependent pluralization:
check the name, create the table through a migration, and populate `$fillable`.
The default list is empty: no fields are mass assignable.

Example for a custom `articles` table with the columns shown:

```php
<?php
declare(strict_types=1);

namespace App\Models;

final class Article extends \PBreeze\Model
{
    protected string $table = 'articles';
    protected bool $timestamps = true;
    protected array $fillable = ['author_id', 'title', 'published', 'metadata', 'published_at'];
    protected array $hidden = ['metadata'];
    protected array $casts = [
        'id' => 'int',
        'author_id' => 'int',
        'published' => 'bool',
        'metadata' => 'json',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function author(): \PBreeze\Query
    {
        return $this->belongsTo(Author::class, 'author_id');
    }
}
```

By default, a model has primary key `id`, `$incrementing = true`, and
`$timestamps = false`. Enable timestamps only if **both** columns
`created_at` and `updated_at` exist. Use UTC fields that can store values
in `Y-m-d H:i:s`. Define foreign keys and database defaults explicitly
in your migration. A model does not run schema migrations or content validation.

```php
require_once $app->root . '/app/models/Article.php';
require_once $app->root . '/app/models/Author.php';

$articles = new \App\Models\Article($app->db);
$article = $articles->create([
    'author_id' => 1,
    'title' => 'My first article',
    'published' => false,
    'metadata' => ['tags' => ['php']],
    'published_at' => null,
]);

$article->title = 'A new title'; // checks fillable and cast
$changes = $article->dirty();        // changed fields in storage format
$article->save();                    // insert new model; otherwise changes only
$article->update(['published' => true]);
$article->refresh();                 // reload; overwrites local changes

$found = $articles->query()->findOrFail($article->id); // Article-object
$list = $articles->query()->where('published', true)->orderBy('id')->get();
$article->delete();                  // deletes the row; do not save again
```

`create()` always creates a **new** model, even if you call it on a loaded
record. `query()->create()` is an equivalent alternative. `save()`,
`update()`, and `delete()` return true on success and explicitly fail on
database errors. Reading an unknown/unloaded field throws a `LogicException`.
`exists()` on a model returns the locally known saved state; for a current
database check, use `query()->where(...)->exists()`.
After a write, defaults and database values are reloaded. Saving an unchanged
model writes nothing. `refresh()` or a write on a deleted row throws
`RecordNotFoundException`; an unchanged `save()` does not check again.
This exception does not automatically produce an HTTP 404: translate it
in your controller to `HttpException(404, ...)` if desired.

`fill()` and field assignment accept only `$fillable`. Unknown fields
are not silently ignored. The primary key of a saved model and
automatic timestamps are not writable. For manual/string keys, set
`$incrementing = false`, choose `$primaryKey`, and add that key to
`$fillable` for creation.

Model queries also support filtered bulk `update`/`delete`.
`update` checks fillable fields and casts, but works without model instances:
automatic timestamps are **not** updated. Use `save()` or `update()` on a
loaded model if you want that. Primary-key updates through a model query
are always rejected. Model protections do not apply to a table query.
`insert()` is only for table queries; use `create()` for model queries.

### Casts and Serialization

| Cast | PHP value on assignment | Value when read |
|---|---|---|
| `int` | int | int, with overflow checking during hydration |
| `float` | finite int/float | float |
| `bool` | bool | bool; stored as 0/1 |
| `string` | string | string |
| `json` | array | array; JSON with explicit error messages |
| `datetime` | `DateTimeInterface` | `DateTimeImmutable` in UTC |
| `encrypted` | string | decrypted string |
| `encrypted:json` | array | decrypted array |

Null remains null for all casts; the database determines whether a column allows null.
Without a cast, only scalars/null are allowed and PDO values are used.
Casts are intentionally strict on assignment: form text `'false'`, for example,
does not accidentally become true. Validate and convert form input yourself.
Dates use UTC with second precision. JSON objects become associative arrays
when read; update a JSON field by assigning the modified array again, not by
indirectly mutating an array on a magic property. Invalid JSON, dates, or other
invalid stored values throw an exception.

```php
$array = $article->toArray();
$json = json_encode($article, JSON_THROW_ON_ERROR);
```

`$hidden` excludes fields from `toArray()` and JSON, including within a list or
pagination. For a user model, for example, put `password_hash` in
`$hidden` and also exclude it from `$fillable` if it should not be mass assignable.
Hidden fields remain directly readable by your own PHP code; the database
and `dirty()` also contain hidden values. Dates are returned in arrays/JSON
as UTC ISO-8601 strings. Serialization does not run relationship queries.
The existing authentication intentionally uses its own atomic SQL for tokens,
queues, and session versions; it does not need to be rewritten for this.

### Relationships

Define relationships with explicit model classes and keys. The related
class must itself be loaded through `require_once`:

```php
final class Author extends \PBreeze\Model
{
    protected string $table = 'authors';
    protected array $fillable = ['name'];

    public function articles(): \PBreeze\Query
    {
        return $this->hasMany(Article::class, 'author_id');
    }
}

$author = $article->author()->first(); // Author of null
$authorArticles = $author->articles()->where('published', true)->get();
```

`hasMany(Related::class, foreignKey, localKey)` uses the model's primary
key as localKey by default. `belongsTo(Related::class, foreignKey, ownerKey)`
uses the related model's primary key by default.
Both return a new, filterable **model query** on the same
database connection. A null relationship key returns no results.
Relationships on unsaved models are rejected; records are not automatically
saved or associated. For a nullable belongsTo, check for null yourself before
navigating further.

There is no implicit lazy loading through properties, eager loading, relationship
cache, cascade-save, polymorphism, or many-to-many API. Relationship queries
inside a loop therefore run as separate queries; for larger lists, deliberately
fetch the required rows in batches with `whereIn`. Cascades and referential integrity
belong in your migrations.

### Pagination and Transactions

```php
$page = $articles->query()->orderBy('id', 'desc')->paginate(20, 2);
// [
//   'data' => [Article, ...],
//   'total' => 42,
//   'per_page' => 20,
//   'current_page' => 2,
//   'last_page' => 3,
// ]
```

Table queries return the same shape with arrays in `data`. Page numbers are
positive; page size is 1 to 100. Without explicit sorting, the primary key is
used (`id` for table queries). When sorting by custom fields, also sort by a
unique key for stable pages.
An empty dataset has `last_page = 1`; pages beyond the end have empty
`data`. Counting and retrieval are separate queries, not a snapshot during
concurrent changes.

```php
$app->db->transaction(function () use ($app): void {
    $authors = new \App\Models\Author($app->db);
    $authors->create(['name' => 'Ada']);
    // Other database writes on the same connection.
});
```

Model writes use the same PDO connection and participate in the existing
transaction. Nested transactions, optimistic locking, soft deletes, and
model events have not been added. After rollback, use new/reloaded
instances: a rollback restores the database, not previously changed
PHP objects. The ORM does not replace validation, route guards, or
per-record authorization.

## Encryption

The native `Encryption` service uses **AES-256-GCM** through OpenSSL:
256-bit keys, a new cryptographically random 12-byte nonce per
encryption, and a full 16-byte authentication tag. Data is returned only
if the integrity check succeeds. There is no fallback to
plaintext, a default key, or insecure encryption.

### Creating and Storing a Key

```sh
php breeze key:generate
```

This displays one `APP_KEY=base64:...` line. Copy it to your local `.env`
or set `APP_KEY` through your deployment's environment configuration/secret store.
The command changes **no** files and never overwrites an existing
key. Each execution displays a new key; do not casually replace a key
that already protects data. `--force` is not accepted.
Run the command in a private terminal, not in shared CI logs.

The key must contain exactly 32 random bytes, canonically base64-encoded
with the `base64:` prefix; a password or custom text key is rejected.
No key is automatically generated at boot or per request.
Without `APP_KEY`, the framework continues to work until you use encryption or a
non-null encrypted cast; then an explicit `EncryptionException` is thrown.

Store a secure key backup **separately from the database**. Share the same
key with the web app, CLI, and workers that must decrypt the same data;
use different keys for different applications/environments.
Never commit real keys. Protect `.env`, environment variables, server logs,
and backups; do not dump the application configuration.
Encrypted data cannot be recovered without the original key.

### Using the Service

```php
$encrypted = $app->encryption->encrypt('Confidential text', 'orders:notes');
$plaintext = $app->encryption->decrypt($encrypted, 'orders:notes');
```

The optional context is authenticated but not stored in the payload.
You must use exactly the same context to decrypt. Choose a fixed,
application-specific context to prevent accidental reuse elsewhere.
Empty strings, Unicode, and binary strings are supported.
The output format is `pbreeze:v1:` followed by base64 of the nonce, tag, and
ciphertext. The prefix/version and context are included as additional authenticated
data. Encrypt the same value again and you get a different payload.
The format does not use PHP serialization.

```php
use PBreeze\Encryption;

$encryption = new Encryption($configuredKey);
$encrypted = $encryption->encrypt($value);
$value = $encryption->decrypt($encrypted);
```

Invalid/outdated payload formats, truncated or modified data, or an incorrect
key or context throw `PBreeze\EncryptionException`. A genuine operational
failure must remain visible; do not catch it and then return empty text or
the original ciphertext as if decryption succeeded.
Errors contain no key or plaintext. Key and plaintext parameters
are marked with `SensitiveParameter`; the service redacts key data
in `var_dump` and cannot be serialized. This does not replace careful
logging practices: your own code can still leak decrypted data.

### Encrypted ORM Casts

```php
final class Customer extends \PBreeze\Model
{
    protected string $table = 'customers';
    protected array $fillable = ['name', 'private_note', 'preferences'];
    protected array $hidden = ['private_note', 'preferences'];
    protected array $casts = [
        'private_note' => 'encrypted',
        'preferences' => 'encrypted:json',
    ];
}

$customers = new Customer($app->db);
$customer = $customers->create([
    'name' => 'Example',
    'private_note' => 'For internal use only',
    'preferences' => ['language' => 'en'],
]);
$text = $customer->private_note;       // decrypted string
$preferences = $customer->preferences; // decrypted array
$customer->update(['private_note' => 'New text']);
```

`encrypted` accepts strings; `encrypted:json` accepts arrays. Both preserve null
as database NULL. Bulk updates through a model query also use these casts.
Hydration/refresh validates ciphertext without re-encrypting:
unchanged data is not marked dirty and does not change during another
field update. Assigning the same decrypted value again preserves the
existing ciphertext. Deliberately assign new content if you want to replace it.

Use `TEXT` or, for large payloads, MySQL `LONGTEXT`, not the original
short `VARCHAR`: encryption adds 28 bytes and a prefix, and base64 further
increases the payload. The exact storage length is `11 + 4 * ceil((28 + bytes) / 3)`.
No existing columns or data are modified automatically.

The ORM context contains the table name, column name, and cast type. Moving
ciphertext to another table/column or changing the cast type is therefore
rejected. The context does not contain a row ID: moving data between rows in the
same column or restoring older valid data is not prevented.
Encryption does not protect against replay, deletion, a compromised
application, or an attacker with access to both data and key.

Encryption does **not** automatically hide fields. Reading and `toArray()`/JSON
return plaintext unless you add the field to `$hidden`.
`dirty()` and direct `$app->db->table(...)` queries use the storage format.
Table queries and handwritten SQL do not apply encrypted casts: use
models when writing to encrypted columns.

Because ciphertext is random, comparisons, `whereIn` with values,
LIKE, sorting, and relationship keys on encrypted model fields are unusable;
the model query explicitly rejects them. `whereNull`/`whereNotNull` and an empty
`whereIn` remain usable. Primary keys and automatic timestamp columns
must not have an encrypted cast. Also do not use a foreign/unique index as
a logical plaintext comparison. Existing login email addresses, password hashes,
reset/verification token hashes, and mail queue remain intentionally unchanged.
Passwords remain one-way hashed, not reversibly encrypted.

For a custom database instance, explicitly pass the same service:

```php
$db = new \PBreeze\Database($root, $databaseConfig, $encryption);
```

### Existing Data and Key Rotation

Adding an encrypted cast to an existing plaintext column does not migrate anything:
reading then intentionally fails. Create your own controlled migration to sufficiently
large columns and encrypt existing values with the correct context.
Back up first and test on a copy. Renaming a table/column also requires
decrypting with the old context and encrypting with the new context.

There is no automatic key rotation, keyring, or migration command.
For a controlled rotation, stop all writers/workers, safely store the old
key, decrypt with the old service, and encrypt with the new
service using the same context. Verify all records/backups, switch all
processes to the new key, and only then resume writes. Replacing
`APP_KEY` alone makes previously encrypted data unreadable.

## Migrations and Seeds

```sh
php breeze make:migration create_messages
php breeze migrate
php breeze migrate:status
php breeze migrate:rollback
php breeze make:seeder MessageSeeder
php breeze db:seed MessageSeeder
```

Fill in the generated `up(PDO $db)` and `down(PDO $db)` first.
The templates intentionally throw an exception until implemented:
this prevents an empty migration from being incorrectly recorded as run.
Files are sorted by timestamp. Each run forms a batch;
rollback reverses **the entire last batch**.
Do not modify or delete applied migrations; create a new migration.

SQLite runs each migration and its registration in a single transaction.
MySQL DDL causes implicit commits: a failed migration may already be
partially applied. Back up before changes and deliberately recover a
partial migration before running again. Write driver-dependent
SQL where needed; the example migration demonstrates this.

A local file lock prevents concurrent migrations from the same
project directory. MySQL also uses a database-wide advisory lock between
PBreeze processes. Use SQLite on a single host with local storage.

Seeders return a `Closure(PDO $db): void` and run in a transaction.
Use seeders for DML, not DDL. The example seeder is repeatable;
ensure your own seeders are as well. Seeders are not registered as migrations.

Database changes in production require `--force`:

```sh
php breeze migrate --force
php breeze db:seed --force
php breeze migrate:rollback --force
```

Generators refuse to overwrite existing files. New controllers
and models are never loaded automatically.

## Email: SMTP, IMAP, and Database Queue

This uses native PHP streams, OpenSSL for TLS, and iconv for incoming
MIME headers and character sets. The IMAP extension, Composer, and `mail()` are not required.
First configure your mail server and run the new migration:

```sh
php breeze migrate
```

In production, as usual, use `php breeze migrate --force`.
An existing `.env` is not automatically updated. Add the following
settings yourself; the full list is in `.env.example`.

```dotenv
MAIL_LOG=true
MAIL_FROM=sender@yourdomain.com
SMTP_HOST=smtp.yourprovider.com
SMTP_PORT=587
SMTP_ENCRYPTION=starttls
SMTP_USERNAME=sender@yourdomain.com
SMTP_PASSWORD="your app password"
IMAP_HOST=imap.yourprovider.com
IMAP_PORT=993
IMAP_ENCRYPTION=tls
IMAP_USERNAME=sender@yourdomain.com
IMAP_PASSWORD="your app password"
IMAP_MAILBOX=INBOX
```

Use your provider's actual values; the hosts above are examples.
SMTP and IMAP can use different accounts. Native password login
is supported: SMTP AUTH PLAIN/LOGIN and IMAP LOGIN. Use an
app password if needed. OAuth2 and providers that allow only OAuth2
are not supported.

`tls` is direct TLS (commonly SMTP 465 and IMAP 993); `starttls` performs
a required TLS upgrade (commonly SMTP 587 and IMAP 143).
There is no fallback to unencrypted traffic. TLS 1.2+ with certificate
and hostname verification is required for external servers.
For a custom CA, set `SMTP_CA_FILE` and `IMAP_CA_FILE` to readable
CA files; this does not disable verification.
`none` is allowed only on `localhost`/`127.0.0.1`, without credentials,
for example, for a local SMTP test server. IMAP then requires PREAUTH.
`SMTP_TIMEOUT` and `IMAP_TIMEOUT` are socket timeouts of 1–300 seconds,
15 by default. Hosts are DNS names or IPv4 addresses, not URLs.

### Sending or Queuing from PHP

Through `$app` in routes, or `$this->app` in controllers:

```php
$message = $app->mail->compose([
    'to' => ['recipient@example.com'],
    'subject' => 'Welcome',
    'text' => 'Welcome to our application.',
    'html' => '<p>Welcome to our application.</p>',
]);

$id = $app->mail->send($message);   // Send immediately through SMTP.
// OR:
$id = $app->mail->queue($message);  // Store only; send later through the CLI.
```

`from` is optional in `compose()` and defaults to `MAIL_FROM`.
You can also directly use `new \PBreeze\Mail\Message(...)`.
Use bare ASCII email addresses without display names; up to 100 recipients.
Subjects and bodies are UTF-8. You can provide text, HTML, or both;
providing both creates a `multipart/alternative` message. Subjects
must not contain control characters and are limited to 500 bytes; bodies
are limited to 5 MiB total. Headers are encoded and MIME bodies are base64-encoded.
Cc, Bcc, attachments, and supplied headers are not included.

`MAIL_LOG=true` is the default:

- Directly sent outgoing messages are stored in `emails` before SMTP.
  The table stores the sender, recipients, subject, text, HTML, Message-ID,
  status, attempts, timestamps, and a sanitized error message.
- A database error before sending prevents the message from being sent
without a log. An SMTP error throws an exception and remains in the table.
- Retrieved incoming messages are stored in the same table.
- With logging enabled, `send()` returns the ID; otherwise, it returns `null`.

With `MAIL_LOG=false`, direct sending and IMAP retrieval do not use an
email table. **Explicitly using `queue()` or `mail:queue` always stores a message**:
a database queue cannot operate without storage. All stored outgoing
messages can still be sent through the CLI, and their status is updated.
Settings and passwords are not stored in the email table.

### Sending Through the CLI

Create a JSON file yourself, for example `message.json`:

```json
{
  "to": ["recipient@example.com"],
  "subject": "Welcome",
  "text": "Welcome to our application.",
  "html": "<p>Welcome to our application.</p>"
}
```

```sh
php breeze mail:queue /path/to/message.json
php breeze mail:list
php breeze mail:show 1
php breeze mail:send 1
php breeze mail:work 50
```

`mail:queue` displays the new ID; replace `1` with that ID. The JSON file
may be up to 6 MiB; the body content limit remains 5 MiB.
Store such files outside `public/` and do not commit confidential messages.
`mail:work` processes one batch, oldest first, and stops at the first error
with exit code 1. It is not a background daemon. You can run this command
periodically through cron (Linux) or Task Scheduler (Windows), using absolute paths to
PHP and `breeze`.
`mail:list`, `mail:show`, and `mail:fetch` output JSON, so even untrusted
subjects cannot execute terminal control codes.

Delivery statuses:

| Status | Meaning |
|---|---|
| `queued` | Ready to send; the worker selects only this status. |
| `sending` | Atomically claimed by a sending process. |
| `sent` | SMTP returned a 250 confirmation after DATA; delivery is not guaranteed. |
| `failed` | Not sent or explicitly rejected by SMTP. |
| `uncertain` | Connection lost after DATA began; SMTP may already have accepted the email. |
| `received` | Retrieved through IMAP; never directly sendable. |

```sh
php breeze mail:retry 1
php breeze mail:send 1
```

`mail:retry` changes only `failed` back to `queued`.
`sent` and incoming emails cannot be resent with these
commands. Create a new message for an intentional resend.
If a process crashes, the status may remain `sending`. In that case,
first check the SMTP server and stop active workers; only then deliberately
use `mail:retry ID --force` for `sending` or `uncertain`.
This can cause **duplicate delivery**. There is intentionally no automatic
timeout retry. The Message-ID remains the same on retries, but that does not
guarantee recipient-side deduplication.

If SMTP accepts the email but saving `sent` fails, the
status remains `sending` and the caller receives an error. In that case,
also check the SMTP server first. Concurrent workers can never claim the same
`queued` row; if a claim conflict occurs, the worker stops explicitly
and the next scheduled run can proceed.
SMTP acceptance is not the same as delivery: bounces are not
processed automatically. Configure SPF, DKIM, and DMARC at the domain/mail server level.

### Reading and Logging the IMAP Inbox

```sh
php breeze mail:fetch 50
```

Or from PHP:

```php
$messages = $app->mail->fetch(50);
foreach ($messages as $message) {
    $id = $message['id'];            // null when MAIL_LOG=false
    $subject = $message['message']['subject'];
    $text = $message['message']['text'];
}
```

The client opens the mailbox with `EXAMINE` and reads `BODY.PEEK[]`: messages
are not marked as read, deleted, or moved. The default is `INBOX`; other
mailbox names are ASCII or pre-encoded IMAP modified UTF-7. When logging is
enabled, the oldest UIDs not yet stored are retrieved so repeated batches
work through the inbox. Without logging, the most recent UIDs are retrieved
and there is no persistent import checkpoint.
Limits are 1–1000 messages per run, 50 by default.

Deduplication uses server, port, account, mailbox, UIDVALIDITY, and UID;
the unique database index also protects concurrent imports.
A changed UIDVALIDITY or account/server configuration can intentionally cause
a new import. Deleted database rows can be imported again. The server is queried
with `UID SEARCH ALL` on each run; metadata is limited to 2 MiB per response.

Incoming MIME is parsed into text and HTML, including base64,
quoted-printable, RFC 2047 headers, and iconv character sets.
Attachments are not unpacked; the exact original email remains
base64-encoded in `raw_message`. `headers_json` stores the
original unfolded headers; outgoing emails store structured
fields instead of a raw copy. Incoming `to_addresses` contains the
original To header as a single list item, not a validated
recipient list. Incoming data is never reused as an outgoing `Message`.

The default maximum is 10 MiB raw per message (`IMAP_MAX_BYTES`), configurable up to
50 MiB; each batch is limited to 50 MiB raw. MIME nesting is limited to 10
and the number of parts to 100. Larger, malformed, or unsupported
messages cause an explicit error, not an apparently successful import.
A parse/network error during retrieval does not save that batch. If a
later database error occurs, earlier inserts in the batch may be retained;
deduplication makes the next run safe.

Protect access to `emails`, backups, and the CLI: messages may
contain confidential data. Base64 is **not encryption**. Set your own
retention period, restricted database permissions, and appropriate storage
security. Incoming HTML is not sanitized: never render it directly
in your application; display it as text with `e()` or use a separate,
well-sanitized and protected viewer.

## Configuration

System environment variables take precedence over `.env`. `.env` supports
`NAME=value`, optional single/double quotes, and full-line comments.
There is no interpolation, escape processing, multiline, or inline comment syntax.
Use `true`/`false` for booleans. `.env` and database files are in
`.gitignore`; new `.env` and SQLite files get Linux permissions
`0600`. On Windows, the folder's NTFS ACLs apply; restrict them in advance.
`APP_ENV` accepts only `local`, `testing`, and `production`;
a typo therefore does not silently disable production security.

## Production and Security Boundaries

- Set the document root **only to `public/`**, never to the project root.
- Use HTTPS, `APP_ENV=production`, and `APP_DEBUG=false`.
- Behind an HTTPS reverse proxy, set `SESSION_SECURE_COOKIE=true`.
  The framework intentionally does not automatically trust forwarded headers.
- Production refuses to start without HTTPS or explicit secure-cookie
  configuration. A secure cookie does not encrypt an HTTP connection:
  also enforce HTTPS in your web server or proxy.
- Ensure PHP errors go only to protected server logs;
  also configure PHP itself with `display_errors=Off` and `log_errors=On`.
- Give only the required user write access to the SQLite database,
  database directory, and `storage/`. On Linux, a file created with `0600`
  is readable only by its owner: align the CLI/PHP-FPM user accordingly.
  On Windows, restrict NTFS ACLs to the required users.
  Do not make the entire application writable by the web server.
- Limit request sizes and timeouts in PHP and the web server; configure rate limiting
  and TLS/HSTS at the proxy or web server level.
- Default headers include CSP, `nosniff`, frame blocking, a restricted
  permissions policy, and `Cache-Control: no-store` for application responses.
  The default CSP allows no scripts or inline styles; only the
  Swagger documentation gets the limited exception described above.
  Custom responses can explicitly change headers; keep such changes limited.
- Static files are served directly by the web server: configure suitable
  caching and security headers for them yourself.
- Protect custom routes with the auth guard and add per-object authorization
  and application-specific validation before public use.
- Never run the development server as a production server. Security measures
  are not a guarantee: have a real application reviewed before deployment.

Apache can use the included `public/.htaccess` with `mod_rewrite`
and permitted overrides. For Nginx, configure PHP-FPM for `/index.php` only,
deny hidden files, and use, for example:

```nginx
root /path/to/pbreeze/public;
index index.php;

location ~ /\. { deny all; }
location / { try_files $uri $uri/ /index.php?$query_string; }
location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
location ~ \.php$ { return 404; }
```

Adjust the socket path for your installation and configure HTTPS separately.
Never put confidential files or uploads containing executable code in `public/`.

## Tests

```sh
php breeze test
php breeze routes
```

The tests use an isolated temporary SQLite database, test all
generators, and briefly start a local HTTP server on an available port.
The server is stopped and temporary files are cleaned up.
SMTP and IMAP are tested through local protocol fixtures, including native TLS,
STARTTLS, authentication, certificate verification, database queue, deduplication,
and loss of the SMTP confirmation. No external email is sent.
Authentication tests exercise the real HTTP forms, session and CSRF rotation,
email verification, token expiration/reuse, password reset, session revocation,
and persistent rate limits on a temporary database.
ORM tests check query bindings, identifier validation, CRUD, write guards,
fillable/hidden, casts and corrupted stored values, pagination, relationships,
manual keys, transactions, and the model generator.
Encryption tests check native OpenSSL interoperability, random
nonces, byte-level tampering, incorrect keys/contexts, invalid
payloads, CLI key generation, and encrypted ORM casts.
Bot protection tests check session/form binding, expiration, reuse,
honeypot types, bounded session storage, SVG responses, refreshing, and the
absence of side effects on all five protected forms.
API tests check token hashes, verification, expiration/revocation, password reset,
stateless HTTP, JSON errors/body limits, rate limiting, OpenAPI/route registration,
CLI scaffolding, and the local Swagger assets and restricted CSP.
MySQL also requires a separate integration run on a disposable database;
the included suite does not validate the MySQL connection against a live server.
