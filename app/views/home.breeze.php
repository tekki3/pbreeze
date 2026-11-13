@extends('layouts.app')

@section('title')
{{ $name }} - Your application is running
@endsection

@section('content')
        <section class="intro" aria-labelledby="welcome-title">
            <div class="intro-copy">
                <h1 id="welcome-title">A fresh start.<br><span>Ready to build.</span></h1>
                <p>Your application is running. A clear foundation in native PHP that keeps you in control. No Composer or autoloader.</p>
                <a class="text-link" href="#try-it">Try your first request <span aria-hidden="true">&darr;</span></a>
            </div>
            <aside class="project-map" aria-label="Your application structure">
                <div class="code-caption"><span class="file-dot" aria-hidden="true"></span> Your application, clearly organized</div>
                <dl>
                    <div><dt>routes/web.php</dt><dd>Your request starts here</dd></div>
                    <div><dt>app/controllers/</dt><dd>Your application logic</dd></div>
                    <div><dt>app/views/</dt><dd>Plain PHP and HTML</dd></div>
                    <div><dt>database/migrations/</dt><dd>Control your database</dd></div>
                </dl>
                <p><span aria-hidden="true">$ </span>php breeze serve</p>
            </aside>
        </section>

        <div class="workspace">
            <section id="try-it" class="form-section" aria-labelledby="form-title">
                <h2 id="form-title">From input to response.</h2>
                <p>Test the complete flow: from this form to your controller and back.</p>
                <form action="/greet#try-it" method="post">
                    @csrf($session)
                    <label for="name">Your name</label>
                    <input id="name" name="name" autocomplete="given-name" required minlength="2" maxlength="80"
                        placeholder="For example, Ada"
                        value="{{ $oldName ?? '' }}"
                        aria-describedby="name-hint{{ !empty($errors['name']) ? ' name-error' : '' }}"
                        @if(!empty($errors['name'])) aria-invalid="true" @endif>
                    <p id="name-hint" class="field-hint">Use 2 to 80 characters. Your name is not stored.</p>
                    @if(!empty($errors['name']))
                        @component('components.feedback', ['kind' => 'error', 'id' => 'name-error'])
                            {{ implode(' ', $errors['name']) }}
                        @endcomponent
                    @endif
                    @include('partials.bot-protection')
                    <button type="submit">Send your name <span aria-hidden="true">&rarr;</span></button>
                    <button class="button-secondary captcha-refresh" type="submit" name="_captcha_refresh" value="1" formnovalidate>Show another code</button>
                    @if(!empty($greeting))
                        @component('components.feedback', ['kind' => 'success'])
                            <strong>{{ $greeting }}</strong>
                            <span>Your request was processed. The form works.</span>
                        @endcomponent
                    @endif
                </form>
                <div class="request-details">
                    <span class="method">POST</span><code>/greet</code>
                    <span>Server validation &middot; CSRF &middot; HTML escaping</span>
                </div>
            </section>

            <section id="build" class="build-section" aria-labelledby="build-title">
                <h2 id="build-title">Make it your application.</h2>
                <p>Start small. Create a controller and a view, then register your route in <code>routes/web.php</code>.</p>
                <div class="terminal">
                    <div class="terminal-header"><span>Your first building blocks</span><span>CLI</span></div>
                    <pre aria-label="Commands to create a controller, view, and model"><code><span class="code-comment"># Make room for your idea</span>
<span class="prompt">$</span> php breeze make:controller PostController
<span class="prompt">$</span> php breeze make:view posts.index
<span class="prompt">$</span> php breeze make:model Post

<span class="code-comment"># Check your application</span>
<span class="prompt">$</span> php breeze test</code></pre>
                </div>
                <details class="more-commands">
                    <summary>Database and email commands</summary>
                    <div class="extra-commands">
                        <p>Migrations and sample data</p>
                        <pre><code>php breeze make:migration create_posts
php breeze migrate
php breeze db:seed</code></pre>
                        <p>Send stored emails and fetch your inbox</p>
                        <pre><code>php breeze mail:work 50
php breeze mail:fetch 50</code></pre>
                        <p>First configure your SMTP and IMAP settings in <code>.env</code>.</p>
                    </div>
                </details>
                <p class="help-note">Explore all commands: <code>php breeze help</code></p>
            </section>
        </div>

        <section class="foundation" aria-labelledby="foundation-title">
            <h2 id="foundation-title">Small by design. Room to grow.</h2>
            <dl>
                <div><dt>Native PHP</dt><dd>Explicit imports. No hidden autoloading or external PHP packages.</dd></div>
                <div><dt>Secure building blocks</dt><dd>CSRF, validation, and prepared statements as your application foundation.</dd></div>
                <div><dt>Windows &amp; Linux</dt><dd>The same structure and CLI, on your workstation and your server.</dd></div>
            </dl>
        </section>
@endsection
