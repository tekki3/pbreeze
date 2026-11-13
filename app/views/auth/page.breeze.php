@extends('layouts.app')
@section('title'){{ $title }} - {{ $name }}@endsection
@section('content')
<section class="auth-shell" aria-labelledby="auth-title">
    <div class="auth-heading">
        <h1 id="auth-title">{{ $title }}</h1>
        <p>{{ $description }}</p>
        @if($mode === 'login')
            <p class="auth-switch">Need an account? <a href="/register">Register</a></p>
        @elseif($mode === 'register')
            <p class="auth-switch">Already have an account? <a href="/login">Log in</a></p>
        @else
            <p class="auth-switch"><a href="/login">Go to login</a> <span aria-hidden="true">&middot;</span> <a href="/account">My account</a></p>
        @endif
    </div>
    <div class="auth-body">
        @if($errors !== [])
            <div class="feedback feedback-error" role="alert" id="auth-errors" tabindex="-1">
                <strong>Check your input</strong>
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
            <p class="auth-next"><a href="/login">Log in</a> or <a href="/account">continue to your account</a>.</p>
        @elseif($mode === 'account')
            <dl class="account-details">
                <div><dt>Name</dt><dd>{{ $user['name'] }}</dd></div>
                <div><dt>Email address</dt><dd>{{ $user['email'] }}</dd></div>
                <div><dt>Verification</dt><dd>Verified</dd></div>
            </dl>
        @else
            @if($mode === 'notice')
                <p>Verification email for <strong>{{ $user['email'] }}</strong>. Check your spam folder too.</p>
                <p class="field-hint">A verification link is valid for 24 hours. Requesting a new link invalidates the previous one.</p>
            @endif
            <form method="post" action="{{ $action }}" @if($errors !== []) aria-describedby="auth-errors" @endif>
                @csrf($session)
                @if($mode === 'reset' || $mode === 'verify')
                    <input type="hidden" name="token" value="{{ $token }}">
                @endif
                @if($mode === 'register')
                    @include('auth.field', ['field' => 'name', 'label' => 'Name', 'type' => 'text', 'autocomplete' => 'name', 'value' => $oldName, 'minimum' => 2, 'maximum' => 80])
                @endif
                @if(in_array($mode, ['login', 'register', 'forgot'], true))
                    @include('auth.field', ['field' => 'email', 'label' => 'Email address', 'type' => 'email', 'autocomplete' => 'email', 'value' => $oldEmail, 'minimum' => 1, 'maximum' => 254])
                @endif
                @if(in_array($mode, ['login', 'register', 'reset'], true))
                    @include('auth.field', ['field' => 'password', 'label' => 'Password', 'type' => 'password', 'autocomplete' => $mode === 'login' ? 'current-password' : 'new-password', 'value' => '', 'minimum' => $mode === 'login' ? 1 : 12, 'maximum' => 72])
                    @if($mode !== 'login')
                        <p class="field-hint" id="password-hint">Use at least 12 characters and no more than 72 bytes. Spaces and passphrases are allowed.</p>
                        @include('auth.field', ['field' => 'password_confirmation', 'label' => 'Confirm your password', 'type' => 'password', 'autocomplete' => 'new-password', 'value' => '', 'minimum' => 12, 'maximum' => 72])
                    @endif
                @endif
                @if($captcha !== null)
                    @include('partials.bot-protection')
                @endif
                <button type="submit">{{ $button }}</button>
                @if($captcha !== null)
                    <button class="button-secondary captcha-refresh" type="submit" name="_captcha_refresh" value="1" formnovalidate>Show another code</button>
                @endif
                @if($mode === 'login')
                    <p class="auth-next"><a href="/forgot-password">Forgot your password?</a></p>
                @elseif($mode === 'reset' || $mode === 'verify')
                    <p class="auth-next">Link expired? <a href="{{ $mode === 'reset' ? '/forgot-password' : '/email/verify' }}">Request a new link</a>.</p>
                @endif
            </form>
        @endif
        @if($user !== null)
            <form method="post" action="/logout" class="auth-logout">
                @csrf($session)
                <button type="submit" class="button-secondary">Log out</button>
            </form>
        @endif
    </div>
</section>
@endsection
