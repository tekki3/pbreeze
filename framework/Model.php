<?php
declare(strict_types=1);

namespace PBreeze;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;

abstract class Model implements JsonSerializable
{
    protected string $table = '';
    protected string $primaryKey = 'id';
    protected bool $incrementing = true;
    protected bool $timestamps = false;
    /** @var list<string> */
    protected array $fillable = [];
    /** @var list<string> */
    protected array $hidden = [];
    /** @var array<string, string> */
    protected array $casts = [];
    /** @var array<string, scalar|null> */
    private array $attributes = [];
    /** @var array<string, scalar|null> */
    private array $original = [];
    private bool $persisted = false;
    private bool $deleted = false;

    final public function __construct(protected readonly Database $db)
    {
        Query::identifier($this->table);
        Query::identifier($this->primaryKey);
        foreach ($this->casts as $key => $cast) {
            if ($this->encrypted($key) && ($key === $this->primaryKey || $this->timestamps && in_array($key, ['created_at', 'updated_at'], true))) {
                throw new LogicException('Primary keys and automatic timestamps cannot be encrypted.');
            }
        }
    }

    /** @return Query<static> */
    final public function query(): Query
    {
        return new Query($this->db, $this->table, $this);
    }

    final public function keyName(): string
    {
        return $this->primaryKey;
    }

    final public function exists(): bool
    {
        return $this->persisted;
    }

    /** @param array<string, mixed> $data */
    final public function create(#[\SensitiveParameter] array $data): static
    {
        $record = new static($this->db);
        $record->fill($data);
        $record->save();
        return $record;
    }

    /** @param array<string, mixed> $data */
    final public function fill(#[\SensitiveParameter] array $data): static
    {
        $encoded = $this->encodeFill($data);
        $this->attributes = array_replace($this->attributes, $encoded);
        return $this;
    }

    /** @internal
     *  @param array<string, mixed> $data
     *  @return array<string, scalar|null>
     */
    final public function encodeFill(#[\SensitiveParameter] array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || !in_array($key, $this->fillable, true)
                || $key === $this->primaryKey && ($this->incrementing || $this->persisted)
                || $this->timestamps && in_array($key, ['created_at', 'updated_at'], true)) {
                throw new InvalidArgumentException('Model field is not writable: ' . $key);
            }
            Query::identifier($key);
            $result[$key] = $this->encode($key, $value);
            if ($this->encrypted($key) && array_key_exists($key, $this->attributes)
                && $this->decode($key, $this->attributes[$key]) === $value) {
                $result[$key] = $this->attributes[$key];
            }
            if ($key === $this->primaryKey && ((!is_int($result[$key]) && !is_string($result[$key])) || $result[$key] === '')) {
                throw new InvalidArgumentException('A primary key must be an int or a nonempty string.');
            }
        }
        return $result;
    }

    /** @internal @param array<string, mixed> $data */
    final public function hydrate(array $data): static
    {
        if (!isset($data[$this->primaryKey]) || !is_int($data[$this->primaryKey]) && !is_string($data[$this->primaryKey])) {
            throw new LogicException('A model record requires an int/string primary key.');
        }
        $record = new static($this->db);
        foreach ($data as $key => $value) {
            Query::identifier($key);
            $decoded = $record->decode($key, $value);
            $record->attributes[$key] = $record->encrypted($key) ? $value : $record->encode($key, $decoded);
        }
        $record->original = $record->attributes;
        $record->persisted = true;
        return $record;
    }

    final public function save(): bool
    {
        if ($this->deleted) {
            throw new LogicException('A deleted model cannot be saved again.');
        }
        if ($this->persisted && $this->dirty() === []) {
            return true;
        }
        $values = $this->attributes;
        if ($this->timestamps) {
            $now = gmdate('Y-m-d H:i:s');
            $values['updated_at'] = $now;
            if (!$this->persisted) {
                $values['created_at'] = $now;
            }
        }
        if ($this->persisted) {
            $changes = $this->changes($values);
            $query = $this->db->table($this->table)->where($this->primaryKey, $this->key());
            if ($query->update($changes) === 0 && !$query->exists()) {
                throw new RecordNotFoundException('The model record no longer exists.');
            }
        } else {
            if (!$this->incrementing && !isset($values[$this->primaryKey])) {
                throw new LogicException('A nonincrementing model requires a primary key.');
            }
            $id = $this->db->table($this->table)->insert($values);
            if ($this->incrementing) {
                $values[$this->primaryKey] = $id;
            }
            $this->persisted = true;
        }
        $this->attributes = $values;
        $this->original = $values;
        $this->refresh();
        return true;
    }

    /** @param array<string, mixed> $data */
    final public function update(#[\SensitiveParameter] array $data): bool
    {
        if (!$this->persisted) {
            throw new LogicException('update requires a saved model.');
        }
        return $this->fill($data)->save();
    }

    final public function delete(): bool
    {
        if (!$this->persisted) {
            throw new LogicException('delete requires a saved model.');
        }
        $deleted = $this->db->table($this->table)->where($this->primaryKey, $this->key())->delete() === 1;
        if (!$deleted) {
            throw new RecordNotFoundException('The model record no longer exists.');
        }
        $this->persisted = false;
        $this->deleted = true;
        return true;
    }

    final public function refresh(): static
    {
        if (!$this->persisted) {
            throw new LogicException('refresh requires a saved model.');
        }
        $data = $this->db->table($this->table)->where($this->primaryKey, $this->key())->firstOrFail();
        $record = $this->hydrate($data);
        $this->attributes = $record->attributes;
        $this->original = $record->original;
        return $this;
    }

    /** @return array<string, scalar|null> */
    final public function dirty(): array
    {
        return $this->changes($this->attributes);
    }

    /** @param array<string, scalar|null> $values
     *  @return array<string, scalar|null>
     */
    private function changes(array $values): array
    {
        $dirty = [];
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    final public function __get(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            throw new LogicException("Unknown or unloaded model field: $key");
        }
        return $this->decode($key, $this->attributes[$key]);
    }

    final public function __set(string $key, #[\SensitiveParameter] mixed $value): void
    {
        $this->fill([$key => $value]);
    }

    final public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /** @return array<string, mixed> */
    final public function toArray(): array
    {
        $data = [];
        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->hidden, true)) {
                $value = $this->decode($key, $value);
                $data[$key] = $value instanceof DateTimeInterface ? $value->format('Y-m-d\TH:i:s\Z') : $value;
            }
        }
        return $data;
    }

    /** @return array<string, mixed> */
    final public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @internal */
    final public function assertSearchable(string $key): void
    {
        if ($this->encrypted($key)) {
            throw new LogicException('Encrypted model fields do not support comparisons, sorting, or relationship keys.');
        }
    }

    /** @param class-string<Model> $related
     *  @return Query<Model>
     */
    final protected function hasMany(string $related, string $foreignKey, ?string $localKey = null): Query
    {
        $value = $this->relationValue($localKey ?? $this->primaryKey);
        $query = $this->related($related)->query();
        return $value === null ? $query->whereIn($foreignKey, []) : $query->where($foreignKey, $value);
    }

    /** @param class-string<Model> $related
     *  @return Query<Model>
     */
    final protected function belongsTo(string $related, string $foreignKey, ?string $ownerKey = null): Query
    {
        $model = $this->related($related);
        $value = $this->relationValue($foreignKey);
        $key = $ownerKey ?? $model->keyName();
        return $value === null ? $model->query()->whereIn($key, []) : $model->query()->where($key, $value);
    }

    private function related(string $class): Model
    {
        if (!is_subclass_of($class, self::class)) {
            throw new InvalidArgumentException('A relationship requires an explicitly loaded Model subclass.');
        }
        return new $class($this->db);
    }

    private function relationValue(string $key): int|string|null
    {
        Query::identifier($key);
        $this->assertSearchable($key);
        if (!$this->persisted) {
            throw new LogicException('Relationships require a saved model.');
        }
        $value = $this->__get($key);
        if ($value !== null && !is_int($value) && !is_string($value)) {
            throw new LogicException('A relationship key must be an int, string, or null.');
        }
        return $value;
    }

    private function key(): int|string
    {
        $key = $this->attributes[$this->primaryKey] ?? null;
        if (!is_int($key) && !is_string($key)) {
            throw new LogicException('A primary key must be an int or string.');
        }
        return $key;
    }

    private function encode(string $key, #[\SensitiveParameter] mixed $value): string|int|float|bool|null
    {
        $cast = $this->casts[$key] ?? null;
        $this->assertCast($cast);
        if ($value === null) {
            return null;
        }
        $valid = match ($cast) {
            'int' => is_int($value),
            'float' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'bool' => is_bool($value),
            'string', 'encrypted' => is_string($value),
            'json', 'encrypted:json' => is_array($value),
            'datetime' => $value instanceof DateTimeInterface,
            null => is_scalar($value) && (!is_float($value) || is_finite($value)),
        };
        if (!$valid) {
            throw new InvalidArgumentException("Invalid type for model field: $key");
        }
        return match ($cast) {
            'encrypted', 'encrypted:json' => $this->db->encryption->encrypt(
                $cast === 'encrypted:json' ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : $value,
                $this->encryptionContext($key),
            ),
            'json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'datetime' => DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'bool' => $value ? 1 : 0,
            'float' => (float) $value,
            default => $value,
        };
    }

    private function decode(string $key, #[\SensitiveParameter] mixed $value): mixed
    {
        $cast = $this->casts[$key] ?? null;
        $this->assertCast($cast);
        if ($value === null) {
            return null;
        }
        if ($this->encrypted($key)) {
            if (!is_string($value)) {
                throw new EncryptionException('Invalid stored value for an encrypted model field.');
            }
            $plaintext = $this->db->encryption->decrypt($value, $this->encryptionContext($key));
            return $cast === 'encrypted:json' ? $this->json($plaintext, $key) : $plaintext;
        }
        return match ($cast) {
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : throw new InvalidArgumentException("Invalid integer in $key"),
            'float' => is_numeric($value) && is_finite((float) $value) ? (float) $value : throw new InvalidArgumentException("Invalid number in $key"),
            'bool' => in_array($value, [0, 1, '0', '1', false, true], true) ? (bool) $value : throw new InvalidArgumentException("Invalid boolean in $key"),
            'string' => is_scalar($value) ? (string) $value : throw new InvalidArgumentException("Invalid text in $key"),
            'json' => $this->json($value, $key),
            'datetime' => $this->date($value, $key),
            null => $value,
        };
    }

    private function assertCast(?string $cast): void
    {
        if (!in_array($cast, [null, 'int', 'float', 'bool', 'string', 'json', 'datetime', 'encrypted', 'encrypted:json'], true)) {
            throw new LogicException("Unknown model cast: $cast");
        }
    }

    private function encrypted(string $key): bool
    {
        return in_array($this->casts[$key] ?? null, ['encrypted', 'encrypted:json'], true);
    }

    private function encryptionContext(string $key): string
    {
        return 'orm:' . $this->table . ':' . $key . ':' . $this->casts[$key];
    }

    private function json(#[\SensitiveParameter] mixed $value, string $key): array
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("Invalid stored JSON in $key");
        }
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : throw new InvalidArgumentException("JSON field $key must contain an array/object.");
    }

    private function date(mixed $value, string $key): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC')) : false;
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException("Invalid UTC date in $key");
        }
        return $date;
    }
}
