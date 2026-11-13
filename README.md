# PBreeze

Een kleine, leerbare MVC-basis in native PHP 8.3+. Geen Composer, externe
packages, PSR-autoloading, container of verborgen bestandsdetectie. Alleen
expliciete `require_once`-regels en PHP-extensies.

Inclusief native SMTP, IMAP, configureerbare e-maillogging en een databasewachtrij.

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

## E-mail: SMTP, IMAP en databasewachtrij

Dit gebruikt native PHP-streams, OpenSSL voor TLS en iconv voor inkomende
MIME-headers en tekensets. De IMAP-extensie, Composer en `mail()` zijn niet nodig.
Stel eerst je mailserver in en voer de nieuwe migratie uit:

```sh
php breeze migrate
```

In productie geldt hiervoor zoals gebruikelijk `php breeze migrate --force`.
Een bestaande `.env` wordt niet automatisch aangepast. Voeg onderstaande
instellingen zelf toe; de volledige lijst staat in `.env.example`.

```dotenv
MAIL_LOG=true
MAIL_FROM=afzender@jouwdomein.nl
SMTP_HOST=smtp.jouwprovider.nl
SMTP_PORT=587
SMTP_ENCRYPTION=starttls
SMTP_USERNAME=afzender@jouwdomein.nl
SMTP_PASSWORD="je app-wachtwoord"
IMAP_HOST=imap.jouwprovider.nl
IMAP_PORT=993
IMAP_ENCRYPTION=tls
IMAP_USERNAME=afzender@jouwdomein.nl
IMAP_PASSWORD="je app-wachtwoord"
IMAP_MAILBOX=INBOX
```

Gebruik de echte waarden van je provider; bovenstaande hosts zijn voorbeelden.
SMTP en IMAP kunnen verschillende accounts gebruiken. Native wachtwoordlogin
wordt ondersteund: SMTP AUTH PLAIN/LOGIN en IMAP LOGIN. Gebruik zo nodig een
app-wachtwoord. OAuth2 en providers die uitsluitend OAuth2 toestaan worden
niet ondersteund.

`tls` is directe TLS (gebruikelijk SMTP 465 en IMAP 993); `starttls` voert
een verplichte TLS-upgrade uit (gebruikelijk SMTP 587 en IMAP 143).
Er is geen terugval naar onversleuteld verkeer. TLS 1.2+ met controle van
certificaat en hostnaam is verplicht voor externe servers.
Voor een eigen CA kun je `SMTP_CA_FILE` en `IMAP_CA_FILE` op een leesbaar
CA-bestand instellen; hiermee wordt de verificatie niet uitgeschakeld.
`none` is alleen toegestaan op `localhost`/`127.0.0.1`, zonder credentials,
bijvoorbeeld voor een lokale SMTP-testserver. IMAP vereist dan PREAUTH.
`SMTP_TIMEOUT` en `IMAP_TIMEOUT` zijn socket-timeouts van 1–300 seconden,
standaard 15. Hosts zijn DNS-namen of IPv4-adressen, geen URL's.

### Vanuit PHP versturen of klaarzetten

Via `$app` in routes, of `$this->app` in controllers:

```php
$message = $app->mail->compose([
    'to' => ['ontvanger@voorbeeld.nl'],
    'subject' => 'Welkom',
    'text' => 'Welkom bij onze applicatie.',
    'html' => '<p>Welkom bij onze applicatie.</p>',
]);

$id = $app->mail->send($message);   // Meteen via SMTP.
// OF:
$id = $app->mail->queue($message);  // Alleen opslaan, later via CLI versturen.
```

`from` is optioneel in `compose()` en gebruikt standaard `MAIL_FROM`.
Je kunt ook rechtstreeks `new \PBreeze\Mail\Message(...)` gebruiken.
Gebruik kale ASCII-e-mailadressen zonder weergavenaam; maximaal 100 ontvangers.
Onderwerpen en bodies zijn UTF-8. Je kunt tekst, HTML of beide meegeven;
met beide wordt een `multipart/alternative`-bericht gemaakt. Onderwerpen
mogen geen stuurtekens bevatten en zijn maximaal 500 bytes; bodies zijn
samen maximaal 5 MiB. Headers worden gecodeerd en MIME-bodies base64 verpakt.
Cc, Bcc, bijlagen en zelf aangeleverde headers zijn niet inbegrepen.

`MAIL_LOG=true` is de standaard:

- Direct verzonden uitgaande berichten worden vóór SMTP opgeslagen in `emails`.
  De tabel bewaart afzender, ontvangers, onderwerp, tekst, HTML, Message-ID,
  status, pogingen, tijdstippen en een opgeschoonde foutmelding.
- Een databasefout vóór verzending verhindert dat het bericht wordt verstuurd
  zonder log. Een SMTP-fout geeft een exception en blijft in de tabel staan.
- Opgehaalde inkomende berichten worden in dezelfde tabel opgeslagen.
- `send()` geeft bij ingeschakelde logging het ID terug, anders `null`.

Met `MAIL_LOG=false` gebruiken direct versturen en IMAP-ophalen geen
e-mailtabel. **Expliciet `queue()` of `mail:queue` gebruiken slaat altijd op**:
een databasewachtrij kan niet zonder opslag. Alle opgeslagen uitgaande
berichten blijven dan via CLI te versturen en hun status wordt bijgewerkt.
Instellingen en wachtwoorden worden niet in de e-mailtabel opgeslagen.

### Via de CLI verzenden

Maak zelf een JSON-bestand, bijvoorbeeld `bericht.json`:

```json
{
  "to": ["ontvanger@voorbeeld.nl"],
  "subject": "Welkom",
  "text": "Welkom bij onze applicatie.",
  "html": "<p>Welkom bij onze applicatie.</p>"
}
```

```sh
php breeze mail:queue /pad/naar/bericht.json
php breeze mail:list
php breeze mail:show 1
php breeze mail:send 1
php breeze mail:work 50
```

`mail:queue` toont het nieuwe ID; vervang `1` door dat ID. Het JSON-bestand
mag maximaal 6 MiB zijn; de inhoudelijke bodylimiet blijft 5 MiB.
Bewaar zulke bestanden buiten `public/` en commit geen vertrouwelijke berichten.
`mail:work` verwerkt één batch, oudste eerst, en stopt bij de eerste fout
met exitcode 1. Het is geen achtergronddaemon. Je kunt dit commando periodiek
via cron aanroepen met absolute paden naar PHP en `breeze`.
`mail:list`, `mail:show` en `mail:fetch` geven JSON weer, zodat ook onbetrouwbare
onderwerpen geen terminalstuurcodes kunnen uitvoeren.

Verzendstatussen:

| Status | Betekenis |
|---|---|
| `queued` | Klaar voor verzending; de worker selecteert alleen deze status. |
| `sending` | Atomair geclaimd door een verzendproces. |
| `sent` | SMTP heeft na DATA een 250-bevestiging gegeven; geen bezorggarantie. |
| `failed` | Niet verstuurd of expliciet geweigerd door SMTP. |
| `uncertain` | Verbinding verloren nadat DATA begon; SMTP kan de mail al hebben aangenomen. |
| `received` | Via IMAP opgehaald; nooit direct verzendbaar. |

```sh
php breeze mail:retry 1
php breeze mail:send 1
```

`mail:retry` zet uitsluitend `failed` opnieuw op `queued`.
`sent` en inkomende mails kunnen niet opnieuw worden verzonden met deze
commando's. Maak voor een bewuste nieuwe verzending een nieuw bericht.
Bij een gecrasht proces kan de status `sending` blijven staan. Controleer
dan eerst de SMTP-server en stop actieve workers; pas daarna kun je bewust
`mail:retry ID --force` gebruiken voor `sending` of `uncertain`.
Dit kan **dubbele bezorging** veroorzaken. Er is bewust geen automatische
timeout-retry. De Message-ID blijft bij retries gelijk, maar dat garandeert
geen deduplicatie door de ontvanger.

Als SMTP de mail accepteert maar het opslaan van `sent` mislukt, blijft de
status `sending` en krijgt de aanroeper een fout. Controleer in dat geval
eveneens eerst de SMTP-server. Gelijktijdige workers kunnen nooit dezelfde
`queued`-rij claimen; bij een claimconflict stopt de betreffende worker
expliciet en kan de volgende periodieke run doorgaan.
SMTP-acceptatie is niet hetzelfde als aflevering: bounces worden niet
automatisch verwerkt. SPF, DKIM en DMARC regel je op domein/mailserverniveau.

### IMAP-inbox lezen en loggen

```sh
php breeze mail:fetch 50
```

Of vanuit PHP:

```php
$messages = $app->mail->fetch(50);
foreach ($messages as $message) {
    $id = $message['id'];            // null bij MAIL_LOG=false
    $subject = $message['message']['subject'];
    $text = $message['message']['text'];
}
```

De client opent de mailbox met `EXAMINE` en leest `BODY.PEEK[]`: berichten
worden niet als gelezen gemarkeerd, verwijderd of verplaatst. De standaard
is `INBOX`; andere mailboxnamen zijn ASCII of vooraf gecodeerde IMAP
modified UTF-7. Bij logging worden de oudste nog niet opgeslagen UIDs
opgehaald, zodat herhaalde batches de inbox doorlopen. Zonder logging worden
de recentste UIDs opgehaald en ontbreekt een blijvend importcheckpoint.
Limieten zijn 1–1000 berichten per run, standaard 50.

Deduplicatie gebruikt server, poort, account, mailbox, UIDVALIDITY en UID;
de unieke database-index beschermt ook gelijktijdige imports.
Een gewijzigde UIDVALIDITY of account-/serverconfiguratie kan bewust een
nieuwe import opleveren. Verwijderde database-rijen kunnen opnieuw
geïmporteerd worden. De server wordt per run met `UID SEARCH ALL` bevraagd;
metadata is begrensd op 2 MiB per antwoord.

Inkomende MIME wordt ontleed naar tekst en HTML, inclusief base64,
quoted-printable, RFC 2047-headers en iconv-tekensets.
Bijlagen worden niet uitgepakt; de exacte originele mail blijft wel
base64-gecodeerd in `raw_message` staan. `headers_json` bewaart de
oorspronkelijke, ontvouwde headers; uitgaande mails bewaren de gestructureerde
velden in plaats van een raw-kopie. Inkomende `to_addresses` bevat de
oorspronkelijke To-header als één lijstitem, niet een gevalideerde
ontvangerslijst. Inkomende data wordt nooit als uitgaand `Message` hergebruikt.

Standaard maximaal 10 MiB raw per bericht (`IMAP_MAX_BYTES`), instelbaar tot
50 MiB; per batch geldt maximaal 50 MiB raw. MIME-nesting is maximaal 10
en het aantal onderdelen maximaal 100. Grotere, kapotte of niet-ondersteunde
berichten veroorzaken een expliciete fout, geen schijnbaar geslaagde import.
Een parse-/netwerkfout tijdens ophalen slaat die batch niet op. Bij een
latere databasefout kunnen eerdere inserts van de batch wel bewaard zijn;
deduplicatie maakt een volgende run veilig.

Bescherm toegang tot `emails`, backups en CLI: berichten kunnen
vertrouwelijke gegevens bevatten. Base64 is **geen encryptie**. Stel zelf
een bewaartermijn, beperkte databasepermissies en passende opslagbeveiliging
in. Inkomende HTML is niet gesanitiseerd: render dit nooit rechtstreeks
in je applicatie; toon het als tekst met `e()` of gebruik een afzonderlijke,
goed gesanitiseerde en afgeschermde viewer.

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
SMTP en IMAP worden via lokale protocolfixtures getest, inclusief native TLS,
STARTTLS, authenticatie, certificaatcontrole, databasewachtrij, deduplicatie
en het verliezen van de SMTP-bevestiging. Er wordt geen externe mail verstuurd.
MySQL vereist daarnaast een eigen integratierun op een wegwerpdatabase;
de meegeleverde suite valideert de MySQL-verbinding niet tegen een live server.
