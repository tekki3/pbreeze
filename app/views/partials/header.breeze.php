<header class="site-header page-width">
    <a class="brand" href="/" aria-label="{{ $name }} startpagina">
        <svg class="brand-mark" viewBox="0 0 36 36" fill="none" aria-hidden="true">
            <rect width="36" height="36" rx="10" fill="currentColor"/>
            <path d="M10 12h10a4 4 0 0 1 0 8H10m4-4h10M10 24h7" stroke="white" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <span>{{ $name }}</span>
    </a>
    <nav aria-label="Paginanavigatie">
        <a href="/#proberen">Formulier testen</a>
        <a href="/#bouwen">Aan de slag</a>
        @if(!empty($user))
            <a href="/account">Mijn account</a>
        @else
            <a href="/login">Inloggen</a>
        @endif
        <a class="health-link" href="/health">Healthcontrole <span aria-hidden="true">&rarr;</span></a>
    </nav>
</header>
