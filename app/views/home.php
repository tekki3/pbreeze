<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($name) ?> - Je applicatie draait</title>
</head>
<body>
    <main>
        <h1><?= e($name) ?></h1>
        <p>Je applicatie draait. Native PHP, zonder Composer of autoloader.</p>
        <h2>Probeer je eerste formulier</h2>
        <p>Dit voorbeeld gebruikt servervalidatie, HTML-escaping en een CSRF-token.</p>
        <?php if (!empty($greeting)): ?>
            <p role="status"><?= e($greeting) ?></p>
        <?php endif; ?>
        <form action="/greet" method="post">
            <?= csrf_field($session) ?>
            <p>
                <label for="name">Je naam</label><br>
                <input id="name" name="name" autocomplete="given-name" required minlength="2" maxlength="80"
                    value="<?= e($oldName ?? '') ?>"
                    <?php if (!empty($errors['name'])): ?>aria-invalid="true" aria-describedby="name-error"<?php endif; ?>>
            </p>
            <?php if (!empty($errors['name'])): ?>
                <p id="name-error" role="alert"><?= e(implode(' ', $errors['name'])) ?></p>
            <?php endif; ?>
            <button type="submit">Verstuur je naam</button>
        </form>
        <h2>Bouw verder</h2>
        <p>Registreer je routes in <code>routes/web.php</code> en gebruik <code>php breeze help</code> voor de CLI.</p>
        <ul>
            <li><code>php breeze make:controller BerichtController</code></li>
            <li><code>php breeze make:view berichten.index</code></li>
            <li><code>php breeze make:migration create_berichten</code></li>
            <li><code>php breeze migrate</code></li>
            <li><code>php breeze db:seed</code></li>
            <li><code>php breeze test</code></li>
        </ul>
        <p><a href="/health">Controleer de health-endpoint</a></p>
    </main>
</body>
</html>
