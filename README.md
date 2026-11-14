# PBreeze

<img src="artwork/logo.svg" alt="PBreeze logo" width="64" height="64">

PBreeze helps you build websites with PHP 8.3 or later on Windows and Linux.
It gives you tools for pages, forms, databases, user accounts, and email.
You write normal PHP; no Composer installation or frontend build is needed.

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
Provides localized date/time, money, decimal, and percentage formatting through
native Intl, with exact decimal-string rounding.
Supports validated multipart uploads with explicit allowed types, a 2 MiB
default file limit, random storage keys, and private storage.
Includes UTF-8 string manipulation, grapheme-safe slicing/truncation,
slug and naming helpers, and cryptographic random strings.

Read the complete [HTML user manual](docs/index.html) for beginner walkthroughs,
every public function, CLI commands, and environment settings. Open it directly
in a browser; its local search, styles, and print layout work offline.
The contents are grouped into learning, building, deployment, and reference
sections. Code examples include language labels and copy controls; if the
browser blocks clipboard access, the manual selects the code for manual copying.
All documentation remains readable without JavaScript.
After changing framework APIs or documentation, run `php docs/build.php` to
regenerate it and `php docs/build.php --check` to verify it is current.
Application styles and scripts live in `public/assets/css/` and
`public/assets/js/`. Vendored Swagger UI keeps its own `css/` and `js/`
subfolders under `public/assets/swagger-ui/`, alongside its license metadata.
The offline manual uses `docs/css/` and `docs/js/`; keep these folders with
`docs/index.html` when copying the manual.
The root `artwork/` folder contains image and logo source files.
The README references its logo, and the manual builder embeds that logo so
the HTML manual remains usable offline without copying the artwork folder.
Artwork is not used by the application or served publicly.

## Start Here: Your First Steps

New to frameworks? Start with a local copy on your own computer, not a live
website. You should be comfortable editing a file, saving it, and writing
basic PHP variables and functions. You do not need to understand every
feature in this manual before making your first page.

### Follow this learning path

1. Follow the [Detailed Installation Guide](#detailed-installation-guide).
   Choose **SQLite** and the built-in development server. Follow either the
   Linux or Windows PHP instructions, not both.
2. Check that the home page opens in your browser. Then follow
   [Your First Page](#your-first-page) below.
3. In the [HTML manual](docs/index.html), continue with **Build your first
   feature** to create a form that saves notes to a database.
4. Read feature chapters only when you need them. Email, AI, Redis,
   external database servers, APIs, and production deployment can wait.

The function reference is a lookup tool, not a course to read from beginning
to end. If a method's technical details feel unfamiliar, return to its
feature chapter for a complete example.

### How to read the examples

- **Terminal commands** start with `php breeze` or another program name.
  Run them in PowerShell on Windows or a terminal on Linux, one line at a
  time. Wait for each command to finish; stop and fix any error before
  continuing. Do not paste these commands into a PHP file.
- **PHP and HTML examples** go in the file named immediately above the
  example. Save the file, then refresh your browser.
- **Configuration examples** go in `.env`, a hidden text file in the main
  project folder. Edit the existing setting; do not add a duplicate line.
  Never share this file because it can contain passwords and keys.
- **Project root** means the main PBreeze folder containing the `breeze`
  file. Run every `php breeze ...` command from there.
- **Example paths** such as `/absolute/path/to/pbreeze` are placeholders.
  Replace them with the folder on your own computer. Do not type a shell
  prompt such as `$` or `PS>` before a command.

### Words you will meet

| Term | Plain-language meaning |
|---|---|
| Framework | Reusable tools and structure so you do not have to build every website feature from scratch. |
| CLI | Command-line interface: commands you type in a terminal, such as `php breeze help`. |
| Route | A rule connecting a URL and request method to the PHP code that handles it. |
| GET / POST | GET requests read a page; POST requests submit data, such as a form. |
| Controller | A PHP class that handles a request, uses services, and returns a response. |
| View / template | A file that describes the HTML shown to the visitor. |
| MVC | Model, View, Controller: separate data work, page display, and request handling. |
| Migration | A PHP file describing a database structure change, such as creating a table. |
| Seeder | Code that adds starting or example data to a database. |
| SQLite | A database stored in a file; it does not need a separate database server. |
| Environment setting | A named value in `.env` that configures this installation without changing application code. |
| Local / production | Local runs on your computer for development; production is a deployed site used by real visitors. |
| CSRF token | A hidden form value checked by the server to help prevent another site from submitting actions using your login session. |
| Escaping | Displaying a value as text instead of allowing it to become executable HTML. Breeze's `{{ ... }}` does this for you. |

## Your First Page

**Goal:** display `Hello, Ada!` at `/hello`. This exercise needs no database
queries, login, or email. Finish the local installation first and confirm
that <http://127.0.0.1:8000> opens.

### 1. Create a template

If the development server is running, leave it running and open a **second
terminal** in the project root for commands. Alternatively, press Ctrl+C
to stop it and start it again when you are ready to test.

Run:

```sh
php breeze make:template hello
```

This creates `app/views/hello.breeze.php`. Open that file in your editor and
**replace its entire contents** with:

```html
<!doctype html>
<html lang="en-US">
<head><meta charset="utf-8"><title>My first PBreeze page</title></head>
<body>
    <h1>Hello, {{ $name }}!</h1>
    <p>I made this page with PBreeze.</p>
</body>
</html>
```

`$name` is a variable we will pass from the route. The double braces display
its value safely as text. Creating this file alone does not create a URL.

### 2. Connect a URL to the template

Open `routes/web.php`. **Add** this code at the bottom, keeping the existing
code. The file already begins with `<?php`; do not add another opening tag.

```php
$router->get('/hello', static function (\PBreeze\Request $request) use ($app): \PBreeze\Response {
    return new \PBreeze\Response($app->views->render('hello', [
        'name' => 'Ada',
    ]));
});
```

This rule handles a GET request to `/hello`. `$app` gives the handler access
to framework services. `render('hello', ...)` loads the template and passes
it a variable named `$name`. `Response` sends the resulting HTML to the
browser. The `$request` parameter represents the incoming request; this
simple page does not need to read anything from it.

### 3. Open and change your page

If the server is not running, run:

```sh
php breeze serve
```

Open <http://127.0.0.1:8000/hello>. You should see **Hello, Ada!** and the
sentence from your template. Use your chosen port instead if it is not 8000.

Change `'Ada'` to your own name in `routes/web.php`, save, and refresh.
The heading should change. No compilation command is needed.

If the page is not found, run `php breeze routes` in the second terminal
and check that `GET /hello` is listed. If it is not, check that you saved
the route in the same project copy that your server is running.
If you see a server error, inspect the server terminal and run
`php breeze logs:tail 20 error`. Check the example for missing brackets,
quotes, or semicolons; do not publish private logs.

You have connected a **route** to a **view** and passed it data. Next, use
**Build your first feature** in the [HTML manual](docs/index.html) to move
request handling into a controller and save form data.

## Comparison with Laravel and CodeIgniter

There is no best framework for every application. PBreeze prioritizes
explicit native PHP code without Composer; Laravel offers a broad integrated
framework and package ecosystem; CodeIgniter offers a modular MVC framework
with both manual and Composer installation options.

**Scope and review date:** November 13, 2026. This compares the PBreeze
source shipped with this manual, **Laravel 13.x**, and **CodeIgniter 4**
using the official documentation linked below. CodeIgniter's online guide
tracks its current release rather than a fixed patch version. Check the
requirements of the exact release and packages you install.
“Included” and “available through an additional package” are distinguished;
neither means that a production application needs no configuration.

### Setup and everyday development

| Area | PBreeze | Laravel 13.x | CodeIgniter 4 |
|---|---|---|---|
| PHP requirement | PHP 8.3+; extensions depend on the features used | PHP 8.3+ and documented required extensions | PHP 8.2+ in the current guide; PHP 8.5 requires CodeIgniter 4.7.0+ |
| Installation | Copy/clone the source, configure `.env`, and run the native CLI; no Composer | Standard installation uses Composer and the Laravel installer or project creation workflow | Composer installation or an officially documented manual ZIP installation |
| Class loading | Explicit `require_once` and registration; no autoloader | Composer-based autoloading and framework conventions | Built-in PSR-4 autoloader, with optional Composer integration |
| Frontend tooling | Local CSS/JS and server-rendered views; no Node build required | Documented frontend workflow uses Vite with Node/npm or Bun; a PHP-only API does not inherently need an asset build | PHP views do not inherently need a Node build; frontend tooling is the application's choice |
| CLI | `php breeze`: generators, migrations, seeds, diagnostics, mail tasks, and explicitly registered custom commands | `php artisan`: framework commands, generators, migrations, queue workers, and custom commands | `php spark`: framework commands, generators, migrations, and custom commands |
| Templates | Plain PHP or compiled Breeze templates, with escaped `{{ ... }}` output | Blade templates, with directives, layouts, and components | PHP views and an optional View Parser |
| Data access | PDO query builder plus optional models, casts, and explicit relationships; SQLite, MySQL, PostgreSQL, SQL Server | Query builder and Eloquent ORM, including relationships, scopes, events, and soft deletes | Query Builder, table-oriented Model classes, and entities; not the same relationship-oriented model API as Eloquent |

Composer is a PHP dependency manager; PSR-4 is a convention for finding
classes automatically. Avoiding them reduces PBreeze's installation
tooling, but means you load files yourself and cannot assume Composer
packages will work without adaptation. Conversely, autoloading and package
management can reduce repetitive wiring as an application grows.
CodeIgniter's manual installation does **not** disable its autoloader.
Breeze templates are a PBreeze feature, not Laravel's similarly named
authentication scaffolding.

### Application features and operations

| Area | PBreeze | Laravel 13.x | CodeIgniter 4 |
|---|---|---|---|
| Authentication | Included registration/login, verification, password reset, and database-backed bearer tokens; application-specific roles and record ownership remain your responsibility | Core authentication facilities; starter kits provide ready-made UI flows, with additional first-party packages for other authentication needs | Official Shield package provides authentication and authorization, including access tokens, roles, and permissions; it is separate from the core |
| Sessions | Native PHP storage or opt-in database sessions; Redis/Memcached cache support does not add session drivers | Includes file, database, cookie, Redis, Memcached, DynamoDB, and nonpersistent array drivers, subject to prerequisites | Includes file, database, Redis, and Memcached session handlers, subject to driver prerequisites |
| Shared cache | Native Redis or Memcached extensions; intentionally limited cache API | Multiple cache backends and a broader framework cache API | Built-in cache component with multiple handlers, including file, Redis, and Memcached |
| Background work | Database-backed outgoing-email queue and bounded CLI mail worker; no general-purpose job queue or built-in task scheduler | General-purpose job queues, workers, retries, job chaining/batching, and task scheduling | General-purpose Queue and Tasks scheduler are available as separate official packages |
| Extension model | Edit explicit PHP services and registries; third-party PHP packages are not part of the framework's dependency model | Composer packages, first-party integrations, and framework extension points | Framework libraries/modules, Composer integration, and optional official packages |
| Deployment scope | Supplied domain-root templates for Apache, Nginx, FrankenPHP standard mode, and IIS; not subdirectory hosting or persistent FrankenPHP workers | Public-directory deployments; official documentation includes Nginx and FrankenPHP, with additional deployment tooling available | Public-directory application structure; configure the chosen supported PHP/web-server environment |
| Release and maintenance planning | No release version or formal support window is currently declared; assess maintenance capacity before adopting | Published versioning and support policy: framework bug fixes for 18 months and security fixes for two years | Current requirements guide states that only the latest framework version is maintained; review upgrade notes and package compatibility |

The PBreeze deployment templates are not a certification of every server
configuration. Apache, Nginx, and FrankenPHP have been exercised in the
project's Linux integration work; live IIS validation was not performed in
that environment. Test your actual operating system, PHP build, database,
and server configuration before deployment.

### Which should you choose?

- **Consider PBreeze** when avoiding Composer and implicit class loading
  is an explicit requirement, or when you want to follow a request through
  directly loaded PHP files. Accept the narrower integration model and
  responsibility for assessing maintenance and production requirements.
- **Consider Laravel** when you need Eloquent's broader data model,
  general-purpose background jobs, first-party integrations, or a
  package-oriented workflow. Budget learning time for its conventions,
  service container, and the particular packages your application uses.
- **Consider CodeIgniter** when you want an MVC framework with a supported
  manual-install option and PSR-4 class loading, adding official packages
  such as Shield, Queue, or Tasks as needed.

These are fit recommendations, not measured learning-difficulty rankings.
A beginner may prefer explicit wiring, or may learn faster with conventions
and existing tutorials. Build the same small feature in each candidate
before committing: include validation, login, a database change, tests,
and deployment, rather than comparing only a “Hello world” page.

**No performance or security winner is claimed.** This project has not
published a reproducible cross-framework benchmark, independent comparative
security audit, or equivalent production load test. Fewer dependencies do
not automatically mean greater security; more features do not automatically
mean slower applications. All three still require supported runtimes,
updates, correct authorization, secure configuration, and operational care.
Package availability also does not establish community size, support quality,
or a service-level guarantee.

### Official sources and how to verify the comparison

PBreeze's entries describe this repository; follow the
[installation](#detailed-installation-guide),
[sessions](#sessions-and-csrf), [ORM](#native-orm-and-query-builder),
[mail](#email-smtp-imap-and-database-queue), and
[production boundaries](#production-and-security-boundaries) chapters
for implementation details and limitations.

- Laravel: [installation](https://laravel.com/docs/13.x/installation),
  [PHP requirements and deployment](https://laravel.com/docs/13.x/deployment),
  [Blade](https://laravel.com/docs/13.x/blade),
  [Eloquent](https://laravel.com/docs/13.x/eloquent),
  [authentication](https://laravel.com/docs/13.x/authentication),
  [sessions](https://laravel.com/docs/13.x/session),
  [cache](https://laravel.com/docs/13.x/cache),
  [queues](https://laravel.com/docs/13.x/queues),
  [scheduling](https://laravel.com/docs/13.x/scheduling), and
  [release/support policy](https://laravel.com/docs/13.x/releases).
- CodeIgniter: [requirements](https://codeigniter.com/user_guide/intro/requirements.html),
  [manual installation](https://codeigniter.com/user_guide/installation/installing_manual.html),
  [autoloading](https://codeigniter.com/user_guide/concepts/autoloader.html),
  [Spark commands](https://codeigniter.com/user_guide/cli/spark_commands.html),
  [models](https://codeigniter.com/user_guide/models/model.html),
  [View Parser](https://codeigniter.com/user_guide/outgoing/view_parser.html),
  [sessions](https://codeigniter.com/user_guide/libraries/sessions.html),
  [cache](https://codeigniter.com/user_guide/libraries/caching.html), and
  [official packages, including Shield, Queue, and Tasks](https://codeigniter.com/user_guide/libraries/official_packages.html).

External sources need an internet connection; the comparison itself remains
available in the offline manual. Recheck these sources when upgrading
instead of treating this dated comparison as a permanent feature matrix.

## License

PBreeze is licensed under the [MIT License](LICENSE).
Vendored Swagger UI and its bundled dependencies retain their own licenses
and notices under `public/assets/swagger-ui/`; the framework's MIT license
does not replace those third-party terms.

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

Applications may explicitly format user data in other locales with the
formatter described below; this does not translate framework-maintained copy.

## Getting Started

If this is your first framework, follow [Start Here](#start-here-your-first-steps)
and the [Detailed Installation Guide](#detailed-installation-guide) instead
of trying to configure every feature listed below.

Requirements: PHP 8.3+, PDO, `pdo_sqlite` for SQLite, `pdo_mysql` for MySQL,
`pdo_pgsql` with libpq for PostgreSQL, or `pdo_sqlsrv` plus Microsoft's ODBC Driver for SQL Server,
and the standard session, json, and filter extensions. The tests also use
native process and socket functions. The CLI uses `proc_open` for the
development server and tests; this function must be available for those tasks.
Encryption requires the native `openssl` extension with AES-256-GCM support.
Excel and Word creation require the native `zip` extension; PDF creation
requires `iconv`. The document tests also use the native `dom` extension.
Localized formatting requires the native `intl` extension and its ICU data.
Upload validation requires native `fileinfo`; DOCX/XLSX upload checks also
require native `zip`. PHP file uploads must be enabled.
AI requests require native `curl` with HTTPS support and a trusted CA store.
Optional shared caching requires native `redis` (PhpRedis 6+) or `memcached`
(3+) and a running server. No Composer packages are required.

```sh
php breeze init
php breeze storage:prepare
php breeze migrate
php breeze serve
```

This is the short version for developers who already have a compatible PHP
installation. `init` creates configuration, `storage:prepare` creates
private working folders, `migrate` prepares database tables, and `serve`
starts the local website. The detailed guide explains each step and key
setup. Seeding example data and running the test suite are optional checks,
not requirements for opening the home page.

Open <http://127.0.0.1:8000>. The example form demonstrates CSRF,
validation, and safe HTML output. The development server stays on localhost.
Use `php breeze serve 8080` for a different port.
The home/test page has a responsive light design with local CSS
in `public/assets/css/welcome.css`, a test form with error and success messages,
and expandable CLI examples. No external fonts or scripts are loaded.

`init` creates a local `.env`, but never overwrites an existing one.
The default is SQLite in `database/app.sqlite`; the database is created by
the first database command. The form does not need a database.

## Detailed Installation Guide

### 1. Choose your installation

**Your first visit:** complete steps 1, 2 **or** 3, and 4 through 7.
Step 8 covers optional features; step 9 is for deploying a live site later.
If PHP is already installed, check its version and extensions before
skipping to step 4.

For the simplest local setup, use **PHP 8.3 or later with SQLite** and the
built-in development server. You do not need Apache, Nginx, IIS, Redis,
Composer, npm, or an external database server to try PBreeze.

For production, choose Apache with FastCGI/PHP-FPM, Nginx with PHP-FPM,
FrankenPHP standard mode, or IIS FastCGI. Follow the
[Web Server Deployment](#web-server-deployment) chapter after completing the
common setup below. Subdirectory hosting and persistent FrankenPHP workers
are outside the supplied deployment scope.

Use a new empty directory for a fresh installation. These instructions are
not an upgrade procedure: do not overwrite an existing application's `.env`,
encryption key, database, uploads, or private storage.

### 2. Install PHP on Linux

On a Debian/Ubuntu installation whose configured repositories provide PHP
8.3 or later, the following installs the usual local-development requirements:

```sh
sudo apt-get update
sudo apt-get install php-cli php-sqlite3 php-intl php-mbstring php-xml php-zip php-curl
php -v
php --ini
php -m
```

Confirm the reported PHP version is **at least 8.3**. Distribution defaults
vary: if your repositories provide an older release, use a supported
distribution/repository that provides a compatible PHP version. Do not mix
extension packages from different PHP release series.

Some PHP builds bundle OpenSSL, iconv, JSON, session, filter, and fileinfo;
others package them separately. Verify actual modules rather than assuming
the package command is sufficient. On other Linux distributions, install
equivalent packages through that distribution's supported package manager.
For FPM deployment, additionally install the matching `php-fpm` package
(often versioned, such as `php8.3-fpm`) and configure it with your server.
The development server needs only CLI PHP.

Check the extensions used by the bundled features and native tests:

```sh
php -r 'foreach (["PDO","pdo_sqlite","openssl","session","json","filter","intl","mbstring","fileinfo","curl","iconv","zip","dom"] as $e) { echo $e, ": ", extension_loaded($e) ? "OK" : "MISSING", PHP_EOL; }'
```

Install/enable any missing extension for the PHP binary shown by `php -v`.
The native test runner also needs `proc_open` and local socket functions.
If a hosting policy disables them, run the tests on an appropriate
development/CI machine; do not blindly weaken a production hosting policy.

### 3. Install PHP on Windows

1. Download a supported PHP 8.3+ Windows ZIP build from
   <https://windows.php.net/download/>. Use the architecture matching your
   system and server. An **NTS** build is appropriate for CLI development and
   IIS/FastCGI; Apache module installations require the matching TS/module
   combination instead. Follow the PHP build's Visual C++ runtime requirements.
2. Extract PHP to a directory such as `C:\PHP`. Copy
   `php.ini-development` to `php.ini` for local work; use a reviewed
   production configuration for deployed services.
3. Set `extension_dir = "C:/PHP/ext"` in that INI file. Enable the available
   extension lines below by removing their leading semicolon:

```ini
extension=pdo_sqlite
extension=sqlite3
extension=openssl
extension=intl
extension=mbstring
extension=fileinfo
extension=curl
extension=zip
```

DOM, iconv, JSON, session, filter, and PDO may already be compiled into your
build. Verify with `php -m`; do not add DLL lines for extensions already built
in or install DLLs from an incompatible PHP version, architecture, or
thread-safety build.

4. Add `C:\PHP` to your user/system `PATH` using Windows Environment Variables,
   then open a **new** PowerShell or Command Prompt window.
5. In PowerShell, verify the executable and loaded configuration:

```powershell
Get-Command php
php -v
php --ini
php -m
```

If PHP is not on `PATH`, run `& "C:\PHP\php.exe" -v` and use the same explicit
executable for the remaining commands. Resolve startup warnings such as
“Unable to load dynamic library” before proceeding. No WSL, Bash, or
executable permission on the `breeze` file is required.

### 4. Obtain the framework files

Download and extract the project's source archive, or clone the actual
repository URL supplied by your project maintainer. PBreeze does not require
`composer install`, autoload generation, or an npm build.

Use a private working directory such as `~/projects/pbreeze` on Linux or
`C:\Projects\PBreeze` on Windows. Do not extract the project directly into a
public web root. The full source must include `breeze`, `bootstrap.php`,
`.env.example`, `framework/`, `app/`, `config/`, `routes/`, `database/`, and
`public/`; enable “show hidden files” when checking archive contents.

Open a terminal **inside the project directory**:

```sh
cd /absolute/path/to/pbreeze
php breeze help
```

PowerShell equivalent:

```powershell
Set-Location "C:\Projects\PBreeze"
php breeze help
```

If your source directory has spaces, quote the path. Do not point commands
at another installed copy accidentally. Existing process environment
variables override `.env`; unexpected settings may come from your shell
or service definition.

### 5. Initialize local configuration

```sh
php breeze init
```

This copies `.env.example` only when `.env` does not already exist. Open the
new `.env` privately in your editor. For the SQLite/local-development path,
verify these values:

```ini
APP_ENV=local
APP_DEBUG=false
APP_URL=http://127.0.0.1:8000
SESSION_SECURE_COOKIE=false
DB_CONNECTION=sqlite
DB_DATABASE=database/app.sqlite
STORAGE_PATH=storage
CACHE_DRIVER=none
```

Leave cache, AI, SMTP, and IMAP credentials empty until you intentionally
configure those services. The environment file supports `NAME=value`,
optional matching quotes, and full-line comments; it does not expand shell
variables or support inline comments. Keep it out of version control and
never paste its contents into tickets or public logs.

Generate an encryption key:

```sh
php breeze key:generate
```

Copy the printed `APP_KEY=base64:...` assignment into your private `.env`.
The command **prints only** and does not update the file. Do not publish the
output. Keep a separate protected backup; replacing the key later does not
re-encrypt existing records and can make encrypted data unrecoverable.
On an existing application, preserve its original key.

### 6. Prepare storage and the database

For a fresh local SQLite installation:

```sh
php breeze storage:prepare
php breeze migrate
php breeze migrate:status
php breeze db:seed
```

Run one command at a time. The last command, `db:seed`, is optional.
If a command reports an error, resolve it before moving on.

`storage:prepare` creates private logs, uploads, and compiled-view
directories. `migrate` creates/applies the bundled schema in the configured
SQLite file. `migrate:status` should show applied batch numbers rather than
`OPEN`. The optional default seeder adds a welcome note; it does **not**
create an administrator or a preconfigured login.

Run these commands as the identity that should own local application data.
On Linux, newly created private files may be readable only by their owner.
On Windows, configure NTFS ACLs explicitly; `chmod` is not equivalent.
Do not solve permission errors with `chmod 777`, administrator-owned
application data, or write access to the entire deployed source tree.

For another database, configure it **before migrating**:

| Database | Install/prepare | Required environment changes |
|---|---|---|
| SQLite | Native `pdo_sqlite`; writable local database directory | `DB_CONNECTION=sqlite`, file path in `DB_DATABASE` |
| MySQL | Native `pdo_mysql`; create an empty database and dedicated account | `DB_CONNECTION=mysql`, host/port/database/username/password, `DB_MYSQL_TLS=required`, readable `DB_MYSQL_SSL_CA` |
| PostgreSQL | Native `pdo_pgsql` and libpq; create a database and dedicated account | `DB_CONNECTION=pgsql`, `DB_PORT=5432`, connection credentials, `DB_SSLMODE=verify-full`, CA configuration as required |
| SQL Server | Native `pdo_sqlsrv` plus Microsoft's compatible ODBC Driver; create database/login | `DB_CONNECTION=sqlsrv`, `DB_PORT=1433`, connection credentials, `DB_ENCRYPT=true`, `DB_TRUST_SERVER_CERTIFICATE=false` |

Server database names are existing databases, not filesystem paths; PBreeze
does not provision the database server or create its database/login. Use an
account with the schema permissions needed for migrations, then apply your
deployment's least-privilege runtime policy. Keep driver, port, and TLS
settings consistent. See the PostgreSQL, SQL Server, and production chapters
for their detailed requirements.

MySQL's default is certificate-verified TLS, **not** plaintext. Configure a
CA bundle and certificate matching `DB_HOST`. An explicit
`DB_MYSQL_TLS=disabled` opt-out exists only for isolated
`localhost`/`127.0.0.1` connections outside production. Do not disable
certificate verification to work around an installation error.

### 7. Check the installation and start the application

```sh
php breeze about
php breeze env:check
php breeze test
php breeze serve
```

`about` reports CLI runtime/driver information without credentials.
`env:check` inventories environment key names without printing values;
empty unused integration settings are expected. The native tests use
isolated fixtures, not your configured application database, and send no
external email or AI requests. Optional live database/cache/server integration
tests are separate and have their own prerequisites.

`serve` is different from the other commands: it keeps running and uses
that terminal until you stop it. This is normal, not a frozen command.
Keep that terminal open and use a second terminal for other commands.
The test command is optional for your first visit; when you do run it,
check that the final summary reports no failures.

Open <http://127.0.0.1:8000>. Confirm that the home page and its CSS load.
Check <http://127.0.0.1:8000/health> for liveness and
<http://127.0.0.1:8000/ready> for database/schema/private-storage readiness.
Both should return HTTP 200 after successful setup; `/ready` returns 503
when its dependencies are not ready.

Stop the development server with **Ctrl+C**. If port 8000 is occupied, use
`php breeze serve 8080` and update `APP_URL` to
`http://127.0.0.1:8080` before testing links generated by the application.
Never use `php breeze serve` as the production web server.

### 8. Enable optional integrations deliberately

- **Authentication emails:** configure a real `MAIL_FROM` and encrypted SMTP
  host/port/login. Registration verification and password recovery require
  delivery; a page loading successfully does not prove mail works.
  Schedule `php breeze mail:work 50` using cron or Windows Task Scheduler.
  Test delivery only to an authorized address; IMAP is optional.
- **Redis/Memcached:** install the selected native extension in CLI and web
  PHP, configure a protected server and a unique `CACHE_PREFIX`, then run
  `php breeze cache:test`. This deliberately writes/reads/deletes one
  temporary key. Keep `CACHE_DRIVER=none` when no cache is configured.
- **OpenAI/Claude:** configure the selected provider's API key and exact
  model ID, plus native cURL/system trust. API usage can incur charges;
  framework tests do not make real provider requests.
- **Document generation/uploads:** verify ZIP, DOM, iconv, fileinfo, PHP
  upload limits, and writable private storage. Application upload limits
  cannot raise PHP or web-server limits.
- **Structured file reading:** JSON and CSV need no extra extension. XML
  requires DOM; YAML/YML requires the native PHP YAML extension with
  `yaml.decode_php=0`. See [Structured File Reading](#structured-file-reading).

Refer to each integration's chapter before enabling it in production.

### 9. Move to a production web server

1. Back up the database, private data, and encryption key. Configure a real
   HTTPS origin, production credentials, and stable private storage outside
   `public/`; keep its real path free of symlink ancestors.
2. Set `APP_ENV=production`, `APP_DEBUG=false`, and the HTTPS `APP_URL`.
   Set secure cookies and configure actual HTTPS at the server/trusted edge.
   Restrict public API docs if not intended for public access.
3. Follow the Apache, Nginx, FrankenPHP, or IIS instructions in
   [Web Server Deployment](#web-server-deployment). Expose **only `public/`**.
   Configure the actual web PHP INI/extensions, service identity, sessions,
   upload temporary directory, timeouts, certificates, and permissions.
4. Under the deployment identity, run `php breeze storage:prepare` and
   review `php breeze migrate:status`. After a verified backup and schema
   review, production migration execution requires
   `php breeze migrate --force`. Do not automatically run development
   seeders against production.
5. Run `php breeze doctor`. Resolve failures and review every warning.
   A local installation normally fails production-only checks; that is not
   evidence that local development is broken.
6. Smoke-test real HTTPS routes, assets, sessions/login, authorized API
   requests, uploads, mail delivery, `/health`, and `/ready`. Test backups,
   restores, monitoring, scheduled mail, and expected load before release.

CLI checks do not certify another PHP SAPI or a complete deployment.
The production and server chapters explain remaining operational boundaries.

### Installation troubleshooting

| Symptom | Check and recovery |
|---|---|
| `php` is not recognized / command not found | Install PHP, correct `PATH`, open a new terminal, or use the full executable path. Check which PHP binary is running. |
| Could not open input file: `breeze` | Change to the project root containing the `breeze` file, or supply its absolute path. |
| PHP startup/DLL warning | Use extension binaries/packages matching the PHP version, architecture, and TS/NTS mode; verify `extension_dir`, dependent runtimes, and `php --ini`. |
| Missing Intl/mbstring/fileinfo/PDO driver | Enable/install the extension for the exact CLI/web runtime reporting the error, then restart the relevant service. |
| `APP_ENV must be ...` / invalid typed setting | Correct the named setting in private configuration; inspect process/service overrides. Commands require a successful bootstrap. |
| Encryption fails or key is invalid | Generate a valid key for a new installation; restore the original key for existing encrypted data rather than replacing it. |
| SQLite cannot open the database / permission denied | Check the configured absolute/root-relative path and service identity; grant only required directory/file access and keep SQLite on local storage. |
| MySQL TLS/CA failure | Supply a readable trusted CA and a certificate matching `DB_HOST`; verify server TLS. Plaintext opt-out is local-development-only. |
| `/ready` returns 503 | Prepare private storage, check database connectivity/TLS, and apply reviewed pending migrations; inspect private application/PHP logs. |
| Home page loads but other routes return server 404 | Correct the public document root and install/enable the server's supplied rewrite/FastCGI configuration. |
| PHP source appears as text/download | Stop public access immediately and configure the PHP handler correctly. Never expose source files as static downloads. |
| Cookies/login fail or forms return 419 | Use a consistent hostname/scheme, writable session storage, and matching secure-cookie settings. Submit the current CSRF token from the same session. |
| Verification/reset emails stay queued | Configure real encrypted SMTP, a valid sender, and scheduled `mail:work`; check delivery diagnostics without exposing message contents. |
| Local `doctor` returns exit 1 | It evaluates production deployment. Review each result; do not enable production settings blindly just to make a local check green. |

## Windows and Linux

Use the same commands on both systems with `php breeze ...`.
On Windows, this works from PowerShell or cmd, without WSL, Bash, or an
executable shebang. Make sure PHP CLI is in `PATH`; check with
`php -v` and use `php --ini` to find the configuration file in use.
PHP for your web server may use a different configuration file.

On Windows, enable the required extensions in `php.ini`: `pdo_sqlite` for SQLite,
`pdo_mysql` for MySQL, `pdo_pgsql` for PostgreSQL, or `pdo_sqlsrv` for SQL Server,
and also `openssl` and `iconv` for email.
Enable `zip` for Excel/Word creation and `dom` for the document tests.
Enable `intl` for localized date/time and number formatting.
Enable `fileinfo` for upload validation.
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
is not a substitute for a database server. Use MySQL, PostgreSQL, or SQL Server for shared databases.

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
Deployment templates and integration tests cover Apache, Nginx, FrankenPHP
standard request mode, and IIS FastCGI. Always set the document root to
`public/`; see [Web Server Deployment](#web-server-deployment) for exact
requirements, configuration, and validation boundaries.

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

## CLI Utilities and Custom Commands

```sh
php breeze about
php breeze env:check
php breeze cache:test
php breeze logs:tail
php breeze logs:tail 100 warning
php breeze make:command Report
```

### Runtime and environment diagnostics

`about` prints safe runtime metadata: PHP version, OS family, SAPI,
environment, configured database/cache driver names, session handler, and
loaded extensions. The framework has no declared release version yet, so
the command says so instead of inventing one. It does not print credentials,
application keys, connection strings, or filesystem paths, and does not
connect to services or create storage. CLI information does not certify the
web server's PHP extensions or configuration.

`env:check` compares your local `.env` key names against `.env.example`,
honoring process environment overrides without changing files or loaded
configuration. Output contains **names and statuses only**, never values:

| Status | Meaning |
|---|---|
| `SET` | An effective value is present and nonempty |
| `EMPTY` | Explicitly empty; may be intentional for unused integrations |
| `MISSING` | An active example setting has no file/process value |
| `OPTIONAL` | A commented example setting has no override |
| `CUSTOM` | A local file key is not listed in the example |

Exit code is 1 if active example keys are missing, otherwise 0. Missing keys
can still have framework defaults; this is a configuration inventory check,
not a production certification. Empty, optional, and custom entries need
human review, not automatic replacement. Duplicate/malformed local entries
fail explicitly without printing their contents. All CLI commands still
require bootstrap to succeed: invalid typed configuration can prevent this
command from starting. Use `doctor` for production settings/readiness.

### Testing cache connectivity

`cache:test` checks the **selected configured backend** using one
cryptographically random key in its existing namespace. It writes with a
30-second TTL, verifies the round trip, and deletes only its probe key.
It never flushes the server or changes other application keys.
Disabled/missing/unreachable backends fail explicitly; there is no fallback.
Cleanup is attempted after a successful write; interrupted or uncertain
writes expire automatically. Success output contains no key, value, host,
or credentials. Native backend failures use the cache service's redacted
errors and normal CLI error logging.

### Reading recent application logs

`logs:tail [lines] [level]` is a one-shot snapshot, **not** a continuous
watcher. It prints the most recent matching entries in chronological order,
across the configured storage directory's daily JSONL files. The line count
is 1..1000, default 50. The optional second argument is the **minimum**
severity: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`,
or `emergency`; default `debug` includes every level.

Reading does not create storage, write log files, or trigger retention.
Missing logs produce a clear informational message. Symlinked paths, corrupt
JSON/levels, entries above 64 KiB, and scans exceeding 8 MiB fail explicitly;
request fewer lines or a less restrictive filter for large logs.
JSON output escapes terminal control characters. Existing log messages
and context may still contain private application data: logger key-based
redaction does not sanitize arbitrary message text. Restrict terminal/log
access and never paste confidential output into public systems.

### Creating application-specific commands

`make:command Report` creates `app/commands/Report.php` containing a Closure.
The generated command deliberately throws until implemented, preventing
an empty success-shaped scaffold. Existing files are never overwritten.
Implement it, then register it **explicitly** in `routes/console.php`:

```php
<?php
declare(strict_types=1);

return [
    'app:report' => require $app->root . '/app/commands/Report.php',
];
```

For example, the command file can return:

```php
<?php
declare(strict_types=1);

use PBreeze\Application;

return static function (Application $app, array $arguments): int {
    if ($arguments !== []) {
        throw new InvalidArgumentException('Usage: php breeze app:report');
    }
    echo "Report completed.\n";
    return 0;
};
```

Run `php breeze app:report`. Help lists registered custom names. The registry
is optional for older projects; create it with the structure above.
There is no Composer, autoloading, or command-directory scanning.
Names must match lowercase letter-led segments containing letters, digits,
or hyphens, optionally separated by colons. Builtin names cannot be
overridden; every entry must be a Closure.

Handlers receive the Application and **unchanged remaining CLI arguments**,
including any options. They must validate their arguments themselves and
return an integer exit code from 0 through 255. Exceptions use the existing
CLI error path. Custom commands must implement their own production guards,
authorization, confirmation, and secret handling; builtin `--force` safeguards
do not automatically apply to arbitrary custom code. The five utility
commands above do not accept `--force` or other options.

## Web Server Deployment

PBreeze uses ordinary PHP requests, `REQUEST_URI`, standard HTTP headers,
and explicit bootstrap files; it does not depend on Apache-specific APIs.
The deployment scope is a **domain root**, not a subdirectory/virtual directory.
FrankenPHP is configured in **standard request mode**, not persistent worker
mode. Do not enable workers without implementing and testing a separate
request-state lifecycle.

| Server | Supplied configuration | PHP integration |
|---|---|---|
| Apache 2.4+ | `deploy/apache.conf` and `public/.htaccess` | PHP-FPM/FastCGI example; an administrator can use an equivalent mod_php setup |
| Nginx | `deploy/nginx.conf` | PHP-FPM/FastCGI, only `/index.php` |
| FrankenPHP | `deploy/Caddyfile` | Embedded PHP in standard request mode |
| IIS 10+ on Windows | `public/web.config` | PHP NTS through FastCGI, with Microsoft URL Rewrite 2.x |

These are supported deployment paths, not a promise that every upstream
server version, module combination, OS build, proxy, or hosting policy will
work without configuration. The framework remains Windows/Linux portable;
server availability and production suitability follow each upstream project.
In particular, IIS is Windows-only, and FrankenPHP builds/extensions must
support your selected platform; do not assume native Windows FrankenPHP or
production-grade Windows Nginx support from framework portability alone.

### Shared deployment contract

1. The document root/physical site path must be **only `public/`**. Never
   expose the project root, even if a rewrite rule appears to hide it.
2. `index.php` is the only public PHP entry point. The templates block direct
   access to other PHP/PHAR/PHTML scripts, including the development router.
   Existing directories must not bypass routing or expose directory listings.
3. Only existing CSS, JS, PNG/JPEG/GIF/WebP/ICO/SVG, and WOFF/WOFF2 files are
   served directly. Other paths go to the framework; hidden paths and common
   secret/database/log/config/backup extensions are denied. This intentionally
   tightens the previous Apache behavior that served any existing public file.
   If your application needs another static type, update and test **all**
   deployed server allowlists and the development router deliberately.
4. Preserve the original URI/query string, method, raw request body,
   `Authorization` header, status code, response body, and `Allow` header.
   Do not replace framework JSON 4xx/5xx errors with HTML error pages.
5. Install the same required PHP extensions in the **web runtime** as in CLI.
   FrankenPHP has its own embedded PHP/extensions/INI configuration; a
   successful `php -m` for CLI does not prove its extensions are available.
6. Configure writable private storage, persistent SQLite/database access,
   PHP session storage, and upload temporary storage for the actual service
   identity. Keep application source read-only. Run `storage:prepare` and
   migrations with a compatible deployment identity; run `php breeze doctor`.
7. Terminate HTTPS at the server or a trusted edge. Set `APP_ENV=production`,
   `APP_DEBUG=false`, and the real HTTPS `APP_URL`. Set
   `SESSION_SECURE_COOKIE=true` behind TLS termination; the flag does not
   encrypt HTTP or validate proxy identity. Never trust arbitrary forwarded
   headers. Restore client IPs only using an allowlisted proxy configuration.

The templates permit an 8 MiB server request body. Configure PHP
`post_max_size=8M`, `upload_max_filesize=3M` (or values appropriate to your
application), a writable `upload_tmp_dir`, and persistent `session.save_path`.
The framework still enforces its independent 1 MiB JSON/API request limit and
default 2 MiB per-file upload limit. Native server/PHP limits can reject a
request before PHP runs; those responses may be server-generated, not JSON.
Coordinate execution/FastCGI/proxy timeouts with long AI operations, and
disable response buffering where your application requires streaming.
Server-side URLs with encoded slashes, double escaping, or invalid characters
may be rejected earlier by different servers; do not loosen these protections
to make malformed requests behave identically.

### Apache

Start from [deploy/apache.conf](deploy/apache.conf). Replace the hostname,
`/srv/pbreeze/public`, and PHP-FPM socket. Enable `mod_rewrite`, `mod_proxy`,
and `mod_proxy_fcgi`, with the usual authorization, directory, and MIME
modules. Permit the included `.htaccess` overrides for this trusted
application directory, or move those rules into the equivalent directory
configuration if your host disables overrides. Apache must be 2.4+ because
the rewrite rules use `END`.

The template's `CGIPassAuth On` plus rewrite authorization mapping preserves
bearer tokens through FastCGI/internal rewrites. The PHP handler is scoped to
`index.php`; do not add a generic executable-files directory.
For Windows Apache/FastCGI, use forward-slash paths such as
`C:/Sites/PBreeze/public` and replace the handler endpoint with, for example,
`proxy:fcgi://127.0.0.1:9000`. Install and supervise a compatible FastCGI
process manager; PHP-FPM itself is not a native Windows service.
For mod_php, have your administrator replace the FPM handler with the
installed module's PHP handler and appropriate MPM/thread-safety combination;
keep the rewrite and access restrictions.

Validate with `apachectl configtest` (or `httpd.exe -t`), then reload through
your service manager. Add actual TLS certificates/bindings and HTTP-to-HTTPS
redirection; the supplied port-80 virtual host alone is not a production TLS
configuration.

### Nginx

Include [deploy/nginx.conf](deploy/nginx.conf) inside the `http` block.
Replace the hostname, public path, and FPM socket; for a separately managed
FastCGI service, a TCP endpoint such as `127.0.0.1:9000` can be used instead.
Include your distribution's `fastcgi_params`, which must preserve
`QUERY_STRING`, `REQUEST_METHOD`, `CONTENT_TYPE`, `CONTENT_LENGTH`,
`REQUEST_URI`, `REMOTE_ADDR`, and `HTTPS` where applicable.

The template explicitly sets `SCRIPT_FILENAME` to the front controller and
forwards `HTTP_AUTHORIZATION`. It does not use `$uri/` directory fallbacks
that bypass the router. Do not add a second broad PHP execution location.
The location explicitly sets `fastcgi_intercept_errors off`; settings
that replace application errors break API error behavior.
Run `nginx -t`, configure TLS/listen directives and certificates, and reload
through your service manager. Restrict any TCP FastCGI listener to trusted
local/server networks.

### FrankenPHP

Use the [Caddyfile](deploy/Caddyfile) with a FrankenPHP build containing PHP
8.3+ and all required extensions. It is not a configuration for plain Caddy:
the `frankenphp`/`php` directives require FrankenPHP.

For local HTTP validation on Linux:

```sh
PBREEZE_PUBLIC_ROOT=/absolute/path/to/pbreeze/public \
PBREEZE_SERVER_NAME=http://127.0.0.1:8080 \
frankenphp run --config deploy/Caddyfile
```

In production, set `PBREEZE_PUBLIC_ROOT` to the real public directory and
`PBREEZE_SERVER_NAME` to your public domain, for example `app.example.com`.
Caddy automatic HTTPS requires working DNS, reachable ports, permissions,
and persistent certificate/configuration storage. Configure service
environment variables rather than relying on an interactive shell.
These two deployment variables are read by Caddy **before PHP starts**;
putting them only in PBreeze's `.env` does not configure Caddy.

Run `frankenphp validate --config deploy/Caddyfile` under the same service
environment, then run/supervise it normally. The configuration preserves
the original URI while internally executing only the front controller.
It intentionally does not configure workers, an FPM backend, or directory
indexes. Configure the Caddy administration endpoint appropriately and
persist its HTTPS data when using containers.

### IIS

Install IIS 10+ with the CGI/FastCGI feature, Microsoft
[URL Rewrite 2.x](https://www.iis.net/downloads/microsoft/url-rewrite), and a
PHP 8.3+ **NTS** build matching the server architecture. Point the site's
physical path to `C:\Sites\PBreeze\public`, not the project root.
The supplied [web.config](public/web.config) provides rewrite rules, hidden
file protection, static-file routing, disabled directory browsing, a default
document, the request-body cap, and `httpErrors existingResponse=PassThrough`.
IIS does not read `.htaccess`.

In IIS Manager (administrator):

1. Create a dedicated application pool with **No Managed Code** and a
   compatible bitness, using a least-privilege application-pool identity.
2. Add a FastCGI application pointing to the actual `php-cgi.exe`. Set
   `PHPRC` to your PHP configuration directory and align
   `PHP_FCGI_MAX_REQUESTS` with the FastCGI `instanceMaxRequests` setting.
3. Add a handler mapping for `index.php`, verbs `*`, module `FastCgiModule`,
   script processor `C:\PHP\php-cgi.exe`, and file resource type. A generic
   `*.php` mapping is unnecessary; remove conflicting inherited handlers.
   Place the PHP mapping **before** the catch-all StaticFile handler.
4. Enable Anonymous Authentication using the application-pool identity
   (empty anonymous username), so framework sessions/bearer tokens handle
   application authentication. Disable IIS Windows/Basic authentication
   unless deliberately integrating it separately.
5. Remove/disable WebDAV handlers/modules for this site if installed:
   otherwise they can intercept PUT/DELETE before the framework sees them.
   Permit required verbs in IIS Request Filtering.
6. Grant the pool identity read/execute access to source and PHP binaries,
   and Modify access only to private storage, SQLite's directory/file when
   used, session storage, and upload temporary storage. Restrict NTFS ACLs;
   Unix-style `chmod` is not a Windows access-control substitute.
7. Configure HTTPS bindings/certificates, request/activity timeouts, and PHP
   error logging. Do not expose detailed PHP or IIS errors publicly.

The PHP executable path, machine-level FastCGI registration, and credentials
are intentionally not hardcoded in `web.config`. If IIS reports 500.19,
install URL Rewrite and check delegation/locking for the referenced sections
with your administrator; do not remove protections just to suppress it.
If APIs return server HTML instead of JSON, inspect inherited `httpErrors`,
authentication, WebDAV, and request-filtering settings.

### Real-server compatibility tests

The common smoke suite exercises actual routing (including UTF-8 paths and
query strings), assets/MIME types, private-file denial, single-script
execution, bearer-token authentication, POST/PUT/PATCH/DELETE JSON,
request limits, cookies/CSRF, multipart uploads, readiness/HEAD probes,
redirects, and application errors. It creates a new disposable application
and database; it never uses the developer's `.env`, storage, or database.

On Linux with the matching servers/PHP-FPM installed:

```sh
php tests/web-server-integration.php apache
php tests/web-server-integration.php nginx
php tests/web-server-integration.php frankenphp
```

Optional executable overrides are `APACHE_BINARY`, `APACHE_MODULE_DIR`,
`NGINX_BINARY`, `NGINX_FASTCGI_PARAMS`, `NGINX_MIME_TYPES`, `PHP_FPM_BINARY`,
and `FRANKENPHP_BINARY`. Apache's Linux harness assumes `/etc/mime.types`;
Nginx defaults to distribution files under `/etc/nginx`. The harness uses
loopback/ephemeral HTTP ports, starts its own child processes without changing
running services, and cleans up its own fixtures. Test launcher requirements
include native PHP `posix`, process, and socket functions on Linux.

On a **disposable Windows IIS host**, run as administrator in Windows
PowerShell with IIS CGI, URL Rewrite, and PHP installed:

```powershell
.\tests\web-server-iis.ps1
```

The IIS launcher creates a GUID-named loopback site/application pool, a
distinct FastCGI registration, and a disposable database, then removes only
its own resources. Do not use a production IIS machine as a test environment.

CI defines Apache/Nginx/FPM jobs on Linux and IIS jobs on Windows for PHP
8.3/8.5, plus a pinned, checksum-verified FrankenPHP integration job using
its embedded PHP runtime. The native suite also validates the IIS XML and
request/authorization mapping. A workflow definition is not proof of a
successful run: require passing CI and real deployment smoke tests before
claiming compatibility for your specific infrastructure. Linux testing
cannot certify IIS or Windows-specific extension behavior.

## Redis and Memcached Cache

The shared `$app->cache` service supports Redis and Memcached using native PHP
extensions, without Composer. It is optional and disabled by default.
It does **not** replace PHP sessions, database-backed rate limits, the mail
queue, or the compiled-template cache.

Enable the matching extension in both CLI and web PHP. For example, on Linux
install your PHP distribution's `redis` or `memcached` extension package. On
Windows, use a compatible extension build matching your PHP version,
architecture, and thread-safety mode; enable it in the appropriate `php.ini`.
The modern **memcached** extension is used, not the legacy **memcache** extension.
Verify with `php --ri redis` or `php --ri memcached`. A separate Redis or
Memcached server is required; it may run on another private host.

### Cache configuration

Choose one backend in your private `.env`:

```ini
CACHE_DRIVER=redis
CACHE_PREFIX=myapp-production:
CACHE_TIMEOUT=2
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DATABASE=0
REDIS_TLS=false
```

For Memcached, use `CACHE_DRIVER=memcached`, `MEMCACHED_HOST=127.0.0.1`, and
`MEMCACHED_PORT=11211`. Set `CACHE_DRIVER=none` to disable caching explicitly.
Disabled or unavailable caching throws when used: it never pretends that a
failed operation is a miss or silently switches to local memory.
Construction and normal bootstrap do not connect to either server.
There is no application-level automatic retry.

Use a unique `CACHE_PREFIX` per application and environment (1..64 ASCII
letters, digits, `:`, `_`, or `-`). Keys are stored as the prefix plus a SHA-256
hash, keeping backend keys bounded and separating namespaces. Prefixes and
hashes are not authorization or encryption. Never share cache write access
with untrusted applications.

Redis supports password-only authentication with `REDIS_PASSWORD`, or ACL
authentication with both `REDIS_USERNAME` and `REDIS_PASSWORD`. Set
`REDIS_TLS=true` for verified TLS; `REDIS_CA_FILE` optionally selects a trusted
CA bundle, otherwise system trust applies. The certificate must match
`REDIS_HOST`; certificate and hostname verification cannot be disabled.
Redis ACLs must permit GET, SET, SETEX, DEL, SELECT, EVAL, INCRBY, and EXPIRE.
This is a single-server adapter, not a Redis Cluster/Sentinel client.

Memcached supports optional SASL through `MEMCACHED_USERNAME` and
`MEMCACHED_PASSWORD` (both required together), with a SASL-capable extension
and server. It uses binary protocol. SASL is authentication, not encryption:
this adapter does not provide Memcached TLS. Use a protected private network
or a separately secured tunnel; never expose Memcached publicly.
Plaintext Redis is likewise appropriate only on a trusted network.
Do not put credentials in controllers, logs, or version control.

### Cache values, expiration, and remembered results

```php
$app->cache->set('catalog:featured', ['id' => 42, 'name' => 'Notebook'], 600);
$featured = $app->cache->get('catalog:featured', []); // Default only on a miss.
$removed = $app->cache->delete('catalog:featured');  // false if already absent.

$app->cache->set('feature:enabled', false, 60);
$enabled = $app->cache->get('feature:enabled', true); // false, not the default.
$app->cache->set('optional:value', null, 60);        // null is cacheable too.

$notes = $app->cache->remember('notes:recent', function () use ($app): array {
    return $app->db->table('notes')->orderBy('id', 'desc')->limit(10)->get();
}, 120);
```

`set()`, `remember()`, and `increment()` default to 300 seconds. TTL is a
relative integer number of seconds, 0..31536000 (one year); `0` means no
expiration, **not** deletion. Values may still be evicted by the server.
Memcached's special absolute-timestamp behavior above 30 days is normalized
automatically. `get()` defaults to `null` on a genuine missing/expired key.
`delete()` returns whether an entry was removed and never flushes the server.
There is intentionally no whole-server clear operation.

Values support null, booleans, integers, finite floats, valid UTF-8 strings,
and nested arrays. PHP objects (including models and dates), resources,
recursive arrays, and invalid UTF-8 are rejected. Convert models to arrays
before caching them. JSON encoding is limited to 512 KiB per value and 60
nested array levels; no PHP object serialization is written by this service.
Only use trusted cache servers; data retrieved from a cache is not an
authorization decision and must still be escaped for HTML output.

`remember()` runs the callback only on a miss, including when a previously
cached value is `null` or `false`. Callback exceptions propagate unchanged
and are not cached. Concurrent misses may run the producer more than once:
this helper is not a distributed lock or stampede-prevention mechanism.
Backend/extension/protocol failures throw `RuntimeException`, rather than
returning a default or calling the producer. Invalid API inputs throw
`InvalidArgumentException`. Native errors are redacted; uncaught exceptions
use the framework's existing web/CLI error handling and logging.

### Atomic cache counters

```php
$views = $app->cache->increment('article:42:views', 1, 3600);
$views = $app->cache->increment('article:42:views', 5); // Add five.
```

A missing counter starts at the positive `amount` (default 1). Existing
counters must contain nonnegative integers; integer values written by `set()`
can be incremented, but numeric strings cannot. Creation/expiration and
increments are atomic. TTL applies only when creating the counter; increments
preserve the existing expiry. Redis requires Lua/EVAL permission for this.
Keep counters within `PHP_INT_MAX`. Redis rejects signed-64-bit overflow;
Memcached uses native unsigned-64-bit arithmetic, which can wrap at its limit.
Values outside PHP's integer range cause an exception, potentially after
Memcached has changed the counter. These evictable counters are not durable
financial records, distributed locks, or a replacement for the framework's
existing authentication/API rate limits.

### Cache deployment checks and tests

`php breeze doctor` checks whether the selected native extension is loaded
and reminds you to verify server/network settings. It does not connect to or
write to cache servers; `/ready` remains a database/storage/schema check.
Test real cache connectivity and application behavior separately in staging.

The native suite tests validation and optional-extension behavior without
requiring servers. Live integration tests use random isolated prefixes and
delete only their own keys; they never flush shared servers:

```sh
CACHE_TEST_DRIVER=redis php tests/cache-integration.php
CACHE_TEST_DRIVER=memcached php tests/cache-integration.php
```

In PowerShell, set `$env:CACHE_TEST_DRIVER = "redis"` before running the PHP
command. Optional test variables are `CACHE_TEST_HOST`, `CACHE_TEST_PORT`,
`CACHE_TEST_USERNAME`, `CACHE_TEST_PASSWORD`, `CACHE_TEST_TLS`, and
`CACHE_TEST_CA_FILE`. Use disposable test servers. CI runs both integrations
with PHP 8.3 and 8.5.

## AI: Claude and OpenAI

The server-side `$app->ai` service supports text conversations and incremental
text streaming through OpenAI's **Responses API** and Anthropic's **Claude
Messages API**. It uses native PHP cURL, not an SDK or Composer. It does not
create public routes or a chat UI. ChatGPT/Claude subscriptions do not provide
API credentials or include API usage; obtain a provider API key and enable
billing/access separately.

### Configuration

Add these settings to your existing `.env` or process environment. Set only
the providers you use; never commit real API keys:

```dotenv
AI_PROVIDER=openai
AI_TIMEOUT=60
AI_MAX_TOKENS=1024
AI_CA_FILE=
OPENAI_API_KEY=
OPENAI_MODEL=
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=
```

`AI_PROVIDER` accepts `openai` or `anthropic` (Claude). Set the API key and exact
API model ID available to that account, not a ChatGPT/Claude UI label. Model
settings are intentionally empty: no possibly retired model is silently chosen.
Provider construction makes no network requests and permits unconfigured
providers; calling an unconfigured provider fails explicitly.

`AI_TIMEOUT` is the complete request deadline in seconds (1..300), including
streaming. Connection setup is capped at the smaller of 15 seconds and that
deadline. `AI_MAX_TOKENS` is the default output budget (1..128000); each model
may enforce lower limits. Some reasoning models use output tokens internally,
leaving fewer visible text tokens. `AI_CA_FILE` optionally specifies a readable
CA bundle; otherwise native cURL/system trust and `curl.cainfo` apply. Keep
certificate verification enabled and configure the correct CA store on both
Windows and Linux.

Bootstrap reads `config/ai.php` and exposes `$app->ai`. Programmatic application
configuration can supply `ai` with `provider`, integer `timeout`/`max_tokens`,
`ca_file`, and `openai`/`anthropic` arrays containing `key` and `model`.
API keys and models are validated only for the provider used by each call.

### Text conversations

```php
$app = require __DIR__ . '/bootstrap.php';

$reply = $app->ai->chat(
    [['role' => 'user', 'content' => 'Explain PHP routing in two sentences.']],
    provider: 'anthropic',
    system: 'Be concise and use US English.',
    maxTokens: 512,
);

echo $reply['text']; // Plain-text CLI output. Escape with e() in HTML.
```

Omit `provider` to use `AI_PROVIDER`. Optional `model` and `maxTokens` named
arguments override configuration for one call only. `system` is a separate
trusted instruction string, not an entry with role `system`.

Pass a nonempty list of messages containing exactly `role` (`user` or
`assistant`) and nonempty UTF-8 `content`. The caller owns conversation history:

```php
$messages = [['role' => 'user', 'content' => 'What is CSRF?']];
$first = $app->ai->chat($messages, provider: 'openai');
$messages[] = ['role' => 'assistant', 'content' => $first['text']];
$messages[] = ['role' => 'user', 'content' => 'Give a short PHP example.'];
$second = $app->ai->chat($messages, provider: 'openai');
```

Every successful call returns exactly:

| Key | Meaning |
|---|---|
| `provider` | `openai` or `anthropic` |
| `id` | Provider response/message ID |
| `model` | Model ID reported by the provider |
| `text` | Combined visible text, including an OpenAI text refusal when returned |
| `finish_reason` | Explicit provider completion reason; inspect before treating text as complete |
| `input_tokens` | Provider-reported nonnegative input-token counter |
| `output_tokens` | Provider-reported nonnegative output-token counter |

OpenAI normally reports `completed`; text refusals report `refusal`. A
token-budget-limited OpenAI response reports `max_output_tokens`. Claude normally
reports `end_turn`, with possible `max_tokens`, `stop_sequence`, `refusal`, or
`model_context_window_exceeded`. Token/context-limit results contain partial
text: inspect the reason and decide whether to request more. Empty visible text,
provider failures, and unsupported non-text results throw rather than returning
a success-shaped empty answer. Token counters are not a cost estimate and do
not include separately reported cache counters or pricing calculations.

### Streaming text

```php
$reply = $app->ai->stream(
    [['role' => 'user', 'content' => 'Explain prepared statements briefly.']],
    static function (string $delta): void {
        echo $delta; // Plain-text CLI output, not HTML.
    },
    provider: 'openai',
    system: 'Use plain text.',
);

// $reply contains the same metadata and complete assembled text as chat().
```

The callback runs synchronously for each nonempty text delta as it arrives, not
after the whole response has been buffered. The method returns only after a
valid terminal event and complete HTTP transfer. SSE comments, fragmented
UTF-8/network chunks, LF/CRLF/CR framing, and multiline data fields are handled.
If your callback throws, the connection is canceled and that exception propagates.

**Partial output is not proof of success.** An HTTP interruption, timeout,
provider stream error, malformed event, missing terminal event, or mismatched
OpenAI final text throws even when the callback has already received text.
Do not automatically retry streams, and do not persist a partial answer as
complete. There are no automatic retries or provider fallback, avoiding duplicate
output and unexpected charges.

This callback API is not an HTTP SSE endpoint. Application code must explicitly
authenticate/authorize any endpoint it adds, impose rate limits and spending
budgets, choose framing/content types, and configure proxy/output buffering and
PHP execution timeouts. Close an active PHP session with `session_write_close()`
before a long-running web stream if it no longer needs session changes.

### Errors, limits, and privacy

Invalid caller input or missing selected-provider configuration throws
`InvalidArgumentException`. HTTP, network/TLS, provider, JSON, unsupported-content,
and premature-stream failures throw `RuntimeException`. Non-200 HTTP errors
include their status in `$error->getCode()` (for example 401 or 429) but never
copy raw provider error bodies or credentials into the exception message.
Catch errors at the application boundary and show a generic failure; inspect
only safe metadata in private diagnostics. Callback exceptions remain unchanged.

The native client uses fixed HTTPS provider URLs, verifies TLS certificates and
hostnames, never follows redirects, and enforces a total timeout. Requests are
capped at 4 MiB; responses at 16 MiB including stream metadata; each SSE event
at 4 MiB. Provider token/context limits still apply. The framework does not
store or log keys, prompts, responses, or conversation history. OpenAI requests
set `store=false`, which is not a guarantee of zero provider retention: check
each provider's data policies before sending personal or confidential content.

AI output is untrusted. Escape it in HTML, never execute generated code or SQL
automatically, and do not let prompts grant authorization. Keep API keys out
of browser scripts, templates, logs, and source control. This API supports only
text input/output; images, tools/function calls, structured output, audio,
provider-side conversation persistence, and full reasoning-state replay are
not implemented.

For isolated tests, `new \PBreeze\Ai($config, $transport)` accepts an optional
trusted transport Closure with signature
`function (string $url, array $headers, string $json, int $timeout, \Closure $receive): void`.
It must synchronously pass successful response-body chunks to `$receive` and
throw for network errors or non-200 HTTP responses. The replacement controls
its own TLS, HTTP status, and timeout safeguards; never derive it from request
input. Normal applications should use the built-in transport.

Provider protocols:
[OpenAI Responses API](https://developers.openai.com/api/reference/resources/responses/methods/create),
[OpenAI streaming](https://developers.openai.com/api/docs/guides/streaming-responses),
[Claude Messages API](https://platform.claude.com/docs/en/api/messages),
[Claude streaming](https://platform.claude.com/docs/en/build-with-claude/streaming).

## HTML Helpers

`PBreeze\Html` is a lightweight static utility loaded by bootstrap. It
builds escaped HTML without an Application service, Composer package, or a new
template language. Existing `e()`, `View::escape()`, Breeze autoescaping, router,
and CSRF behavior remain unchanged.

```php
use PBreeze\Html;

echo Html::escape($name);
echo Html::tag('div', 'Hello', [
    'class' => ['container', 'active' => true],
    'data-id' => 123,
    'aria-live' => 'polite',
]);
echo Html::a('/users', 'Users');
echo Html::img('/logo.png', ['alt' => 'PBreeze']);
```

### Escaped text and explicit trusted HTML

Text and quoted attribute values reuse the existing UTF-8 escaping functions.
Quotes, angle brackets, and ampersands are escaped; invalid UTF-8 bytes use
replacement characters. Pass **unescaped** data, not pre-encoded entities.
Scalar values, null, and `Stringable` values are supported; arbitrary objects
and arrays are not text.

Ordinary strings are always text, even if they look like HTML:

```php
echo Html::tag('p', '<strong>Hello</strong>');
// <p>&lt;strong&gt;Hello&lt;/strong&gt;</p>

echo Html::tag('p', Html::raw(Html::tag('strong', $name)));
// One paragraph containing a strong element; $name is still escaped.
```

`Html::raw()` returns an explicit `HtmlString` trusted-markup wrapper. It is
**not a sanitizer**: never pass user-submitted HTML to it. Only use it for
application-authored markup or output composed entirely by these helpers.
Plain `Stringable` objects are not implicitly trusted. Attribute values reject
raw HTML; textarea, option, and title content always escape it.

Helpers return ordinary HTML strings (except `raw()` and `csrf()`), so render
them intentionally:

```php
<?= \PBreeze\Html::a('/users', $label) ?>
```

In Breeze, use explicit raw output for the completed helper result:

```html
{!! \PBreeze\Html::a('/users', $label) !!}
```

Do not use raw output on `$label` itself. `{{ $value }}` continues escaping all
values, including `HtmlString`; this feature does not change template trust rules.

### Attributes, classes, and void elements

```php
echo Html::input('text', 'name', [
    'id' => 'name',
    'value' => $name,
    'required' => true,
    'disabled' => false,
    'class' => ['field', 'invalid' => $hasError],
    'data-id' => 123,
    'aria-invalid' => $hasError,
]);

// For an existing application-authored opening tag:
echo '<div' . Html::attributes(['class' => ['panel', 'wide']]) . '>...</div>';

$classes = Html::classes(['panel', ['wide', 'panel'], 'active' => true]);
// "panel wide active" (unescaped tokens; use attributes() when rendering).
```

`attributes()` includes a leading space when nonempty. Names are normalized to
lowercase; invalid names and case-insensitive duplicates fail. True native
boolean attributes render bare (`required`), false/null omit them. Boolean
attributes require actual booleans, not strings such as `"false"`. For other
attributes, booleans become `"true"`/`"false"` strings, so ARIA/data state is
preserved. The overloaded `download`/`capture` attributes accept true for a bare
attribute, false to omit, or a string value. Null always omits. `data-*` and `aria-*` values are escaped, not
automatically JSON-encoded; explicitly use `json_encode()` for structured data.

Class arrays accept strings, nested lists, boolean conditional maps, and
false/null list entries. Duplicate tokens are removed in first-seen order;
nesting is limited to 32. Pass the resulting string through `attributes()`,
not directly into unquoted markup.

`tag()` handles uncommon elements without a helper for every tag:

```php
echo Html::tag('section', $heading, ['class' => 'summary']);
echo Html::tag('br'); // <br>, never <br></br>
```

Void elements reject content. Element/attribute names are trusted application
structure, not arbitrary request parameters. The lightweight API deliberately
rejects executable/raw-text and foreign-content elements (including script,
style, iframe, SVG, and MathML), custom element names, event handlers, inline
styles, `srcdoc`, `srcset`, `ping`, namespace attributes, and customized built-ins.
Use reviewed application templates for those specialized contexts. It does not
parse or clean HTML, and these checks are not a general sanitization service.

### Links and URL safety

Links accept local absolute/relative paths, fragments, query strings, and
explicit `http`, `https`, `mailto`, or `tel` URLs. Image sources and other
supported URL attributes accept local paths or HTTP(S), not mailto/tel.
HTTP(S) URLs require a host and reject embedded credentials.

```php
echo Html::a('/users?' . http_build_query(['search' => $search]), 'Search');
echo Html::a('https://example.test/help', 'Help', [
    'target' => '_blank',
    'rel' => 'noopener noreferrer',
]);
```

JavaScript/data/blob/file URLs, protocol-relative URLs (`//host`), whitespace,
controls, and backslashes are rejected. Do not pre-escape URLs; attributes are
escaped after checking. Use `rawurlencode()` for dynamic path segments and
`http_build_query()` for queries. There is no named-route generator in the
existing router, so the helper accepts your route path directly.

External HTTP(S) links are allowed. This is not host authorization, URL
reachability checking, SSRF protection, or a replacement for the stricter
local-only `Response::redirect()`. Continue applying your application's allowed
destination policy.

### Form controls and CSRF

```php
echo Html::label('email', 'Email address');
echo Html::input('email', 'email', [
    'id' => 'email', 'value' => $email, 'required' => true,
]);
echo Html::textarea('description', $description, ['rows' => 5]);
echo Html::select('status', [
    'active' => 'Active', 'inactive' => 'Inactive',
], 'active');
echo Html::checkbox('active', 1, true, ['id' => 'active']);
echo Html::radio('type', 'admin', false, ['id' => 'type-admin']);
echo Html::button('Save', ['type' => 'submit']);
```

Labels reference the control's **id**, not its name; set matching IDs
explicitly. Textarea values are content, never a `value` attribute. Select
options are a flat `value => label` map; labels always escape, and selections
match exact string representations (`0` differs from `"01"`). For multiple
selection, set `multiple => true`, pass a selection array, and use your
application's intended array field name:

```php
echo Html::select('roles[]', ['reader' => 'Reader', 'editor' => 'Editor'],
    ['reader'], ['multiple' => true]);
```

Checkboxes/radios do not add hidden unchecked fields. Buttons default to
`type="button"` to avoid accidental submission; specify `submit` explicitly.
Attributes cannot override positional arguments such as name, href, or checked;
invalid usage throws `InvalidArgumentException` instead of silently choosing one.

For browser forms, let the normal application lifecycle start the session,
then reuse its existing CSRF protection:

```php
$content = (string) Html::csrf($app->session)
    . Html::input('text', 'name', ['value' => $name])
    . Html::button('Save', ['type' => 'submit']);
echo Html::tag('form', Html::raw($content), [
    'method' => 'post', 'action' => '/users',
]);
```

`Html::csrf()` wraps the existing `csrf_field()` output; it does not start a
session or implement another token system. Existing plain-PHP `csrf_field()`
and Breeze `@csrf($session)` remain valid alternatives. Merely rendering a
form with `tag()` does not insert a token: explicitly include it for mutations
and retain normal server-side CSRF, authentication, authorization, and validation.
These are output builders, not a replacement for the [Form Generator](#form-generator).

## Form Generator

Create a standalone Breeze form view using `field:type` arguments:

```sh
php breeze make:form forms.contact name:text email:email message:textarea
```

This writes `app/views/forms/contact.breeze.php`. Names use the same dot-separated
format as other view generators; fields use lowercase snake_case names beginning
with a letter. At least one field is required and duplicate fields are rejected.
Supported types: `text`, `email`, `password`, `textarea`, `number`, `date`, `time`,
`datetime-local`, `url`, `tel`, and `checkbox`. Selects and file uploads are not
generated; add those controls manually when needed.

The generated POST form includes `@csrf($session)`, associated labels, and escaped
previous values. Field errors use `aria-invalid` and `aria-describedby`.
Passwords are never repopulated; their autocomplete defaults to `new-password`
(change it to `current-password` for a login form). Checkboxes submit `1` when
checked and submit nothing when unchecked; previous values `1`, `'1'`, or `true`
restore the checked state. Array-valued old input is not inserted into controls.
Fields are optional by default: add `required`, lengths, limits, and other HTML
attributes to suit your form, and always validate on the server.

Render the form with a `Session` and a **trusted local action URL**. Optional
`old` values are an array keyed by field name; `errors` has the
`array<string, list<string>>` shape returned by `Validator::errors()`:

```php
// Inside a controller action; register the GET/POST routes explicitly yourself.
$input = $request->method === 'POST' ? $request->all() : [];
$errors = $request->method === 'POST'
    ? \PBreeze\Validator::errors($input, [
        'name' => 'required|string|max:80',
        'email' => 'required|email|max:254',
        'message' => 'required|string|max:2000',
    ])
    : [];

// Handle a valid POST here, then redirect; this example only renders the view.
return new \PBreeze\Response($this->app->views->render('forms.contact', [
    'session' => $this->app->session,
    'action' => '/contact',
    'old' => array_intersect_key($input, array_flip(['name', 'email', 'message'])),
    'errors' => $errors,
]), $errors === [] ? 200 : 422);
```

Only the form markup is generated, not a layout, controller, route, validation
rules, authorization, bot protection, or data storage. Include it in a page with
`@include('forms.contact')`, or add your own layout. Include a particular form
only once per page so its generated label/error IDs remain unique. Do not retain
passwords or other secrets in `old`, logs, or sessions.

Existing `.php` and `.breeze.php` views are preserved. The command rejects
`--force` rather than overwriting them.

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
JSON must be an object; invalid JSON returns 400. On web routes, read multipart
uploads separately with `$request->file('document')` or
`$request->files('documents')`. Uploaded files are not included in `all()`.

```php
return new \PBreeze\Response($this->app->views->render('messages.index', [
    'title' => 'My messages',
]));

return \PBreeze\Response::json(['saved' => true], 201);
return \PBreeze\Response::redirect('/berichten');
```

Redirects accept only local paths that start with a single `/`.

## Application Logging

Use the native `$app->log` service for structured application logs. No Composer,
PSR interface, database migration, or external logging package is required.

```php
$app->log->info('Report created.', ['report_id' => 42]);
$app->log->warning('Delivery delayed.', ['attempt' => 2]);
$app->log->error('Processing failed.', ['exception' => $exception]);
$app->log->log('notice', 'Import completed.', ['rows' => 120]);
```

Available methods/levels, from lowest to highest severity, are `debug`, `info`,
`notice`, `warning`, `error`, `critical`, `alert`, and `emergency`.
Configure the minimum level and retention in `.env`:

```dotenv
LOG_LEVEL=debug
LOG_RETENTION_DAYS=5
```

The corresponding application configuration keys are `log_level` and
`log_retention_days`. Retention must be an integer from 1 through 3650.
Levels are lowercase and validated when the application is constructed.
Standalone usage is also supported:

```php
$logger = new \PBreeze\Logger($app->root, retentionDays: 5, minimumLevel: 'info');
$logger->notice('Maintenance completed.');
```

Logs are newline-delimited JSON in `storage/logs/app-YYYY-MM-DD.jsonl`.
Each entry contains a UTC ISO-style `timestamp` with microseconds, `level`,
`message`, and `context`. Embedded line breaks are JSON-escaped; a message
cannot inject a second log record. Directories are created lazily with `0700`
and files/locks use `0600` on Linux. On Windows, restrict inherited NTFS ACLs
to the administrator and PHP service user. Storage stays outside `public/`,
rejects symlinked directories/files, and is ignored by the existing Git rules.
Never expose the storage directory through a web-server alias.

Concurrent writers serialize through a private file lock. After each written
entry, retention removes only matching daily log files older than the retained
UTC calendar window: five days means today plus the previous four dates.
Unrelated files and symlinks are not removed. Cleanup runs on writes, not on a
background schedule; filtered entries do not trigger cleanup. Files are daily,
not size-rotated: provision disk space, choose an appropriate minimum level,
and monitor write failures.

Context supports nested arrays, scalars, null, and exceptions. Sensitive keys
containing password, secret, token, authorization, cookie, credential, or API/
private-key names are recursively replaced with `[REDACTED]`. Exceptions are
reduced to class, source file, and line; exception messages, traces, previous
exceptions, and arguments are deliberately omitted because they may contain
credentials or request data. Context is limited to eight nested levels and
each serialized entry to 64 KiB. Unsupported objects/resources, invalid UTF-8,
nonfinite numbers, recursive/deep context, or oversized entries throw explicitly.
Messages are plain text; there is no placeholder interpolation.

**Redaction is not a general secret detector.** It does not inspect arbitrary
message text or values under unrelated keys. Do not log passwords, tokens,
connection strings, full request objects, uploaded contents, or personal data.
Choose safe, minimal context fields explicitly.

Unexpected web/API errors are logged automatically through the configured
application logger while existing public error responses remain unchanged.
Server-side HTTP exceptions (5xx) are also logged; ordinary validation,
authentication, and other 4xx responses are not. API errors caught inside the
API router use the same logger. CLI command failures after application startup
are logged except invalid-argument/usage errors. There is no automatic access
log or request-body/header/query logging.

Direct logger writes throw on failure. Automatic exception reporting catches
logging failures and explicitly reports a safe diagnostic to PHP's server log
without recursively invoking the file logger. Errors before application
initialization also use the PHP server log; the CLI still prints its error and
exits with a failure status. This service does not install global error/exception
handlers or capture engine fatal errors/shutdown events: keep PHP's
`log_errors` and server monitoring configured. A minimum level above `error`
also filters automatic application-error entries.

## File Uploads

Web routes accept native PHP `multipart/form-data` uploads through `Request`.
Use an explicit list of allowed filename extensions for each upload handler.
There is deliberately no allow-all default. The default per-file size limit
is **2 MiB (2,097,152 bytes)**.

Configure the application default in `.env`:

```dotenv
UPLOAD_MAX_BYTES=2097152
```

This must be a positive integer number of bytes. It configures
`$app->uploads->maxBytes` through `upload_max_bytes` in the application config.
It does not change PHP's `upload_max_filesize` or `post_max_size`.

Supported types are `jpg`, `jpeg`, `png`, `gif`, `webp`, `pdf`, `txt`, `csv`,
`docx`, and `xlsx`. Use lowercase extensions without a dot. Uploaded extension
matching is case-insensitive. PHP/script files, HTML, SVG, arbitrary ZIP
archives, and legacy `.doc`/`.xls` files are not supported.

### Receiving and Storing a File

Register a protected web route in `routes/web.php`:

```php
use PBreeze\HttpException;
use PBreeze\Request;
use PBreeze\Response;

$router->post('/documents', $app->auth->protect(
    static function (Request $request) use ($app): Response {
        $file = $request->file('document');
        if ($file === null) {
            throw new HttpException(422, 'Choose a document to upload.');
        }
        $stored = $app->uploads->store(
            $file,
            allowedTypes: ['pdf', 'docx', 'xlsx'],
        );

        // Persist these values with the current user's ID in your own model.
        // Delete the stored upload if your database write subsequently fails.
        return Response::json([
            'key' => $stored->key,
            'name' => $stored->originalName,
            'mime' => $stored->mimeType,
            'size' => $stored->size,
        ], 201);
    },
));
```

In a Breeze template, submit a multipart form with the normal CSRF field.
Pass `'session' => $app->session` in the view's render data:

```html
<form action="/documents" method="post" enctype="multipart/form-data">
    @csrf($session)
    <label for="document">Document</label>
    <input id="document" name="document" type="file" accept=".pdf,.docx,.xlsx" required>
    <button type="submit">Upload document</button>
</form>
```

The browser's `accept` attribute is only a selection hint, not validation.
JavaScript clients may send the CSRF token in `X-CSRF-Token`; let the browser
set the multipart boundary when using `FormData`.

`file()` returns an `UploadedFile` or null for a missing field/no file
selection. If more than one file is supplied to a single-file accessor,
it throws HTTP 400. `files()` always returns a list; missing selections
are omitted. One-dimensional `documents[]` and keyed file lists are supported;
deeper nested upload fields are rejected with HTTP 400.
Nonempty PHP upload failures remain accessible and are reported by validation.

The uploaded object exposes `originalName`, `clientMediaType`, and PHP's
`error` code. Client path components are removed from the original name;
the remaining UTF-8 name must contain 1 to 255 bytes without control characters.
Treat this name as display metadata and escape it in HTML. The client media
type is explicitly untrusted and is never used to approve or serve a file.

`store()` validates before writing. It returns a `StoredUpload` with read-only
`key`, `originalName`, `mimeType`, and actual byte `size` properties.
It uses `is_uploaded_file()` and `move_uploaded_file()`: arbitrary local
server files and CLI-created temporary files cannot be passed off as uploads.
Each file receives a cryptographically random 64-hex-character key plus
its validated extension. Files are stored as
`storage/uploads/<random-id>/file.<extension>`, never under the client's name,
so equal original names cannot overwrite one another.

Storage directories are created on demand with `0700` permissions and files
receive `0600` on Linux. On Windows, restrict inherited NTFS ACLs to the
administrator and PHP service user. The storage location is outside `public/`;
symlinked storage directories and symlinked stored files are rejected.
Upload contents are ignored by the existing `storage/` Git ignore rule.

There is no automatic attachment database table or ownership model. Persist
the storage key, display name, size, media type, and owner as your application
requires. Keys locate files; they are **not authorization credentials**.
No public upload/download routes are installed by this feature.

### Multiple Files and Custom Limits

```php
$files = $request->files('documents');
if ($files === [] || count($files) > 5) {
    throw new \PBreeze\HttpException(422, 'Choose between one and five files.');
}

// Check every file before starting a batch write.
foreach ($files as $file) {
    $metadata = $app->uploads->validate($file, ['jpg', 'jpeg', 'png'], maxBytes: 524288);
}
foreach ($files as $file) {
    $stored = $app->uploads->store($file, ['jpg', 'jpeg', 'png'], maxBytes: 524288);
    // Persist each file and its owner.
}
```

Use an input with `name="documents[]"` and the `multiple` attribute.
`$app->uploads->validate($file, $allowedTypes)` performs the same checks as
`store()` without moving the file, using the configured default,
returning `extension`, canonical `mime`, and actual byte `size`. The optional
positive integer `maxBytes` overrides the configured per-file default; it does not
raise PHP or web-server limits. Empty files are rejected.
Calling `$file->validate()` directly is standalone validation and retains a
2 MiB default unless you explicitly pass `maxBytes`; use the application
service for environment-aware validation.

Batches are not filesystem/database transactions. Prevalidating a batch
avoids starting writes for an already-invalid file, but disk or database
failures can still occur later. Track successful keys and clean them up if
the operation must be all-or-nothing. Enforce application-specific file-count,
combined-byte, user-quota, and rate limits.

### Protected Downloads and Deletion

After authenticating a user and loading an attachment record belonging
to that user, return:

```php
return $app->uploads->download($attachment['storage_key'], $attachment['original_name']);
```

Register the download action with `$app->auth->protect(...)` and perform the
ownership/record authorization check **before** calling `download()`.
Never authorize access just because someone knows a random storage key.
`download()` itself is a storage operation, not an authorization service.
Its optional filename defaults to `download.<extension>`; the original name
must come from your persisted metadata if you want to restore it.

Downloads reuse the normal attachment response: canonical media type,
UTF-8-safe filename headers, correct byte length, `nosniff`, and `no-store`.
HEAD requests omit the response body. Invalid keys, missing files, and
symlinked file targets produce HTTP 404. Files are read into memory to build
the response; bound upload limits accordingly.

After the same authentication and ownership checks, a CSRF-protected POST or
DELETE handler may call:

```php
$app->uploads->delete($attachment['storage_key']);
```

This removes only that stored file and its random directory. Coordinate
deletion of your database metadata explicitly; deleting an attachment record
does not automatically delete the file, and deleting a file does not update
your database.

### Validation and Deployment Limits

- Validation checks the real temporary file size, allowed extension, and
  server-detected media type using native `fileinfo`, not client-provided
  size/type claims.
- Images also undergo a native header/dimension check. This is not a full
  image decode, re-encoding service, or pixel-count limit; add application
  image-dimension limits before further processing untrusted images.
- DOCX/XLSX files must be ZIP packages containing the matching main document,
  content-type, and relationship entries. Checks reject macro-named entries,
  encrypted entries, unsafe archive paths, more than 1,000 entries, entries
  larger than 10 MiB, or a combined declared expansion larger than 20 MiB.
  Archives are not extracted. These are bounded package-shape checks, not
  complete Office schema validation or a guarantee of harmless content.
- MIME/header checks are **not malware scanning** or content sanitization.
  PDFs and Office documents may contain active content or external links.
  Uploaded CSV files may contain spreadsheet formulas; validation does not
  rewrite their contents.
  Add quarantine/scanning if your deployment requires it. Do not execute,
  include, extract, or render uploaded content inline simply because it passed
  validation.
- `file_uploads` must be enabled. Configure a writable PHP `upload_tmp_dir`
  and writable private storage. Enable native `fileinfo`; Office checks
  additionally need `zip`.
- PHP's `upload_max_filesize` must accommodate the per-file limit;
  `post_max_size` must accommodate all files, form fields, and multipart
  overhead. For a single 2 MiB file, a typical starting point is
  `upload_max_filesize=2M` and `post_max_size=8M`. Configure these in the PHP
  configuration used by your actual web server.
- PHP's `max_file_uploads`, `max_input_vars`, and `max_input_nesting_level`
  can limit parsed fields. PHP may omit files/fields beyond its parsing limits;
  handlers must check required fields and expected file counts.
- Web-server/reverse-proxy body-size limits also apply. Raising framework
  `maxBytes` cannot bypass them.

The request layer returns HTTP 413 when a POST exceeds PHP's configured
`post_max_size`, rather than treating PHP's discarded body as an empty form.
Per-file PHP size failures and framework size failures also return 413;
partial files, disallowed types, invalid images/packages, empty files, and
untrusted local-file sources return 422. Malformed file-field structures return
400. Server receive/storage failures throw and use the standard logged,
generic HTTP 500 response. Application-required file errors should be
explicitly reported by the handler, as in the example above.

Multipart support is for normal POST web routes, preserving session/CSRF
behavior. The separate `/api/*` router remains JSON-only and rejects multipart
bodies with HTTP 415 (or 413 when its request-size limit is exceeded).
Native PHP does not populate this API for raw PUT/PATCH uploads, chunked
upload protocols, remote URLs, or base64 JSON file payloads.

## String Manipulation

Use the native `$app->strings` service, or instantiate `PBreeze\Strings`
after bootstrap. It accepts UTF-8 strings and throws `InvalidArgumentException`
for malformed encoding instead of silently replacing bytes. No Composer
dependencies are used. Case conversion requires `mbstring`; grapheme operations,
naming helpers, and slug transliteration require `intl`.
The service is stateless and construction does not require either extension.

```php
$strings = $app->strings;
echo $strings->upper('Straße');                   // STRASSE
echo $strings->lower('ÉLÈVE');                    // élève
echo $strings->title('hello WORLD');              // Hello World
echo $strings->trim("\u{00a0} Ada \u{2003}");      // Ada
echo $strings->length("e\u{0301}");               // 1 visible character
echo $strings->slice('Hello world', 6);           // world
echo $strings->slice('Hello world', -5);          // world
```

`length()`, `slice()`, and `truncate()` use Unicode grapheme clusters: combining
accents and joined emoji are not split into individual code points.
Offsets are zero-based; negative offsets count from the end. A null slice
length includes the remainder; a negative length omits that many characters
from the end. Out-of-range offsets return an empty string.
Case conversion follows Unicode mappings, not locale-specific Turkish/Dutch
rules, and can expand characters (`ß` becomes `SS`). No method changes global
locale, encoding, or input data.

### Matching, Extraction, and Replacement

```php
$strings->contains('Hello café', 'café');         // true
$strings->startsWith('report.csv', 'report');     // true
$strings->endsWith('report.csv', '.csv');          // true
$strings->before('one/two/three', '/');            // one
$strings->after('one/two/three', '/');             // two/three
$strings->replace('Hello Ada, Ada!', 'Ada', 'Lin'); // Hello Lin, Lin!
```

Matching is literal and case-sensitive, without regex interpretation or
automatic Unicode normalization. Visually identical composed/decomposed
characters may therefore differ. Empty needles match in `contains()`,
`startsWith()`, and `endsWith()`, including against an empty string.
`before()` and `after()` use the first occurrence and return the original
text when absent. Empty extraction delimiters and replacement searches throw;
an empty replacement deletes each occurrence. `replace()` changes all
nonoverlapping occurrences. Unicode whitespace trimming removes leading and
trailing whitespace/separators, not internal whitespace or zero-width marks.

### Truncation, Slugs, and Naming

```php
$strings->truncate('Hello world', 8);             // Hello...
$strings->truncate('Hello world', 5, '');         // Hello
$strings->truncate('Hello world', 2);             // ..
$strings->slug(' Crème brûlée! ');                // creme-brulee
$strings->slug('Hello World', '_');               // hello_world
$strings->snake('HTTPServerID');                  // http_server_id
$strings->camel('HTTP_server_ID');                // httpServerId
$strings->studly('hello-world');                  // HelloWorld
```

The truncation limit includes the suffix (default `...`), measured in visible
characters. A zero limit produces empty text when truncation is needed;
negative limits throw. An oversized suffix is itself cut to fit. Short text
is returned unchanged, with no added suffix.

Slugs transliterate through ICU into lowercase ASCII letters/digits, collapse
other characters into a hyphen or underscore, and trim outer separators.
Punctuation-only or untransliterable input can yield an empty slug.
ICU versions can change transliteration results. Slugs are not guaranteed
unique: validate the result and enforce application/database uniqueness.
They are not file-path validation or authorization.

Naming helpers split separator runs, lowercase-to-uppercase boundaries,
and acronym-to-word boundaries (`HTTPServer` becomes `HTTP`, `Server`).
They retain Unicode letters/marks/numbers, normalize word casing, and do
not transliterate. Separator-only input becomes empty. Digits remain with
their preceding word unless followed by an uppercase letter.

### Cryptographic Random Strings

```php
$token = $strings->random();                      // 32 alphanumeric characters
$hex = $strings->random(64, '0123456789abcdef');   // 64 hex characters
```

`random()` uses native `random_bytes()` and rejection sampling to avoid
alphabet-selection bias, not `rand()` or `mt_rand()`. Length must be 1..65536;
the alphabet must contain 2..94 distinct printable non-space ASCII characters.
Duplicate characters, Unicode alphabets, whitespace, and invalid lengths throw.
Random-source failures propagate. Choose sufficient length/alphabet entropy
for your use case; this does not add expiry, hashing, uniqueness guarantees,
or authorization. Continue using the built-in authentication/API token services
for those flows, and do not log secrets.

### Using Strings in Views

`$strings` is reserved and available automatically in both PHP and Breeze views,
including layouts and partials. Render data cannot override it.

```html
<h1>{{ $strings->title($title) }}</h1>
<p>{{ $strings->truncate($description, 160) }}</p>
```

Plain PHP views should use `e($strings->upper($title))`. String operations do
not escape HTML, sanitize rich text, or make SQL/URLs safe; keep normal
context-appropriate output escaping and parameter binding.

## Date, Time, Money, and Decimal Formatting

The shared `$app->format` service uses native PHP Intl/ICU without Composer
or external packages. Defaults are `en-US` and `UTC`; configure them in
your local `.env`:

```dotenv
APP_LOCALE=en-US
APP_TIMEZONE=UTC
```

The application configuration uses `locale` and `timezone` keys. You can also
create a standalone formatter:

```php
$format = new \PBreeze\Formatter(locale: 'de-DE', timezone: 'Europe/Berlin');
```

Use language tags such as `en-US`, `nl-NL`, `de-DE`, `fr-FR`, `hi-IN`, or
`ar-EG`. Optional script and region subtags are supported; ICU extensions
and locale keywords are not. ICU may fall back to a supported parent locale;
an unsupported language throws instead of silently using the machine's default.
Use IANA time zone identifiers such as `UTC`, `America/New_York`, or
`Europe/Amsterdam`, not ambiguous abbreviations or numeric UTC offsets.
Neither formatter creation nor individual calls change PHP's global time zone
or Intl's global locale. Missing Intl is reported when formatting is requested,
so unrelated framework features do not require it.

### Dates and Times

```php
$date = new \DateTimeImmutable('2026-11-13T15:45:06+00:00');

$app->format->date($date, 'short'); // 11/13/26
$app->format->date($date);          // Nov 13, 2026
$app->format->date($date, 'long');   // November 13, 2026
$app->format->date($date, 'full');   // Friday, November 13, 2026
$app->format->time($date, 'short');
$app->format->dateTime($date);

$app->format->date($date, 'short', locale: 'de-DE'); // 13.11.26
$app->format->dateTime(
    $date,
    locale: 'en-US',
    timezone: 'America/New_York',
    pattern: 'yyyy-MM-dd HH:mm:ss XXX',
); // 2026-11-13 10:45:06 -05:00

$app->format->dateTime(0, pattern: 'yyyy-MM-dd HH:mm:ss'); // 1970-01-01 00:00:00
```

Inputs are `DateTimeInterface` objects or integer Unix timestamps in seconds.
Strings are intentionally not parsed; construct and validate your dates
explicitly. Dates are formatted in the configured or overridden time zone,
not necessarily the input object's zone, without mutating that object.
IANA daylight-saving rules apply.

`date()` and `time()` default to `medium`. `dateTime()` defaults to a
`medium` date and `short` time. Styles are `short`, `medium`, `long`, and `full`;
for `dateTime()`, either part may be `none`. At least one part must be visible
unless a custom pattern is supplied.

The optional `pattern` uses **ICU syntax, not PHP `date()` syntax**.
For example, `yyyy-MM-dd` is a numeric date and `HH:mm:ss` is 24-hour time.
Quote literal text using ICU apostrophe rules. A pattern overrides the style
layout and must contain 1 to 256 UTF-8 bytes without control characters.
Localized punctuation, spacing, and names can vary with the installed ICU
version. The calendar is explicitly Gregorian.

### Decimal Numbers and Percentages

```php
$app->format->decimal('1234.5');                       // 1,234.50
$app->format->decimal('1234.5', precision: 3);         // 1,234.500
$app->format->decimal(1234, precision: 0);             // 1,234
$app->format->decimal('1234.5', locale: 'de-DE');       // 1.234,50
$app->format->decimal('12345678.5', locale: 'hi-IN');   // 1,23,45,678.50
$app->format->decimal('1234.5', grouping: false);      // 1234.50
$app->format->decimal('1.005');                        // 1.01
$app->format->decimal('-1.005');                       // -1.01

$app->format->percent('0.125');                       // 12.50%
$app->format->percent('0.125', precision: 1);          // 12.5%
$app->format->percent('0.125', locale: 'de-DE');        // 12,50 %
```

Decimal and percent inputs are integers or decimal strings. The precision
range is 0 through 20, defaulting to exactly 2 fractional digits. Rounding
is half away from zero, performed on strings without binary floating-point
conversion. Negative values that round to zero display as positive zero.
Percent inputs are **ratios**: `1` means 100%, not 1%.

Grouping sizes, decimal/group separators, digits, signs, and percent
placement follow ICU locale rules. Some locales use nonbreaking spaces or
non-Latin digits, and some do not group four-digit numbers. Do not compare
rendered values after replacing those characters with ordinary spaces.

### Money

```php
$app->format->money('1234.565', 'USD');                 // $1,234.57
$app->format->money('1.005', 'USD');                    // $1.01
$app->format->money('1234.5', 'EUR', locale: 'de-DE');   // 1.234,50 €
$app->format->money('1234.5', 'JPY');                   // ¥1,235
$app->format->money('1.2345', 'KWD');                   // KWD 1.235
$app->format->money('1234.5', grouping: false);         // $1234.50

$app->format->moneyMinor(12345, 'USD');                 // $123.45
$app->format->moneyMinor(-1, 'USD');                    // -$0.01
$app->format->moneyMinor(12345, 'JPY');                 // ¥12,345
$app->format->moneyMinor(12345, 'KWD');                 // KWD 12.345
```

`money()` accepts a decimal string in major currency units; `moneyMinor()`
accepts an integer in the currency's minor units. Floats are not supported
inputs: keep database decimal amounts as strings, and do not compute money
with floating-point arithmetic before calling the formatter.
The default currency is `USD`. Pass uppercase three-letter ISO 4217 codes;
syntax is checked, but this is not a registry of currently active currencies.
Unknown codes use ICU's generic currency formatting.

ICU supplies standard currency precision: typically 2 digits for USD/EUR,
0 for JPY, 3 for KWD, and 4 for CLF. This is not a universal cents conversion.
String rounding remains exact even beyond PHP's integer range or Excel's
numeric precision; integer minor units are limited to PHP's integer range.
Currency position, symbol/code, spacing, negative sign, and digits follow
the requested locale. Space characters in the examples above may actually
be nonbreaking spaces. No currency conversion, accounting arithmetic, cash
rounding increments, or localized-input parsing is performed.

For all numeric methods, decimal strings use ASCII digits, an optional leading
minus, and an optional period followed by fractional digits. No whitespace,
leading plus, grouping separators, or exponent notation is accepted.
Inputs are limited to 1,024 bytes. Decimal and percentage inputs also reject
floats: use strings when fractional precision matters. For example, `"0.1"`
is valid; `"1,25"`, `".5"`, and `"1e3"` are not.

### Breeze Templates and Exports

Every Breeze template and plain PHP view receives the same `$format` service
as `$app->format`, including layouts, includes, and components:

```blade
{{ $format->date($createdAt, 'long') }}
{{ $format->dateTime($createdAt, timezone: 'America/New_York') }}
{{ $format->money($price, 'USD') }}
{{ $format->moneyMinor($amountInCents, 'USD') }}
{{ $format->decimal($quantity, precision: 3, locale: 'de-DE') }}
{{ $format->percent($completionRatio, precision: 1) }}
```

Use normal escaped output. The view-data name `format` is reserved for this
service and cannot override it. Formatting data does not alter template
escaping or translate framework-maintained US English copy.

For documents, pass formatted strings explicitly:

```php
$file = \PBreeze\Documents\Documents::excel()
    ->sheet('Report', [
        ['Date', 'Display total', 'Numeric total'],
        [
            $app->format->date($createdAt),
            $app->format->money('1234.50', 'USD'),
            1234.50,
        ],
    ])->build();
```

Formatted strings become literal Excel text, not numeric cells. Keep raw
numeric cells separate when spreadsheet calculations are needed. JSON numbers
must remain locale-independent; emit a separate display string if needed.
PDF still has its documented Windows-1252 font limit, so localized digits or
spacing outside that character set may require Word instead.

## Structured File Reading

Read CSV, JSON, XML, YAML, and YML with one API after loading bootstrap:

```php
use PBreeze\FileReader;

$rows = FileReader::read($app->root . '/storage/import/customers.csv');
$settings = FileReader::read($app->root . '/storage/import/settings.json');
$data = FileReader::read($app->root . '/storage/import/data.txt', [
    'format' => 'json',
    'max_bytes' => 1048576,
    'max_depth' => 32,
]);
```

Extensions are case-insensitive. `format` overrides the extension; supported
values are `csv`, `json`, `xml`, `yaml`, and `yml`. Every `read()` result is
a PHP array, never a parser object. This does **not** make every format have
the same schema: CSV produces rows, JSON/YAML preserve their data tree, and XML
uses the explicit node structure below. JSON/YAML scalar roots are rejected;
empty mappings and lists both become `[]`.

The static utility is available without an Application instance by explicitly
requiring `framework/FileReader.php`. It does not connect to any service.

### Input safety and limits

Only existing, readable, regular local files are accepted. Stream wrappers
(including HTTP, `php://`, and `phar://`) are rejected. Text must be UTF-8;
an optional UTF-8 BOM is removed. Convert UTF-16/UTF-32 files to UTF-8 before
importing. Readers never evaluate PHP from input.

The default `max_bytes` is **16 MiB**, including any BOM, even for streamed
CSV. Set a positive integer explicitly for larger trusted workloads.
`max_depth` defaults to 64 and accepts 1..256 for JSON/XML/YAML; it is not
relevant to flat CSV records. XML also limits elements to 100000. YAML
normalization limits its traversal to 100000 values/containers to reject cyclic
or excessively expanding aliases. Whole-document parsers still need memory
for their native parse tree; limits are safeguards, not a process sandbox.
Use process memory/time limits appropriate to the workload.

**Authorize paths in your application.** Local-file validation is not a
directory allowlist, upload validator, schema validator, or permission system.
Do not pass arbitrary request paths directly to `read()`; select authorized
private files, validate required fields/types, and escape imported values when
rendering. CSV strings beginning with spreadsheet formulas remain unchanged.

### CSV rows and streaming

```php
$rows = FileReader::read($path, [
    'headers' => true,
    'delimiter' => ';',
    'enclosure' => '"',
    'escape' => '',
    'skip_empty_lines' => true,
]);
// name;email followed by Ada;ada@example.test becomes:
// [['name' => 'Ada', 'email' => 'ada@example.test']]

foreach (FileReader::iterate($path, [
    'headers' => true,
    'max_bytes' => 268435456,
]) as $row) {
    importCustomer($row);
}
```

The defaults are comma delimiter, double-quote enclosure, empty escape (standard
doubled quotes), headers enabled, and empty physical records skipped.
`headers: true` consumes the first nonblank record as unique nonempty names.
Use `headers: false` for numeric cell lists or
`headers: ['name', 'email']` to supply names without consuming a record.
Records must match the header width. Empty files return `[]`.
Cells remain strings, including numbers, booleans, and empty cells.

Delimiter/enclosure/nonempty escape must be distinct single bytes other than
CR, LF, and NUL. For a legacy backslash-escaped file, explicitly set
`'escape' => '\\'`; native PHP preserves backslash escapes in returned cells.
Quoted multiline fields and doubled quotes are supported. Invalid UTF-8,
malformed quoting, empty/duplicate headers, and inconsistent header widths
produce errors rather than silently dropping data. Whitespace outside closing
quotes is rejected by the strict CSV syntax.

`iterate()` is lazy: errors may appear after some rows were processed.
Choose transaction boundaries/idempotency for partial imports. Memory use is
proportional to the largest record, not total row count. The generator closes
its file when exhausted or destroyed; unset an iterator you retain after an
early break. JSON, XML, and YAML currently reject `iterate()` explicitly rather
than pretending to stream a fully buffered document.

### JSON data trees

JSON objects become associative arrays and JSON lists remain numeric arrays.
Nested strings, booleans, nulls, and numbers retain native PHP types. Integers
outside PHP's integer range become strings to avoid precision loss; nonfinite
float overflow is rejected.

```php
// {"customer":{"name":"Ada","roles":["reader"]}}
$data = FileReader::read($path, ['format' => 'json']);
$name = $data['customer']['name'];
```

Invalid JSON throws a `RuntimeException` explaining the native parse reason,
with the original `JsonException` available through `getPrevious()`. Neither
the message nor XML/YAML diagnostics include source snippets.

### XML nodes, attributes, and namespaces

XML requires PHP's native DOM extension. Its output always includes the root.
Every node has `@attributes`, `@text`, and `@children`; child groups are
**always lists**, even for a single child:

```php
// <catalog><item id="1">Book</item><item id="2"/></catalog>
$data = FileReader::read($path, ['format' => 'xml']);
$items = $data['catalog']['@children']['item'];
$id = $items[0]['@attributes']['id']; // "1"
$text = $items[0]['@text'];           // "Book"
```

Nested nodes use the same structure. Direct text/CDATA and whitespace are
preserved; comments and processing instructions are ignored. Namespace names
use `{URI}local`, independently of the chosen prefix. For example,
`<x:item xmlns:x="urn:catalog"/>` is keyed by `{urn:catalog}item`.
Namespace declarations are not returned as attributes. Mixed-content text is
concatenated and children are grouped by name: this is a data import API,
not a lossless XML round-trip representation.

**DTDs and entity declarations are rejected.** External resolution and entity
substitution are disabled, networking is disabled for libxml, and readers never
load schemas or process XInclude. Normal predefined entities such as `&amp;`
work. Do not enable additional entity-loading flags in custom readers.

### YAML and YML setup

Both extensions use the same `YamlReader`. There is intentionally no
Composer dependency or limited handwritten YAML fallback.

1. Install the native **ext-yaml** module compatible with your PHP version,
   architecture, and thread-safety mode. On Linux, use the distribution's PHP
   YAML package (for example `php-yaml`, or a version-specific package).
   On Windows, use a matching supported PHP YAML extension build and its
   required LibYAML DLLs.
2. Enable it in the PHP configuration used for imports (CLI and/or web PHP).
   Keep `yaml.decode_php=0`; PBreeze rejects an enabled PHP deserializer
   rather than changing the process's INI configuration.
3. Verify with `php --ri yaml`. Restart the web PHP service after INI changes.

```php
$settings = FileReader::read($path, ['format' => 'yaml']);
// name: example
// roles: [reader, editor]
// becomes ['name' => 'example', 'roles' => ['reader', 'editor']]
```

Mappings, sequences, nested collections, block strings, anchors/aliases, and
merge keys follow the installed ext-yaml/LibYAML semantics (including YAML 1.1
scalar coercions such as `yes`/`no`). Quote values that must remain strings.
Exactly one mapping/sequence document is accepted. PHP object tags, cyclic or
excessively expanding aliases, unsupported PHP value types, nonfinite numbers,
scalar roots, and multiple documents fail explicitly. Native INI options for
timestamp/binary decoding still apply; do not enable PHP object decoding.
Without ext-yaml, YAML reads throw a clear missing-extension exception.

### Errors and additional formats

Invalid options/unsupported formats throw `InvalidArgumentException`. Missing,
unreadable, oversized files, malformed data, and unavailable extensions throw
`RuntimeException`. No error becomes an empty successful result. Report failures
through your application's existing logger/error response; do not expose
private paths or imported data to users.

`CsvReader`, `JsonReader`, `XmlReader`, and `YamlReader` live in
`PBreeze\Readers` and implement `ReaderInterface`. Each is usable directly.
To add a format, explicitly require trusted application code implementing
`read(string $path, array $options = []): array` and
`iterate(string $path, array $options = []): iterable`. Extending the abstract
`PBreeze\Readers\Reader` reuses bounded local-file helpers and the explicit
unsupported-streaming exception.

```php
// Treat an application-specific extension as JSON:
FileReader::register('dataset', new \PBreeze\Readers\JsonReader());
$data = FileReader::read($app->root . '/storage/import/customer.dataset');
```

Registrations are process-local and trusted; they can override built-in format
names. Never construct or register reader classes from untrusted file content.
No application service/configuration or autoloader is needed for this utility.

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

## Validation Component

The modern validator works on arrays independently of HTTP, sessions,
databases, Composer, or an Application instance. Bootstrap loads it explicitly;
standalone scripts can require `framework/Validator.php`.

```php
use PBreeze\Validator;

$validator = Validator::make($data, [
    'name' => 'required|string|max:100',
    'email' => 'required|email',
    'age' => 'nullable|integer|min:18',
    'active' => 'boolean',
    'password' => 'required|string|min:8',
]);

if ($validator->fails()) {
    $errors = $validator->errors()->toArray(); // field => list of messages
}
if ($validator->passes()) {
    $validated = $validator->validated();
}
```

`make()` returns a `ValidationResult`; rules are normalized once, and one
evaluation is cached across `passes()`, `fails()`, `errors()`, `validated()`,
and `validate()`. Unknown/malformed rules throw immediately, even for missing
optional fields. Construct a new result for new input.

The existing static `Validator::errors($data, $rules)` remains a compatibility
API for existing authentication/forms: it supports its original seven rules,
messages, Unicode string-size checks, and optional-empty skipping. Use
`make()` for all new rules and the explicit null/missing semantics below.

### Presence, null, and types

Missing fields skip ordinary value rules. Use `required` or `present` when the
key must exist:

| Rule | Meaning |
|---|---|
| `required` | Exists and is not null, an empty/ASCII-whitespace-only string, or an empty array |
| `required_if:type,company,nonprofit` | Required when another field matches one of these values |
| `required_unless:type,person` | Required unless another field matches; missing comparison fields do not match |
| `present` | Key must exist, even if its value is null |
| `nullable` | Null skips value rules, but never overrides a required/present rule |
| `sometimes` | If missing, skip the entire field, including presence rules |
| `string` | Valid UTF-8 string; no coercion |
| `integer` | PHP integer or signed decimal integer string, within PHP's integer range, without whitespace or leading zeroes except zero itself |
| `numeric`, `float` | Finite PHP number or decimal/exponent string, without whitespace; integers also qualify |
| `boolean` | Exactly true, false, 0, 1, `"0"`, or `"1"` |
| `array` | PHP array; does not automatically validate/copy children |

`0`, `"0"`, and `false` satisfy `required`. A present null fails `string`
unless `nullable` is specified; a present empty string passes `string` but
fails `integer`/`email`. No automatic trimming, string-to-number conversion,
HTML stripping, or null conversion occurs.

For conditional comparison lists, string/integer/float values compare by their
exact string representation. Booleans match only `true`/`false` literals and
null matches only `null`; false never silently equals zero.

### Sizes, strings, formatting, and comparisons

| Rules | Meaning |
|---|---|
| `min:n`, `max:n`, `between:min,max` | Inclusive numeric magnitude for native numbers or fields with integer/numeric/float rules; otherwise Unicode code-point length for strings or item count for arrays |
| `length:n` | Exact string code-point length or array count |
| `regex:/pattern/u` | Native PCRE match; invalid patterns and engine/resource errors throw explicitly |
| `alpha`, `alpha_num` | Nonempty Unicode letters, or letters/numbers; spaces, punctuation and combining marks outside those categories do not qualify |
| `email` | Native `FILTER_VALIDATE_EMAIL` |
| `url` | Absolute HTTP(S) URL without credentials, whitespace, controls, or backslashes |
| `uuid` | Canonical hyphenated UUID with version 1..8 and RFC variant bits |
| `ip`, `ipv4`, `ipv6` | Native IP address validation, optionally restricted to one family |
| `json` | Syntactically valid JSON string, depth at most 64; scalar JSON is permitted |
| `same:other`, `different:other` | Strict PHP identity/nonidentity; comparison field must exist |
| `in:a,b`, `not_in:a,b` | Membership/nonmembership using the conditional-list comparison semantics above |
| `confirmed` | Strict equality to the sibling `field_confirmation` value |

Rules accept pipe strings or lists of individual rule strings/objects:

```php
$validator = Validator::make($data, [
    'name' => ['required', 'string', 'max:100'],
    'pet' => ['string', 'regex:/^(cat|dog)$/u'],
]);
```

Use **array syntax for regex patterns containing `|`**, so alternation is not
mistaken for a rule separator. Patterns are trusted application configuration,
not user-supplied expressions. PCRE's configured resource limits still apply.
Size arguments accept finite signed/decimal bounds; `length` and `max_size`
require nonnegative integers. Integer bounds within PHP's integer range compare
as integers; decimal/out-of-range magnitudes use PHP floating-point precision,
not arbitrary-precision financial arithmetic.

### Dates

`date` accepts strict `YYYY-MM-DD` or ISO timestamps with `T`, seconds, optional
fractional seconds, and optional `Z`/numeric offset. Invalid calendar dates and
rollover times fail. Timezone-less input uses UTC independently of the global
PHP timezone. Relative strings such as `tomorrow` are not accepted.

```php
$data = Validator::make($input, [
    'start' => 'required|date',
    'end' => 'required|date|after:@start|before:2030-01-01',
    'display_date' => 'date_format:d/m/Y',
])->validate();
```

`before`/`after` are exclusive and accept an ISO literal or explicit `@field`
reference; referenced fields must contain valid ISO dates. `date_format`
uses native PHP date-format characters with exact round-trip matching. Do not
combine `date` with a non-ISO `date_format` expecting conversion.

### Nested data, wildcard arrays, and filtered output

```php
$data = Validator::make($input, [
    'user' => 'required|array',
    'user.name' => 'required|string',
    'user.email' => 'required|email',
    'users' => 'required|array|min:1|max:100',
    'users.*.email' => 'required|email',
    'tags' => 'array',
    'tags.*' => 'string|max:30',
])->validate();
```

Errors use concrete paths such as `users.1.email`. Comparison-field wildcards
are resolved at the same indexes, for example
`users.*.end => after:@users.*.start`. Dot paths traverse arrays only.
Dots/asterisks inside literal keys are not supported; map such imported keys to
simple field names first. Empty arrays expand to no wildcard matches: require
and bound the parent array separately. Wildcards are never a substitute for
validating the container's type.

**Array rules validate containers, not all their descendants.** Validated output
starts each covered array as `[]`, then copies only covered descendants.
For example, validating `user => array` and `user.email => email` excludes
`user.admin`. A standalone `user => array` produces `user => []`; add rules
for every permitted child or `user.*` for all permitted scalar children.
This intentional filtering also applies to the Request/API adapters. Array
keys/indexes are preserved, including sparse numeric indexes.

Missing optional fields are omitted. Present allowed nulls remain null.
No partial output is returned on failure: both `validated()` and `validate()`
throw `ValidationException` when any field is invalid.

### Error messages and field labels

```php
$validator = Validator::make($input,
    ['email_address' => 'required|email', 'users.*.email' => 'email'],
    [
        'email_address.required' => 'An email address is required.',
        'email_address.email' => 'Please provide a valid :attribute.',
        'users.*.email.email' => 'Please check this row email.',
    ],
    ['email_address' => 'email address'],
);

$errors = $validator->errors();
$all = $errors->all();                 // Flat message list
$first = $errors->first();             // First message, or null
$email = $errors->first('email_address');
$messages = $errors->get('email_address'); // [] when absent
$hasError = $errors->has('email_address');
$map = $errors->toArray();
$json = json_encode($errors, JSON_THROW_ON_ERROR);
```

Message precedence is concrete `field.rule`, declared wildcard `pattern.rule`,
global `rule`, then the default English message. Attribute labels accept exact
fields or the declared wildcard pattern. Supported placeholders are
`:attribute`, `:field`, `:rule`, `:min`, `:max`, and `:length`.
`get()`/`first(field)`/`has()` use exact concrete paths, not wildcard queries.
The framework has locale-aware number/date formatting but no translation
service; supplied message/label maps provide the localization seam without
introducing a new translation subsystem.

Errors never automatically include submitted values. Messages are still plain,
untrusted output: escape them in HTML, including application-provided labels.
JSON serialization produces the field/message map (or `{}` when empty).

### Request, API, and exception integration

```php
$data = $request->validate([
    'name' => 'required|string|max:100',
    'email' => 'required|email',
]);
```

This validates the parsed **body only**, never query parameters or uploads
implicitly. JSON capture now converts nested JSON objects to associative
arrays for consistent dot-path validation. For query filters, explicitly build
an array from `$request->query()`.

On failure, `ValidationException` exposes a `ValidationErrors` object as
`$exception->errors`; it does not retain original input. API routes can call
`$request->validate()` directly: existing API exception handling sends HTTP 422
using PBreeze's established envelope, not a second response convention:

```json
{
  "error": {
    "status": 422,
    "message": "Check your input.",
    "details": {
      "email": ["The email field must be a valid email value."]
    }
  }
}
```

`Api::validate($request, $rules, $messages, $attributes)` uses the same engine
but retains `ApiValidationException` for compatibility. Web application
exception handling returns generic plain-text 422; form controllers can instead
use `fails()`/`errors()->toArray()` or catch `ValidationException` to render
field messages using their existing views. CSRF, authentication, rate limiting,
and authorization remain separate.

### Imports, DTOs/models, and custom rules

```php
$rows = \PBreeze\FileReader::iterate($csvPath);
foreach ($rows as $row) {
    $data = Validator::make($row, [
        'email' => 'required|email',
        'age' => 'nullable|integer|min:18',
    ])->validate();
    // Apply business authorization and persistence explicitly.
}
```

CSV values stay strings, so use `integer`/`numeric` when numeric bounds are
intended. Blank CSV cells are empty strings, not null. JSON/YAML reader arrays
work directly. XML's explicit node representation can be validated with matching
paths or mapped to a simpler record first. Map DTO/model input explicitly to
arrays; the validator never reflects into objects, invokes model hydration,
or mass-assigns fields.

Custom rules implement the small `PBreeze\RuleInterface`:

```php
final class VatNumberRule implements \PBreeze\RuleInterface
{
    public function validate(string $field, mixed $value, array $data): bool
    {
        return is_string($value) && preg_match('/^NL[0-9]{9}B[0-9]{2}$/D', $value) === 1;
    }

    public function message(): string
    {
        return 'The :attribute value is not a valid VAT number.';
    }
}

$data = Validator::make($input, [
    'vat_number' => ['required', new VatNumberRule()],
])->validate();
```

Explicitly require custom rule classes; there is no class discovery.
Custom rules run for existing fields (including empty values), but skip allowed
nullable nulls; use `required` for presence. They receive the concrete field,
unchanged value, and original array. Business/database failures propagate rather
than becoming successful defaults. A custom rule may accept an explicitly
injected `Database`/repository for future `unique`/`exists` checks; the core
never opens a connection. Enforce database uniqueness with database constraints
as well, since validation alone cannot prevent races. No built-in database rule
or SQL table name is inferred from untrusted input.

### Uploaded files

When bootstrap has loaded the existing upload abstraction, explicitly pass the
selected `UploadedFile` objects:

```php
$data = Validator::make(['document' => $request->file('document')], [
    'document' => 'required|file|mimes:pdf|max_size:2097152',
])->validate();
```

`file`, `mimes`, `mimetypes`, and `max_size` all reuse
`UploadedFile::validate()`: actual HTTP-upload provenance, nonempty content,
extension/MIME matching, supported image headers, and Office-package checks.
`mimes` is an explicit comma-separated list from the existing supported
extension allowlist. `mimetypes` accepts exact canonical detected MIME names,
not wildcard patterns or the browser's claimed type. `max_size` is **bytes**.
No file rule accepts a local path instead of an HTTP upload.

File rules without a `max_size` impose no additional validator byte cap
(PHP/web-server limits still apply). Supply a bound explicitly or use the
existing Uploads service for configured environment limits. Unsupported upload
formats remain unsupported. Content/type/size failures become field errors;
server-side upload failures propagate normally. Validated output retains the
same accepted object; storing/moving the file is a separate authorized operation.

### Boundaries

Validation is not sanitization, output escaping, authorization, CSRF, or
business correctness. Never interpolate validated values into SQL/shell code;
retain parameter binding and context-appropriate escaping.
Limits are 1000 field definitions, 32 path segments/512 bytes, and 100000
wildcard expansion steps. Bound incoming payloads/array sizes and trusted regex
complexity at their own boundaries. Configuration errors and operational
failures throw explicitly; they never become empty successful validation.

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

### Validation in existing forms

This section shows the backward-compatible static API used by existing forms.
For expanded rules, nullable fields, nested arrays, filtered output, and custom
rules, use the [Validation Component](#validation-component).

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

For browser routes, a session remembers data between visits, such as the
logged-in user and form security tokens. By default, PBreeze uses PHP's
configured session handler, normally local files. The browser stores only
a session-ID cookie, not the session's contents.

### Store sessions in the database

Use this when multiple application servers need to share login sessions,
or when you prefer database-managed storage. No extra PHP packages are needed;
the driver uses the same database configured by `DB_*`.

1. Keep `SESSION_DRIVER=native` while preparing the database.
2. Run `php breeze migrate` to create the `sessions` table. For production,
   back up first and use the reviewed `php breeze migrate --force` command.
3. Add these settings to your private `.env`, or edit their existing entries:

```ini
SESSION_DRIVER=database
SESSION_LIFETIME=7200
SESSION_LOCK_TIMEOUT=10
```

`SESSION_LIFETIME` is the idle timeout in **seconds**: 7200 is two hours.
Each successful session save refreshes it, even if the data did not change.
`SESSION_LOCK_TIMEOUT` is how many seconds a request waits for another
request using the same session to finish (1 to 60; default 10).
Native storage continues to use PHP's own expiry settings.

Restart/reload your web service if its deployment requires configuration
reloads. Sign in again and check that a row appears in `sessions`.
Changing storage drivers does not transfer sessions; existing users must
sign in again. To switch back, set `SESSION_DRIVER=native`. Do not roll back
the sessions migration while the database driver is active.

The table stores a SHA-256 hash of the session ID, a base64-encoded PHP
session payload, and `last_activity`/`expires_at` Unix timestamps.
**Base64 is not encryption.** Restrict database access and backups just as
you would for login data. Do not print payloads in admin pages or logs.
Session cookies remain HttpOnly, SameSite=Lax, and browser-session cookies;
the idle timeout does not turn them into persistent "remember me" cookies.
Keep `SESSION_SECURE_COOKIE=true` for production HTTPS.

SQLite, MySQL, PostgreSQL, and SQL Server are supported. Requests sharing
a session are locked to avoid lost updates during simultaneous form or
login requests. MySQL/PostgreSQL/SQL Server use connection-scoped advisory
locks on a dedicated connection; use direct or session-affine connections,
not transaction-pooled PostgreSQL connections. SQL Server accounts need
permission to use `sp_getapplock`/`sp_releaseapplock`.
SQLite uses up to 256 private lock files in `STORAGE_PATH/sessions`;
all processes using the same SQLite database must share that storage path.
Use a server database for multi-node deployments, not SQLite on a network share.
Do not delete lock files while requests are running.

Expired sessions are rejected immediately on access. PHP's
`session.gc_probability` / `session.gc_divisor` schedule removal of expired
rows. If your PHP installation disables automatic cleanup, schedule a trusted
CLI script that loads `bootstrap.php`, calls `$app->session->start()`,
then `session_gc()`, and finally `$app->session->close()`.
This removes expired rows only; it is not a command for logging everyone out.

The framework saves sessions before sending responses, so a failed database
write returns an error instead of appearing successful. Database outages
and lock timeouts do not silently fall back to local files. Stateless API
requests and health/readiness probes still do not start sessions; readiness
checks the sessions table when database storage is selected.

### Protect browser forms with CSRF

All browser-route POST, PUT, PATCH, and DELETE requests require a session token.
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
intentionally no default browser-route CSRF exceptions. Stateless API routes
use bearer tokens rather than browser sessions or CSRF tokens.
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
| `GET/POST /verify-email` | Verify an email address and establish the owner's password with a token |
| `GET /email/verify` | Verification notice for signed-in users |
| `POST /email/verification-notification` | Request a new verification email |
| `GET /account` | Example page requiring login and email verification |

After registration, sign in; unverified accounts are sent to the verification page.
Open the verification link and **choose and confirm the password to use from
then on**. The POST confirmation replaces the registration password, increments
the account's session version, and revokes existing authentication and API
tokens. All earlier login sessions become invalid, and the requester is signed
out. This prevents someone who registered your email with their own password
from retaining access after you verify ownership. Sign in again with your
chosen password; verification does not sign you in automatically.

A mail scanner opening the link with GET or HEAD does not consume it.
Missing or invalid passwords do not consume a valid link. Invalid/expired tokens return 400, validation errors 422,
too many attempts 429, and missing/incorrect CSRF 419.

**Verification API upgrade:** custom callers must now pass password and
password confirmation as the third argument:

```php
$app->auth->verifyEmail($request->input('token'), $request->clientIp(), $request->all());
```

Token-only calls intentionally fail. Update custom verification forms and
controllers together; existing unexpired links can still be used with the
updated form. Already-verified accounts are not automatically remediated:
if pre-registration abuse may have occurred, investigate affected accounts,
revoke their sessions/tokens, and have their email owners establish new
passwords through a trusted recovery process.

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

## PostgreSQL

PostgreSQL is supported through the native `pdo_pgsql` PHP extension and libpq
on Windows and Linux, without Composer. Enable the extension in both CLI and
web PHP. The shipped PostgreSQL schema uses identity columns; use a maintained
PostgreSQL release. CI is configured to exercise PostgreSQL 16.

Create a UTF-8 database and a dedicated login yourself. Use a least-privilege
application role; migrations also need schema creation/drop permissions.
Keep the same trusted `search_path` for CLI and web connections, normally
`public`. Do not allow untrusted users to create objects in that schema.
Update the existing `.env` entries rather than adding duplicate keys:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=postgres.example.test
DB_PORT=5432
DB_DATABASE=pbreeze
DB_USERNAME=pbreeze
DB_PASSWORD="your own password"
DB_SSLMODE=verify-full
DB_SSLROOTCERT=/path/to/trusted-root.crt
```

`DB_SSLROOTCERT` is optional: empty uses libpq's default root-certificate lookup.
On Windows, use forward-slash paths such as `C:/certificates/root.crt`.
The default `verify-full` mode requires TLS, a trusted certificate chain, and a
certificate matching `DB_HOST`. Supported modes are `disable`, `allow`, `prefer`,
`require`, `verify-ca`, and `verify-full`. Prefer `verify-full` in production.
For an isolated localhost server without TLS only, explicitly use
`DB_SSLMODE=disable`; do not weaken production TLS to fix a certificate problem.
SQL Server's `DB_ENCRYPT`/`DB_TRUST_SERVER_CERTIFICATE` settings do not affect PostgreSQL.

Connections use TCP with a hostname or IPv4 address, not Unix socket paths or
arbitrary DSN fragments. Set `DB_PORT=5432`: the example file explicitly uses
3306 for MySQL. If the variable is entirely absent, the configured driver
selects the appropriate default. Connection setup has a 15-second timeout,
UTF-8 client encoding, and UTC session time zone. Database names accepted by
this configuration are 1..63 ASCII letters, digits, underscores, dots, or
hyphens, beginning with a letter, digit, or underscore.

```sh
php breeze migrate
php breeze migrate:status
php breeze db:seed
```

The migration runner uses same-named overrides in `database/migrations/pgsql/`
for the four shipped migrations. Existing top-level migration files and their
history names are unchanged. Custom migrations must still have a top-level
file; optionally supply a same-named PostgreSQL override or write driver-aware
SQL. Do not edit applied migrations. Switching drivers does not transfer data.

PostgreSQL migrations and bookkeeping run transactionally. A database-scoped
session advisory lock prevents overlapping PBreeze migration processes,
in addition to the local file lock. Operations PostgreSQL prohibits inside a
transaction (for example `CREATE INDEX CONCURRENTLY`) cannot be used in normal
migrations; plan those separately with appropriate operational safeguards.

The query builder uses double-quoted simple identifiers, `LIMIT/OFFSET`, bound
values, and `INSERT ... RETURNING` for generated keys. Custom incrementing and
nonincrementing ORM keys work through the same APIs as the other databases.
Use explicit stable ordering for pagination.

```php
$id = $app->db->table('notes')->insert(['title' => 'A PostgreSQL note']);
$note = $app->db->table('notes')->findOrFail($id);
$page = $app->db->table('notes')->orderBy('id')->paginate(20, 1);
```

Raw SQL passed to PDO or `execute()` is not translated. Use PostgreSQL SQL in
custom migrations, seeders, and queries. Registration and IMAP deduplication
use `ON CONFLICT` so expected duplicates do not abort surrounding transactions.
PostgreSQL text cannot contain NUL bytes; encode binary application data or use
an application-specific `bytea` binding path rather than storing it as text.

### PostgreSQL Validation

The native suite includes recording-PDO PostgreSQL dialect tests. A separate
live integration test checks schema/rollback, seeds, Unicode CRUD, ORM,
pagination, transactions, duplicate registration, verification/password reset,
API tokens, rate limits, email queue transitions, and duplicate IMAP imports
without sending SMTP.

Provision an **empty, disposable** database named `pbreeze_test_*`, then set
these process environment variables in PowerShell, your Linux shell, or CI:
`PGSQL_TEST_DATABASE`, `PGSQL_TEST_HOST`, `PGSQL_TEST_PORT` (default 5432),
`PGSQL_TEST_USERNAME`, `PGSQL_TEST_PASSWORD`, and
`PGSQL_TEST_ALLOW_DESTRUCTIVE=true`. Optional `PGSQL_TEST_SSLMODE` defaults to
`verify-full`; `PGSQL_TEST_SSLROOTCERT` supplies a CA file. Use `disable` only
for an isolated local non-TLS test server. These variables are not read from `.env`.

```sh
php tests/pgsql-integration.php
```

The script refuses a nonempty database or a name outside `pbreeze_test_*`.
It creates and removes framework tables. CI has a dedicated PostgreSQL 16
service job on Ubuntu with PHP 8.3/8.5; its local isolated connection disables
TLS explicitly. A configured CI job is not proof of a successful run:
validate your actual PostgreSQL/PHP/libpq/OS combination before deployment.

## Microsoft SQL Server

SQL Server 2012+ syntax is supported through Microsoft's native **PDO_SQLSRV**
PHP extension on Windows and Linux. Install a supported Microsoft ODBC Driver
and a PDO_SQLSRV build matching your PHP version, architecture, and thread-safety
mode. There is no Composer dependency. Check availability with:

```sh
php -r "print_r(PDO::getAvailableDrivers());"
```

The list must contain `sqlsrv`. Check the web server's PHP installation too.
See Microsoft's [PHP driver installation guide](https://learn.microsoft.com/en-us/sql/connect/php/loading-the-php-sql-driver)
for platform prerequisites and supported PHP/driver versions.
Do not assume every PDO_SQLSRV release supports every PHP release.

Create the database and a dedicated SQL authentication login/user yourself.
Select a least-privilege application account; migrations need additional DDL
permissions and application-lock permission. Use the user's default schema
consistently, normally `dbo`, for both web and CLI connections.
Then update the existing settings in `.env`, without adding duplicate keys:

```dotenv
DB_CONNECTION=sqlsrv
DB_HOST=sql.example.test
DB_PORT=1433
DB_DATABASE=pbreeze
DB_USERNAME=pbreeze
DB_PASSWORD="your own password"
DB_ENCRYPT=true
DB_TRUST_SERVER_CERTIFICATE=false
```

Use a hostname or IPv4 address and an explicit TCP port. Named instance suffixes,
arbitrary DSN fragments, integrated Windows authentication, and Azure-specific
authentication modes are not exposed by this configuration. When `DB_PORT` is
absent it defaults to 1433 for `sqlsrv`, but the example file explicitly sets
3306 for MySQL: change that value when switching drivers.
The connection has a 15-second login timeout.

Encryption is enabled and certificate verification is required by default.
Install a trusted server certificate whose name matches `DB_HOST`.
`DB_TRUST_SERVER_CERTIFICATE=true` bypasses verification and should be restricted
to isolated local testing, not used as a production certificate fix.

Run the normal commands against the intended database:

```sh
php breeze migrate
php breeze migrate:status
php breeze db:seed
```

The migration runner selects same-named overrides in
`database/migrations/sqlsrv/` for the four shipped migrations. Original
SQLite/MySQL migration files and migration history names remain unchanged.
Custom migrations still live in `database/migrations/`; implement driver-aware
SQL there, or provide a same-named SQL Server override. An override without a
corresponding top-level migration is not discovered. Do not edit applied
migrations; create new ones for subsequent schema changes.
Switching drivers does not transfer an existing database or its data.

SQL Server migrations use transactional DDL and a database application lock
alongside the local file lock. The supplied schema uses `BIGINT IDENTITY`,
`NVARCHAR` for Unicode text, `DATETIME2` for note dates, and a filtered unique
index so multiple outgoing emails can have a null IMAP source key.
SQL Server `TIMESTAMP` is a rowversion, not a date/time column.

The query builder uses square-bracket identifiers, `TOP` for limits and
`OFFSET/FETCH` for paging. Use an explicit stable order for pagination.
Inserts retrieve IDs with `OUTPUT INSERTED`, not session-wide `@@IDENTITY`.
An incrementing model can declare a custom primary key; a nonincrementing model
does not request an identity result.

```php
$id = $app->db->table('notes')->insert(['title' => 'Unicode: café']);
$page = $app->db->table('notes')->orderBy('id')->paginate(20, 1);
// For a custom identity column:
$id = $app->db->table('articles')->insert(['title' => 'Hello'], identity: 'article_id');
// For a table without an identity result, insert() returns 0:
$app->db->table('settings')->insert(['code' => 'theme', 'value' => 'light'], identity: null);
```

Raw SQL passed to `execute()` or PDO is not translated. Write T-SQL for raw
queries, migrations, and seeders when this driver is selected. Driver-specific
limits still apply, including SQL Server's 2100-parameter ceiling.
The ID-returning insert path does not support enabled INSERT triggers on the
target table; use an application-specific, audited OUTPUT INTO/stored-procedure
path if such triggers are required.

### SQL Server Validation

The standard native test suite includes recording-PDO SQL Server dialect tests;
it does not connect to a real SQL Server. For a live run, provision an **empty,
disposable** database whose name begins with `pbreeze_test_`. Set these process
environment variables through PowerShell, your Linux shell, or CI:
`SQLSRV_TEST_DATABASE`, `SQLSRV_TEST_HOST`, `SQLSRV_TEST_PORT` (default 1433),
`SQLSRV_TEST_USERNAME`, `SQLSRV_TEST_PASSWORD`, and
`SQLSRV_TEST_ALLOW_DESTRUCTIVE=true`. Do not put real application credentials
in the test configuration. These variables are read directly from the process,
not from `.env`.

Only for a local self-signed test server, set
`SQLSRV_TEST_TRUST_SERVER_CERTIFICATE=true`. Encryption remains enabled.
Run:

```sh
php tests/sqlsrv-integration.php
```

The script refuses a nonempty database or a name outside `pbreeze_test_*`.
It creates and removes framework tables and checks migrations/rollback,
repeatable seeds, Unicode CRUD, ORM, pagination, transactions, authentication,
password reset, API tokens, rate limits, and database mail transitions.
No SMTP connection is made. Run this against your supported SQL Server/PHP/ODBC
combination before deployment; recording tests are not live compatibility proof.

## Native ORM and Query Builder

The ORM consists of `framework/Query.php` and `framework/Model.php`, explicitly
loaded in the bootstrap. Each query and model receives the existing
`Database` instance. There are no packages, autoloading, global connections,
schema inspection, or automatic migrations. The SQL is designed for SQLite,
MySQL, SQL Server, and PostgreSQL; the existing cross-platform PDO configuration remains authoritative.

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

SQLite, SQL Server, and PostgreSQL run each migration and its registration in a single transaction.
MySQL DDL causes implicit commits: a failed migration may already be
partially applied. Back up before changes and deliberately recover a
partial migration before running again. Write driver-dependent
SQL where needed; the example migration demonstrates this.

A local file lock prevents concurrent migrations from the same
project directory. MySQL, SQL Server, and PostgreSQL also use a database-wide advisory lock between
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

### Deployment checks and readiness

The framework provides production safeguards, not automatic production
configuration. Local `.env` values are intentionally preserved during upgrades.
Run these commands as the deployment/service identity after configuring the
environment and before routing traffic to the new release:

```sh
php breeze storage:prepare
php breeze migrate --force
php breeze doctor
```

Back up the database first and use backward-compatible migrations during rolling
releases. `storage:prepare` creates only the private storage root and its `logs`,
`uploads`, and `views` children. It does not move files, adjust existing
ownership, configure sessions, or change database contents.

`doctor` prints `PASS`, `WARN`, or `FAIL` for production flags, trusted HTTPS
origin, extensions, private storage, database/schema/migration readiness,
encryption, mail configuration, and operational concerns. Its exit code is 1
when any check fails and 0 otherwise; review warnings too. It does not create
directories, databases, migration tables, or records, send email, or call AI.
An SMTP configuration warning is not evidence that real mail delivery works.
It checks the CLI PHP installation; verify the web/FPM installation separately.
If bootstrap configuration is invalid, the command exits with the normal
startup error before diagnostics can run.

- `GET /health`: lightweight process liveness only; a 200 does not prove that
  the database is usable.
- `GET /ready`: 200 with `{"status":"ready"}` only when private storage exists
  and is readable/writable, the existing database accepts reads, bundled tables
  exist, and applied migration names match the deployed files. Otherwise it
  returns 503 with `{"status":"not_ready"}` and logs safe failure metadata.
  It never creates a missing SQLite database or runs migrations.
- Exact GET/HEAD requests to those two probe paths do not start a session or
  issue a session cookie. HEAD responses have an empty body.

Restrict probes to your load balancer/monitoring network at the web server.
Readiness performs database reads, so set a sensible polling interval and edge
rate limits. It does not test SMTP/AI availability, usable free disk space,
backup recovery, real writes, or application-specific dependencies. Database
network/query deadlines must also be set in the database/server/PHP stack.
The framework retains provider/driver-specific timeout behavior rather than
claiming a universal probe deadline.

### Persistent storage across releases

```dotenv
STORAGE_PATH=/srv/pbreeze-data
```

On Windows, use an absolute native path such as `C:/PBreezeData`. Default
`storage` remains project-relative. The configured location contains private
`logs/`, `uploads/`, `views/`, and `migrations.lock`; `$app->storagePath` exposes
the resolved path. The default configured services and CLI use this location.
Standalone Logger/Uploads/Migrator constructors accept an optional storagePath;
standalone custom View caches remain caller-managed.

Set a real directory, not a symlink. Public paths, ancestors of `public/`,
dot segments (`.`/`..`), stream wrappers, and symlink ancestors are rejected.
Configure an absolute shared data directory directly rather than symlinking
each release's `storage/`. Keep the code read-only for the web identity.
Existing directories are not chmodded or chowned by `storage:prepare`; restrict
POSIX permissions or inherited NTFS ACLs explicitly.

**Changing STORAGE_PATH does not move existing data.** Stop or drain writers,
back up and copy uploads and required logs to the new private directory,
preserve ownership/ACLs, deploy consistent settings to web and workers, and
verify downloads before switching traffic. Compiled views may be regenerated.
Do not run `view:clear` against a cache actively used by other releases; prefer
leaving content-addressed cache entries until old workers have drained.

SQLite is independent: set `DB_DATABASE` to an absolute persistent file path
outside the release and public directories. Do not copy a live SQLite database
as an ordinary file without a consistent backup procedure. PHP session storage
is also independent: configure the web SAPI's `session.save_handler` and
`session.save_path`, permissions, and retention. Multiple nodes need shared
sessions or appropriate sticky routing and common upload storage. SQLite on
network filesystems is not supported.

### MySQL verified TLS: upgrade requirement

MySQL now defaults to verified TLS. Before upgrading a MySQL installation,
configure:

```dotenv
DB_MYSQL_TLS=required
DB_MYSQL_SSL_CA=/etc/ssl/certs/mysql-ca.pem
```

The CA must be a readable local certificate bundle and the server certificate
must match `DB_HOST`. Use a TCP hostname/address; `localhost` may select a Unix
socket instead of TLS. Native PDO verifies the certificate and the framework
rejects connections without a negotiated TLS cipher. No plaintext fallback
is attempted. Windows certificate paths can use forward slashes.

Only isolated local development/testing can explicitly set
`DB_MYSQL_TLS=disabled`, with `DB_HOST=localhost` or `127.0.0.1` and
`APP_ENV` other than `production`. Production always rejects that opt-out.
Programmatic Database settings use `tls` and `ssl_ca`; standalone production
instances must pass `production: true`. PostgreSQL and SQL Server keep their
existing TLS settings.

### Request limits and reverse proxies

Every `application/json` request, including non-API routes, now has a fixed
1 MiB raw-body cap. All API bodies retain that cap. Bounded reads enforce it
even without Content-Length; oversized input returns 413. This is separate
from the 2 MiB per-upload default: set PHP/web-server multipart limits with
appropriate overhead. Edge limits must reject oversized traffic before it
reaches PHP, which may parse some request types first.

Client identity still uses `REMOTE_ADDR`, never arbitrary forwarded headers.
Configure Apache `mod_remoteip` or Nginx `real_ip` using **only your trusted
proxy CIDRs**, and block direct traffic bypassing the proxy. Otherwise clients
behind that proxy share application rate limits. TLS termination also needs
`SESSION_SECURE_COOKIE=true` and HTTPS redirection/HSTS at the edge.

### Operations and release gate

1. Use `APP_ENV=production`, `APP_DEBUG=false`, a real HTTPS `APP_URL`, and a
   valid application key before using encryption. Keep the key backed up
   separately from encrypted data. Disable or edge-protect API documentation.
2. Verify sender authorization and real SMTP delivery in staging. Run
   `php breeze mail:work 50` periodically with cron/systemd timers on Linux or
   Task Scheduler on Windows. The command processes one batch and exits; it
   is not a permanent worker. Prevent overlapping runs, alert on nonzero exits,
   and monitor queued age plus failed/uncertain/sending states. Recover uncertain
   mail manually to avoid duplicate delivery; never blindly force retries.
3. Keep consistent backups of the database, private uploads, and encryption
   keys. Perform and document a restore rehearsal on an isolated environment;
   verify ownership, downloads, account recovery, and encrypted records.
4. Monitor 5xx/503 responses, request latency, disk space, log write failures,
   queue backlog, certificate expiration, and database connection saturation.
   Export operational logs if the default five-day local retention is too short.
5. Test the actual PHP-FPM/Apache/IIS configuration and selected database,
   enforce application/per-record authorization, and load-test expected traffic.
   Set realistic process, socket, request-body, AI, and proxy timeouts.

The CI definition includes native Ubuntu/Windows tests and separate PostgreSQL
and MySQL integration jobs. SQL Server still requires its opt-in live test on
a suitable server. CI definitions are not proof that the jobs have executed
successfully: require green results and your staging/recovery checks before
release.

For a disposable empty MySQL database named `pbreeze_test_*`, set process
variables `MYSQL_TEST_DATABASE`, `MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`,
`MYSQL_TEST_USERNAME`, `MYSQL_TEST_PASSWORD`, `MYSQL_TEST_TLS`,
`MYSQL_TEST_SSL_CA`, and `MYSQL_TEST_ALLOW_DESTRUCTIVE=true`, then run:

```sh
php tests/mysql-integration.php
```

These settings are process environment variables, not `.env` values. TLS
defaults to required; `disabled` is only for a local disposable test server.
The script checks emptiness, creates/test-drops its schema, and sends no SMTP.

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

Use the maintained [Web Server Deployment](#web-server-deployment) templates
for Apache, Nginx, FrankenPHP, or IIS rather than ad hoc rewrite rules.
Configure HTTPS for the actual service. Never put confidential files or
uploads containing executable code in `public/`.

## Native Scheduling and Background Jobs

PBreeze's scheduler decides **when** work runs inside a long-running PHP
process. It does not need cron or Windows Task Scheduler. Durable jobs use the
application database, not Redis, Composer packages, or serialized PHP objects.

### Quick Start

Configure the application's existing database first. Then run:

```sh
php breeze scheduler:install
php breeze migrate
```

The install command checks prerequisites and explains setup; it does not change
your database or overwrite application files. In production, review and back up
the database before running `php breeze migrate --force`.

Define tasks in `routes/schedule.php`, which returns a registration callback:

```php
use PBreeze\Application;
use PBreeze\Scheduler\Schedule;

return static function (Schedule $schedule, Application $app): void {
    $schedule->command('mail:work', ['50'])
        ->everyMinute()
        ->withoutOverlapping();
};
```

Start the scheduler in a dedicated terminal:

```sh
php breeze scheduler:work
```

The command stays running; this is expected. Use another terminal for your web
server. `php pbreeze scheduler:work` is an alias for the same CLI.
Direct scheduled tasks use the `default` queue unless explicitly changed;
changing `QUEUE_DEFAULT` only changes the ordinary queue worker's default
selection, not the scheduler's.

For schedules using `->queue('billing')`, also start a queue worker:

```sh
php breeze queue:work --queue=billing
```

### Defining Tasks and Frequencies

Application handlers are trusted code. Explicitly require their PHP files in
the registration file; PBreeze does not discover or autoload classes.

```php
$schedule->task('cleanup')
    ->everyMinutes(5)
    ->run(static function (): void {
        CleanupService::run();
    });

$schedule->task('invoice-processing')
    ->dailyAt('02:00')
    ->timezone('Europe/Amsterdam')
    ->withoutOverlapping()
    ->runMissed(limit: 1)
    ->retries(3)
    ->retryAfter(60)
    ->timeout(600)
    ->queue('billing')
    ->run([InvoiceService::class, 'process']);
```

Frequencies include `everySecond()`, `everySeconds(10)`, `everyMinute()`,
`everyMinutes(5)`, `hourly()`, `everyHours(2)`, `daily()`, `dailyAt('02:00')`,
`weekly()`, `weeklyOn(1, '08:00')`, and `monthly()`. Weekly days use ISO
numbering: Monday is 1 and Sunday is 7. Monthly means the first day of the month
at midnight. Interval schedules use a stable UTC anchor; calendar schedules
use the task's timezone.

Use unique, stable task names. Do not rename a task or change a pending job's
handler without reviewing persisted work. Definition fingerprints protect
against silently running queued occurrences with incompatible definitions.

### Registered Jobs and JSON Payloads

`routes/jobs.php` returns explicitly registered job types. Validators reject
bad payloads before dispatch and again before execution:

```php
return [
    'invoice.generate.v1' => [
        'validate' => static function (array $payload): void {
            if (array_keys($payload) !== ['invoice_id']
                || !is_int($payload['invoice_id']) || $payload['invoice_id'] < 1) {
                throw new InvalidArgumentException('A positive invoice_id is required.');
            }
        },
        'handle' => static function (
            array $payload,
            \PBreeze\Queue\ExecutionContext $context,
        ) use ($app): void {
            $context->checkpoint();
            // Require InvoiceService explicitly in this registry before use.
            InvoiceService::generate($payload['invoice_id'], $context->idempotencyKey());
        },
    ],
];
```

Queue a job through the application service:

```php
$id = $app->queue->dispatch(
    'invoice.generate.v1',
    ['invoice_id' => 123],
    queue: 'billing',
    priority: 2,
    tries: 3,
    backoff: [60, 300],
    timeout: 300,
    unique: 'invoice:123',
);
```

Payloads are bounded JSON data, not executable code. Do not put credentials,
large documents, or unnecessary personal data in them; store a record ID and
retrieve authorized data in the handler. Queue database access is privileged.
Never expose dispatch, retry, or monitoring services as unauthenticated routes.

`runNow($type, $payload)` invokes a registered handler in the caller. It is
synchronous and does not provide durable delivery, process isolation, or worker
recovery. Prefer `dispatch()` for background work.

For bounded exponential delays, use
`\PBreeze\Queue\Queue::exponentialBackoff(30, 2, 300)` as the `backoff` argument.
This returns `[30, 60]`, suitable for the two retries of a three-attempt job.
Unique dispatch keys deduplicate active work of the same registered job type.

### Claims, Overlaps, and Multiple Servers

Each scheduled occurrence has one database identity. Atomic conditional claims
ensure only one worker owns that occurrence at a time. Every completion checks
the claim credential; a stale worker cannot acknowledge a newer claim.

`withoutOverlapping()` additionally excludes simultaneous execution of
different occurrences of the same task. It coalesces missed work into at most
one latest occurrence and suppresses new occurrences while any earlier one is
pending, reserved, running, or uncertain. `onOneServer()` explicitly declares
the existing cluster-wide occurrence guarantee; omitting it does not cause
one execution per server.

All cooperating nodes must share the same application database, synchronize
their system clocks, and deploy compatible handler registrations.
SQLite is for local/single-host operation,
not independent database files on multiple servers or a shared network drive.

The bundled background-work migration separates definition/runtime/history:

| Table | Responsibility |
|---|---|
| `pbreeze_scheduler_tasks` | Definition fingerprint and durable schedule cursor |
| `scheduled_occurrences` | Unique task/time occurrence identity, retained after job-history pruning |
| `background_jobs` | Registered job type, JSON payload, queue, retry policy, ownership, and current state |
| `background_attempts` | Individual execution attempts and safe outcome metadata |
| `background_workers` | Worker identity, heartbeat, stop request, and state |
| `background_locks` | Uniqueness and overlap ownership |

This uses one additional table compared with keeping occurrence uniqueness only
on job rows: retained occurrence tombstones prevent history pruning from
accidentally allowing an old occurrence to be created again. Schedule handlers
remain in application code, never in these tables.

### Retries, Failure, and Crash Recovery

`retries(3)` means three additional scheduled attempts, or four total.
Scheduled tasks default to zero additional retries and a 300-second timeout.
Queue `tries: 3` and worker `--tries=3` mean three total attempts.
Backoff controls when a failed attempt becomes eligible again. A job exception
does not stop the whole worker.

Use `PBreeze\Queue\RetryableJobFailure` to declare a known retryable failure,
or `PBreeze\Queue\JobFailure` to declare a known terminal failure. Unexpected
exceptions are treated conservatively because earlier side effects may already
have happened. For an idempotent job that may safely repeat, explicitly pass
`retrySafe: true` when dispatching, or use `->retrySafe()` on a scheduled task.
This is an application assertion, not an automatic proof of idempotency.

States distinguish pending, reserved, running, completed, failed, and
**uncertain** work. If application work succeeds but its acknowledgement is
lost, PBreeze cannot know whether it is safe to repeat that work. An expired
heartbeat is not proof that the old PHP process stopped.

Do not assume exactly-once execution. Use stable idempotency keys and unique
business constraints. For external APIs, pass their supported idempotency key.
For atomic application updates plus enqueueing, dispatch on the same connection
inside your database transaction; PBreeze does not commit the caller's
transaction. External effects require application-specific outbox/idempotency
handling.

```sh
php breeze queue:failed
php breeze queue:retry JOB_ID
php breeze queue:retry-all
```

Retry-all processes at most 100 confirmed failures per call and excludes
uncertain work. Production retries require `--force`.

For uncertain work, first investigate external effects and stop/fence the old
execution. Then make an explicit operator decision:

```sh
php breeze queue:resolve JOB_ID retry --force
php breeze queue:resolve JOB_ID complete --force
```

The force flag is an operator assertion, not proof that a remote process died.
Never release overlap/uniqueness protection merely because a clock expired.
The existing email queue keeps its separate uncertain SMTP-delivery safeguards;
scheduling `mail:work` does not automatically resend uncertain email.

### Timezones, DST, and Missed Schedules

Persistence uses UTC timestamps. Calendar tasks interpret their configured
IANA timezone, independently of the server's display timezone.

At spring-forward, a nonexistent local time is skipped. At fall-back, a repeated
local time runs only at its first occurrence. Elapsed-time intervals remain
anchored in UTC.

`skipMissed()` is the default: only work within the configured polling tolerance
is materialized. `runMissed(limit: 1)` catches up the latest missed occurrence.
Larger catch-up is bounded by the configured ceiling; a restart does not
silently replay years of historical work. A newly registered task starts from
the current schedule boundary, not an invented historical backlog.

### Worker Management and Timeouts

```sh
php breeze scheduler:list
php breeze scheduler:status
php breeze scheduler:run
php breeze queue:status
php breeze workers:list
php breeze workers:stop WORKER_ID
```

Workers register random identities plus host/PID information and update
heartbeats. Stale workers are suspected offline, not conclusively dead.
Database-backed stop requests work without Unix signals. The worker listing
shows the newest 100 registrations by default.

```sh
php breeze queue:work --queue=billing,default --sleep=1 --tries=3
php breeze queue:work --max-jobs=100 --max-time=3600
php breeze queue:work --once
```

Lifetime/job-count limits are checked between executions; they do not
interrupt arbitrary running PHP. `--once` exits after at most one eligible
execution, including when the selected queue is empty.

Priorities are integers: 0 low, 1 normal, 2 high, 3 critical. Waiting-time aging
prevents fresh high-priority jobs from permanently starving older low-priority
jobs. Workers only claim their selected queues.

Workers use monitored child PHP processes, so the parent can maintain heartbeats.
The native `proc_open()` function must be enabled. Timeouts request cooperative
cancellation and quarantine uncertain work; they do not forcibly terminate
arbitrary PHP or undo side effects. An uncooperative child can keep its parent
occupied until it exits. Use additional workers for other work and a supervisor
for hard process deadlines. Handlers should check their execution context between
operations and configure network/database timeouts of their own.

### Production Deployment: Linux, Windows, and Docker

PBreeze owns scheduling logic while its processes are alive. It cannot keep
itself alive after a machine reboot, crash, or container termination.

- **Linux:** supervise separate scheduler and queue processes with systemd or
  Supervisor. Use the application directory as working directory and an
  unprivileged service identity. Adapt the supplied
  [systemd template](deploy/pbreeze@.service), then enable
  `pbreeze@scheduler` and `pbreeze@queue`. Replace its user, paths, and shutdown
  timeout for your deployment; never start services before configuring and
  migrating the application database.
- **Windows:** use a Windows service wrapper to run the same PHP CLI commands,
  with an explicit working directory, PHP path, database access, and private
  storage ACLs.
- **Docker:** use separate scheduler and worker services with restart policies,
  the same deployed code/configuration, and sufficient shutdown grace time.
- **macOS:** run the CLI directly for development or supervise it with launchd.

These are process-lifecycle integrations, not cron replacements containing your
application's timetable. No cron or Windows Task Scheduler is required.
Use a PHP CLI configuration with appropriate memory limits and extension
settings. Recycle long-lived workers during deployments so they load new code.

For Docker Compose, adapt this example to an **existing application image**
with PHP CLI, the required native extensions, deployed code, and database
configuration. It is not a Docker build recipe:

```yaml
services:
  scheduler:
    image: your-pbreeze-application:release
    working_dir: /app
    command: ["php", "breeze", "scheduler:work"]
    restart: unless-stopped
    stop_grace_period: 11m
    env_file: .env
  queue:
    image: your-pbreeze-application:release
    working_dir: /app
    command: ["php", "breeze", "queue:work", "--queue=default,billing"]
    restart: unless-stopped
    stop_grace_period: 11m
    env_file: .env
```

Use the same server database for both services and run migrations as a separate
deployment step. Supply protected persistent storage for application data/logs.
Tune the example's shutdown grace period to exceed your longest job timeout;
an orchestrator may still forcibly terminate processes when that period ends.

### Monitoring, Retention, and Troubleshooting

Queue/scheduler status commands show identifiers and safe runtime metadata,
not payloads. Application logs contain structured lifecycle information;
exception messages and handler output are not assumed safe to expose.

For trusted programmatic monitoring, use `$app->queue->store()->status()` or
`jobs('pending', 50)`, which omit payloads and claim credentials. Register
listeners on `$app->worker->events->on('job.finished', $listener)` before
starting the worker. Listeners receive safe metadata arrays. Available events
are `worker.started`, `worker.stopped`, `job.claimed`, `job.started`, and
`job.finished`; listener failures are logged without undoing job acknowledgement.
Child-process events such as `job.started` require listeners registered during
trusted child bootstrap; in-memory parent listeners are not copied to children.

```sh
php breeze queue:prune --days=30 --limit=100
```

Pruning removes bounded terminal history, not active or uncertain work.
Keep occurrence deduplication records long enough for recovery; do not manually
truncate live tables.

| Symptom | Check |
|---|---|
| Worker exits at startup | CLI PHP version, enabled `proc_open()`, database schema and private storage permissions |
| Nothing runs | Schedule registry, timezone, selected queue, worker heartbeat, availability/backoff |
| Job type rejected | Explicit registry loading, validator, versioned job identifier |
| Pending occurrence rejected after deployment | Definition fingerprint and deployed handler compatibility |
| Work is uncertain | Investigate acknowledgement loss, old processes, and external effects before resolving |
| Database repeatedly fails | Check connectivity/TLS/schema; infrastructure failures stop the worker, so configure supervisor restart delays rather than a tight restart loop |
| Billing runs twice after recovery | Application idempotency/business constraints; queue delivery is not exactly-once |

## Tests

### Create your own tests

Generate a test from the project root:

```sh
php breeze make:test ContactTest
```

This creates `tests/application/ContactTest.php`. If you supply `Contact`
instead, the CLI adds the `Test` suffix for you. Names must start with an
uppercase ASCII letter and contain only letters and digits. Existing files
are never overwritten; `--force` is not supported.

Open `tests/application.php` and add the registration line printed by the
command. Keep any existing registrations. Do not add a second `<?php` tag:

```php
require __DIR__ . '/application/ContactTest.php';
```

There is no automatic file discovery: an unregistered file will not run.
The generator does not edit the registry for you. If an older project does
not have `tests/application.php`, create it with an opening `<?php` tag and
the require line above; its test runner must include the application registry
as shown in the current `tests/run.php`.

The generated placeholder deliberately fails so an unfinished test cannot
appear to pass. Replace its entire contents with a real check, for example:

```php
<?php
declare(strict_types=1);

test('Contact names must contain at least two characters', static function (): void {
    $errors = \PBreeze\Validator::errors(['name' => 'A'], [
        'name' => 'required|string|min:2',
    ]);
    check(isset($errors['name']), 'A one-character name should be rejected.');
    same([], \PBreeze\Validator::errors(['name' => 'Ada'], [
        'name' => 'required|string|min:2',
    ]));
});
```

Run all registered tests, including the framework suite:

```sh
php breeze test
```

Look for your test's `OK` or `FAIL` line and the final pass/fail summary.
Any failed test makes the command exit with code 1. The native helpers are
`check($condition, $message)` for a true condition,
`same($expected, $actual)` for strict equality, and
`raises($callback, ExceptionClass::class)` for an expected exception.
Test files are loaded by the runner, not executed directly.

Write each test as a `test('description', static function (): void { ... });`
block. Load application classes explicitly with `require_once` as needed.
Do not use the real application's database, mail delivery, or AI service
merely to run a test; create isolated fixtures and clean them up in `finally`.
No PHPUnit, Composer packages, test filtering, or separate feature/unit
runner is introduced by this generator.

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
AI tests exercise request mapping, history, streamed SSE framing, errors,
truncation, callback cancellation, and native HTTPS through isolated local TLS
fixtures. They use no real provider credentials, call no external AI service,
and incur no API charges.
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
MySQL has an opt-in disposable-database integration script and a CI service
job described in the production chapter. Native local tests do not connect to
a live MySQL server; require successful integration results for your deployment.
SQL Server has recording-PDO dialect tests and a separate, explicitly enabled
live integration script described in its chapter.
PostgreSQL likewise has recording-PDO tests, an opt-in live test script, and a
dedicated CI service job described above.
