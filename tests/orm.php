<?php
declare(strict_types=1);

use PBreeze\Database;
use PBreeze\Model;
use PBreeze\Query;
use PBreeze\RecordNotFoundException;

final class OrmAuthor extends Model
{
    protected string $table = 'orm_authors';
    protected array $fillable = ['name', 'secret'];
    protected array $hidden = ['secret'];

    public function posts(): Query
    {
        return $this->hasMany(OrmPost::class, 'author_id');
    }

    public function badRelation(): Query
    {
        return $this->hasMany(stdClass::class, 'author_id');
    }

    public function keys(): Query
    {
        return $this->hasMany(OrmKey::class, 'value', 'name');
    }
}

final class OrmPost extends Model
{
    protected string $table = 'orm_posts';
    protected bool $timestamps = true;
    protected array $fillable = ['author_id', 'title', 'active', 'score', 'metadata', 'published_at'];
    protected array $hidden = ['metadata'];
    protected array $casts = [
        'id' => 'int', 'author_id' => 'int', 'active' => 'bool', 'score' => 'float',
        'metadata' => 'json', 'published_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime',
    ];

    public function author(): Query
    {
        return $this->belongsTo(OrmAuthor::class, 'author_id');
    }
}

final class OrmKey extends Model
{
    protected string $table = 'orm_keys';
    protected string $primaryKey = 'code';
    protected bool $incrementing = false;
    protected array $fillable = ['code', 'value'];

    public function author(): Query
    {
        return $this->belongsTo(OrmAuthor::class, 'value', 'name');
    }
}

function ormDatabase(): Database
{
    global $temp;
    $db = new Database($temp, ['driver' => 'sqlite', 'database' => ':memory:']);
    $db->execute('CREATE TABLE orm_authors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, secret TEXT NULL)');
    $db->execute('CREATE TABLE orm_posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER NULL REFERENCES orm_authors(id),
        title TEXT NOT NULL DEFAULT \'Nieuw\', active INTEGER NOT NULL DEFAULT 0, score REAL NOT NULL DEFAULT 0,
        metadata TEXT NULL, published_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
    )');
    $db->execute('CREATE TABLE orm_keys (code TEXT PRIMARY KEY, value TEXT NULL)');
    return $db;
}

test('Querybuilder voert gebonden CRUD uit en bewaart nulls, booleans, quotes en Unicode', static function (): void {
    $db = ormDatabase();
    $id = $db->table('orm_authors')->insert(['name' => "Ada ' ; DROP TABLE orm_authors; --", 'secret' => null]);
    check(is_int($id));
    same("Ada ' ; DROP TABLE orm_authors; --", $db->table('orm_authors')->find($id)['name']);
    same(null, $db->table('orm_authors')->find($id)['secret']);
    same(1, $db->table('orm_authors')->where('id', $id)->update(['name' => 'Renée']));
    same('Renée', $db->table('orm_authors')->findOrFail($id)['name']);
    same(0, $db->table('orm_authors')->where('id', 999)->update(['name' => 'Nooit']));
    same(1, $db->table('orm_authors')->where('id', $id)->delete());
    same(null, $db->table('orm_authors')->find($id));
    same(false, $db->table('orm_authors')->exists());
    raises(fn () => $db->table('orm_authors')->findOrFail($id), RecordNotFoundException::class);
    raises(fn () => $db->table('orm_authors')->firstOrFail(), RecordNotFoundException::class);
});

test('Queries zijn immutable en ondersteunen AND, vergelijking, IN, LIKE en null', static function (): void {
    $db = ormDatabase();
    foreach (['Ada', 'Grace', 'Linus'] as $name) {
        $db->table('orm_authors')->insert(['name' => $name, 'secret' => $name === 'Grace' ? 'privé' : null]);
    }
    $base = $db->table('orm_authors');
    $filtered = $base->where('id', '>=', 2)->where('id', '<', 4);
    same(3, $base->count());
    same(2, $filtered->count());
    same('Grace', $filtered->orderBy('id')->first()['name']);
    same(2, $base->whereIn('id', [1, 3])->count());
    same(0, $base->whereIn('id', [])->count());
    same(2, $base->where('secret', null)->count());
    same(1, $base->where('secret', '!=', null)->count());
    same(1, $base->whereNotNull('secret')->count());
    same(2, $base->whereNull('secret')->count());
    same(1, $base->where('name', 'like', 'Gr%')->count());
    same(2, $base->where('name', 'not like', 'Gr%')->count());
    same(2, $base->where('id', '<>', 1)->count());
    same(1, $base->where('id', '>', 2)->where('id', '<=', 3)->count());
    same(3, $base->limit(1)->offset(1)->count());
    same(true, $base->where('id', 1)->offset(10)->exists());
});

test('Identifiers, operatoren, richtingen en ongeldige bindings worden expliciet geweigerd', static function (): void {
    $db = ormDatabase();
    $base = $db->table('orm_authors');
    foreach (['users; DROP TABLE users', 'users.id', 'x`', '', 'name DESC', 'x/*a*/'] as $name) {
        raises(fn () => $db->table($name), InvalidArgumentException::class);
        raises(fn () => $base->where($name, 1), InvalidArgumentException::class);
        raises(fn () => $base->orderBy($name), InvalidArgumentException::class);
        raises(fn () => $base->insert([$name => 'a']), InvalidArgumentException::class);
    }
    raises(fn () => $base->where('id', '= ? OR 1 = 1 --', 1), InvalidArgumentException::class);
    raises(fn () => $base->where('id', 'LIKE', null), InvalidArgumentException::class);
    raises(fn () => $base->orderBy('id', 'desc; DELETE'), InvalidArgumentException::class);
    foreach ([[], new stdClass(), INF, NAN, fopen('php://memory', 'r+')] as $value) {
        try {
            raises(fn () => $base->where('id', $value), InvalidArgumentException::class);
            raises(fn () => $base->insert(['name' => $value]), InvalidArgumentException::class);
        } finally {
            if (is_resource($value)) {
                fclose($value);
            }
        }
    }
    raises(fn () => $base->whereIn('id', [null]), InvalidArgumentException::class);
    raises(fn () => $base->whereIn('id', ['id' => 1]), InvalidArgumentException::class);
    raises(fn () => $base->whereIn('id', range(1, 501)), InvalidArgumentException::class);
    raises(fn () => $base->insert(['Ada']), InvalidArgumentException::class);
});

test('Updates en deletes vereisen filters en negeren geen limiet of sortering', static function (): void {
    $db = ormDatabase();
    $base = $db->table('orm_authors');
    $base->insert(['name' => 'Ada']);
    raises(fn () => $base->update(['name' => 'Grace']), LogicException::class);
    raises(fn () => $base->delete(), LogicException::class);
    foreach ([$base->where('id', 1)->limit(1), $base->where('id', 1)->offset(1), $base->where('id', 1)->orderBy('id')] as $query) {
        raises(fn () => $query->update(['name' => 'Grace']), LogicException::class);
        raises(fn () => $query->delete(), LogicException::class);
        raises(fn () => $query->insert(['name' => 'Grace']), LogicException::class);
    }
    raises(fn () => $base->where('id', 1)->update([]), InvalidArgumentException::class);
    same(0, $base->whereIn('id', [])->update(['name' => 'Grace']));
    same(0, $base->whereIn('id', [])->delete());
    same('Ada', $base->first()['name']);
    raises(fn () => $base->create(['name' => 'Grace']), LogicException::class);
});

test('Paginering geeft de exacte vorm, stabiele standaardvolgorde en lege pagina’s', static function (): void {
    $db = ormDatabase();
    for ($i = 1; $i <= 5; $i++) {
        $db->table('orm_authors')->insert(['name' => "Auteur $i"]);
    }
    $query = $db->table('orm_authors');
    $page = $query->paginate(2, 2);
    same(['data', 'total', 'per_page', 'current_page', 'last_page'], array_keys($page));
    same([3, 4], array_column($page['data'], 'id'));
    same(5, $page['total']);
    same(2, $page['per_page']);
    same(2, $page['current_page']);
    same(3, $page['last_page']);
    same([], $query->paginate(2, 10)['data']);
    same(1, $query->whereIn('id', [])->paginate()['last_page']);
    same([5, 4], array_column($query->orderBy('id', 'desc')->paginate(2)['data'], 'id'));
    same(1, count($query->orderBy('id')->offset(3)->limit(1)->get()));
    foreach ([[0, 1], [101, 1], [2, 0], [100, PHP_INT_MAX]] as [$size, $page]) {
        raises(fn () => $query->paginate($size, $page), InvalidArgumentException::class);
    }
    raises(fn () => $query->limit(0), InvalidArgumentException::class);
    raises(fn () => $query->offset(-1), InvalidArgumentException::class);
    raises(fn () => $query->offset(1)->get(), LogicException::class);
});

test('Modellen ondersteunen CRUD, defaults, refresh en strict dirty tracking', static function (): void {
    $db = ormDatabase();
    $repository = new OrmAuthor($db);
    $author = $repository->create(['name' => 'Ada']);
    check($author instanceof OrmAuthor);
    same(false, $repository->exists());
    same(true, $author->exists());
    same(null, $author->secret);
    same([], $author->dirty());
    $author->secret = '';
    same(['secret' => ''], $author->dirty());
    same(true, $author->save());
    same('', $db->table('orm_authors')->find($author->id)['secret']);
    $author->secret = null;
    $author->save();
    same(null, $db->table('orm_authors')->find($author->id)['secret']);
    same(true, $author->update(['name' => 'Grace']));
    same('Grace', $repository->query()->findOrFail($author->id)->name);
    $db->table('orm_authors')->where('id', $author->id)->update(['name' => 'Extern']);
    same('Grace', $author->name);
    same($author, $author->refresh());
    same('Extern', $author->name);
    same([], $author->dirty());
    same(true, $author->save());
    same(true, $author->delete());
    same(false, $author->exists());
    raises(fn () => $author->save(), LogicException::class);
    raises(fn () => $author->delete(), LogicException::class);
    raises(fn () => $author->refresh(), LogicException::class);
    raises(fn () => $repository->update(['name' => 'Nooit']), LogicException::class);
});

test('Fillable blokkeert onbedoelde velden, primaire sleutels en timestamps zonder gedeeltelijke fill', static function (): void {
    $db = ormDatabase();
    $author = (new OrmAuthor($db))->create(['name' => 'Ada']);
    raises(fn () => $author->fill(['name' => 'Gewijzigd', 'is_admin' => true]), InvalidArgumentException::class);
    same('Ada', $author->name);
    raises(fn () => $author->id = 42, InvalidArgumentException::class);
    raises(fn () => $author->missing, LogicException::class);
    same(false, isset($author->missing));
    same(false, isset($author->secret));
    same(true, isset($author->name));
    raises(fn () => (new OrmPost($db))->create(['created_at' => '2020-01-01']), InvalidArgumentException::class);
    raises(fn () => $author->query()->where('id', 1)->update(['id' => 3]), InvalidArgumentException::class);
    raises(fn () => $author->query()->where('id', 1)->update(['is_admin' => true]), InvalidArgumentException::class);
    same('Ada', $author->refresh()->name);
    same(1, $author->query()->where('id', 1)->update(['name' => 'Via query']));
    same('Via query', $author->refresh()->name);
});

test('Casts zetten JSON, UTC-datums, booleans en getallen betrouwbaar heen en terug', static function (): void {
    $db = ormDatabase();
    $record = (new OrmPost($db))->create([
        'title' => 'Casts', 'active' => true, 'score' => 1.25, 'metadata' => ['a' => [1, 'é'], 'float' => 1.0],
        'published_at' => new DateTimeImmutable('2026-01-02 12:00:00+02:00'),
    ]);
    same(true, $record->active);
    same(1.25, $record->score);
    same(['a' => [1, 'é'], 'float' => 1.0], $record->metadata);
    check($record->published_at instanceof DateTimeImmutable);
    same('2026-01-02T10:00:00+00:00', $record->published_at->format('c'));
    check($record->created_at instanceof DateTimeImmutable);
    check($record->updated_at instanceof DateTimeImmutable);
    same(null, $record->author_id);
    $raw = $db->table('orm_posts')->findOrFail($record->id);
    same(1, $raw['active']);
    same('2026-01-02 10:00:00', $raw['published_at']);
    $record->active = false;
    $record->score = 2;
    $record->metadata = [];
    $record->published_at = null;
    $record->save();
    same(false, $record->active);
    same(2.0, $record->score);
    same([], $record->metadata);
    same(null, $record->published_at);
    same(1, $record->query()->where('id', $record->id)->update(['active' => true, 'metadata' => ['bulk' => true]]));
    same(true, $record->refresh()->active);
    same(['bulk' => true], $record->metadata);
    $defaults = (new OrmPost($db))->create([]);
    same('Nieuw', $defaults->title);
    same(false, $defaults->active);
    same(0.0, $defaults->score);
});

test('Ongeldige casts en corrupte databasewaarden falen expliciet', static function (): void {
    $db = ormDatabase();
    $model = new OrmPost($db);
    foreach ([
        ['active' => 'false'], ['score' => INF], ['score' => '1.2'], ['author_id' => '1'],
        ['metadata' => '{}'], ['metadata' => new stdClass()], ['published_at' => '2026-01-02'],
    ] as $values) {
        raises(fn () => $model->fill($values), InvalidArgumentException::class);
    }
    $post = $model->create([]);
    $db->table('orm_posts')->where('id', $post->id)->update(['metadata' => '{broken']);
    raises(fn () => $model->query()->get(), JsonException::class);
    $db->table('orm_posts')->where('id', $post->id)->update(['metadata' => '"scalar"']);
    raises(fn () => $post->refresh(), InvalidArgumentException::class);
    $db->table('orm_posts')->where('id', $post->id)->update(['metadata' => null, 'active' => 2]);
    raises(fn () => $post->refresh(), InvalidArgumentException::class);
    $db->table('orm_posts')->where('id', $post->id)->update(['active' => 0, 'published_at' => '2026-02-31 00:00:00']);
    raises(fn () => $post->refresh(), InvalidArgumentException::class);
    $bad = new class($db) extends Model {
        protected string $table = 'orm_authors';
        protected array $fillable = ['name'];
        protected array $casts = ['name' => 'typo'];
    };
    raises(fn () => $bad->fill(['name' => 'Ada']), LogicException::class);
    raises(fn () => $model->hydrate(['id' => 1, 'author_id' => '92233720368547758080']), InvalidArgumentException::class);
    raises(fn () => $model->hydrate(['id' => 1, 'score' => 'one']), InvalidArgumentException::class);
    raises(fn () => $model->hydrate(['title' => 'geen primaire sleutel']), LogicException::class);
    raises(fn () => new class($db) extends Model {}, InvalidArgumentException::class);
});

test('Hidden-velden verdwijnen uit arrays, JSON en gepagineerde modellen', static function (): void {
    $db = ormDatabase();
    $author = (new OrmAuthor($db))->create(['name' => 'Ada', 'secret' => 'niet publiceren']);
    same('niet publiceren', $author->secret);
    same(['id' => $author->id, 'name' => 'Ada'], $author->toArray());
    $json = json_encode($author->query()->paginate(), JSON_THROW_ON_ERROR);
    check(!str_contains($json, 'secret'));
    check(!str_contains($json, 'niet publiceren'));
    $post = (new OrmPost($db))->create(['published_at' => new DateTimeImmutable('2026-01-01T00:00:00Z')]);
    same('2026-01-01T00:00:00Z', $post->toArray()['published_at']);
    check(!array_key_exists('metadata', $post->toArray()));
});

test('belongsTo en hasMany gebruiken dezelfde verbinding en ondersteunen expliciete filters', static function (): void {
    $db = ormDatabase();
    $ada = (new OrmAuthor($db))->create(['name' => 'Ada']);
    $grace = (new OrmAuthor($db))->create(['name' => 'Grace']);
    $posts = new OrmPost($db);
    $first = $posts->create(['author_id' => $ada->id, 'title' => 'Eerste', 'active' => true]);
    $posts->create(['author_id' => $ada->id, 'title' => 'Tweede']);
    $posts->create(['author_id' => $grace->id, 'title' => 'Anders']);
    $orphan = $posts->create(['author_id' => null]);
    same(2, $ada->posts()->count());
    same('Eerste', $ada->posts()->where('active', true)->firstOrFail()->title);
    same('Ada', $first->author()->firstOrFail()->name);
    same(null, $orphan->author()->first());
    check($ada->posts()->paginate(1)['data'][0] instanceof OrmPost);
    raises(fn () => (new OrmAuthor($db))->posts(), LogicException::class);
    raises(fn () => $ada->badRelation(), InvalidArgumentException::class);
    $db2 = ormDatabase();
    same(0, (new OrmAuthor($db2))->query()->count());
    same(2, $ada->posts()->count());
});

test('Niet-incrementele primaire sleutels werken met find, updates en relaties', static function (): void {
    $db = ormDatabase();
    $keys = new OrmKey($db);
    raises(fn () => $keys->create(['value' => 'geen sleutel']), LogicException::class);
    raises(fn () => $keys->create(['code' => null]), InvalidArgumentException::class);
    $record = $keys->create(['code' => 'nl-NL', 'value' => 'Nederlands']);
    same('nl-NL', $record->code);
    same('Nederlands', $keys->query()->findOrFail('nl-NL')->value);
    raises(fn () => $record->code = 'en-US', InvalidArgumentException::class);
    raises(fn () => $keys->query()->where('code', 'nl-NL')->update(['code' => 'en-US']), InvalidArgumentException::class);
    $record->update(['value' => 'Taal']);
    same('Taal', $keys->query()->find('nl-NL')->value);
    $author = (new OrmAuthor($db))->create(['name' => 'Taal']);
    same($author->id, $record->author()->firstOrFail()->id);
    same('nl-NL', $author->keys()->firstOrFail()->code);
    same(1, $keys->query()->paginate()['total']);
    same(true, $record->delete());
    same(0, $keys->query()->count());
});

test('Verwijderde records en databasefouten worden niet als geslaagde modelwrites verborgen', static function (): void {
    $db = ormDatabase();
    $model = new OrmAuthor($db);
    $record = $model->create(['name' => 'Ada']);
    $db->table('orm_authors')->where('id', $record->id)->delete();
    raises(fn () => $record->refresh(), RecordNotFoundException::class);
    raises(fn () => $record->update(['name' => 'Verdwenen']), RecordNotFoundException::class);
    raises(fn () => $record->delete(), RecordNotFoundException::class);
    raises(fn () => $model->create([]), PDOException::class);
    same(0, $model->query()->count());
});

test('ORM-transacties gebruiken Database::transaction zonder globale verbinding', static function (): void {
    $db = ormDatabase();
    raises(function () use ($db): void {
        $db->transaction(function () use ($db): void {
            (new OrmAuthor($db))->create(['name' => 'Wordt teruggedraaid']);
            throw new RuntimeException('rollback');
        });
    }, RuntimeException::class);
    same(0, $db->table('orm_authors')->count());
    $db->transaction(fn () => (new OrmAuthor($db))->query()->create(['name' => 'Blijft']));
    same(1, $db->table('orm_authors')->count());
});

test('Modelgenerator produceert bruikbare ORM-modellen met een gesloten fillable-lijst', static function () use ($app, $temp): void {
    console($app, ['make:model', 'OrmGenerated']);
    $file = $temp . '/app/models/OrmGenerated.php';
    $source = file_get_contents($file);
    check(str_contains($source, "protected string \$table = 'orm_generateds';"));
    require_once $file;
    $model = new \App\Models\OrmGenerated($app->db);
    check($model instanceof Model);
    raises(fn () => $model->fill(['name' => 'Niet toegestaan']), InvalidArgumentException::class);
    check($model->query() instanceof Query);
    console($app, ['make:model', 'Model']);
    require_once $temp . '/app/models/Model.php';
    check(new \App\Models\Model($app->db) instanceof Model);
});
