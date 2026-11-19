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
        @if($mode === 'login' && $ssoEnabled)
            <form method="post" action="/sso/login" class="auth-sso">
                @csrf($session)
                <button type="submit" class="button-secondary">Continue with {{ $ssoName }}</button>
            </form>
            <p class="field-hint">Or use your local account below.</p>
        @endif
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
                <div><dt>Multi-factor authentication</dt><dd>{{ $mfaEnabled ? 'Enabled' : 'Not enabled' }}</dd></div>
            </dl>
            @if($mfaEnabled || $mfaEnrollmentEnabled)
                <p class="auth-next"><a href="/account/mfa">{{ $mfaEnabled ? 'Manage multi-factor authentication' : 'Set up multi-factor authentication' }}</a></p>
            @else
                <p class="auth-next">New authenticator enrollment is currently unavailable. Existing enrollments are not affected.</p>
            @endif
        @elseif($mode === 'mfa-unavailable')
            <p>You cannot start or confirm a new authenticator setup while enrollment is disabled. Contact the account administrator if you need help.</p>
            <p class="auth-next"><a href="/account">Return to my account</a></p>
        @elseif($mode === 'mfa-recovery')
            @if($regenerated)
                <p>Your old recovery codes are no longer valid. Your authenticator is unchanged.</p>
            @endif
            <p>Each recovery code works once in place of an authenticator code. These codes are shown only now.</p>
            <ul class="mfa-recovery-codes" aria-label="Single-use recovery codes">
                @foreach($recoveryCodes as $recoveryCode)
                    <li><code>{{ $recoveryCode }}</code></li>
                @endforeach
            </ul>
            <p>Password resets do not remove multi-factor authentication. If you lose your authenticator and all recovery codes, contact the account administrator.</p>
            <p class="auth-next"><a href="/account">I saved my codes. Continue to my account.</a></p>
        @else
            @if($mode === 'mfa-disable')
                <p class="auth-next"><a href="/account/mfa/recovery-codes">Regenerate recovery codes without disabling MFA</a></p>
            @elseif($mode === 'mfa-regenerate')
                <p class="mfa-recovery-warning">All old recovery codes will stop working immediately. Other login sessions and API tokens will be invalidated. Save the new codes when they appear; they cannot be retrieved again.</p>
                <p class="auth-next"><a href="/account/mfa">Cancel and return to MFA settings</a></p>
            @endif
            @if($mode === 'notice')
                <p>Verification email for <strong>{{ $user['email'] }}</strong>. Check your spam folder too.</p>
                <p class="field-hint">A verification link is valid for 24 hours. Requesting a new link invalidates the previous one.</p>
            @endif
            @if($mode === 'mfa-confirm')
                @if($setup !== null)
                    <p>Scan this QR code with your authenticator app:</p>
                    <div class="mfa-qr">{!! $setupQr !!}</div>
                    <p>Or add a time-based account manually using this setup key:</p>
                    <p class="mfa-secret"><code>{{ $setup['secret'] }}</code></p>
                    <p class="auth-next"><a href="{{ $setup['uri'] }}">Open in an authenticator app on this device</a></p>
                    <p class="field-hint">Use SHA-1, six digits, and a 30-second period. Keep this key private.</p>
                @endif
                <p class="auth-next">Need a new setup key? <a href="/account/mfa">Start setup again</a>.</p>
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
                @if(in_array($mode, ['login', 'register', 'reset', 'verify', 'mfa-setup', 'mfa-disable', 'mfa-regenerate'], true))
                    @include('auth.field', ['field' => 'password', 'label' => in_array($mode, ['mfa-setup', 'mfa-disable', 'mfa-regenerate'], true) ? 'Current password' : 'Password', 'type' => 'password', 'autocomplete' => in_array($mode, ['login', 'mfa-setup', 'mfa-disable', 'mfa-regenerate'], true) ? 'current-password' : 'new-password', 'value' => '', 'minimum' => in_array($mode, ['login', 'mfa-setup', 'mfa-disable', 'mfa-regenerate'], true) ? 1 : 12, 'maximum' => 72])
                    @if(in_array($mode, ['register', 'reset', 'verify'], true))
                        <p class="field-hint" id="password-hint">Use at least 12 characters and no more than 72 bytes. Spaces and passphrases are allowed.</p>
                        @include('auth.field', ['field' => 'password_confirmation', 'label' => 'Confirm your password', 'type' => 'password', 'autocomplete' => 'new-password', 'value' => '', 'minimum' => 12, 'maximum' => 72])
                    @endif
                @endif
                @if(in_array($mode, ['mfa', 'mfa-confirm', 'mfa-disable', 'mfa-regenerate'], true))
                    <div class="auth-field">
                        <label for="code">{{ $mode === 'mfa-confirm' ? 'Authenticator code' : 'Authenticator or recovery code' }}</label>
                        <input id="code" name="code" type="text" autocomplete="one-time-code" required
                            minlength="6" maxlength="{{ $mode === 'mfa-confirm' ? 6 : 20 }}"
                            @if($mode === 'mfa-confirm') inputmode="numeric" pattern="[0-9]{6}" @endif
                            @if(!empty($errors['code'])) aria-invalid="true" aria-describedby="code-error" @endif>
                        @if(!empty($errors['code']))
                            <p class="field-error" id="code-error">{{ implode(' ', $errors['code']) }}</p>
                        @endif
                    </div>
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
            @if($mode === 'mfa')
                <form method="post" action="/logout" class="auth-logout">
                    @csrf($session)
                    <button type="submit" class="button-secondary">Cancel login</button>
                </form>
            @endif
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
