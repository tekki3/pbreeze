<?php
declare(strict_types=1);

namespace PBreeze;

use JsonSerializable;

final class ValidationErrors implements JsonSerializable
{
    /** @param array<string, list<string>> $messages */
    public function __construct(private readonly array $messages) {}

    /** @return list<string> */
    public function all(): array
    {
        return array_merge([], ...array_values($this->messages));
    }

    public function first(?string $field = null): ?string
    {
        return ($field === null ? $this->all() : $this->get($field))[0] ?? null;
    }

    /** @return list<string> */
    public function get(string $field): array
    {
        return $this->messages[$field] ?? [];
    }

    public function has(string $field): bool
    {
        return $this->get($field) !== [];
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return $this->messages;
    }

    public function jsonSerialize(): object
    {
        return (object) $this->messages;
    }
}
