<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * @template T of Model|array<string, mixed>
 */
final class Query
{
    /** @var list<string> */
    private array $conditions = [];
    /** @var list<scalar|null> */
    private array $bindings = [];
    /** @var list<string> */
    private array $orders = [];
    private ?int $take = null;
    private int $skip = 0;

    public function __construct(
        private readonly Database $db,
        private readonly string $table,
        private readonly ?Model $model = null,
    ) {
        self::identifier($table);
    }

    public static function identifier(string $name, string $driver = 'mysql'): string
    {
        if (!in_array($driver, ['sqlite', 'mysql', 'sqlsrv'], true)) {
            throw new InvalidArgumentException('Unsupported SQL identifier driver.');
        }
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Use a simple SQL identifier without expressions or a table prefix.');
        }
        return $driver === 'sqlsrv' ? '[' . $name . ']' : '`' . $name . '`';
    }

    private function quote(string $name): string
    {
        return self::identifier($name, $this->db->driver());
    }
    public function where(string $column, #[\SensitiveParameter] mixed $operator, #[\SensitiveParameter] mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        if (!is_string($operator) || !in_array(strtoupper($operator), ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'], true)) {
            throw new InvalidArgumentException('Invalid comparison operator.');
        }
        $operator = strtoupper($operator);
        if ($value === null) {
            return match ($operator) {
                '=' => $this->whereNull($column),
                '!=', '<>' => $this->whereNotNull($column),
                default => throw new InvalidArgumentException('Use = or != to compare with null.'),
            };
        }
        self::value($value);
        $this->model?->assertSearchable($column);
        $query = clone $this;
        $query->conditions[] = $this->quote($column) . " $operator ?";
        $query->bindings[] = $value;
        return $query;
    }

    public function whereNull(string $column): self
    {
        $query = clone $this;
        $query->conditions[] = $this->quote($column) . ' IS NULL';
        return $query;
    }

    public function whereNotNull(string $column): self
    {
        $query = clone $this;
        $query->conditions[] = $this->quote($column) . ' IS NOT NULL';
        return $query;
    }

    /** @param list<scalar|null> $values */
    public function whereIn(string $column, #[\SensitiveParameter] array $values): self
    {
        $identifier = $this->quote($column);
        if ($values !== []) {
            $this->model?->assertSearchable($column);
        }
        if (!array_is_list($values) || count($values) > 500) {
            throw new InvalidArgumentException('whereIn expects a list of up to 500 values.');
        }
        foreach ($values as $value) {
            self::value($value);
            if ($value === null) {
                throw new InvalidArgumentException('Use whereNull for null values.');
            }
        }
        $query = clone $this;
        $query->conditions[] = $values === [] ? '1 = 0' : $identifier . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        array_push($query->bindings, ...$values);
        return $query;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->model?->assertSearchable($column);
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Sort direction must be asc or desc.');
        }
        $query = clone $this;
        $query->orders[] = $this->quote($column) . ' ' . $direction;
        return $query;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('The limit must be positive.');
        }
        $query = clone $this;
        $query->take = $limit;
        return $query;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('The offset must not be negative.');
        }
        $query = clone $this;
        $query->skip = $offset;
        return $query;
    }

    /** @return list<T> */
    public function get(): array
    {
        $sql = 'SELECT * FROM ' . $this->quote($this->table) . $this->whereSql();
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->take !== null) {
            if ($this->skip !== 0 && $this->orders === [] && $this->db->driver() === 'sqlsrv') {
                $sql .= ' ORDER BY (SELECT NULL)';
            }
            $sql = $this->db->limit($sql, $this->take, $this->skip);
        } elseif ($this->skip !== 0) {
            throw new LogicException('offset requires an explicit limit.');
        }
        $rows = $this->execute($sql, $this->bindings)->fetchAll();
        return $this->model === null ? $rows : array_map($this->model->hydrate(...), $rows);
    }

    /** @return T|null */
    public function first(): Model|array|null
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    /** @return T */
    public function firstOrFail(): Model|array
    {
        return $this->first() ?? throw new RecordNotFoundException('Record not found.');
    }

    /** @return T|null */
    public function find(int|string $id): Model|array|null
    {
        return $this->where($this->model?->keyName() ?? 'id', $id)->first();
    }

    /** @return T */
    public function findOrFail(int|string $id): Model|array
    {
        return $this->find($id) ?? throw new RecordNotFoundException('Record not found.');
    }

    public function count(): int
    {
        return (int) $this->execute('SELECT COUNT(*) FROM ' . $this->quote($this->table) . $this->whereSql(), $this->bindings)->fetchColumn();
    }

    public function exists(): bool
    {
        return $this->execute($this->db->limit('SELECT 1 FROM ' . $this->quote($this->table) . $this->whereSql(), 1), $this->bindings)->fetchColumn() !== false;
    }

    /** @return array{data: list<T>, total: int, per_page: int, current_page: int, last_page: int} */
    public function paginate(int $perPage = 20, int $page = 1): array
    {
        if ($perPage < 1 || $perPage > 100 || $page < 1 || $page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw new InvalidArgumentException('Use a positive page number and 1 to 100 records per page.');
        }
        $query = $this->orders === [] ? $this->orderBy($this->model?->keyName() ?? 'id') : $this;
        $total = $this->count();
        return [
            'data' => $query->limit($perPage)->offset(($page - 1) * $perPage)->get(),
            'total' => $total, 'per_page' => $perPage, 'current_page' => $page,
            'last_page' => max(1, intdiv(max(0, $total - 1), $perPage) + ($total > 0 ? 1 : 0)),
        ];
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data, ?string $identity = 'id'): int|string
    {
        if ($this->model !== null) {
            throw new LogicException('Use create() for a model query.');
        }
        $this->assertInsert();
        $this->columns($data);
        return $this->db->insert($this->table, $data, $identity);
    }

    /** @param array<string, mixed> $data
     *  @return T
     */
    public function create(#[\SensitiveParameter] array $data): Model
    {
        $this->assertInsert();
        if ($this->model === null) {
            throw new LogicException('create requires a model query; use insert for a table.');
        }
        return $this->model->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(#[\SensitiveParameter] array $data): int
    {
        $this->assertWrite();
        if ($this->model !== null) {
            if (array_key_exists($this->model->keyName(), $data)) {
                throw new InvalidArgumentException('A model update cannot change the primary key.');
            }
            $data = $this->model->encodeFill($data);
        }
        if ($data === []) {
            throw new InvalidArgumentException('An update requires at least one field.');
        }
        $columns = $this->columns($data);
        return $this->execute('UPDATE ' . $this->quote($this->table) . ' SET '
            . implode(', ', array_map(static fn (string $column): string => "$column = ?", $columns))
            . $this->whereSql(), [...array_values($data), ...$this->bindings])->rowCount();
    }

    public function delete(): int
    {
        $this->assertWrite();
        return $this->execute('DELETE FROM ' . $this->quote($this->table) . $this->whereSql(), $this->bindings)->rowCount();
    }

    private function assertInsert(): void
    {
        if ($this->conditions !== [] || $this->orders !== [] || $this->take !== null || $this->skip !== 0) {
            throw new LogicException('insert/create does not accept filters, sorting, or pagination.');
        }
    }

    private function assertWrite(): void
    {
        if ($this->conditions === []) {
            throw new LogicException('An update/delete requires an explicit filter.');
        }
        if ($this->orders !== [] || $this->take !== null || $this->skip !== 0) {
            throw new LogicException('update/delete with sorting or pagination is not supported.');
        }
    }

    private function whereSql(): string
    {
        return $this->conditions === [] ? '' : ' WHERE ' . implode(' AND ', $this->conditions);
    }

    /** @param array<string, mixed> $data
     *  @return list<string>
     */
    private function columns(array $data): array
    {
        $columns = [];
        foreach ($data as $column => $value) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Field names must be strings.');
            }
            $columns[] = $this->quote($column);
            self::value($value);
        }
        return $columns;
    }

    private static function value(mixed $value): void
    {
        if (!is_scalar($value) && $value !== null || is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('SQL values must be scalars or null; use model casts for JSON/dates.');
        }
    }

    /** @param list<scalar|null> $values */
    private function execute(string $sql, array $values): \PDOStatement
    {
        return $this->db->execute($sql, $values);
    }
}

final class RecordNotFoundException extends RuntimeException
{
}
