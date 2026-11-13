@extends('layouts.app')

@section('title')
{{ $name }} - Je applicatie draait
@endsection

@section('content')
        <section class="intro" aria-labelledby="welcome-title">
            <div class="intro-copy">
                <h1 id="welcome-title">Een frisse start.<br><span>Klaar om te bouwen.</span></h1>
                <p>Je applicatie draait. Een heldere basis in native PHP, met de controle in jouw handen. Zonder Composer of autoloader.</p>
                <a class="text-link" href="#proberen">Probeer je eerste request <span aria-hidden="true">&darr;</span></a>
            </div>
            <aside class="project-map" aria-label="De structuur van je applicatie">
                <div class="code-caption"><span class="file-dot" aria-hidden="true"></span> Jouw applicatie, overzichtelijk ingedeeld</div>
                <dl>
                    <div><dt>routes/web.php</dt><dd>Hier begint je request</dd></div>
                    <div><dt>app/controllers/</dt><dd>Jouw applicatielogica</dd></div>
                    <div><dt>app/views/</dt><dd>Gewoon PHP en HTML</dd></div>
                    <div><dt>database/migrations/</dt><dd>Grip op je database</dd></div>
                </dl>
                <p><span aria-hidden="true">$ </span>php breeze serve</p>
            </aside>
        </section>

        <div class="workspace">
            <section id="proberen" class="form-section" aria-labelledby="form-title">
                <h2 id="form-title">Van invoer naar antwoord.</h2>
                <p>Test de volledige route: van dit formulier naar je controller en weer terug.</p>
                <form action="/greet#proberen" method="post">
                    @csrf($session)
                    <label for="name">Je naam</label>
                    <input id="name" name="name" autocomplete="given-name" required minlength="2" maxlength="80"
                        placeholder="Bijvoorbeeld Ada"
                        value="{{ $oldName ?? '' }}"
                        aria-describedby="name-hint{{ !empty($errors['name']) ? ' name-error' : '' }}"
                        @if(!empty($errors['name'])) aria-invalid="true" @endif>
                    <p id="name-hint" class="field-hint">Gebruik 2 tot 80 tekens. Je naam wordt niet opgeslagen.</p>
                    @if(!empty($errors['name']))
                        @component('components.feedback', ['kind' => 'error', 'id' => 'name-error'])
                            {{ implode(' ', $errors['name']) }}
                        @endcomponent
                    @endif
                    @include('partials.bot-protection')
                    <button type="submit">Verstuur je naam <span aria-hidden="true">&rarr;</span></button>
                    <button class="button-secondary captcha-refresh" type="submit" name="_captcha_refresh" value="1" formnovalidate>Andere code tonen</button>
                    @if(!empty($greeting))
                        @component('components.feedback', ['kind' => 'success'])
                            <strong>{{ $greeting }}</strong>
                            <span>Je request is verwerkt. Het formulier werkt.</span>
                        @endcomponent
                    @endif
                </form>
                <div class="request-details">
                    <span class="method">POST</span><code>/greet</code>
                    <span>Servervalidatie &middot; CSRF &middot; HTML-escaping</span>
                </div>
            </section>

            <section id="bouwen" class="build-section" aria-labelledby="build-title">
                <h2 id="build-title">Maak het jouw applicatie.</h2>
                <p>Begin klein. Maak een controller en een view, en registreer je route in <code>routes/web.php</code>.</p>
                <div class="terminal">
                    <div class="terminal-header"><span>Je eerste bouwstenen</span><span>CLI</span></div>
                    <pre aria-label="Commando's voor een nieuwe controller, view en model"><code><span class="code-comment"># Maak ruimte voor je eigen idee</span>
<span class="prompt">$</span> php breeze make:controller BerichtController
<span class="prompt">$</span> php breeze make:view berichten.index
<span class="prompt">$</span> php breeze make:model Bericht

<span class="code-comment"># Controleer je applicatie</span>
<span class="prompt">$</span> php breeze test</code></pre>
                </div>
                <details class="more-commands">
                    <summary>Database- en e-mailcommando's</summary>
                    <div class="extra-commands">
                        <p>Migraties en voorbeelddata</p>
                        <pre><code>php breeze make:migration create_berichten
php breeze migrate
php breeze db:seed</code></pre>
                        <p>Opgeslagen e-mails verzenden en je inbox ophalen</p>
                        <pre><code>php breeze mail:work 50
php breeze mail:fetch 50</code></pre>
                        <p>Configureer eerst je SMTP- en IMAP-gegevens in <code>.env</code>.</p>
                    </div>
                </details>
                <p class="help-note">Alle mogelijkheden bekijken? <code>php breeze help</code></p>
            </section>
        </div>

        <section class="foundation" aria-labelledby="foundation-title">
            <h2 id="foundation-title">Klein van opzet. Ruimte om te groeien.</h2>
            <dl>
                <div><dt>Native PHP</dt><dd>Expliciete bestanden. Geen verborgen autoloading of externe packages.</dd></div>
                <div><dt>Veilige bouwstenen</dt><dd>CSRF, validatie en prepared statements als basis voor jouw applicatie.</dd></div>
                <div><dt>Windows &amp; Linux</dt><dd>Dezelfde structuur en CLI, op je werkplek en op je server.</dd></div>
            </dl>
        </section>
@endsection
