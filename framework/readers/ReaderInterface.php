<?php
declare(strict_types=1);

namespace PBreeze\Readers;

interface ReaderInterface
{
    /** @return array<mixed> */
    public function read(string $path, array $options = []): array;

    /** @return iterable<array<mixed>> */
    public function iterate(string $path, array $options = []): iterable;
}
