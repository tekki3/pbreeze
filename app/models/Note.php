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
        return $this->db->table('notes')->orderBy('id', 'desc')->limit($limit)->get();
    }

    public function create(string $title): int
    {
        if (trim($title) === '' || !preg_match('/^.{1,120}$/usD', $title)) {
            throw new InvalidArgumentException('Een notitietitel moet 1 tot 120 tekens bevatten.');
        }
        return (int) $this->db->table('notes')->insert(['title' => $title]);
    }
}
