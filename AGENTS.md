# PBreeze Agent Guidelines

## Project constraints

- Target PHP 8.3+ on both Windows and Linux.
- Use native PHP components and extensions. Do not introduce Composer, external PHP packages, PSR autoloading, or implicit file discovery.
- Register dependencies explicitly with `require_once`, following the existing bootstrap and route registries.
- Keep all maintained UI, CLI output, diagnostics, emails, generated code, comments, tests, and documentation in US English. HTML pages use `lang="en-US"`.
- The locally vendored Swagger UI is an intentional frontend exception. Preserve its licenses, notices, and integrity metadata.

## Architecture

- `framework/`: framework services, HTTP handling, ORM, templates, mail, authentication, and CLI.
- `framework/documents/`: native PDF/Office/JSON/CSV/XML creation; `Documents` factories return files or builders with `build()`.
- `app/controllers/` and `app/views/`: application controllers and compiled Breeze templates.
- `routes/`: explicit web and API registration.
- `config/`: configuration; `database/`: migrations and seeders.
- `public/`: web entry points and local assets. Only this directory should be exposed by the web server.
- `tests/`: native test runner and isolated fixtures.
- See [README.md](README.md) for setup, commands, APIs, limitations, and deployment guidance. Update relevant documentation when behavior changes.

## Implementation conventions

- Follow existing helpers, naming, formatting, and explicit registration patterns before adding new abstractions.
- Preserve Windows/Linux portability: respect file-name casing, use existing path/process helpers, and avoid Unix-only shell assumptions.
- Keep web session/CSRF authentication separate from stateless API bearer authentication. Preserve authorization checks, rate limits, output escaping, parameterized queries, and secret handling.
- Never commit credentials or overwrite local `.env` files or stored application data.
- Use new migrations for schema changes; do not rewrite previously applied migrations.
- When changing CLI generators, update and test their generated output as well as the generator.
- Use `$app->format` (or `$format` in views) for localized display values. Money uses decimal strings or integer minor units, never floats; keep stored/API numeric data locale-independent.
- Handle multipart uploads through `Request::file()`/`files()` and `$app->uploads`, with explicit allowed types and private storage. Authorize ownership before downloads/deletions; storage keys are not credentials.
- Use `$app->log` for structured application diagnostics. Keep messages/context free of secrets; exception logging records source metadata only. Log retention is configured through `LOG_RETENTION_DAYS`.

## Validation

- Run commands from the project root. Use `php breeze help` to inspect available commands.
- Run `php breeze test` for the native regression suite; add coverage for changed behavior.
- CI runs that suite on Ubuntu and Windows with PHP 8.3 and 8.5, using `pdo_sqlite`, `openssl`, `iconv`, `mbstring`, `zip`, `dom`, `intl`, and `fileinfo`.
- Validate changed PHP syntax with `php -l path/to/file.php`. Breeze templates must be exercised through the template engine, not linted as ordinary PHP.
- For UI changes, check rendered desktop/mobile layouts and relevant form states.
- Do not run migrations, seeds, email sending, or other data-changing commands against a real application merely to validate code. Prefer the isolated test fixtures.
- Documentation-only changes do not require a full test run.
