<?php
declare(strict_types=1);

$commands = [
    'init' => ['Create .env from .env.example only if absent, with private platform-appropriate permissions. Existing configuration is preserved; no database or application key is created.', 'php breeze init'],
    'key:generate' => ['Print a fresh APP_KEY assignment. Copy it securely to .env yourself; files are not changed. Replacing a key makes older encrypted data unreadable unless you plan a migration.', 'php breeze key:generate'],
    'serve' => ['Start the native localhost development server, default port 8000, or a supplied 1024..65535 port. Runs in the foreground until stopped. Never use as a production server.', 'php breeze serve 8080'],
    'routes' => ['Load web/API route registries and print method/path pairs. Duplicate imports or invalid routes fail rather than being ignored.', 'php breeze routes'],
    'make:controller' => ['Create a controller skeleton under app/controllers. Explicitly require it and register its actions in routes/web.php; generating a controller does not expose a URL.', 'php breeze make:controller ContactController'],
    'make:api' => ['Create API controller, route module, explicit registry import, and OpenAPI metadata together. Default resource actions are bearer-protected 501 placeholders; implement authorization and actual persistence before use.', 'php breeze make:api Products'],
    'api:token' => ['Issue a bearer token for an existing verified user. Optional name defaults to cli; days defaults to 30 and accepts 1..365. The secret is printed once: do not log/share it or paste it into source control.', 'php breeze api:token 1 integration 30'],
    'api:tokens' => ['List token metadata for the supplied user ID, not token secrets. Use IDs from this list to revoke a token.', 'php breeze api:tokens 1'],
    'api:revoke' => ['Revoke the token identified by its token-row ID, not user ID. Already-issued clients will subsequently receive unauthorized responses.', 'php breeze api:revoke 12'],
    'api:openapi' => ['Print the registered OpenAPI JSON document to stdout. This still works with public API docs disabled. Shell redirection creates a file; keep export paths trusted.', 'php breeze api:openapi'],
    'make:model' => ['Create an explicitly loaded Model subclass under app/models. Configure the correct table, fillable, hidden, and casts before using it. It does not create or alter a database table.', 'php breeze make:model Article'],
    'make:view' => ['Create a plain PHP view using a dot-separated name. Render it through View, escaping values with e(); no route is registered.', 'php breeze make:view articles.index'],
    'make:template' => ['Create a compiled Breeze .breeze.php source template. The first render compiles it into storage/views. No Node/npm build or route registration is needed.', 'php breeze make:template articles.index'],
    'make:form' => ['Create a Breeze POST form view from one or more field:type arguments. Supports text, email, password, textarea, number, date, time, datetime-local, url, tel, and checkbox. Includes CSRF, labels, escaped old values, and accessible field errors; passwords are never repopulated. Pass session and a trusted local action URL when rendering, optionally old and errors arrays. Does not create routes, controllers, validation, or persistence. Existing PHP/Breeze views are never overwritten; --force is not accepted.', 'php breeze make:form forms.contact name:text email:email message:textarea'],
    'view:clear' => ['Remove recognized compiled Breeze cache files and print the count. Source views remain untouched; subsequent renders compile again.', 'php breeze view:clear'],
    'make:migration' => ['Create a timestamped migration with up/down methods. Fill in driver-appropriate schema SQL before migrating; generation itself does not connect or change schema.', 'php breeze make:migration create_articles'],
    'make:seeder' => ['Create a seeder under database/seeders. Write repeatable inserts/updates deliberately. Seeders are executable trusted PHP, not validated user input.', 'php breeze make:seeder ArticleSeeder'],
    'migrate' => ['Apply pending migrations in order and print applied names. Production requires --force after a backup. SQLite/SQL Server/PostgreSQL DDL is transactional; MySQL DDL may not roll back.', 'php breeze migrate'],
    'migrate:status' => ['Print OPEN or BATCH numbers for discovered migrations, helping distinguish pending/applied schema work. Missing applied migration files are treated as errors.', 'php breeze migrate:status'],
    'migrate:rollback' => ['Run down() for only the latest batch in reverse order. May destroy data; production requires --force. Rollback is not a substitute for a backup.', 'php breeze migrate:rollback'],
    'db:seed' => ['Run the named seeder, or DatabaseSeeder when omitted. Production requires --force. Review the seeder first because it can change/delete data.', 'php breeze db:seed ArticleSeeder'],
    'mail:queue' => ['Read an existing JSON object file up to 6 MiB, validate from/to/subject/text/html, store a queued message, and print its ID. No SMTP is sent by this command.', 'php breeze mail:queue message.json'],
    'mail:list' => ['Print recent email metadata as JSON; default 50, accepted range 1..1000. Bodies are not listed, but subjects and delivery diagnostics can still be confidential.', 'php breeze mail:list 20'],
    'mail:show' => ['Print a complete stored email row as JSON by positive ID. Contains private message content; restrict terminal output, logs, and access.', 'php breeze mail:show 12'],
    'mail:send' => ['Claim/send one queued outgoing ID using SMTP, update its status, and report acceptance. Acceptance is not inbox delivery. Failed/uncertain rows require explicit retry handling first.', 'php breeze mail:send 12'],
    'mail:work' => ['Send queued IDs oldest-first in one bounded run, default 50, accepted 1..1000. Stop at the first failure. This is not a persistent daemon; schedule it with cron/Task Scheduler.', 'php breeze mail:work 50'],
    'mail:retry' => ['Requeue failed outgoing mail. --force also permits uncertain/sending recovery, which can duplicate delivery; stop active workers and check SMTP evidence first.', 'php breeze mail:retry 12'],
    'mail:fetch' => ['Read/import up to 1..1000 IMAP messages, default 50, without marking Seen or changing the mailbox. MAIL_LOG controls database import/deduplication.', 'php breeze mail:fetch 10'],
    'test' => ['Run isolated native tests, local HTTP, SQLite, local SMTP/IMAP, and AI HTTPS/SSE fixtures. No external email or AI requests are sent. Requires process/socket support and documented extensions.', 'php breeze test'],
    'help' => ['Print usage and the complete current command list. Also the default when no command is supplied. Unknown commands/options and excess arguments fail explicitly.', 'php breeze help'],
];
$reflection = new ReflectionClass(\PBreeze\Console::class);
$help = $reflection->getReflectionConstant('HELP')->getValue();
preg_match_all('/^  ([a-z][a-z:-]+)\s/m', $help, $matches);
if (array_diff($matches[1], array_keys($commands)) !== [] || array_diff(array_keys($commands), $matches[1]) !== []) {
    throw new RuntimeException('CLI manual coverage does not match Console help.');
}

$environment = [
    'APP_NAME' => 'Application name used in pages and API metadata. Default PBreeze.',
    'APP_URL' => 'Trusted base URL for authentication email links. Use the actual HTTPS origin in production; do not derive it from untrusted Host input.',
    'APP_ENV' => 'local, testing, or production only. If absent, configuration defaults to production; the example file intentionally selects local.',
    'APP_DEBUG' => 'Boolean diagnostic web output flag. Detailed errors are shown only with local environment and true. Keep false in production; API unexpected errors stay generic.',
    'APP_KEY' => 'Secret base64:-prefixed 32-byte encryption key. Empty disables usable encryption, not unrelated features. Generate securely; never commit or log it.',
    'APP_LOCALE' => 'Default display locale for Formatter, en-US. Does not translate framework-maintained UI. Process environment overrides .env.',
    'APP_TIMEZONE' => 'Default Formatter IANA zone, UTC. No global PHP timezone change occurs.',
    'LOG_RETENTION_DAYS' => 'Daily-file retention window, 1..3650 days, default 5. Includes today; pruning runs when an entry is written.',
    'LOG_LEVEL' => 'Minimum lowercase severity: debug, info, notice, warning, error, critical, alert, emergency. Default debug. Higher thresholds may filter automatic error entries.',
    'UPLOAD_MAX_BYTES' => 'Positive integer per-file default for the Uploads service, default 2097152 (2 MiB). Does not change PHP or proxy request limits; handlers can override.',
    'AI_PROVIDER' => 'Default server-side text AI provider: openai or anthropic (Claude), default openai. Individual calls can override it. No public endpoints are created.',
    'AI_TIMEOUT' => 'Total native HTTPS deadline in seconds, 1..300, default 60. Includes connection setup and the complete stream; connect time is capped at 15 seconds.',
    'AI_MAX_TOKENS' => 'Default output token budget, 1..128000, default 1024. Per-model limits may be lower; reasoning models can spend budget on reasoning. Token-limit stop reasons remain explicit.',
    'AI_CA_FILE' => 'Optional readable CA bundle for native cURL. Empty uses system/cURL trust, including curl.cainfo. Never disables peer or hostname verification. Use forward slashes on Windows.',
    'OPENAI_API_KEY' => 'Secret OpenAI API key. Required only for OpenAI calls; API access and billing are separate from ChatGPT subscriptions. Never log or expose it.',
    'OPENAI_MODEL' => 'Exact OpenAI Responses API model ID available to your API account. Intentionally empty: set it before calling OpenAI or pass a model override.',
    'ANTHROPIC_API_KEY' => 'Secret Anthropic API key for Claude Messages. Required only for Claude calls; API access/billing are separate from Claude subscriptions.',
    'ANTHROPIC_MODEL' => 'Exact Claude Messages API model ID available to your account. No default is assumed; configure before calling Claude or pass a model override.',
    'API_DOCS_ENABLED' => 'Optional boolean override. Without it, HTTP Swagger/OpenAPI is enabled in local/testing and disabled in production. Static vendored assets remain public.',
    'DB_CONNECTION' => 'sqlite, mysql, sqlsrv, or pgsql. PostgreSQL requires native pdo_pgsql/libpq; SQL Server requires PDO_SQLSRV and the Microsoft ODBC Driver. Live deployments need integration verification.',
    'DB_DATABASE' => 'SQLite file path relative to project root, absolute local path, or :memory:. For server drivers, an existing database name. PostgreSQL names here are 1..63 ASCII letters/digits/underscore/dot/hyphen, starting with a letter, digit, or underscore. SQLite should not use SMB/NFS shared storage.',
    'DB_HOST' => 'Server hostname or IPv4 address, default 127.0.0.1. Not used for SQLite. SQL Server/PostgreSQL use a separate TCP port, not named instances or Unix socket paths.',
    'DB_PORT' => 'Server TCP port 1..65535. Example: 3306 for MySQL; set 1433 for SQL Server or 5432 for PostgreSQL. When entirely absent, defaults follow the selected driver.',
    'DB_USERNAME' => 'Database login, root in the MySQL-oriented example. Set an appropriate SQL authentication login for SQL Server. Use least privilege in production.',
    'DB_PASSWORD' => 'Secret database password. Empty in the example, not a production recommendation.',
    'DB_ENCRYPT' => 'SQL Server only: true or false, default true. Encrypts the database connection. Keep true in production.',
    'DB_TRUST_SERVER_CERTIFICATE' => 'SQL Server only: true bypasses certificate verification, default false. Keep false in production and configure trusted certificates.',
    'DB_SSLMODE' => 'PostgreSQL only: disable, allow, prefer, require, verify-ca, or verify-full. Default verify-full requires TLS and a trusted certificate matching DB_HOST. disable is for isolated non-TLS development only.',
    'DB_SSLROOTCERT' => 'PostgreSQL only: optional readable local CA certificate file. Empty leaves root-certificate lookup to libpq. Use forward slashes on Windows; DSN delimiters are rejected.',
    'SESSION_SECURE_COOKIE' => 'Boolean Secure-cookie flag, false locally. Set true behind a properly configured HTTPS proxy; this does not encrypt an HTTP connection by itself.',
    'MAIL_LOG' => 'Boolean immediate-send logging/IMAP-import control, default true. Explicit queue() and auth mail always use the database queue.',
    'MAIL_FROM' => 'Default ASCII sender without a display name. Replace example.test with a valid authorized sender before real delivery.',
    'SMTP_HOST' => 'SMTP server hostname/address. Empty until configured. Do not send through arbitrary user-selected hosts.',
    'SMTP_PORT' => 'SMTP TCP port, default 587 for STARTTLS. Use the server-authorized port matching the selected encryption mode.',
    'SMTP_ENCRYPTION' => 'starttls, tls, or none. Default starttls; tls starts TLS immediately. none is restricted to unauthenticated localhost.',
    'SMTP_USERNAME' => 'SMTP authentication username when required. Never log credentials.',
    'SMTP_PASSWORD' => 'Secret SMTP authentication password/app password. Protect .env and backups.',
    'SMTP_TIMEOUT' => 'Native socket timeout seconds, 1..300, default 15. A timeout after DATA may leave uncertain delivery.',
    'SMTP_CA_FILE' => 'Optional readable CA bundle for peer verification. Empty uses system/PHP trust. Never disable verification to work around certificate problems.',
    'IMAP_HOST' => 'IMAP server hostname/address. Empty until configured.',
    'IMAP_PORT' => 'IMAP port, default 993 for immediate TLS. Match the server configuration when using STARTTLS instead.',
    'IMAP_ENCRYPTION' => 'tls, starttls, or restricted none. Default tls; ordinary authenticated IMAP requires encryption.',
    'IMAP_USERNAME' => 'IMAP account username. Used with encrypted LOGIN; not exposed in manual output.',
    'IMAP_PASSWORD' => 'Secret IMAP password/app password. Never paste it into documentation or diagnostics.',
    'IMAP_TIMEOUT' => 'Socket timeout seconds, 1..300, default 15.',
    'IMAP_CA_FILE' => 'Optional readable CA trust bundle, otherwise PHP/system CA defaults are used.',
    'IMAP_MAILBOX' => 'Read-only examined mailbox, default INBOX. ASCII or IMAP modified UTF-7 name, up to 255 printable ASCII bytes.',
    'IMAP_MAX_BYTES' => 'Per-message retrieval bound, 1..52428800 bytes, default 10485760 (10 MiB). Batch retrieval is also bounded at 50 MiB.',
];
$exampleEnvironment = file_get_contents(dirname(__DIR__) . '/.env.example');
if ($exampleEnvironment === false) {
    throw new RuntimeException('Cannot read example configuration.');
}
$exampleEnvironment = str_replace(["\r\n", "\r"], "\n", $exampleEnvironment);
preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=(.*)$/m', $exampleEnvironment, $matches, PREG_SET_ORDER);
$settings = [];
foreach ($matches as $match) {
    if (!isset($environment[$match[1]])) {
        throw new RuntimeException('Missing environment documentation: ' . $match[1]);
    }
    $settings[$match[1]] = $match[2];
}
if (array_diff(array_keys($environment), array_keys($settings)) !== []) {
    throw new RuntimeException('Stale environment reference.');
}

ob_start();
?>
<section class="chapter" id="tutorial" data-search-unit data-title="Build your first feature">
<h2>Build your first feature</h2>
<p>This walkthrough builds a small notes screen using a fresh local PBreeze installation. It reuses the shipped <code>notes</code> migration. All paths below are relative to the project root. Use a disposable development database while learning.</p>
<h3>Check PHP and initialize</h3>
<p>Open a terminal in the project folder. On Windows, use PowerShell or cmd; on Linux, use your normal terminal. Install PHP 8.3+ and enable the native extensions listed in Getting Started. There is no Composer install step.</p>
<?= code("php -v\nphp -m\nphp breeze init\nphp breeze migrate\nphp breeze migrate:status", 'sh') ?>
<p><code>init</code> preserves an existing <code>.env</code>. Inspect that file before migrating. The default local database is SQLite at <code>database/app.sqlite</code>; migrations create the notes, email, authentication, and API tables. <code>migrate:status</code> shows applied batches.</p>
<h3>Understand where code runs</h3>
<p><code>public/index.php</code> boots the framework and returns an Application. <code>routes/web.php</code> explicitly imports controllers and registers handlers. Controllers receive a Request and return a Response. Templates render display content; database queries belong in controllers/models, not HTML.</p>
<p>Use <code>$app-&gt;db</code>, <code>$app-&gt;views</code>, and other injected services. Never create an autoloader or rely on magic class discovery. A controller existing on disk does not make it public: only a registered route does.</p>
<h3>Create a controller</h3>
<?= code('php breeze make:controller NotebookController', 'sh') ?>
<p>Replace the generated controller body with this small example in <code>app/controllers/NotebookController.php</code>. The constructor explicitly receives Application. The GET action reads twenty notes; the POST action validates and inserts only a title.</p>
<?= code(<<<'PHP'
<?php
declare(strict_types=1);

namespace App\Controllers;

use PBreeze\Application;
use PBreeze\Request;
use PBreeze\Response;
use PBreeze\Validator;

final class NotebookController
{
    public function __construct(private readonly Application $app) {}

    public function index(Request $request): Response
    {
        return $this->page();
    }

    public function store(Request $request): Response
    {
        $errors = Validator::errors($request->all(), [
            'title' => 'required|string|min:2|max:120',
        ]);
        if ($errors !== []) {
            return $this->page($errors, 422);
        }
        $this->app->db->table('notes')->insert([
            'title' => $request->input('title'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return Response::redirect('/notebook');
    }

    private function page(array $errors = [], int $status = 200): Response
    {
        return new Response($this->app->views->render('notebook.index', [
            'notes' => $this->app->db->table('notes')
                ->orderBy('id', 'desc')->limit(20)->get(),
            'errors' => $errors,
            'session' => $this->app->session,
        ]), $status);
    }
}
PHP) ?>
<p>On success, the browser receives a 303 redirect, so refreshing the notes page does not repeat the insert. On invalid input, the handler returns the same page with status 422 and visible errors. The query builder binds the title value instead of concatenating SQL.</p>
<h3>Register the routes explicitly</h3>
<p>Append these lines to <code>routes/web.php</code>. Keep existing routes intact. This is a local learning feature with shared notes, not a multi-user authorization design.</p>
<?= code(<<<'PHP'
require_once $app->root . '/app/controllers/NotebookController.php';
$notebook = new \App\Controllers\NotebookController($app);
$router->get('/notebook', [$notebook, 'index']);
$router->post('/notebook', [$notebook, 'store']);
PHP) ?>
<h3>Create the view</h3>
<?= code('php breeze make:template notebook.index', 'sh') ?>
<p>Replace the generated content in <code>app/views/notebook/index.breeze.php</code> with the following. <code>{{ ... }}</code> escapes text; <code>@csrf($session)</code> supplies the hidden token. Unlike raw output, the escaped title cannot introduce executable HTML.</p>
<?= code(<<<'HTML'
<!doctype html>
<html lang="en-US">
<head><meta charset="utf-8"><title>My notebook</title></head>
<body>
    <h1>My notebook</h1>
    @if(!empty($errors))
        <p role="alert">{{ implode(' ', $errors['title'] ?? []) }}</p>
    @endif
    <form method="post" action="/notebook">
        @csrf($session)
        <label for="title">Note title</label>
        <input id="title" name="title" required minlength="2" maxlength="120">
        <button type="submit">Save note</button>
    </form>
    <ul>
        @foreach($notes as $note)
            <li>{{ $note['title'] }}</li>
        @endforeach
    </ul>
</body>
</html>
HTML, 'html') ?>
<h3>Run and check the feature</h3>
<?= code("php breeze routes\nphp breeze serve", 'sh') ?>
<p>Open <code>http://127.0.0.1:8000/notebook</code>. Save a note, then try a one-character title. The valid note should appear; invalid input should show an error without inserting a row. Missing CSRF should return 419. Stop the development server before running another process on the same port.</p>
<h3>Move from demo to application</h3>
<p>Wrap both handlers with <code>$app-&gt;auth-&gt;protect(...)</code> if login is required. Add a new migration for a note-owner column and scope every read/write to the authenticated owner. The guard checks identity/verification, not object ownership. For public forms, also integrate the purpose-bound CAPTCHA/honeypot workflow from its chapter. Do not deploy the unscoped learning feature as a private notebook.</p>
<?= code(<<<'PHP'
$router->get('/notebook', $app->auth->protect([$notebook, 'index']));
$router->post('/notebook', $app->auth->protect([$notebook, 'store']));
PHP) ?>
<p>Use these guarded registrations <em>instead of</em> the unguarded ones, not in addition. The existing registration/login/verification pages are already wired; configure mail and run a worker so verification/reset emails can be delivered.</p>
<h3>Common service recipes</h3>
<?= code(<<<'PHP'
// Display an exact amount without using floating-point money.
$total = $app->format->money('1234.565', 'USD'); // $1,234.57

// Create a JSON export; authorize access before returning it.
$file = \PBreeze\Documents\Documents::json(['total_display' => $total]);
$response = $file->download('summary.json');

// Log safe identifiers, not request bodies, passwords, or tokens.
$app->log->info('Summary created.', ['report_id' => 42]);

// Build and queue an email; delivery is a separate worker action.
$message = $app->mail->compose([
    'to' => ['ada@example.test'],
    'subject' => 'Your summary is ready',
    'text' => 'Sign in to view your summary.',
]);
$emailId = $app->mail->queue($message);
PHP) ?>
<p>These recipes illustrate separate tasks; do not queue mail while rendering a GET page. Perform side effects only in an authorized, validated action and use the queue worker deliberately.</p>
</section>

<section class="chapter" id="cli-reference" data-search-unit data-title="Every CLI command">
<h2>Every CLI command</h2>
<p>Run from the project root with <code>php breeze</code> on Windows or Linux. Names are arguments, not arbitrary paths. Generators reject traversal, invalid identifiers, Windows device names, and existing/conflicting output rather than overwriting work.</p>
<p>Commands normally return exit status 0 on success; failures print an error and exit nonzero. Commands can create files, change the database, or send actual email. <code>--force</code> permits production migrations/rollback/seeding; it is not a universal overwrite switch. API commands do not accept it.</p>
<?php foreach ($commands as $name => [$description, $example]): ?>
<article class="function-entry" id="cli-<?= anchor($name) ?>" data-search-unit data-title="<?= html('CLI ' . $name) ?>">
<h3><a href="#cli-<?= anchor($name) ?>"><?= html($name) ?></a></h3>
<p><?= inline($description) ?></p>
<?= code($example, 'sh') ?>
</article>
<?php endforeach; ?>
<h3>JSON input for mail:queue</h3>
<p>Create <code>message.json</code> yourself with a recipient list and at least one body. The configured MAIL_FROM is used when from is omitted. Do not commit real private message content.</p>
<?= code(<<<'JSON'
{
    "to": ["ada@example.test"],
    "subject": "Welcome",
    "text": "Hello, Ada!"
}
JSON, 'json') ?>
<p>For a reviewed production migration, the explicit form is <code>php breeze migrate --force</code>. For an investigated uncertain email, <code>php breeze mail:retry 12 --force</code> can requeue it, but may duplicate delivery. Never combine either with an unreviewed deployment script.</p>
</section>

<section class="chapter" id="configuration-reference" data-search-unit data-title="Every environment setting">
<h2>Environment reference</h2>
<p>The following defaults/example values come from the committed <code>.env.example</code>, never from a developer's real <code>.env</code>. System environment variables override the file. An empty value is not the same as a missing variable. Quotes remove only their outer delimiters; inline comments, interpolation, multiline values, and escape processing are unsupported.</p>
<?php foreach ($settings as $key => $value): ?>
<article class="setting" id="setting-<?= anchor($key) ?>" data-search-unit data-title="<?= html($key) ?>">
<h3><a href="#setting-<?= anchor($key) ?>"><?= html($key) ?></a></h3>
<p><strong>Example file value:</strong> <code><?= $value === '' ? '(empty)' : html($value) ?></code></p>
<p><?= inline($environment[$key]) ?></p>
</article>
<?php endforeach; ?>
<h3>PHP and web-server settings are separate</h3>
<p><code>upload_max_filesize</code>, <code>post_max_size</code>, <code>max_file_uploads</code>, <code>upload_tmp_dir</code>, session save paths, CA certificates, memory/time limits, error logging, and web-server body/TLS limits are not automatically set from these environment keys. Configure the PHP installation used by the actual web server, which may differ from CLI PHP.</p>
</section>

<section class="chapter" id="troubleshooting" data-search-unit data-title="Troubleshooting and deployment checklist">
<h2>Troubleshooting</h2>
<dl>
<dt>Class not found</dt><dd>Check exact filename casing and add an explicit <code>require_once</code> before using the class. PBreeze intentionally has no Composer/PSR autoloader.</dd>
<dt>404 or 405</dt><dd>Run <code>php breeze routes</code>. Check the exact route, HTTP method, registry import, and web-server front-controller rewrite. Generating a controller/view does not automatically register it.</dd>
<dt>419 Session verification failed</dt><dd>Reload the form, keep the same session cookie, and submit the current hidden CSRF token or header. Login/logout/reset rotate session state. Multipart requests need CSRF too.</dd>
<dt>422 Security check failed</dt><dd>Use the new CAPTCHA challenge after every attempt. Keep the website honeypot empty and submit the correct form purpose within five minutes. A consumed code cannot be retried.</dd>
<dt>401 on the API</dt><dd>Use an unexpired, unrevoked bearer token for a verified user. Browser login cookies do not authenticate API requests. A password reset invalidates existing tokens.</dd>
<dt>413 uploading</dt><dd>Compare UPLOAD_MAX_BYTES, handler overrides, PHP upload_max_filesize/post_max_size, multipart overhead, and proxy limits. Framework settings cannot raise PHP/server limits.</dd>
<dt>415 on the API</dt><dd>Send an application/json object, not form data or multipart uploads. Use normal web routes for native file uploads/downloads.</dd>
<dt>429 Too many requests</dt><dd>Honor Retry-After. Direct REMOTE_ADDR is used; clients behind a reverse proxy may share its IP. Do not disable rate limits to hide an upstream configuration issue.</dd>
<dt>Unexpected 500</dt><dd>Inspect private storage/logs and PHP server logs. Automatic exception entries record safe source location/class, not messages or arguments. Verify required extensions, permissions, environment validation, and schema status.</dd>
<dt>Missing extension</dt><dd>Use php -m for CLI, and inspect the web PHP configuration separately. Enable the selected native PDO driver (SQLite, MySQL, SQL Server, or PostgreSQL), OpenSSL, iconv, mbstring as needed, Intl, zip, DOM, and fileinfo for the features/tests described. SQL Server also requires the Microsoft ODBC Driver; PostgreSQL uses libpq. Do not install Composer packages as substitutes.</dd>
<dt>Email stays queued</dt><dd>Configure SMTP and run mail:work. Registration/reset queue mail rather than sending it inline. Check delivery status; uncertain/sending recovery can duplicate messages.</dd>
<dt>Verification link points to localhost</dt><dd>Set APP_URL to the trusted public HTTPS base URL before creating new links. Existing queued messages retain their original content.</dd>
<dt>Encrypted content will not decrypt</dt><dd>Check the exact APP_KEY and authenticated context. Never generate a replacement key to fix unreadable existing data. Restore the original key from secure backup or perform a planned migration.</dd>
<dt>Formatting differs between machines</dt><dd>Compare explicit locale/time zone and installed ICU data versions. Nonbreaking spaces, localized digits, and punctuation are intentional. Never parse formatted money as database numbers.</dd>
<dt>Template appears stale</dt><dd>Run view:clear and check the view name/path. A .breeze.php view takes precedence over a same-named .php view. Templates are trusted PHP, not a sandbox.</dd>
<dt>Log file is absent</dt><dd>Storage is created only on a written entry. Check LOG_LEVEL filtering, write permissions, symlink restrictions, and PHP server diagnostics. Retention runs on writes, not a timer.</dd>
</dl>
<h3>Before production</h3>
<ul>
<li>Serve only public/, enforce HTTPS, set APP_ENV=production and APP_DEBUG=false, and configure secure cookies correctly behind a proxy.</li>
<li>Protect .env, private storage, database/backups, mail contents, and log files. Align Linux service/CLI ownership or restrict Windows NTFS ACLs.</li>
<li>Back up schema/data and encryption keys separately. Review every migration/seeder and test restoration before using --force.</li>
<li>Guard private routes and apply per-record authorization; neither valid tokens nor random file keys grant object ownership.</li>
<li>Set upload/request/time/disk/user quotas; consider quarantine and malware scanning. MIME checks do not prove a document harmless.</li>
<li>Configure SMTP/IMAP certificate trust and schedule bounded queue workers without overlapping unsafe recovery tasks.</li>
<li>Keep public API docs disabled unless intentionally needed; do not enable credential persistence in Swagger.</li>
<li>Run the native suite and separate deployment-specific Windows/MySQL/SQL Server/PostgreSQL checks where applicable. A CI configuration is not evidence that a specific deployment was tested.</li>
</ul>
<h3>Maintain this manual</h3>
<?= code("php docs/build.php\nphp docs/build.php --check", 'sh') ?>
<p>The builder requires native DOM for link validation. It checks declared public-method coverage, explicit examples, syntax of reference snippets, all CLI commands, every example environment key, duplicate IDs, and internal/source links. Keep the HTML and local assets together. For local preview, serve only the docs directory on localhost, never the project root.</p>
</section>
<?php
return (string) ob_get_clean();
