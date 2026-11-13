<div class="form-trap" aria-hidden="true">
    <label for="website">Leave this field empty</label>
    <input id="website" name="website" type="text" value="" tabindex="-1" autocomplete="off">
</div>
<fieldset class="captcha-field">
    <legend>Security check</legend>
    <p class="field-hint" id="captcha-hint">Enter the 6 characters shown. Letters are not case-sensitive. The code is valid for 5 minutes.</p>
    <img class="captcha-image" src="{{ $captcha['image'] }}" width="240" height="80"
        alt="Six-character security code to enter below">
    <input type="hidden" name="_captcha_id" value="{{ $captcha['id'] }}">
    <label for="captcha-answer">Code from the image</label>
    <input id="captcha-answer" name="_captcha_answer" type="text" required minlength="6" maxlength="6"
        autocomplete="off" autocapitalize="characters" spellcheck="false"
        aria-describedby="captcha-hint{{ !empty($errors['captcha']) ? ' captcha-error' : '' }}"
        @if(!empty($errors['captcha'])) aria-invalid="true" @endif>
    @if(!empty($errors['captcha']))
        <p class="field-error" id="captcha-error" role="alert">{{ implode(' ', $errors['captcha']) }}</p>
    @endif
    @if(!empty($captchaRefreshed))
        <p class="field-hint" role="status">New code loaded. Enter the code and submit the form again.</p>
    @endif
</fieldset>
