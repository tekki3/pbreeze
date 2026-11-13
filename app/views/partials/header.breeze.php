<header class="site-header page-width">
    <a class="brand" href="/" aria-label="{{ $name }} home">
        <svg class="brand-mark" viewBox="0 0 36 36" fill="none" aria-hidden="true">
            <rect width="36" height="36" rx="10" fill="currentColor"/>
            <path d="M10 12h10a4 4 0 0 1 0 8H10m4-4h10M10 24h7" stroke="white" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <span>{{ $name }}</span>
    </a>
    <nav aria-label="Page navigation">
        <a href="/#try-it">Test the form</a>
        <a href="/#build">Get started</a>
        @if(!empty($user))
            <a href="/account">My account</a>
        @else
            <a href="/login">Log in</a>
        @endif
        <a class="health-link" href="/health">Health check <span aria-hidden="true">&rarr;</span></a>
    </nav>
</header>
