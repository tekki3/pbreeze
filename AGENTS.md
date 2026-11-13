# PBreeze Guide for AI Coding Assistants

This file describes repository-wide engineering conventions. Use it when
implementing, reviewing, testing, or documenting PBreeze. For complete API
signatures and examples, consult the [README](README.md) and
[offline user manual](docs/index.html); verify behavior in source before
assuming an API exists.

## 1. Purpose and non-negotiable constraints

PBreeze is a native PHP MVC framework intended to be approachable and explicit.
It includes routing, templates, validation, authentication, databases, mail,
uploads, document creation, formatting, strings, AI clients, caching, and a CLI.
It is not a Laravel or CodeIgniter compatibility layer.

- Support **PHP 8.3+ on Windows and Linux**. Do not use newer-only syntax
  or extensions without preserving that minimum.
- Use native PHP and extensions. Do not add Composer, a vendor autoloader,
  external PHP packages, PSR autoloading, a dependency-injection container,
  or implicit controller/service/test discovery.
- Load dependencies explicitly with `require_once` and existing registries.
  A `use` statement imports a name; it does not load a class file.
- Keep maintained UI, CLI output, diagnostics, emails, generated code,
  comments, tests, and documentation in **US English**. HTML uses `en-US`.
  Data formatting in another locale does not translate framework messages.
- Keep third-party licenses intact. The locally vendored Swagger UI is an
  intentional frontend exception, not permission to add PHP dependencies
  or introduce a CDN/npm requirement.
- Preserve local configuration and user data. Never overwrite a real
  `.env`, rotate a live key, migrate a real database, or send external mail
  or AI requests simply to validate a change.
- Do not claim universal server compatibility, a security certification,
  benchmark superiority, or a support window that the project has not established.

## 2. Repository map and ownership

| Location | Responsibility |
|---|---|
| [bootstrap.php](bootstrap.php) | Ordered explicit framework loading, environment/config loading, Application construction |
| [breeze](breeze) | CLI entry point; loads Console and propagates command exit codes |
| [framework/](framework/) | Framework namespace `PBreeze`; reusable services and infrastructure |
| [framework/Http.php](framework/Http.php) | Request, Response, and HTTP exception types; not one file per class |
| [framework/mail/](framework/mail/) | Native SMTP/IMAP, MIME, mail storage/queue, mail CLI |
| [framework/documents/](framework/documents/) | Native PDF, XLSX, DOCX, JSON, CSV, and XML creation |
| [app/controllers/](app/controllers/) | Application request handlers in `App\Controllers` |
| [app/models/](app/models/) | Explicitly loaded application models in `App\Models` |
| [app/views/](app/views/) | PHP views and compiled Breeze source templates |
| [routes/web.php](routes/web.php) | Explicit controller imports and browser-route registrations |
| [routes/api.php](routes/api.php) | Explicit imports of API modules; generator may append registrations |
| [routes/console.php](routes/console.php) | Explicit custom command name-to-Closure registry |
| [config/](config/) | Validated service configuration, read from Env |
| [database/migrations/](database/migrations/) | Ordered schema changes and SQL Server/PostgreSQL overrides |
| [database/seeders/](database/seeders/) | Trusted PHP callbacks for initial/example data |
| [public/](public/) | The only web document root; front controller and approved static assets |
| [deploy/](deploy/) | Apache, Nginx, and FrankenPHP deployment templates |
| [tests/](tests/) | Native runner, explicit test modules, and isolated fixtures/integrations |
| [docs/](docs/) | Manual builder, authored references/tutorials, generated HTML, local assets |
| [artwork/](artwork/) | Image/logo source files; not an application runtime dependency |

`STORAGE_PATH` selects private runtime storage, normally `storage/`.
Do not treat runtime files as source or put them in the public directory.

## 3. Application lifecycle and service wiring

Read [Application](framework/Application.php) before changing lifecycle behavior:

1. The front controller loads bootstrap, which returns an Application.
2. Bootstrap explicitly loads framework files and configuration. Database,
   cache, and external-service connections remain lazy.
3. `Application::run()` enforces production transport settings and starts a
   browser session when appropriate.
4. `routes()` loads web and API registries with `$app`, `$router`, and `$api`
   in scope. Register once; duplicate routes are errors.
5. `handle()` dispatches `/api` and `/api/*` to the stateless API router;
   other requests pass browser CSRF verification before route dispatch.
6. Handlers return a Response. The application maps exceptions, persists
   session changes, then sends output. Session write failures must not
   produce success responses.

Exact GET/HEAD `/health` and `/ready` probes do not start sessions. The API
does not use browser login cookies or CSRF tokens.
`handle()` alone does not start sessions, import routes, or catch exceptions.

Application exposes `$app->db`, `views`, `session`, `auth`, `encryption`,
`botProtection`, `api`, `uploads`, `log`, `format`, `strings`, `ai`, and `cache`,
plus `router`, `root`, `config`, and `storagePath`. Reuse these services
instead of global connections or duplicate clients. New framework services
need explicit bootstrap loading, Application wiring, configuration,
documentation, and tests for initialization and use.

## 4. PHP style and naming

- Follow neighboring code: `declare(strict_types=1);`, four-space indentation,
  explicit visibility, typed parameters/returns/properties, and readonly
  constructor promotion where appropriate.
- Use PascalCase class names, camelCase methods/properties, snake_case SQL
  tables/columns and migration names, and uppercase environment keys.
- Use PHPDoc for array shapes, lists, and generics that native types cannot
  express. Keep Application configuration shapes current when adding settings.
- Prefer small cohesive services and explicit data flow. Do not import
  facades, middleware pipelines, event systems, or ORM methods from another
  framework by analogy.
- Validate inputs at boundaries. Invalid arguments and operational failures
  must be explicit; do not turn exceptions into empty data or successful defaults.
- Keep public error text safe. Follow existing `HttpException`,
  `ApiValidationException`, and logging patterns rather than exposing stack
  traces, database errors, credentials, or provider responses.

## 5. Routes, controllers, views, and forms

Use [HomeController](app/controllers/HomeController.php) and
[web routes](routes/web.php) as browser-flow examples.

- Controllers receive Application explicitly and actions receive Request.
  Return `Response`, `Response::json()`, or a trusted local redirect; do not
  echo content before the response/session lifecycle finishes.
- Generating a controller or view does not publish a URL. Require the
  controller file and register its actions explicitly.
- Read request bodies with `input()`/`all()`, query parameters with `query()`,
  and headers with `header()`. Validate expected types and rules before use.
- Keep database/service work in handlers/models, not templates.
  Pass template data deliberately, including `session` for browser forms.
- View names use dots: `articles.index` resolves to the corresponding
  nested file under application views. A `.breeze.php` source takes
  precedence over a same-named `.php` view.
- Breeze `{{ ... }}` escapes output; plain PHP uses `e()`. Reserve raw output
  for deliberately trusted HTML. Templates execute trusted PHP and are not
  a sandbox for user-submitted template code.
- Source templates compile into private `storage/views` or the configured
  equivalent. Do not manually edit generated compiled templates.
- Browser mutations require CSRF; GET/HEAD/OPTIONS must not mutate data.
  Include `@csrf($session)` or `csrf_field($session)`. Invalid CSRF returns 419.
- Preserve CAPTCHA purpose/session binding, honeypots, expiry, consumption,
  and refresh behavior. Refresh actions must not perform the protected mutation.
  Keep validation errors and safe old values visible; never repopulate passwords.
- Put application CSS and JS in separate
  [CSS](public/assets/css/) and [JS](public/assets/js/) folders. Keep Swagger's
  vendored assets separate. Never serve the root artwork directory.

## 6. Authentication, API, and authorization boundaries

- Reuse [Auth](framework/Auth.php) for session login, verification, reset,
  and protected-route wrappers. Login/logout reset session state and IDs.
- Authentication is not authorization. Scope every private record lookup,
  update, delete, export, and upload download to the authenticated owner or
  an explicitly authorized role. Random IDs/storage keys are not permissions.
- Reuse [Api](framework/Api.php) and [ApiTokens](framework/ApiTokens.php) for
  bearer authentication. API registration requires OpenAPI metadata with
  unique operation IDs; routes are authenticated by default.
- `make:api` creates controller/module/registry/OpenAPI scaffolding together.
  Generated 501 actions are placeholders, not complete persistence or ownership checks.
- Preserve the JSON response/error conventions, media-type checks,
  parameterized validation, and fixed 1 MiB raw JSON/API body limits.
  Multipart uploads use browser routes, not the JSON API.
- Preserve hashed token storage, expiry/revocation, verified-user requirements,
  password-reset invalidation, and database rate limits.
  Never print/log bearer tokens except the intended one-time issuance output.
- Do not trust arbitrary forwarded IP headers. Client IP restoration belongs
  at a properly configured trusted proxy/web server.

## 7. Databases, ORM, migrations, and encryption

Read [Database](framework/Database.php), [Query](framework/Query.php), and
[Model](framework/Model.php) before adding SQL or data-model behavior.

- Support SQLite, MySQL, PostgreSQL, and SQL Server. Reuse driver-aware
  identifier quoting, insert-ID retrieval, limiting, and transaction helpers.
  Bind values; never concatenate untrusted input into SQL.
- Query filters clone builders. Retain the returned query rather than
  assuming an in-place mutation. Unscoped update/delete is guarded.
- Model instances receive Database explicitly; there is no static global
  Eloquent-style connection. Configure table/key, fillable, hidden, casts,
  and timestamps explicitly. Relationships are explicit, not implicit loaders.
- Mass-assignment allowlists and hidden serialization fields remain separate
  from validation and authorization. Do not bypass these by passing all input.
- Add a **new** timestamped migration for a schema change; never rewrite an
  already-applied migration. Migrations return a Migration object with
  `up(PDO $db)` and `down(PDO $db)` methods.
- Provide driver overrides in the existing `pgsql`/`sqlsrv` migration
  subdirectories when needed; portable SQL can stay in a shared migration.
  MySQL DDL is not transactionally rolled back. Preserve migration locking.
- Seeders are trusted callbacks, not production fixtures to run automatically.
  Destructive production database commands require reviewed backups and `--force`.
- MySQL defaults to certificate-verified TLS; plaintext opt-out is limited
  to isolated localhost nonproduction use. Preserve PostgreSQL/SQL Server
  certificate and encryption validation.
- `key:generate` prints an APP_KEY assignment; it does not edit configuration.
  Never replace an existing key to fix decryption. Preserve authenticated
  AES-256-GCM contexts and encrypted-cast restrictions; randomized ciphertext
  is not suitable for ordinary plaintext filtering.

## 8. Sessions and private storage

- `SESSION_DRIVER=native` preserves PHP's configured handler.
  `database` uses the migrated sessions table and a dedicated lazy database
  connection, not the application transaction connection.
- Database sessions hash IDs, encode payloads with base64 (not encryption),
  enforce strict ID validation and per-read expiry, refresh idle lifetime
  on saves, and lock concurrent requests. Never fall back to files on failure.
- Preserve session changes even on handled error responses, including consumed
  CAPTCHA challenges. Persist before response output, releasing session locks.
- SQLite session locks live in private `sessions/` under storage. All processes
  using the same SQLite database must share that lock location. Server
  databases require session-affine connections for advisory locks.
- Changing drivers logs users out; changing storage paths does not move data.
  Native PHP session paths and SQLite database files are configured separately.
- Use [Filesystem](framework/Filesystem.php) path/permission helpers.
  Reject public storage, traversal, wrappers, and symlink ancestors. Keep
  SQLite, uploads, logs, locks, and backups outside the public root.
- Do not solve permissions with `chmod 777` or globally writable source.
  Use the correct Linux identity or Windows NTFS ACLs; chmod is not Windows ACL management.

## 9. Other service conventions

| Service | Required conventions |
|---|---|
| Mail | Reuse `$app->mail`; preserve SMTP/IMAP TLS verification, bounded reads, optional DB logging, and queued/failed/uncertain delivery semantics. `mail:work` processes a bounded batch and exits; schedule externally. Retrying uncertain delivery can duplicate mail. |
| Cache | `$app->cache` supports native Redis/Memcached, JSON-only values, key namespaces, bounded payloads/TTLs, and explicit backend errors. It does not replace sessions, rate limits, or view caching. Memcached requires a protected network; the adapter does not provide TLS. |
| Uploads | Use Request `file()`/`files()` and `$app->uploads`, explicit allowed types, private random storage keys, and ownership checks. Default per-file cap is 2 MiB. MIME checks do not prove a file harmless; preserve Office archive/path/expansion checks. |
| Documents | Use `Documents` factories and existing builders with `build()`/save/download behavior. Native PDF/Office implementations have documented limits; do not promise full browser/Office rendering or add a package silently. Authorize exports before returning files. |
| Formatting | Use `$app->format` or injected `$format` in views. Money uses exact decimal strings or integer minor units, never floats. Stored/API values remain locale-independent; formatting is for display. |
| Strings | Use `$app->strings` or injected `$strings`; preserve UTF-8/grapheme-safe operations and cryptographic randomness. Slugs and random tokens are not interchangeable. |
| AI | Use `$app->ai` for server-side OpenAI/Claude text calls. Validate/authorize input, protect keys and prompts, treat generated text as untrusted, and account for partial streaming output before failure. |
| Logging | Use `$app->log` structured events with safe identifiers, not full requests, passwords, tokens, mail bodies, or AI prompts. Automatic exception reporting records safe source metadata. Daily retention is controlled by `LOG_RETENTION_DAYS`. |

## 10. Configuration and CLI changes

- [Env](framework/Env.php) reads process environment first, file values second,
  then defaults. Empty is not missing. The file supports matching outer quotes
  and full-line comments, not interpolation, inline comments, or multiline values.
- Add settings to the appropriate config file and [.env.example](.env.example),
  validate ranges/enums, document defaults/units/security effects, and cover
  invalid input. Do not inspect or copy a user's private environment into docs/tests.
- The absent environment defaults to production; the example file intentionally
  selects local. Do not weaken production guards to make local tests pass.
- [Console](framework/Console.php) owns built-in dispatch, help, and generators.
  Add help text, argument/option validation, generator-output tests, and
  [CLI reference](docs/walkthrough.php) entries with command changes.
- Keep generators exclusive-create and traversal/device-name safe; never
  silently overwrite or claim to implement generated placeholders.
  Preserve deliberate differences: `make:api` updates its registry;
  `make:command` and `make:test` print explicit registration instructions.
- Custom command files return `Closure(Application $app, array $arguments): int`;
  register them in console routes. Preserve raw arguments and exit codes
  0..255. Custom handlers own their validation and production guards.
- `make:test` creates native application tests. Register explicit requires in
  [tests/application.php](tests/application.php); no discovery or PHPUnit.
  Generated placeholders must fail until assertions replace them.
- `about` and `env:check` are secret-safe diagnostics. `env:check` inventories
  key names; it does not prove semantic correctness or deployment readiness.

## 11. Testing and verification

Run commands from the project root:

```sh
php breeze help
php -l path/to/changed-file.php
php breeze test
php docs/build.php
php docs/build.php --check
```

The lint path is a placeholder for each changed ordinary PHP file, not a
literal project command. Exercise Breeze templates through the engine;
they are not ordinary PHP sources for linting.

- The native [runner](tests/run.php) defines `test()`, `check()`,
  `same()` (strict expected/actual equality), and `raises()`. Register
  framework test modules explicitly; application tests use their own registry.
- Tests use isolated temporary roots, SQLite, subprocesses, and HTTP fixtures.
  Exercise measurable boundaries, error behavior, and generated code, not just
  string matches. Clean up task-owned resources in `finally`.
- Do not bootstrap a production app and call mutations as a shortcut.
  Tests for real databases/cache/servers are separate opt-in integrations
  with dedicated disposable resources and documented safety prerequisites.
- CI defines Ubuntu/Windows PHP 8.3 and 8.5 native runs plus separate service
  jobs in [the workflow](.github/workflows/tests.yml). Core feature/test
  extensions include PDO SQLite, OpenSSL, curl, iconv, mbstring, zip, DOM,
  Intl, and fileinfo; Redis/Memcached and external databases need their own
  native drivers/services. There is no Composer install step.
- After code changes, lint changed files and run relevant regression coverage;
  use the full native suite when shared routing, bootstrap, config, sessions,
  database, or generators change. Documentation-only changes do not require it.
- For UI work, verify desktop/mobile layouts, keyboard operation, validation
  states, escaping, and local/offline assets. Do not claim validation you did not run.

## 12. Deployment and diagnostic boundaries

- Expose only public root with one executable front controller and approved
  static allowlists. Preserve Authorization forwarding, original URI/query,
  methods/status codes, body limits, and error pass-through across servers.
- Supplied deployment scope is domain-root Apache, Nginx, FrankenPHP
  **standard request mode**, and IIS. Do not assume subdirectory hosting or
  persistent workers are supported. IIS needs Windows for live validation.
- Align PHP/web-server request/upload/time/session limits separately from
  application settings. A `.env` change cannot raise PHP upload limits.
- `storage:prepare` creates private working directories; it does not relocate
  files or fix ownership. `doctor` checks production configuration and may
  legitimately fail on a local setup. It is not proof of mail delivery,
  external-service connectivity, backups, or load capacity.
- `/health` is liveness; `/ready` checks existing private storage, database,
  required schema, and deployed migration names. Database sessions add a
  sessions-table check. Probes must remain session-free and avoid implicit
  database creation/migrations.
- Protect probes at the edge, enforce HTTPS, set a trusted APP_URL and secure
  cookies, disable debug, and preserve backups/key recovery. Real deployment
  smoke tests and database-specific integrations remain necessary.

## 13. Documentation and change completion

- Keep [README](README.md) beginner-friendly: explain where snippets belong,
  what commands change, expected results, and what remains manual.
- The manual is generated by [docs/build.php](docs/build.php) from README,
  [reference.php](docs/reference.php), [walkthrough.php](docs/walkthrough.php),
  and reflected framework APIs. Edit those sources, not generated HTML alone.
- Every declared framework public method needs an authored reference/example;
  environment settings and CLI help must match their reference coverage.
  Rebuild and check the manual after relevant source/documentation changes.
- Keep manual CSS/JS in their separate local folders. Preserve offline
  reading, search, printing, accessible navigation, and clipboard-denied recovery.
  The manual embeds the canonical artwork logo at build time.
- Before finishing, check related surfaces together: bootstrap, configuration,
  registries, generator output, dialects, tests, manual, and deployment
  templates as applicable. Preserve unrelated work and report actual
  verification results and any remaining limitations.
