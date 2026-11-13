# PBreeze

Een kleine, leerbare MVC-basis in native PHP 8.3+. Geen Composer, externe
packages, PSR-autoloading, container of verborgen bestandsdetectie. Alleen
expliciete `require_once`-regels en PHP-extensies.

## Beginnen

Vereist: PHP 8.3+, PDO, `pdo_sqlite` voor SQLite of `pdo_mysql` voor MySQL,
en de standaardextensies session, json en filter. De tests gebruiken daarnaast
de native proces- en socketfuncties. Er is geen installatie van packages nodig.

```sh
php breeze init
php breeze migrate
php breeze db:seed
php breeze test
php breeze serve
```

Open <http://127.0.0.1:8000>. Het voorbeeldformulier demonstreert CSRF,
validatie en veilige HTML-uitvoer. De ontwikkelserver blijft op localhost.
Gebruik `php breeze serve 8080` voor een andere poort.

`init` maakt een lokale `.env`, maar overschrijft nooit een bestaande.
De standaard is SQLite in `database/app.sqlite`; de database wordt bij het
eerste databasecommando aangemaakt. Het formulier heeft geen database nodig.

## Zo loopt een verzoek

1. De webserver bedient uitsluitend `public/`.
2. `public/index.php` laadt `bootstrap.php`.
3. De bootstrap laadt de frameworkbestanden expliciet en leest de configuratie.
4. De applicatie start de sessie en laadt `routes/web.php`.
5. Een onveilig HTTP-verzoek wordt eerst op CSRF gecontroleerd.
6. De router roept je controller aan; die geeft een `Response` terug.

```text
app/
  controllers/       HTTP-acties
  models/            Databaselogica, zonder verplicht ORM
  views/             Gewone PHP-templates
config/app.php       Getypeerd gebruikte applicatie-instellingen
database/
  migrations/        Anonieme Migration-objecten met up/down
  seeders/           Closures voor voorbeeld- of startdata
framework/           Kleine native bouwstenen
public/              Enige publieke document root
routes/web.php       Expliciete imports en routeregistratie
storage/             Migratieslot; schrijfbaar voor de CLI
tests/run.php        Native tests, inclusief echte HTTP-verzoeken
bootstrap.php        Expliciete frameworkregistratie
breeze               CLI, starten met php breeze
```

## Controllers en routes

```sh
php breeze make:controller BerichtController
php breeze make:view berichten.index
php breeze make:model Bericht
```

Laad een nieuwe controller **zelf expliciet** in `routes/web.php`:

```php
require_once $app->root . '/app/controllers/BerichtController.php';

$controller = new \App\Controllers\BerichtController($app);
$router->get('/berichten', [$controller, 'index']);
```

Een controlleractie ontvangt eerst een `Request`, gevolgd door eventuele
routeparameters **op volgorde**, en geeft altijd een `Response` terug:

```php
$router->get('/berichten/{id}', static function (\PBreeze\Request $request, string $id): \PBreeze\Response {
    return \PBreeze\Response::json(['id' => $id]);
});

$router->post('/berichten', [$controller, 'store']);
$router->add('DELETE', '/berichten/{id}', [$controller, 'destroy']);
```

Registreer specifieke routes vóór parameter-routes. Parameters beslaan één
padsegment; eigen regex in routes wordt bewust niet ondersteund. Trailing
slashes zijn gelijkwaardig. HEAD gebruikt GET tenzij een expliciete HEAD-route
bestaat. Onbekende paden geven 404, verkeerde methoden 405 met een `Allow`-header.
Er is geen automatische OPTIONS/CORS- of method-override-ondersteuning.

Lees formulier- en JSON-invoer met `$request->input('name')`, alle bodyvelden
met `$request->all()` en queryparameters afzonderlijk met `$request->query('page')`.
JSON moet een object zijn; ongeldige JSON geeft 400. Bestandsuploads en andere
bodyformaten hebben nog geen framework-API.

```php
return new \PBreeze\Response($this->app->views->render('berichten.index', [
    'title' => 'Mijn berichten',
]));

return \PBreeze\Response::json(['saved' => true], 201);
return \PBreeze\Response::redirect('/berichten');
```

Redirects accepteren alleen lokale paden die beginnen met één `/`.

## Views en validatie

Een viewnaam zoals `berichten.index` verwijst naar
`app/views/berichten/index.php`. Data wordt beschikbaar als variabelen.
Escape **alle onbetrouwbare waarden** met `e()`:

```php
<h1><?= e($title) ?></h1>
```

Dit is een expliciete helper, geen automatische template-escaping.
Gebruik `e()` voor HTML-tekst en geciteerde attributen, niet als vervanging voor
URL-validatie of JavaScript-escaping. Templates zijn vertrouwde PHP-code;
laat bezoekers nooit templates uploaden of een viewpad bepalen.

```php
$errors = \PBreeze\Validator::errors($request->all(), [
    'name' => 'required|string|min:2|max:80',
    'email' => 'required|email',
    'age' => 'integer',
    'password' => 'required|string|min:12|confirmed',
]);
```

`errors()` geeft per veld een lijst Nederlandstalige fouten terug, of `[]`.
`min` en `max` controleren het aantal Unicode-tekens van **strings**, niet
getalswaarden. Zonder `required` mogen velden ontbreken of leeg zijn.
`confirmed` vergelijkt met bijvoorbeeld `password_confirmation`.
Onbekende of verkeerd ingestelde regels veroorzaken een exception.

Controleer `$errors` vóór je gegevens opslaat. Het voorbeeld in
`HomeController` geeft bij validatiefouten status 422, toont fouten bij het veld
en bewaart de invoer. Kies bij andere formulieren zelf dezelfde HTML-aanpak of
een JSON-response met status 422.

## Sessies en CSRF

Alle POST-, PUT-, PATCH- en DELETE-verzoeken vereisen een sessietoken.
Alleen GET, HEAD en OPTIONS zijn vrijgesteld; wijzig daarmee geen gegevens.

```php
<form action="/berichten" method="post">
    <?= csrf_field($session) ?>
    <button type="submit">Opslaan</button>
</form>
```

Geef `$this->app->session` als `session` mee aan de view.
Een JSON-client kan hetzelfde token als `X-CSRF-Token` meesturen, samen met
de sessiecookie. Een verkeerd of ontbrekend token geeft 419. Er zijn bewust
geen standaard CSRF-uitzonderingen of onbeveiligde API-routes.

```php
$this->app->session->put('notice', 'Opgeslagen');
$notice = $this->app->session->get('notice');
$this->app->session->regenerate();
```

Vernieuw het sessie-ID na een toekomstige login of wijziging van rechten.
Authenticatie en autorisatie zijn niet meegeleverd; een CSRF-token is geen
toegangscontrole. Voor wachtwoorden gebruik je native `password_hash()`,
`password_verify()` en `password_needs_rehash()`.

## Database en modellen

```dotenv
DB_CONNECTION=mysql
DB_DATABASE=pbreeze
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=pbreeze
DB_PASSWORD="je eigen wachtwoord"
```

Maak de MySQL-database en een aparte gebruiker zelf aan. MySQL gebruikt
`utf8mb4` en echte prepared statements; gebruik voor transacties InnoDB.
SQLite heeft foreign keys en een busy timeout ingeschakeld.

```php
$rows = $app->db->execute(
    'SELECT id, title FROM notes WHERE title = ?',
    [$title],
)->fetchAll();

$app->db->transaction(static function (\PDO $pdo): void {
    // Alleen databasewerk; een exception veroorzaakt rollback.
});
```

Bind altijd de **waarden**; plak gebruikersinvoer nooit in SQL.
Tabelnamen, kolomnamen en sorteerrichtingen kun je niet binden: gebruik
daarvoor een vaste whitelist. Vermijd geneste transacties en DDL in
`Database::transaction()`.

`app/models/Note.php` is een concreet modelvoorbeeld:

```php
require_once $app->root . '/app/models/Note.php';
$notes = new \App\Models\Note($app->db);
$id = $notes->create('Mijn eerste notitie');
$recent = $notes->latest(20);
```

Er is bewust geen ORM, querybuilder of automatische mass assignment.

## Migraties en seeds

```sh
php breeze make:migration create_berichten
php breeze migrate
php breeze migrate:status
php breeze migrate:rollback
php breeze make:seeder BerichtSeeder
php breeze db:seed BerichtSeeder
```

Vul de gegenereerde `up(PDO $db)` en `down(PDO $db)` eerst in.
De templates gooien bewust een exception zolang ze niet geïmplementeerd zijn:
een lege migratie wordt zo niet onterecht als uitgevoerd geregistreerd.
Bestanden worden op tijdstempel gesorteerd. Elke run vormt een batch;
rollback draait **de hele laatste batch** in omgekeerde volgorde terug.
Wijzig of verwijder geen toegepaste migraties; maak een nieuwe migratie.

SQLite voert iedere migratie plus registratie in één transactie uit.
MySQL DDL veroorzaakt impliciete commits: een mislukte migratie kan daar al
deels toegepast zijn. Maak vóór wijzigingen een back-up en herstel een
gedeeltelijke migratie bewust vóór je opnieuw draait. Schrijf driverafhankelijke
SQL waar nodig; de voorbeeldmigratie laat dit zien.

Een lokaal bestandsslot voorkomt gelijktijdige migraties vanuit dezelfde
projectmap. MySQL gebruikt bovendien een databasebreed advisory lock tussen
PBreeze-processen. Gebruik SQLite op één host met lokale opslag.

Seeders geven een `Closure(PDO $db): void` terug en draaien in een transactie.
Gebruik seeders voor DML, niet voor DDL. De voorbeeldseeder is herhaalbaar;
zorg daar bij eigen seeders zelf voor. Seeders worden niet als migraties
geregistreerd.

Databasewijzigingen in productie vereisen `--force`:

```sh
php breeze migrate --force
php breeze db:seed --force
php breeze migrate:rollback --force
```

Generators weigeren bestaande bestanden te overschrijven. Nieuwe controllers
en modellen worden nooit automatisch geladen.

## Configuratie

Systeemomgevingsvariabelen gaan vóór `.env`. `.env` ondersteunt
`NAAM=waarde`, optionele enkele/dubbele quotes en volledige commentaarregels.
Er is geen interpolatie, escapeverwerking, multiline- of inline-commentaarsyntax.
Gebruik `true`/`false` voor booleans. `.env` en databasebestanden staan in
`.gitignore`; nieuwe `.env`- en SQLite-bestanden krijgen rechten `0600`.
`APP_ENV` accepteert uitsluitend `local`, `testing` en `production`;
een typefout schakelt de productiebeveiliging dus niet stilzwijgend uit.

## Productie en veiligheidsgrenzen

- Zet de document root **uitsluitend op `public/`**, nooit op de projectroot.
- Gebruik HTTPS, `APP_ENV=production` en `APP_DEBUG=false`.
- Achter een HTTPS reverse proxy: zet `SESSION_SECURE_COOKIE=true`.
  Het framework vertrouwt forwarded headers bewust niet automatisch.
- Zonder HTTPS of expliciete secure-cookieconfiguratie weigert productie
  te starten. Een secure cookie maakt een HTTP-verbinding niet versleuteld:
  forceer HTTPS ook in je webserver of proxy.
- Zorg dat PHP-foutmeldingen alleen naar beschermde serverlogs gaan;
  configureer ook PHP zelf met `display_errors=Off` en `log_errors=On`.
- Geef alleen de benodigde gebruiker schrijfrechten op de SQLite-database,
  de databasedirectory en `storage/`. Een met `0600` aangemaakt bestand is
  alleen leesbaar door de eigenaar: stem de CLI/PHP-FPM-gebruiker daarop af.
  Maak niet de hele applicatie schrijfbaar voor de webserver.
- Beperk requestgroottes en timeouts in PHP en de webserver; regel rate limiting
  en TLS/HSTS op proxy- of webserverniveau.
- De standaardheaders bevatten CSP, `nosniff`, frameblokkering, beperkt
  permissiebeleid en `Cache-Control: no-store` voor applicatieresponses.
  De CSP staat geen scripts of inline styles toe. Zelfgeschreven responses
  kunnen headers expliciet aanpassen; houd die wijzigingen beperkt.
- Statische bestanden worden direct door de webserver bediend: configureer
  daarvoor zelf passende cache- en veiligheidsheaders.
- Voeg vóór publieke ingebruikname je eigen autorisatie, authenticatie en
  toepassingsspecifieke validatie toe. De voorbeeldapp heeft geen accounts.
- Draai de ontwikkelserver nooit als productieserver. Veiligheidsmaatregelen
  zijn geen garantie: laat een echte applicatie vóór uitrol beoordelen.

Apache kan de meegeleverde `public/.htaccess` gebruiken met `mod_rewrite`
en toegestane overrides. Stel bij Nginx PHP-FPM in voor uitsluitend
`/index.php`, weiger verborgen bestanden en gebruik bijvoorbeeld:

```nginx
root /pad/naar/pbreeze/public;
index index.php;

location ~ /\. { deny all; }
location / { try_files $uri $uri/ /index.php?$query_string; }
location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
location ~ \.php$ { return 404; }
```

Pas het socketpad aan je installatie aan en configureer HTTPS apart.
Plaats nooit vertrouwelijke bestanden of uploads met uitvoerbare code in `public/`.

## Tests

```sh
php breeze test
php breeze routes
```

De tests gebruiken een geïsoleerde tijdelijke SQLite-database, testen alle
generators en starten kort een lokale HTTP-server op een vrije poort.
De server wordt gestopt en de tijdelijke bestanden worden opgeruimd.
MySQL vereist daarnaast een eigen integratierun op een wegwerpdatabase;
de meegeleverde suite valideert de MySQL-verbinding niet tegen een live server.
