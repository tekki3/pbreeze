# PBreeze

Een kleine, leerbare MVC-basis in native PHP 8.3+. Geen Composer, externe
packages, PSR-autoloading, container of verborgen bestandsdetectie. Alleen
expliciete `require_once`-regels en PHP-extensies.

Inclusief native SMTP, IMAP, configureerbare e-maillogging en een databasewachtrij.
Met registratie, login/logout, wachtwoordherstel en verplichte e-mailverificatie
voor beschermde routes.
Inclusief een native PDO-querybuilder en optionele ORM met modellen, casts
en expliciete relaties. Geen globale databaseverbinding of autoloading.
De applicatie, CLI en tests zijn ingericht voor zowel Windows als Linux.

## Beginnen

Vereist: PHP 8.3+, PDO, `pdo_sqlite` voor SQLite of `pdo_mysql` voor MySQL,
en de standaardextensies session, json en filter. De tests gebruiken daarnaast
de native proces- en socketfuncties. De CLI gebruikt `proc_open` voor de
ontwikkelserver en tests; deze functie moet daarvoor beschikbaar zijn.
Er is geen installatie van packages nodig.

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
De start-/testpagina heeft een responsive lichte vormgeving met lokale CSS
in `public/assets/welcome.css`, een testformulier met fout- en succesmeldingen
en uitklapbare CLI-voorbeelden. Er worden geen externe fonts of scripts geladen.

`init` maakt een lokale `.env`, maar overschrijft nooit een bestaande.
De standaard is SQLite in `database/app.sqlite`; de database wordt bij het
eerste databasecommando aangemaakt. Het formulier heeft geen database nodig.

## Windows en Linux

Gebruik op beide systemen dezelfde commando's met `php breeze ...`.
Op Windows werkt dit vanuit PowerShell of cmd, zonder WSL, Bash of
uitvoerbare shebang. Zorg dat PHP CLI in `PATH` staat; controleer dit met
`php -v` en bekijk `php --ini` om het gebruikte configuratiebestand te vinden.
PHP voor je webserver kan een ander configuratiebestand gebruiken.

Activeer op Windows de benodigde extensies in `php.ini`: voor SQLite
`pdo_sqlite`, voor MySQL `pdo_mysql`, voor e-mail ook `openssl` en `iconv`.
Gebruik de juiste DLL's voor je PHP-distributie. OpenSSL heeft een geldige
CA-bundel nodig; stel indien nodig `openssl.cafile` in of gebruik de
afzonderlijke `SMTP_CA_FILE`/`IMAP_CA_FILE`-instellingen. Schakel
certificaatcontrole niet uit.

Relatieve databasepaden zijn altijd relatief aan de projectroot. Zowel
`database/app.sqlite` als `database\app.sqlite` is toegestaan. Volledige
Windows-schijfpaden en UNC-paden worden herkend, bijvoorbeeld:

```dotenv
DB_DATABASE="C:/Data/PBreeze/app.sqlite"
```

Backslashes zijn ook toegestaan en worden niet als `.env`-escapes behandeld.
Gebruik geen schijfrelatieve paden zoals `C:app.sqlite`. UNC-paden worden
als absoluut herkend, maar gebruik voor SQLite lokale opslag: SMB/NFS-
bestandsvergrendeling is geen vervanging voor een databaseserver. Gebruik
MySQL voor gedeelde databases.

De CLI start PHP zonder shell, zodat spaties, procenttekens en andere
shelltekens in projectpaden behouden blijven. Generators weigeren op beide
systemen Windows-apparaatnamen zoals `CON`, `NUL` en `COM1`. Houd daarnaast
de exacte hoofdletters van bestanden en `require_once`-paden gelijk:
Linux is doorgaans hoofdlettergevoelig, Windows doorgaans niet.

Linux gebruikt POSIX-bestandsrechten: nieuwe `.env`- en SQLite-bestanden
krijgen `0600`. Op Windows worden bestaande/geerfde NTFS-ACL's gebruikt;
`chmod(0600)` levert daar geen vergelijkbare bescherming. Beperk daarom
vóór initialisatie de toegang tot de projectmap, datamap en hun backups
tot de beheerder en de PHP-servicegebruiker via Windows-mapbeveiliging.
Geef de servicegebruiker alleen waar nodig schrijfrechten. De tests controleren
op Windows lees-/schrijfbaarheid, niet Unix-permissiebits.

Voor lokale ontwikkeling werkt `php breeze serve` op beide systemen.
In productie kun je op beide systemen Apache gebruiken met `public/` als
document root en de meegeleverde `.htaccess` met `mod_rewrite`. Bij IIS
configureer je PHP via FastCGI, stel je de siteroot in op `public/` en
configureer je URL Rewrite naar `index.php` voor niet-bestaande bestanden.
`.htaccess` wordt door IIS niet gelezen. HTTPS en blokkering van verborgen
bestanden moeten ook daar op serverniveau ingesteld worden.

Voor periodieke e-mailverzending gebruik je op Linux cron en op Windows
Taakplanner. Kies als programma het volledige pad naar `php`/`php.exe`,
geef het absolute pad naar `breeze` en `mail:work 50` als argumenten mee
en stel de projectmap in als werkmap. Zet paden met spaties tussen quotes.

De workflow `.github/workflows/tests.yml` voert de volledige native suite
uit op `ubuntu-latest` en `windows-latest`, met PHP 8.3 en 8.5, zodra het
project op GitHub staat en Actions is ingeschakeld. Een toegevoegde workflow
is nog geen uitgevoerde Windows-test.

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
  models/            ORM-modellen of eigen databaselogica
  views/             PHP-views en gecompileerde .breeze.php-templates
    layouts/         Gedeelde paginastructuur
    partials/        Herbruikbare paginafragmenten
    components/      Componenten met props en slots
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

### Breeze-templates

De native template-engine ondersteunt layouts, secties, includes, componenten,
slots, condities en loops. Geen dependencies, `eval()` of Composer:
templates worden naar PHP gecompileerd en met `require` uitgevoerd.

```sh
php breeze make:template berichten.index
php breeze make:template layouts.blog
php breeze make:template components.panel
```

`berichten.index` verwijst naar `app/views/berichten/index.breeze.php`.
Je controller blijft dezelfde API gebruiken:

```php
return new \PBreeze\Response($this->app->views->render('berichten.index', [
    'name' => 'Mijn applicatie',
    'title' => 'Berichten',
    'items' => ['Eerste bericht', 'Tweede bericht'],
    'session' => $this->app->session,
]));
```

Data is beschikbaar als gewone PHP-variabelen. Expressies gebruiken native
PHP-syntax, zoals `$title ?? 'Standaardtitel'`, `strtoupper($name)` of
`$item['title']`. Alle `{{ ... }}`-uitvoer wordt automatisch voor HTML escaped:

```html
<h1>{{ $title }}</h1>
<input name="name" value="{{ $oldName ?? '' }}">
{{-- Dit commentaar verschijnt niet in de HTML. --}}
```

Gebruik dit voor HTML-tekst en geciteerde attributen. Dit vervangt geen
URL-validatie, CSS-escaping of JavaScript-encoding. Scalars, `null` en
`Stringable` worden ondersteund; arrays en andere objecten geven een fout.
Gebruik niet daarnaast `e()` binnen `{{ }}`, anders escape je dubbel.

**Ongeescapete HTML is expliciet:** `{!! $html !!}`. Gebruik dit uitsluitend
voor vertrouwde markup, bijvoorbeeld een al gerenderd componentslot, nooit
voor ongesanitiseerde gebruikersinvoer of inkomende e-mail.

### Layouts en secties

Een layout in `app/views/layouts/blog.breeze.php`:

```html
<!doctype html>
<html lang="nl">
<head><title>@yield('title', 'Mijn blog')</title></head>
<body>
    @include('partials.header')
    <main>@yield('content')</main>
</body>
</html>
```

En een pagina in `app/views/berichten/index.breeze.php`:

```html
@extends('layouts.blog')

@section('title')
{{ $title }}
@endsection

@section('content')
    <h1>{{ $title }}</h1>
    @if(empty($items))
        <p>Er zijn nog geen berichten.</p>
    @else
        <ul>
        @foreach($items as $item)
            <li>{{ $item }}</li>
        @endforeach
        </ul>
    @endif
@endsection
```

`@section` is altijd een blok met `@endsection`; de inlinevariant
`@section('title', 'Titel')` wordt niet ondersteund.
`@yield` geeft een al gerenderde sectie weer zonder extra escaping.
De optionele standaardwaarde van `@yield` wordt wel escaped.
Een template met `@extends` mag alleen witruimte buiten secties produceren.
Layouts mogen zelf een andere layout uitbreiden; de secties van de meest
specifieke pagina hebben voorrang. Een dubbele sectie in dezelfde template
is een fout. Secties worden nooit tussen requests of losse renders gedeeld.

### Partials en componenten

Een include erft de variabelen op de huidige plek, ook loopvariabelen.
Expliciete data overschrijft alleen binnen de include de geerfde waarden:

```html
@include('partials.item', ['title' => $item])
```

Includes hebben hun eigen sectiecontext; definieer layoutsecties in de pagina
zelf, niet in een include.

Componenten krijgen alleen de expliciete props en hun gerenderde slots.
Bijvoorbeeld `app/views/components/panel.breeze.php`:

```html
<section>
    <h2>{{ $title }}</h2>
    {!! $slot !!}
    @if(isset($footer))
        <footer>{!! $footer !!}</footer>
    @endif
</section>
```

Gebruik:

```html
@component('components.panel', ['title' => 'Laatste berichten'])
    <p>{{ $intro }}</p>
    @slot('footer')
        <a href="/berichten">Alle berichten</a>
    @endslot
@endcomponent
```

`$slot` bevat de hoofdinhoud. Benoemde slots zoals `$footer` worden apart
beschikbaar en overschrijven eventuele gelijknamige props. `slot` is
gereserveerd. Slotnamen zijn unieke variabelenamen zonder voorloop-underscore;
een `@slot` staat rechtstreeks binnen een component. Je kunt verschillende
componenten nesten. Variabelen binnen de aanroepinhoud komen uit de pagina,
niet uit de props van de component.

### Directives en foutafhandeling

| Syntax | Gebruik |
|---|---|
| `{{ $value }}` | HTML-geescapete uitvoer |
| `{!! $html !!}` | Bewust onbewerkte HTML |
| `{{-- ... --}}` | Templatecommentaar |
| `@extends('layouts.app')` | Een layout uitbreiden |
| `@section('naam') ... @endsection` | Sectie vastleggen |
| `@yield('naam', 'standaard')` | Sectie tonen, met optionele standaard |
| `@include('partial', ['key' => $value])` | Partial met optionele data |
| `@component('component', ['key' => $value]) ... @endcomponent` | Component |
| `@slot('naam') ... @endslot` | Benoemd componentslot |
| `@if(...) ... @elseif(...) ... @else ... @endif` | Condities |
| `@foreach($items as $item) ... @endforeach` | Collectie doorlopen |
| `@for($i = 0; $i < 3; $i++) ... @endfor` | Getelde loop |
| `@csrf($session)` | Veilig hidden CSRF-veld |

Gebruik `@@` voor een letterlijke `@`, bijvoorbeeld `@@include`.
Scheid directives zonder argumenten van aansluitende letters met witruimte
of HTML, bijvoorbeeld `@else <p>Geen resultaten</p>`.
Normale e-mailadressen kun je als tekst opnemen; bevat een adres toevallig
een directivenaam, schrijf het dan via `{{ 'naam@if.example' }}`.
Gebruik geen PHP-tags, heredoc of backtick-expressies in Breeze-templates;
gewone PHP-views blijven beschikbaar voor uitzonderingen.

Onbekende directives, kapotte expressies en onjuist geneste blokken geven
een exception met de templatenaam. Ontbrekende views, cyclische layouts,
recursieve includes/componenten en nesting boven 32 views geven ook een fout.
Outputbuffers worden na fouten opgeruimd, zonder gedeeltelijke pagina als
succes terug te geven. De bestaande applicatiefoutafhandeling toont in
productie een generieke 500 en logt de fout.

### Compilatie en cache

De cache staat standaard in `storage/views/`, buiten `public/`.
De directory en nieuwe cachebestanden krijgen op Linux `0700` en `0600`;
op Windows gelden de NTFS-ACL's van de map. Zorg dat de PHP-servicegebruiker
deze cache mag aanmaken en lezen. Stem bij CLI en webserver de eigenaar en
rechten op elkaar af; geef nooit uploaders schrijfrechten op deze directory.

De cachenaam bevat een hash van het templatepad, de inhoud en de
compilerversie. Gewijzigde templates worden automatisch opnieuw gecompileerd,
ook wanneer hun wijzigingstijd gelijk blijft. Cachebestanden bevatten alleen
gecompileerde code, geen meegegeven viewdata of gerenderde pagina's.
Een slot en atomische publicatie beschermen gelijktijdige compilaties.
Er wordt geen gecachte oude inhoud gebruikt als compilatie mislukt.
Oude hashes blijven staan totdat je de cache wist:

```sh
php breeze view:clear
```

Dit verwijdert alleen de gecompileerde Breeze-cachebestanden, niet je
brontemplates of overige bestanden in `storage/`. Voer dit bij deployment
uit wanneer workers/verzoeken niet actief zijn; wis geen code die op dat
moment geladen wordt. Bij een eigen `View`-instantie kun je de cachedirectory
als tweede constructorargument meegeven.

De startpagina gebruikt `home.breeze.php`, `layouts.app`, twee partials en
`components.feedback` als werkend voorbeeld.

### Bestaande PHP-views

`php breeze make:view berichten.index` blijft een gewone
`app/views/berichten/index.php` genereren. Wanneer beide formaten bestaan,
heeft `.breeze.php` voorrang; de generators weigeren zulke naamconflicten.
In gewone PHP-views escape je nog steeds zelf met `e()`:

```php
<h1><?= e($title) ?></h1>
```

**Veiligheidsgrens:** beide templateformaten zijn vertrouwde applicatiecode,
geen sandbox voor bezoekers. PHP-expressies kunnen PHP-functies uitvoeren;
laat bezoekers nooit templates uploaden, aanpassen of viewpaden bepalen.
Namen van viewdata die met `__` beginnen zijn gereserveerd voor de engine.

### Validatie

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

Authenticatie vernieuwt automatisch het sessie-ID en het CSRF-token bij login
en logout. Daarbij worden bestaande sessiegegevens gewist. Een CSRF-token
vervangt geen toegangscontrole; bescherm je routes expliciet.

## Authenticatie

Voer `php breeze migrate` uit om `users`, `auth_tokens` en `auth_limits`
aan te maken. Stel in je bestaande `.env` ook de publieke origin in:

```dotenv
APP_URL=http://127.0.0.1:8000
```

In productie is dit bijvoorbeeld `https://app.example.nl`: HTTPS is verplicht,
zonder pad, gebruikersnaam, query of fragment. HTTP is alleen lokaal op
localhost toegestaan. Links worden uitsluitend uit deze configuratie opgebouwd,
nooit uit de inkomende Host- of proxyheaders. Gebruik je lokaal een andere
poort, pas `APP_URL` daarop aan.

### Pagina's en stroom

| Route | Functie |
|---|---|
| `GET/POST /register` | Account aanmaken en verificatiemail klaarzetten |
| `GET/POST /login` | Inloggen met e-mailadres en wachtwoord |
| `POST /logout` | Sessie wissen, ID en CSRF vernieuwen |
| `GET/POST /forgot-password` | Een herstellink aanvragen |
| `GET/POST /reset-password` | Nieuw wachtwoord kiezen via een token |
| `GET/POST /verify-email` | E-mailadres bevestigen via een token |
| `GET /email/verify` | Verificatiemelding voor ingelogde gebruikers |
| `POST /email/verification-notification` | Nieuwe verificatiemail aanvragen |
| `GET /account` | Voorbeeldpagina achter login én e-mailverificatie |

Na registratie log je in; onbevestigde accounts komen op de verificatiepagina.
Een verificatielink bevestig je met een POST-formulier, zodat een mail-scanner
die de link via GET of HEAD opent hem niet verbruikt. Bevestiging logt je niet
automatisch in. Onjuiste/verlopen tokens geven 400, validatiefouten 422,
te veel pogingen 429 en ontbrekende/onjuiste CSRF 419.

Wachtwoorden vereisen minimaal 12 tekens en maximaal 72 **bytes** (de native
bcryptgrens), plus een gelijke bevestiging. Ze worden niet teruggezet in
formulieren, niet gemaild en uitsluitend met `password_hash()`/bcrypt,
cost 12 opgeslagen. Login gebruikt `password_verify()` en herhashing indien nodig.
E-mailadressen zijn ASCII en worden getrimd en naar kleine letters omgezet;
naam en andere velden worden niet automatisch als databasevelden overgenomen.

### Mails verzenden

Authenticatiemails gaan **altijd via de bestaande databasewachtrij**, ook als
`MAIL_LOG=false`. Configureer `MAIL_FROM` en SMTP zoals hieronder beschreven en
voer regelmatig uit, via cron of Windows Taakplanner:

```sh
php breeze mail:work 50
```

Een succesvolle aanvraag betekent dat de mail klaarstaat, niet dat deze al is
bezorgd. Zonder werkende SMTP-configuratie/worker ontvangt de gebruiker geen mail.
Er wordt geen externe mail verstuurd tijdens installatie of tests. Nieuwe
accounts en hun verificatiemail worden samen opgeslagen: faalt het klaarzetten,
dan wordt registratie teruggedraaid. Database- en configuratiefouten worden
als serverfout gelogd, niet als geslaagde aanvraag getoond.

Tokens bevatten 32 cryptografisch willekeurige bytes en worden alleen als
SHA-256-hash opgeslagen in `auth_tokens`. Verificatietokens zijn 24 uur geldig,
hersteltokens 30 minuten, gerekend vanaf de aanvraag. Ze zijn eenmalig;
een nieuwe aanvraag vervangt de eerdere link voor hetzelfde doel. De worker
moet dus tijdig draaien. Reset maakt alle bestaande inlogsessies voor het account
ongeldig en logt de aanvrager uit. Reset bevestigt niet automatisch het e-mailadres.

**Let op:** de verzendbare e-mail in `emails` bevat noodzakelijkerwijs de
volledige link. Bescherm de database, backups en CLI-toegang als geheimen.
Wis oude mails volgens je bewaarbeleid en configureer webserver/proxylogs
zodat tokendragende querystrings niet worden bewaard. Authenticatiepagina's
gebruiken `no-store` en `Referrer-Policy: no-referrer`. Zet debug uit in productie.

### Eigen routes beschermen

```php
$router->get('/dashboard', $app->auth->protect([$controller, 'dashboard']));

// Uitzondering: alleen login verplicht, bijvoorbeeld voor de verificatiepagina.
$router->get('/onboarding', $app->auth->protect([$controller, 'onboarding'], false));

$user = $app->auth->user();
// null of: id, name, email, email_verified_at; nooit een wachtwoordhash.
```

De guard stuurt gasten naar `/login` en onbevestigde accounts naar `/email/verify`.
Er wordt niet automatisch naar een meegestuurde URL teruggestuurd. Beveilig ook
je POST/PUT/PATCH/DELETE-routes met de guard. Bestaande voorbeeldroutes blijven
publiek en werken zonder auth-tabellen zolang er geen ingelogde sessie is.
Rollen, rechten per object, MFA, remember-me en externe providers zijn niet
meegeleverd; voeg autorisatie toe vóór je gebruikersdata ontsluit.

Sessies verlopen na 30 minuten inactiviteit of maximaal 12 uur vanaf login.
Iedere controle leest de huidige gebruiker en sessieversie uit de database.
Verwijderde gebruikers en sessies met een oude versie verliezen hun toegang.

Vaste, databasegebonden tijdvensters begrenzen pogingen, ook over sessies heen:

| Actie | Limiet |
|---|---|
| Registratie | 5 per IP per uur |
| Login | 10 per e-mailadres en 40 per IP per 15 minuten |
| Herstellink | 5 per e-mailadres en 20 per IP per uur |
| Reset indienen | 20 per IP per uur |
| Verificatiemail opnieuw | 5 per gebruiker en 20 per IP per uur |
| Verificatietoken indienen | 30 per IP per 15 minuten |

Ook ongeldige aanvragen tellen mee voor de IP-limiet. Verlopen limietrecords
worden bij een volgende poging opgeruimd. Alleen SHA-256-buckets worden bewaard,
geen ruwe IP-adressen in `auth_limits`. De bron is uitsluitend `REMOTE_ADDR`;
forwarded headers worden niet vertrouwd. Achter een reverse proxy delen
gebruikers mogelijk één limiet: configureer de echte client-IP alleen op een
vertrouwde webserver/proxy, en pas aanvullend edge-rate-limiting toe.
Registratie en herstel geven dezelfde melding voor bestaande en ontbrekende
accounts; deze berichten voorkomen geen analyse van timing of mailbezorging.

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

`app/models/Note.php` bewaart de bestaande kleine repository-API en gebruikt
intern de querybuilder:

```php
require_once $app->root . '/app/models/Note.php';
$notes = new \App\Models\Note($app->db);
$id = $notes->create('Mijn eerste notitie');
$recent = $notes->latest(20);
```

Direct PDO blijft beschikbaar naast de optionele ORM.

## Native ORM en querybuilder

De ORM bestaat uit `framework/Query.php` en `framework/Model.php`, expliciet
geladen in de bootstrap. Elke query en elk model krijgt de bestaande
`Database`-instantie. Er zijn geen packages, autoloading, globale verbindingen,
schema-inspectie of automatische migraties. De SQL is ingericht voor SQLite
en MySQL; de bestaande platformonafhankelijke PDO-configuratie blijft leidend.

### Tabelqueries: arrays

```php
$query = $app->db->table('notes');
$id = $query->insert(['title' => 'Mijn notitie']);
$note = $query->find($id);          // array of null
$note = $query->findOrFail($id);    // array of RecordNotFoundException

$recent = $query->where('id', '>', 0)
    ->where('title', 'LIKE', '%notitie%')
    ->orderBy('id', 'desc')
    ->limit(20)
    ->get();

$query->where('id', $id)->update(['title' => 'Gewijzigd']); // aantal rijen
$query->where('id', $id)->delete();                       // aantal rijen
```

Queries zijn **immutable**: `where`, `orderBy`, `limit` en `offset` geven een
nieuwe query terug. Bewaar dus het resultaat of keten de aanroepen.
Alle `where`-voorwaarden worden met AND gecombineerd:

```php
$query = $app->db->table('notes');
$filtered = $query->whereIn('id', [1, 2, 3])->whereNotNull('title');
$first = $filtered->first();            // array of null
$first = $filtered->firstOrFail();      // exception indien afwezig
$count = $filtered->count();            // int
$exists = $filtered->exists();          // bool
$rows = $filtered->orderBy('id')->limit(10)->offset(10)->get();
```

Ondersteunde operatoren: `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`,
`NOT LIKE`. `where('kolom', $waarde)` gebruikt `=`.
`where('kolom', null)` en `where('kolom', '!=', null)` gebruiken respectievelijk
`IS NULL` en `IS NOT NULL`; expliciete `whereNull`/`whereNotNull` bestaan ook.
`whereIn` verwacht een lijst van maximaal 500 waarden zonder null; een lege
lijst vindt of wijzigt **geen** rijen. LIKE-wildcards `%` en `_` behouden hun
SQL-betekenis: parameterbinding maakt ze niet letterlijk.

Waarden worden gebonden als int, bool, string of null; floats worden als
tekstuele getallen gebonden. Arrays, objecten, resources, INF en NAN worden
geweigerd. Tabel- en kolomnamen moeten eenvoudige identifiers zijn
(`letters_cijfers_underscore`, niet beginnend met een cijfer); ze worden gequote.
Operatoren en sorteerrichtingen worden gecontroleerd. De builder accepteert
geen SQL-fragmenten, joins, aliassen, schema-/tabelprefixen, OR-groepen of raw-
expressies. Gebruik voor complexere SQL bewust `Database::execute()` met
bindings. Kies ook bij geldige kolomnamen zelf welke een gebruiker mag opvragen
of wijzigen: identifiercontrole is geen autorisatie.

`update` en `delete` zonder expliciete WHERE worden geweigerd. Writes met
sortering, limiet of offset worden eveneens geweigerd, zodat voorwaarden nooit
stilzwijgend worden genegeerd. `insert`/`create` accepteert geen filters,
sortering of paginering. `insert([])` gebruikt de databasedefaults en retourneert
het PDO-insert-ID (int indien representabel, anders string); bij handmatige
sleutels gebruik je de zelf aangeleverde sleutel, niet dit driverafhankelijke ID.
`get()` haalt alle passende rijen op tenzij je een limiet opgeeft.
`count()` en `exists()` werken op de filters en negeren leeslimieten,
offsets en sortering. `offset` bij `get()` vereist een expliciete `limit`.

### Modellen: objecten

```sh
php breeze make:model Article
```

De generator maakt een `Model`-subklasse met standaard een snake_case-naam
plus `s` als tabelnaam. Er is bewust geen taalafhankelijke meervoudsherkenning:
controleer de naam, maak de tabel via een migratie en vul `$fillable` in.
De standaardlijst is leeg: geen enkel veld is dan massaal schrijfbaar.

Voorbeeld voor een eigen `articles`-tabel met de getoonde kolommen:

```php
<?php
declare(strict_types=1);

namespace App\Models;

final class Article extends \PBreeze\Model
{
    protected string $table = 'articles';
    protected bool $timestamps = true;
    protected array $fillable = ['author_id', 'title', 'published', 'metadata', 'published_at'];
    protected array $hidden = ['metadata'];
    protected array $casts = [
        'id' => 'int',
        'author_id' => 'int',
        'published' => 'bool',
        'metadata' => 'json',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function author(): \PBreeze\Query
    {
        return $this->belongsTo(Author::class, 'author_id');
    }
}
```

Een model heeft standaard primaire sleutel `id`, `$incrementing = true` en
`$timestamps = false`. Zet timestamps alleen aan als **beide** kolommen
`created_at` en `updated_at` bestaan. Gebruik daarvoor UTC-velden die waarden
in `Y-m-d H:i:s` kunnen opslaan. Maak foreign keys en databasedefaults expliciet
in je migratie. Een model voert geen schemamigraties of inhoudsvalidatie uit.

```php
require_once $app->root . '/app/models/Article.php';
require_once $app->root . '/app/models/Author.php';

$articles = new \App\Models\Article($app->db);
$article = $articles->create([
    'author_id' => 1,
    'title' => 'Mijn eerste artikel',
    'published' => false,
    'metadata' => ['tags' => ['php']],
    'published_at' => null,
]);

$article->title = 'Een nieuwe titel'; // controleert fillable en cast
$changes = $article->dirty();        // gewijzigde velden in opslagformaat
$article->save();                    // insert nieuw model; anders alleen wijzigingen
$article->update(['published' => true]);
$article->refresh();                 // herladen; overschrijft lokale wijzigingen

$found = $articles->query()->findOrFail($article->id); // Article-object
$list = $articles->query()->where('published', true)->orderBy('id')->get();
$article->delete();                  // verwijdert de rij; niet opnieuw opslaan
```

`create()` maakt altijd een **nieuw** model, ook als je het op een geladen
record aanroept. `query()->create()` is hetzelfde alternatief. `save()`,
`update()` en `delete()` geven bij succes true en falen bij databasefouten
expliciet. Een onbekend/niet-geladen veld lezen geeft een `LogicException`.
`exists()` op een model geeft de lokaal bekende opgeslagen status; voor een
actuele databasecontrole gebruik je `query()->where(...)->exists()`.
Na een write worden defaults en databasewaarden herladen. Een ongewijzigd
model opslaan schrijft niets. `refresh()` of een write op een verdwenen rij
geeft `RecordNotFoundException`; een ongewijzigde `save()` controleert dat
niet opnieuw. Deze exception is geen automatische HTTP-404: vertaal hem
waar gewenst in je controller naar een `HttpException(404, ...)`.

`fill()` en veldtoekenning accepteren alleen `$fillable`. Onbekende velden
worden niet stil genegeerd. De primaire sleutel van een opgeslagen model en
automatische timestamps zijn niet schrijfbaar. Voor handmatige/string-
sleutels zet je `$incrementing = false`, kies je `$primaryKey` en voeg je die
sleutel toe aan `$fillable` voor het aanmaken.

Modelqueries ondersteunen ook gefilterde bulk-`update`/`delete`.
`update` controleert fillable en casts, maar werkt zonder modelinstanties:
automatische timestamps worden daarbij **niet** bijgewerkt. Gebruik `save()`
of `update()` op een geladen model als je die wilt. Primaire-sleutelupdates
via een modelquery worden altijd geweigerd. Op een tabelquery zijn
modelbeschermingen niet van toepassing. `insert()` is alleen voor tabelqueries;
gebruik `create()` voor modelqueries.

### Casts en serialisatie

| Cast | PHP-waarde bij toekenning | Waarde bij lezen |
|---|---|---|
| `int` | int | int, met overflowcontrole bij hydration |
| `float` | eindige int/float | float |
| `bool` | bool | bool; opslag 0/1 |
| `string` | string | string |
| `json` | array | array; JSON met expliciete foutmeldingen |
| `datetime` | `DateTimeInterface` | `DateTimeImmutable` in UTC |

Null blijft bij alle casts null; de database bepaalt of een kolom null toestaat.
Zonder cast zijn alleen scalars/null toegestaan en worden PDO-waarden gebruikt.
Casts zijn bewust strikt bij toekenning: formuliertekst `'false'` wordt
bijvoorbeeld niet per ongeluk true. Valideer en converteer formulierinvoer
zelf. Datums gebruiken UTC met secondenprecisie. JSON-objecten worden bij het
lezen associatieve arrays; wijzig een JSON-veld door de aangepaste array
opnieuw toe te kennen, niet via indirecte arraymutatie op een magic property.
Foute JSON, datums of andere ongeldige opslagwaarden geven een exception.

```php
$array = $article->toArray();
$json = json_encode($article, JSON_THROW_ON_ERROR);
```

`$hidden` sluit velden uit van `toArray()` en JSON, ook binnen een lijst of
paginering. Zet bij een gebruikersmodel bijvoorbeeld `password_hash` in
`$hidden` én niet in `$fillable` als die niet massaal schrijfbaar mag zijn.
Verborgen velden blijven voor je eigen PHP-code direct leesbaar; de database
en `dirty()` bevatten ook verborgen waarden. Datums worden in arrays/JSON
als UTC ISO-8601-strings teruggegeven. Serialisatie voert geen relatiequeries uit.
De bestaande authenticatie gebruikt bewust de eigen atomaire SQL voor tokens,
wachtrijen en sessieversies en hoeft hiervoor niet te worden omgeschreven.

### Relaties

Definieer relaties met expliciete modelklassen en sleutels. De gerelateerde
klasse moet zelf via `require_once` geladen zijn:

```php
final class Author extends \PBreeze\Model
{
    protected string $table = 'authors';
    protected array $fillable = ['name'];

    public function articles(): \PBreeze\Query
    {
        return $this->hasMany(Article::class, 'author_id');
    }
}

$author = $article->author()->first(); // Author of null
$authorArticles = $author->articles()->where('published', true)->get();
```

`hasMany(Related::class, foreignKey, localKey)` gebruikt standaard de eigen
primaire sleutel als localKey. `belongsTo(Related::class, foreignKey, ownerKey)`
gebruikt standaard de primaire sleutel van het gerelateerde model.
Beide geven een nieuwe, filterbare **modelquery** terug op dezelfde
databaseverbinding. Een null-relatiesleutel geeft geen resultaten.
Relaties op niet-opgeslagen modellen worden geweigerd; er worden geen records
automatisch opgeslagen of gekoppeld. Gebruik bij een nullable belongsTo
zelf een nullcontrole voordat je verder navigeert.

Er is geen impliciet lazy loading via properties, eager loading, relationcache,
cascade-save, polymorfisme of many-to-many-API. Relatiequeries binnen een loop
maken dus afzonderlijke queries; haal voor grotere lijsten bewust benodigde
rijen in batches op met `whereIn`. Cascades en referentiële integriteit
horen in je migraties.

### Paginering en transacties

```php
$page = $articles->query()->orderBy('id', 'desc')->paginate(20, 2);
// [
//   'data' => [Article, ...],
//   'total' => 42,
//   'per_page' => 20,
//   'current_page' => 2,
//   'last_page' => 3,
// ]
```

Tabelqueries geven dezelfde vorm met arrays in `data`. Paginanummers zijn
positief; de paginagrootte is 1 tot 100. Zonder expliciete sortering wordt de
primaire sleutel gebruikt (`id` bij tabelqueries). Sorteer bij eigen
sorteervelden ook op een unieke sleutel voor stabiele pagina's.
Een lege dataset heeft `last_page = 1`; pagina's voorbij het einde hebben
lege `data`. Telling en ophalen zijn aparte queries, geen snapshot bij
gelijktijdige wijzigingen.

```php
$app->db->transaction(function () use ($app): void {
    $authors = new \App\Models\Author($app->db);
    $authors->create(['name' => 'Ada']);
    // Andere databasewrites op dezelfde verbinding.
});
```

Modelwrites gebruiken dezelfde PDO-verbinding en doen mee aan de bestaande
transactie. Geneste transacties, optimistic locking, soft deletes en
model-events zijn niet toegevoegd. Gebruik na rollback nieuwe/herladen
instanties: een rollback herstelt de database, niet eerder gewijzigde
PHP-objecten. De ORM vervangt geen validatie, routeguards of autorisatie
per record.

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
via cron (Linux) of Taakplanner (Windows) aanroepen met absolute paden naar
PHP en `breeze`.
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
`.gitignore`; nieuwe `.env`- en SQLite-bestanden krijgen op Linux rechten
`0600`. Op Windows gelden de NTFS-ACL's van de map; beperk die vooraf.
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
  de databasedirectory en `storage/`. Op Linux is een met `0600` aangemaakt
  bestand alleen leesbaar door de eigenaar: stem de CLI/PHP-FPM-gebruiker
  daarop af. Op Windows beperk je de NTFS-ACL's tot de benodigde gebruikers.
  Maak niet de hele applicatie schrijfbaar voor de webserver.
- Beperk requestgroottes en timeouts in PHP en de webserver; regel rate limiting
  en TLS/HSTS op proxy- of webserverniveau.
- De standaardheaders bevatten CSP, `nosniff`, frameblokkering, beperkt
  permissiebeleid en `Cache-Control: no-store` voor applicatieresponses.
  De CSP staat geen scripts of inline styles toe. Zelfgeschreven responses
  kunnen headers expliciet aanpassen; houd die wijzigingen beperkt.
- Statische bestanden worden direct door de webserver bediend: configureer
  daarvoor zelf passende cache- en veiligheidsheaders.
- Bescherm eigen routes met de auth-guard en voeg vóór publieke ingebruikname
  autorisatie per object en toepassingsspecifieke validatie toe.
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
Authenticatietests doorlopen de echte HTTP-formulieren, sessie- en CSRF-rotatie,
e-mailverificatie, tokenverval/hergebruik, wachtwoordherstel, sessie-intrekking
en persistente rate limits op een tijdelijke database.
ORM-tests controleren querybindings, identifiercontrole, CRUD, schrijfguards,
fillable/hidden, casts en corrupte opslagwaarden, paginering, relaties,
handmatige sleutels, transacties en de modelgenerator.
MySQL vereist daarnaast een eigen integratierun op een wegwerpdatabase;
de meegeleverde suite valideert de MySQL-verbinding niet tegen een live server.
