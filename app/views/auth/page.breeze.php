@extends('layouts.app')
@section('title'){{ $title }} - {{ $name }}@endsection
@section('content')
<section class="auth-shell" aria-labelledby="auth-title">
    <div class="auth-heading">
        <h1 id="auth-title">{{ $title }}</h1>
        <p>{{ $description }}</p>
        @if($mode === 'login')
            <p class="auth-switch">Nog geen account? <a href="/register">Registreren</a></p>
        @elseif($mode === 'register')
            <p class="auth-switch">Al een account? <a href="/login">Inloggen</a></p>
        @else
            <p class="auth-switch"><a href="/login">Naar inloggen</a> <span aria-hidden="true">&middot;</span> <a href="/account">Mijn account</a></p>
        @endif
    </div>
    <div class="auth-body">
        @if($errors !== [])
            <div class="feedback feedback-error" role="alert" id="auth-errors" tabindex="-1">
                <strong>Controleer je invoer</strong>
                <ul>
                    @foreach($errors as $field => $messages)
                        @foreach($messages as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        @endif
        @if($message !== null)
            <div class="feedback feedback-success" role="status">{{ $message }}</div>
            <p class="auth-next"><a href="/login">Inloggen</a> of <a href="/account">verder naar je account</a>.</p>
        @elseif($mode === 'account')
            <dl class="account-details">
                <div><dt>Naam</dt><dd>{{ $user['name'] }}</dd></div>
                <div><dt>E-mailadres</dt><dd>{{ $user['email'] }}</dd></div>
                <div><dt>Verificatie</dt><dd>Bevestigd</dd></div>
            </dl>
        @else
            @if($mode === 'notice')
                <p>Verificatiemail voor <strong>{{ $user['email'] }}</strong>. Kijk ook in je spammap.</p>
                <p class="field-hint">Een verificatielink is 24 uur geldig. Met een nieuwe aanvraag vervalt de vorige link.</p>
            @endif
            <form method="post" action="{{ $action }}" @if($errors !== []) aria-describedby="auth-errors" @endif>
                @csrf($session)
                @if($mode === 'reset' || $mode === 'verify')
                    <input type="hidden" name="token" value="{{ $token }}">
                @endif
                @if($mode === 'register')
                    @include('auth.field', ['field' => 'name', 'label' => 'Naam', 'type' => 'text', 'autocomplete' => 'name', 'value' => $oldName, 'minimum' => 2, 'maximum' => 80])
                @endif
                @if(in_array($mode, ['login', 'register', 'forgot'], true))
                    @include('auth.field', ['field' => 'email', 'label' => 'E-mailadres', 'type' => 'email', 'autocomplete' => 'email', 'value' => $oldEmail, 'minimum' => 1, 'maximum' => 254])
                @endif
                @if(in_array($mode, ['login', 'register', 'reset'], true))
                    @include('auth.field', ['field' => 'password', 'label' => 'Wachtwoord', 'type' => 'password', 'autocomplete' => $mode === 'login' ? 'current-password' : 'new-password', 'value' => '', 'minimum' => $mode === 'login' ? 1 : 12, 'maximum' => 72])
                    @if($mode !== 'login')
                        <p class="field-hint" id="password-hint">Minimaal 12 tekens en maximaal 72 bytes. Een spatie of wachtwoordzin mag.</p>
                        @include('auth.field', ['field' => 'password_confirmation', 'label' => 'Herhaal je wachtwoord', 'type' => 'password', 'autocomplete' => 'new-password', 'value' => '', 'minimum' => 12, 'maximum' => 72])
                    @endif
                @endif
                @if($captcha !== null)
                    @include('partials.bot-protection')
                @endif
                <button type="submit">{{ $button }}</button>
                @if($captcha !== null)
                    <button class="button-secondary captcha-refresh" type="submit" name="_captcha_refresh" value="1" formnovalidate>Andere code tonen</button>
                @endif
                @if($mode === 'login')
                    <p class="auth-next"><a href="/forgot-password">Wachtwoord vergeten?</a></p>
                @elseif($mode === 'reset' || $mode === 'verify')
                    <p class="auth-next">Link verlopen? <a href="{{ $mode === 'reset' ? '/forgot-password' : '/email/verify' }}">Vraag een nieuwe link aan</a>.</p>
                @endif
            </form>
        @endif
        @if($user !== null)
            <form method="post" action="/logout" class="auth-logout">
                @csrf($session)
                <button type="submit" class="button-secondary">Uitloggen</button>
            </form>
        @endif
    </div>
</section>
@endsection
