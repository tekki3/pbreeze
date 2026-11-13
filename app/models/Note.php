<?php
declare(strict_types=1);

namespace App\Models;

use InvalidArgumentException;
use PBreeze\Database;

final class Note
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array{id: int, title: string, created_at: string}> */
    public function latest(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Gebruik een limiet tussen 1 en 100.');
        }
        $statement = $this->db->pdo()->prepare('SELECT id, title, created_at FROM notes ORDER BY id DESC LIMIT ?');
        $statement->bindValue(1, $limit, \PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function create(string $title): int
    {
        if (trim($title) === '' || !preg_match('/^.{1,120}$/usD', $title)) {
            throw new InvalidArgumentException('Een notitietitel moet 1 tot 120 tekens bevatten.');
        }
        $this->db->execute('INSERT INTO notes (title) VALUES (?)', [$title]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
