<?php
declare(strict_types=1);

namespace PBreeze;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(public readonly ValidationErrors $errors)
    {
        parent::__construct('Check your input.');
    }
}
