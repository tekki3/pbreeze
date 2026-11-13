<div class="form-trap" aria-hidden="true">
    <label for="website">Laat dit veld leeg</label>
    <input id="website" name="website" type="text" value="" tabindex="-1" autocomplete="off">
</div>
<fieldset class="captcha-field">
    <legend>Beveiligingscontrole</legend>
    <p class="field-hint" id="captcha-hint">Neem de 6 tekens over. Hoofdletters of kleine letters maakt niet uit. De code is 5 minuten geldig.</p>
    <img class="captcha-image" src="{{ $captcha['image'] }}" width="240" height="80"
        alt="Beveiligingscode met zes tekens om hieronder over te nemen">
    <input type="hidden" name="_captcha_id" value="{{ $captcha['id'] }}">
    <label for="captcha-answer">Code uit de afbeelding</label>
    <input id="captcha-answer" name="_captcha_answer" type="text" required minlength="6" maxlength="6"
        autocomplete="off" autocapitalize="characters" spellcheck="false"
        aria-describedby="captcha-hint{{ !empty($errors['captcha']) ? ' captcha-error' : '' }}"
        @if(!empty($errors['captcha'])) aria-invalid="true" @endif>
    @if(!empty($errors['captcha']))
        <p class="field-error" id="captcha-error" role="alert">{{ implode(' ', $errors['captcha']) }}</p>
    @endif
    @if(!empty($captchaRefreshed))
        <p class="field-hint" role="status">Nieuwe code geladen. Vul de code in en verstuur het formulier opnieuw.</p>
    @endif
</fieldset>
