<div class="auth-field">
    <label for="{{ $field }}">{{ $label }}</label>
    <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" value="{{ $value }}"
        autocomplete="{{ $autocomplete }}" minlength="{{ $minimum }}" maxlength="{{ $maximum }}" required
        @if(!empty($errors[$field])) aria-invalid="true" aria-describedby="{{ $field }}-error"
        @elseif($field === 'password' && $mode !== 'login') aria-describedby="password-hint"
        @endif>
    @if(!empty($errors[$field]))
        <p class="field-error" id="{{ $field }}-error">{{ implode(' ', $errors[$field]) }}</p>
    @endif
</div>
