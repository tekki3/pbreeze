<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }} API reference</title>
    <link rel="stylesheet" href="/assets/swagger-ui/swagger-ui.css">
    <link rel="stylesheet" href="/assets/api-docs.css">
    <script src="/assets/swagger-ui/swagger-ui-bundle.js" defer></script>
    <script src="/assets/api-docs.js" defer></script>
</head>
<body>
    <header class="docs-header">
        <a href="/" class="docs-brand">{{ $name }}</a>
        <nav aria-label="API documentation">
            <a href="#swagger-ui">API reference</a>
            <a href="/api/openapi.json" download="openapi.json">Download OpenAPI</a>
        </nav>
    </header>
    <main>
        <div class="docs-intro">
            <h1>API reference</h1>
            <p>Explore endpoints and send requests to this server. Protected endpoints require a verified user's bearer token issued with <code>php breeze api:token</code>.</p>
            <p>Use <strong>Authorize</strong> to enter the token. It stays in this tab's memory only; never use a real production token on a shared device.</p>
        </div>
        <noscript><p class="docs-intro">Enable JavaScript for interactive documentation, or download the OpenAPI definition above.</p></noscript>
        <div id="docs-error" class="docs-intro" role="alert" hidden>Documentation could not load. Reload this page or download the OpenAPI definition above.</div>
        <div id="swagger-ui" aria-label="Interactive API documentation"></div>
    </main>
</body>
</html>
