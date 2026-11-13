<?php
declare(strict_types=1);

use PBreeze\Application;
use PBreeze\Database;
use PBreeze\Encryption;
use PBreeze\EncryptionException;
use PBreeze\Model;

final class EncryptedEntry extends Model
{
    protected string $table = 'encrypted_entries';
    protected array $fillable = ['title', 'secret', 'other_secret', 'details'];
    protected array $hidden = ['secret', 'other_secret', 'details'];
    protected array $casts = ['secret' => 'encrypted', 'other_secret' => 'encrypted', 'details' => 'encrypted:json'];
}

function encryptedDatabase(?Encryption $encryption = null): Database
{
    global $temp;
    $db = new Database($temp, ['driver' => 'sqlite', 'database' => ':memory:'], $encryption);
    $db->execute('CREATE TABLE encrypted_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, secret TEXT NULL, other_secret TEXT NULL, details TEXT NULL
    )');
    return $db;
}

test('AES-256-GCM round trip supports empty text, Unicode, binary data, and large messages', static function (): void {
    $encryption = new Encryption(Encryption::generateKey());
    foreach (['', '0', 'Hello world!', 'Secret: café 日本語', "\0\xff\xfe\n\r", str_repeat('abc', 350000)] as $text) {
        $ciphertext = $encryption->encrypt($text);
        check(str_starts_with($ciphertext, 'pbreeze:v1:'));
        same($text, $encryption->decrypt($ciphertext));
        check($ciphertext !== $encryption->encrypt($text));
    }
});

test('Decryption rejects every modified nonce, tag, or ciphertext and incorrect contexts or keys', static function (): void {
    $encryption = new Encryption(Encryption::generateKey());
    $ciphertext = $encryption->encrypt('Vertrouwelijke testinhoud', 'orders:notes');
    $bytes = base64_decode(substr($ciphertext, strlen('pbreeze:v1:')), true);
    for ($i = 0; $i < strlen($bytes); $i++) {
        $changed = $bytes;
        $changed[$i] = chr(ord($changed[$i]) ^ 1);
        raises(fn () => $encryption->decrypt('pbreeze:v1:' . base64_encode($changed), 'orders:notes'), EncryptionException::class);
    }
    raises(fn () => $encryption->decrypt($ciphertext), EncryptionException::class);
    raises(fn () => $encryption->decrypt($ciphertext, 'orders:other'), EncryptionException::class);
    raises(fn () => (new Encryption(Encryption::generateKey()))->decrypt($ciphertext, 'orders:notes'), EncryptionException::class);
    same('Vertrouwelijke testinhoud', $encryption->decrypt($ciphertext, 'orders:notes'));
});

test('Encryption format works directly with native OpenSSL AES-256-GCM', static function (): void {
    $configured = Encryption::generateKey();
    $key = base64_decode(substr($configured, 7), true);
    $encryption = new Encryption($configured);
    $payload = $encryption->encrypt('Onafhankelijke controle', 'test-context');
    $bytes = base64_decode(substr($payload, 11), true);
    same('Onafhankelijke controle', openssl_decrypt(
        substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($bytes, 0, 12), substr($bytes, 12, 16), 'pbreeze:v1:test-context',
    ));
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt('Reverse', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'pbreeze:v1:interop', 16);
    check(is_string($ciphertext));
    same('Reverse', $encryption->decrypt('pbreeze:v1:' . base64_encode($nonce . $tag . $ciphertext), 'interop'));
});

test('Encryption rejects invalid envelopes and all missing or malformed keys', static function (): void {
    $valid = new Encryption(Encryption::generateKey());
    foreach (['', 'plaintext', 'pbreeze:v2:AAAA', 'pbreeze:v1:!', 'pbreeze:v1:', 'pbreeze:v1:' . base64_encode(str_repeat('x', 27))] as $payload) {
        raises(fn () => $valid->decrypt($payload), EncryptionException::class);
    }
    $ciphertext = $valid->encrypt('content');
    raises(fn () => $valid->decrypt($ciphertext . "\n"), EncryptionException::class);
    raises(fn () => $valid->decrypt(substr($ciphertext, 0, -1)), EncryptionException::class);
    foreach (['', 'a-password', str_repeat('a', 32), 'base64:!', 'base64:' . base64_encode(random_bytes(31)), 'base64:' . base64_encode(random_bytes(33))] as $key) {
        $invalid = new Encryption($key);
        raises(fn () => $invalid->encrypt('test'), EncryptionException::class);
        raises(fn () => $invalid->decrypt($ciphertext), EncryptionException::class);
    }
});

test('Encryption keys are not dumped or serialized and parameters are sensitive', static function (): void {
    $key = Encryption::generateKey();
    $encryption = new Encryption($key);
    ob_start();
    var_dump($encryption);
    $dump = ob_get_clean();
    check(!str_contains($dump, $key));
    check(str_contains($dump, '[redacted]'));
    raises(fn () => serialize($encryption), LogicException::class);
    foreach (['__construct', 'encrypt', 'decrypt'] as $method) {
        $parameter = (new ReflectionMethod(Encryption::class, $method))->getParameters()[0];
        same(1, count($parameter->getAttributes(SensitiveParameter::class)));
    }
    $marker = 'not-in-error-message';
    try {
        (new Encryption($marker))->encrypt($marker);
        throw new LogicException('Expected error was not thrown.');
    } catch (EncryptionException $error) {
        check(!str_contains((string) $error, $marker));
    }
});

test('Application configuration shares encryption with the ORM without requiring a key for normal use', static function () use ($root): void {
    $config = [
        'name' => 'Test', 'environment' => 'testing', 'debug' => false, 'secure_cookie' => false,
        'key' => Encryption::generateKey(), 'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ];
    $app = new Application($root, $config);
    same($app->encryption, $app->db->encryption);
    $value = $app->encryption->encrypt('test');
    same('test', $app->db->encryption->decrypt($value));
    unset($config['key']);
    $app = new Application($root, $config);
    $app->router->get('/test', fn () => new PBreeze\Response('OK'));
    same(200, $app->router->dispatch(new PBreeze\Request('GET', '/test'))->status);
    raises(fn () => $app->encryption->encrypt('test'), EncryptionException::class);
});

test('key:generate creates valid unique keys without changing configuration files', static function () use ($app, $temp): void {
    $file = $temp . '/.env';
    $before = is_file($file) ? file_get_contents($file) : null;
    $first = trim(console($app, ['key:generate']));
    $second = trim(console($app, ['key:generate']));
    check(preg_match('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/D', $first) === 1);
    check($first !== $second);
    $encryption = new Encryption(substr($first, strlen('APP_KEY=')));
    same('ok', $encryption->decrypt($encryption->encrypt('ok')));
    same($before, is_file($file) ? file_get_contents($file) : null);
    raises(fn () => console($app, ['key:generate', '--force']), InvalidArgumentException::class);
    raises(fn () => console($app, ['key:generate', 'extra']), InvalidArgumentException::class);
});

test('Encrypted ORM casts store only ciphertext and hydrate without re-encryption or dirty changes', static function (): void {
    $db = encryptedDatabase(new Encryption(Encryption::generateKey()));
    $model = new EncryptedEntry($db);
    $record = $model->create([
        'title' => 'Example', 'secret' => 'Secret with Unicode: café', 'other_secret' => '',
        'details' => ['list' => [1, false, 'é'], 'float' => 1.0],
    ]);
    $raw = $db->table('encrypted_entries')->findOrFail($record->id);
    $wrongKey = new EncryptedEntry(encryptedDatabase(new Encryption(Encryption::generateKey())));
    raises(fn () => $wrongKey->hydrate($raw), EncryptionException::class);
    $otherTable = new class($db) extends Model {
        protected string $table = 'other_entries';
        protected array $casts = ['secret' => 'encrypted'];
    };
    raises(fn () => $otherTable->hydrate($raw), EncryptionException::class);
    foreach (['secret', 'other_secret', 'details'] as $field) {
        check(str_starts_with($raw[$field], 'pbreeze:v1:'));
    }
    check(!str_contains($raw['secret'], 'Secret'));
    same('Secret with Unicode: café', $record->secret);
    same('', $record->other_secret);
    same(['list' => [1, false, 'é'], 'float' => 1.0], $record->details);
    same([], $record->dirty());
    $record->refresh()->save();
    same($raw, $db->table('encrypted_entries')->findOrFail($record->id));
    $record->secret = 'Secret with Unicode: café';
    same([], $record->dirty());
    $record->title = 'Different';
    $record->save();
    same($raw['secret'], $db->table('encrypted_entries')->findOrFail($record->id)['secret']);
    $second = $model->create(['title' => 'Second', 'secret' => $record->secret]);
    check($raw['secret'] !== $db->table('encrypted_entries')->findOrFail($second->id)['secret']);
    same(['id' => $record->id, 'title' => 'Different'], $record->toArray());
    check(!str_contains(json_encode($record, JSON_THROW_ON_ERROR), 'Secret'));
});

test('Encrypted model and bulk updates support null, JSON, and type checking', static function (): void {
    $db = encryptedDatabase(new Encryption(Encryption::generateKey()));
    $record = (new EncryptedEntry($db))->create(['title' => 'Test', 'secret' => null, 'details' => null]);
    same(null, $record->secret);
    same(null, $record->details);
    $record->update(['secret' => 'new', 'details' => []]);
    same('new', $record->secret);
    same([], $record->details);
    same(1, $record->query()->where('id', $record->id)->update(['secret' => 'bulk', 'details' => ['bulk' => true]]));
    $record->refresh();
    same('bulk', $record->secret);
    same(['bulk' => true], $record->details);
    check(!str_contains(json_encode($record->dirty(), JSON_THROW_ON_ERROR), 'bulk'));
    raises(fn () => $record->secret = [], InvalidArgumentException::class);
    raises(fn () => $record->details = '{}', InvalidArgumentException::class);
    raises(fn () => $record->details = ['bad' => "\xff"], JsonException::class);
    $record->update(['secret' => null]);
    same(null, $db->table('encrypted_entries')->findOrFail($record->id)['secret']);
});

test('ORM rejects plaintext, forged ciphertext, and moving values between encrypted columns', static function (): void {
    $db = encryptedDatabase(new Encryption(Encryption::generateKey()));
    $record = (new EncryptedEntry($db))->create(['title' => 'Test', 'secret' => 'confidential', 'other_secret' => 'other']);
    $raw = $db->table('encrypted_entries')->findOrFail($record->id);
    $db->table('encrypted_entries')->where('id', $record->id)->update(['other_secret' => $raw['secret']]);
    raises(fn () => $record->refresh(), EncryptionException::class);
    $db->table('encrypted_entries')->where('id', $record->id)->update(['other_secret' => 'unencrypted']);
    raises(fn () => $record->query()->get(), EncryptionException::class);
    $db->table('encrypted_entries')->where('id', $record->id)->update(['other_secret' => $raw['other_secret'], 'secret' => substr($raw['secret'], 0, -4)]);
    raises(fn () => $record->refresh(), EncryptionException::class);
    same('confidential', $record->secret);
});

test('Encrypted casts fail without a key and block comparisons, sorting, and unsuitable keys', static function (): void {
    $db = encryptedDatabase();
    $model = new EncryptedEntry($db);
    raises(fn () => $model->create(['title' => 'No key', 'secret' => 'test']), EncryptionException::class);
    same(0, $model->query()->count());
    foreach ([
        fn () => $model->query()->where('secret', 'test'),
        fn () => $model->query()->whereIn('secret', ['test']),
        fn () => $model->query()->orderBy('details'),
    ] as $query) {
        raises($query, LogicException::class);
    }
    same(0, $model->query()->whereNull('secret')->count());
    same(0, $model->query()->where('secret', '!=', null)->count());
    same(0, $model->query()->whereIn('secret', [])->count());
    raises(fn () => new class($db) extends Model {
        protected string $table = 'encrypted_entries';
        protected array $casts = ['id' => 'encrypted'];
    }, LogicException::class);
    raises(fn () => new class($db) extends Model {
        protected string $table = 'encrypted_entries';
        protected bool $timestamps = true;
        protected array $casts = ['created_at' => 'encrypted'];
    }, LogicException::class);
});
