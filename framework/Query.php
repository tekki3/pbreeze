<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;
use LogicException;
use PDO;
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

    public static function identifier(string $name): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException('Gebruik een eenvoudige SQL-identifier zonder expressies of tabelprefix.');
        }
        return '`' . $name . '`';
    }

    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        if (!is_string($operator) || !in_array(strtoupper($operator), ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'], true)) {
            throw new InvalidArgumentException('Ongeldige vergelijkingsoperator.');
        }
        $operator = strtoupper($operator);
        if ($value === null) {
            return match ($operator) {
                '=' => $this->whereNull($column),
                '!=', '<>' => $this->whereNotNull($column),
                default => throw new InvalidArgumentException('Gebruik = of != om met null te vergelijken.'),
            };
        }
        self::value($value);
        $query = clone $this;
        $query->conditions[] = self::identifier($column) . " $operator ?";
        $query->bindings[] = $value;
        return $query;
    }

    public function whereNull(string $column): self
    {
        $query = clone $this;
        $query->conditions[] = self::identifier($column) . ' IS NULL';
        return $query;
    }

    public function whereNotNull(string $column): self
    {
        $query = clone $this;
        $query->conditions[] = self::identifier($column) . ' IS NOT NULL';
        return $query;
    }

    /** @param list<scalar|null> $values */
    public function whereIn(string $column, array $values): self
    {
        $identifier = self::identifier($column);
        if (!array_is_list($values) || count($values) > 500) {
            throw new InvalidArgumentException('whereIn verwacht een lijst van maximaal 500 waarden.');
        }
        foreach ($values as $value) {
            self::value($value);
            if ($value === null) {
                throw new InvalidArgumentException('Gebruik whereNull voor null-waarden.');
            }
        }
        $query = clone $this;
        $query->conditions[] = $values === [] ? '1 = 0' : $identifier . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        array_push($query->bindings, ...$values);
        return $query;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Sorteerrichting moet asc of desc zijn.');
        }
        $query = clone $this;
        $query->orders[] = self::identifier($column) . ' ' . $direction;
        return $query;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('De limiet moet positief zijn.');
        }
        $query = clone $this;
        $query->take = $limit;
        return $query;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('De offset mag niet negatief zijn.');
        }
        $query = clone $this;
        $query->skip = $offset;
        return $query;
    }

    /** @return list<T> */
    public function get(): array
    {
        $sql = 'SELECT * FROM ' . self::identifier($this->table) . $this->whereSql();
        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->take !== null) {
            $sql .= ' LIMIT ' . $this->take;
        } elseif ($this->skip !== 0) {
            throw new LogicException('offset vereist een expliciete limit.');
        }
        if ($this->skip !== 0) {
            $sql .= ' OFFSET ' . $this->skip;
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
        return $this->first() ?? throw new RecordNotFoundException('Record niet gevonden.');
    }

    /** @return T|null */
    public function find(int|string $id): Model|array|null
    {
        return $this->where($this->model?->keyName() ?? 'id', $id)->first();
    }

    /** @return T */
    public function findOrFail(int|string $id): Model|array
    {
        return $this->find($id) ?? throw new RecordNotFoundException('Record niet gevonden.');
    }

    public function count(): int
    {
        return (int) $this->execute('SELECT COUNT(*) FROM ' . self::identifier($this->table) . $this->whereSql(), $this->bindings)->fetchColumn();
    }

    public function exists(): bool
    {
        return $this->execute('SELECT 1 FROM ' . self::identifier($this->table) . $this->whereSql() . ' LIMIT 1', $this->bindings)->fetchColumn() !== false;
    }

    /** @return array{data: list<T>, total: int, per_page: int, current_page: int, last_page: int} */
    public function paginate(int $perPage = 20, int $page = 1): array
    {
        if ($perPage < 1 || $perPage > 100 || $page < 1 || $page - 1 > intdiv(PHP_INT_MAX, $perPage)) {
            throw new InvalidArgumentException('Gebruik een positieve pagina en 1 tot 100 records per pagina.');
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
    public function insert(array $data): int|string
    {
        if ($this->model !== null) {
            throw new LogicException('Gebruik create() voor een modelquery.');
        }
        $this->assertInsert();
        $columns = $this->columns($data);
        if ($data === []) {
            $suffix = $this->db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' () VALUES ()' : ' DEFAULT VALUES';
            $this->execute('INSERT INTO ' . self::identifier($this->table) . $suffix, []);
        } else {
            $this->execute('INSERT INTO ' . self::identifier($this->table) . ' (' . implode(', ', $columns)
                . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')', array_values($data));
        }
        $id = $this->db->pdo()->lastInsertId();
        if ($id === false) {
            throw new RuntimeException('Kan het insert-ID niet ophalen.');
        }
        return filter_var($id, FILTER_VALIDATE_INT) !== false ? (int) $id : $id;
    }

    /** @param array<string, mixed> $data
     *  @return T
     */
    public function create(array $data): Model
    {
        $this->assertInsert();
        if ($this->model === null) {
            throw new LogicException('create vereist een modelquery; gebruik insert voor een tabel.');
        }
        return $this->model->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): int
    {
        $this->assertWrite();
        if ($this->model !== null) {
            if (array_key_exists($this->model->keyName(), $data)) {
                throw new InvalidArgumentException('Een modelupdate mag de primaire sleutel niet wijzigen.');
            }
            $data = $this->model->encodeFill($data);
        }
        if ($data === []) {
            throw new InvalidArgumentException('Een update vereist minimaal een veld.');
        }
        $columns = $this->columns($data);
        return $this->execute('UPDATE ' . self::identifier($this->table) . ' SET '
            . implode(', ', array_map(static fn (string $column): string => "$column = ?", $columns))
            . $this->whereSql(), [...array_values($data), ...$this->bindings])->rowCount();
    }

    public function delete(): int
    {
        $this->assertWrite();
        return $this->execute('DELETE FROM ' . self::identifier($this->table) . $this->whereSql(), $this->bindings)->rowCount();
    }

    private function assertInsert(): void
    {
        if ($this->conditions !== [] || $this->orders !== [] || $this->take !== null || $this->skip !== 0) {
            throw new LogicException('insert/create accepteert geen filters, sortering of paginering.');
        }
    }

    private function assertWrite(): void
    {
        if ($this->conditions === []) {
            throw new LogicException('Een update/delete vereist een expliciet filter.');
        }
        if ($this->orders !== [] || $this->take !== null || $this->skip !== 0) {
            throw new LogicException('update/delete met sortering of paginering wordt niet ondersteund.');
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
                throw new InvalidArgumentException('Veldnamen moeten tekst zijn.');
            }
            $columns[] = self::identifier($column);
            self::value($value);
        }
        return $columns;
    }

    private static function value(mixed $value): void
    {
        if (!is_scalar($value) && $value !== null || is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('SQL-waarden moeten scalars of null zijn; gebruik modelcasts voor JSON/datums.');
        }
    }

    /** @param list<scalar|null> $values */
    private function execute(string $sql, array $values): \PDOStatement
    {
        $statement = $this->db->pdo()->prepare($sql);
        foreach ($values as $index => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->execute();
        return $statement;
    }
}

final class RecordNotFoundException extends RuntimeException
{
}
