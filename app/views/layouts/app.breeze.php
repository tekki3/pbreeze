<!doctype html>
<html lang="en-US">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $name)</title>
    <meta name="color-scheme" content="light">
    <link rel="stylesheet" href="/assets/css/welcome.css">
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    @include('partials.header')
    <main id="main" class="page-width">
        @yield('content')
    </main>
    @include('partials.footer')
</body>
</html>
