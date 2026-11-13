<?php
declare(strict_types=1);

$commands = [
    'about' => ['Print the framework identity (no release version is currently declared), PHP version, OS family, CLI SAPI, environment, database/cache driver names, session handler, and loaded extensions. Does not connect to services, print credentials/paths, or create storage. This describes CLI PHP, not necessarily the web runtime. No arguments/options.', 'php breeze about'],
    'env:check' => ['Compare .env key names with active/commented settings in .env.example, honoring process overrides. Print SET, EMPTY, MISSING, OPTIONAL, or CUSTOM and names only, never values. Exit 1 for missing active example keys, otherwise 0; empty/optional/custom entries need human review, not automatic failure. Reject malformed/duplicate local keys. Does not modify Env state/files or validate production readiness. Bootstrap must already succeed; use doctor for semantic checks. No options.', 'php breeze env:check'],
    'cache:test' => ['Write/read/delete one cryptographically random namespaced cache key with a 30-second TTL. No flush, database access, or application-key deletion. Requires an enabled reachable native Redis/Memcached backend; disabled or failed operations throw and the CLI exits 1. Cleanup runs after successful writes; interrupted/uncertain writes expire. Success prints no key, value, host, or credentials. No arguments/options.', 'php breeze cache:test'],
    'logs:tail' => ['Print up to 1..1000 most-recent matching JSONL records, default 50, in chronological order across daily files in configured private storage. Optional second argument is a lowercase minimum severity (debug through emergency), default debug. One-shot snapshot, not follow mode. Does not create storage or prune logs. Reject symlinks, invalid JSON/levels, entries above 64 KiB, and scans above 8 MiB instead of silently truncating. Re-encode terminal controls. Log messages/context remain confidential: restrict output and never pipe to public services. No options.', 'php breeze logs:tail 100 warning'],
    'make:command' => ['Create app/commands/Name.php returning a Closure(Application $app, array $arguments): int with a deliberately unimplemented body. Print an explicit registration entry for routes/console.php; do not auto-discover/import files or overwrite existing commands. Names use PascalCase ASCII letters/digits and cannot be Windows devices. Register a trusted unique lowercase command name; builtins cannot be overridden. Custom handlers receive raw remaining arguments, own validation/production guards, and must return an integer 0..255. No --force.', 'php breeze make:command Report'],
    'init' => ['Create .env from .env.example only if absent, with private platform-appropriate permissions. Existing configuration is preserved; no database or application key is created.', 'php breeze init'],
    'doctor' => ['Check production configuration, required/optional extensions, private storage, database/schema/migration readiness, encryption, SMTP configuration, and operational reminders. Returns exit 1 if any check fails, otherwise 0; warnings need human review. Does not create storage/databases/tables, send mail, call AI, or test real web-server settings. Database connection and read queries occur only after storage checks pass. No --force.', 'php breeze doctor'],
    'storage:prepare' => ['Create the configured private storage root and logs, uploads, and views directories using restrictive creation permissions. Existing files are preserved; symlinks and public storage are rejected. No migration, file transfer, ownership change, or session/database relocation occurs. Run as the correct deployment identity; no --force.', 'php breeze storage:prepare'],
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
    'STORAGE_PATH' => 'Private logs/uploads/view-cache/migration-lock directory, default storage relative to the project root. Absolute local paths support persistent data across releases. No dot segments, stream wrappers, symlink ancestors, public paths, or ancestors of public are allowed. Run storage:prepare; migrate existing files explicitly. Does not relocate SQLite or PHP sessions.',
    'APP_LOCALE' => 'Default display locale for Formatter, en-US. Does not translate framework-maintained UI. Process environment overrides .env.',
    'APP_TIMEZONE' => 'Default Formatter IANA zone, UTC. No global PHP timezone change occurs.',
    'LOG_RETENTION_DAYS' => 'Daily-file retention window, 1..3650 days, default 5. Includes today; pruning runs when an entry is written.',
    'LOG_LEVEL' => 'Minimum lowercase severity: debug, info, notice, warning, error, critical, alert, emergency. Default debug. Higher thresholds may filter automatic error entries.',
    'CACHE_DRIVER' => 'Optional shared cache backend: none (default, disabled), redis (native redis extension 6+), or memcached (native memcached extension 3+, not legacy memcache). No Composer; no session/rate-limit changes. Disabled or unavailable use throws, without fallback.',
    'CACHE_PREFIX' => 'Application/environment cache namespace, default pbreeze:. Requires 1..64 ASCII letters/digits/colon/underscore/hyphen. Keys are SHA-256 hashed after this prefix. Not an authorization boundary; never share cache write access with untrusted applications.',
    'CACHE_TIMEOUT' => 'Connection/read timeout seconds, integer 1..30, default 2. No application-level retry or fallback.',
    'REDIS_HOST' => 'Single Redis server hostname/address, default 127.0.0.1. No URI, Unix socket, Cluster, or Sentinel discovery. TLS certificates must match this host.',
    'REDIS_PORT' => 'Redis TCP port, 1..65535, default 6379. Configure the actual TLS port when enabling TLS.',
    'REDIS_USERNAME' => 'Optional Redis ACL username. Requires REDIS_PASSWORD when nonempty; an empty username uses password-only AUTH.',
    'REDIS_PASSWORD' => 'Secret Redis password. Never log or commit it. Protect transport with TLS outside trusted networks.',
    'REDIS_DATABASE' => 'Redis logical database index, integer 0..65535, default 0. Server must provide this database and allow SELECT.',
    'REDIS_TLS' => 'Boolean Redis TLS flag, default false for local development. True verifies peer certificates and hostname; verification cannot be disabled. Plaintext requires a trusted network.',
    'REDIS_CA_FILE' => 'Optional readable CA bundle for verified Redis TLS; empty uses system/PHP trust. Use forward slashes on Windows.',
    'MEMCACHED_HOST' => 'Single Memcached server hostname/address, default 127.0.0.1. Binary protocol; no TLS in this adapter. Use a trusted private network or secured tunnel and never expose the server publicly.',
    'MEMCACHED_PORT' => 'Memcached TCP port, integer 1..65535, default 11211.',
    'MEMCACHED_USERNAME' => 'Optional SASL username, requires MEMCACHED_PASSWORD and a SASL-capable native extension/server. SASL authenticates but does not encrypt.',
    'MEMCACHED_PASSWORD' => 'Secret optional SASL password, requires MEMCACHED_USERNAME. Never log or commit it.',
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
    'DB_MYSQL_TLS' => 'MySQL only: required by default, or disabled explicitly for localhost outside production. Required verifies the certificate and checks the negotiated cipher. An intentional compatibility change from earlier unencrypted defaults.',
    'DB_MYSQL_SSL_CA' => 'MySQL only: readable local CA bundle required when DB_MYSQL_TLS=required. Use a server certificate matching DB_HOST. Forward slashes are recommended on Windows.',
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
<p><strong>Goal:</strong> build a page where you can enter a note title, save it, and see your latest notes. You will create one controller, one template, and two routes. This is a local learning exercise: everyone using this page sees the same notes.</p>
<p><strong>Before you start:</strong> finish the <a href="#guide-detailed-installation-guide">installation guide</a> and <a href="#guide-your-first-page">Your First Page</a>. Use a fresh local project and a database containing only practice data. All file paths below start from the project root, the folder containing <code>breeze</code>. You do not need email, AI, Redis, or a separate database server.</p>
<h3>1. Check your local database</h3>
<p>If you followed the SQLite installation steps, your tables already exist. In a terminal in the project root, run:</p>
<?= code('php breeze migrate:status', 'sh') ?>
<p><strong>Expected result:</strong> applied migrations show batch numbers, not <code>OPEN</code>. If any are open, run <code>php breeze migrate</code> on this practice database and check again. If configuration or extensions are missing, return to the installation guide rather than continuing through errors.</p>
<p>The installation uses SQLite at <code>database/app.sqlite</code>. The bundled migrations already create a <code>notes</code> table, so this exercise does not need a new migration or model.</p>
<h3>Understand where code runs</h3>
<p>When you visit a URL, a route chooses a handler. In this example the handler is a method on a controller. The controller reads or saves data, asks a template to produce HTML, and returns that HTML in a response.</p>
<ol><li><code>routes/web.php</code> connects <code>/notebook</code> to the controller.</li><li>The controller uses <code>$app-&gt;db</code> for database work and <code>$app-&gt;views</code> for page display.</li><li>The template displays the notes and the form. It does not run database queries.</li></ol>
<p>PBreeze loads controller files explicitly with <code>require_once</code>. The generator creates a file, but you still need to register its routes. We will do both below.</p>
<h3>2. Create a controller</h3>
<?= code('php breeze make:controller NotebookController', 'sh') ?>
<p>Open <code>app/controllers/NotebookController.php</code> and <strong>replace the entire file contents</strong> with the following code, including its opening <code>&lt;?php</code> tag. Save the file. The constructor receives <code>Application</code>, giving this class access to the framework services.</p>
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
<p><strong>What this code does:</strong> <code>index()</code> shows the page. <code>store()</code> checks that the title is text between 2 and 120 characters, then saves it. <code>page()</code> reads the latest twenty notes and renders the template we will create next.</p>
<p>After saving, the browser is redirected back to the list (HTTP status 303), so refreshing does not save the note again. Invalid input returns the form with errors (status 422). The query builder passes values separately from SQL, helping prevent SQL injection.</p>
<h3>3. Register the routes</h3>
<p>Open <code>routes/web.php</code> and <strong>add</strong> these lines at the bottom. Keep existing routes intact. Do not add a second <code>&lt;?php</code> tag.</p>
<?= code(<<<'PHP'
require_once $app->root . '/app/controllers/NotebookController.php';
$notebook = new \App\Controllers\NotebookController($app);
$router->get('/notebook', [$notebook, 'index']);
$router->post('/notebook', [$notebook, 'store']);
PHP) ?>
<p><code>GET /notebook</code> opens the page; <code>POST /notebook</code> handles the submitted form. The URL is the same, but the request method chooses a different controller method.</p>
<h3>4. Create the view</h3>
<?= code('php breeze make:template notebook.index', 'sh') ?>
<p>Open <code>app/views/notebook/index.breeze.php</code>, replace its entire contents with the following, and save. The dot in <code>notebook.index</code> becomes a folder separator. <code>{{ ... }}</code> displays values safely as text; <code>@csrf($session)</code> adds the hidden security token required for this form.</p>
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
<h3>5. Run and check the feature</h3>
<?= code('php breeze routes', 'sh') ?>
<p>Check that both <code>GET /notebook</code> and <code>POST /notebook</code> are listed. If your development server is not already running, start it with <code>php breeze serve</code>. Do not start a second server on the same port.</p>
<ol><li>Open <code>http://127.0.0.1:8000/notebook</code> (use your chosen port if different). You should see a heading, a title field, and a Save note button.</li><li>Enter <code>My first note</code> and select Save note. The title should appear in the list.</li><li>Refresh the page. The note should still be there without being added a second time.</li><li>Try a one-character title. Your browser should block submission because of <code>minlength="2"</code>. The controller also checks the length because browser checks can be bypassed.</li></ol>
<p>To exercise the server-side length check on this local practice page, temporarily remove <code>minlength="2"</code> from the template, save it, reload the page, and submit one character. You should see an error without a new row being saved. Restore <code>minlength="2"</code> afterward. Keep the CSRF field in place.</p>
<p>If you get 404, check the routes and URL. If you get 419, reload the form and submit again with cookies enabled. For a server error, check the server terminal and run <code>php breeze logs:tail 20 error</code> in another terminal. Keep those logs private.</p>
<h3>Next: move from demo to application</h3>
<p><strong>Stop here if you are just learning.</strong> You have built the complete local example. The following steps are design work needed before making a real private notebook; adding a login check alone is not enough.</p>
<p>Wrap both handlers with <code>$app-&gt;auth-&gt;protect(...)</code> if login is required. Add a new migration for a note-owner column and scope every read/write to the authenticated owner. The guard checks identity/verification, not object ownership. For public forms, also integrate the purpose-bound CAPTCHA/honeypot workflow from its chapter. Do not deploy the unscoped learning feature as a private notebook.</p>
<?= code(<<<'PHP'
$router->get('/notebook', $app->auth->protect([$notebook, 'index']));
$router->post('/notebook', $app->auth->protect([$notebook, 'store']));
PHP) ?>
<p>Use these guarded registrations <em>instead of</em> the unguarded ones, not in addition. The existing registration/login/verification pages are already wired; configure mail and run a worker so verification/reset emails can be delivered.</p>
<h3>Common service recipes</h3>
<p>These are separate examples for later, not more code to paste into the notebook. Learn one service at a time from its feature chapter; email requires its own configuration and delivery worker.</p>
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
