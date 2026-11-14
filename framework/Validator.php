<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;

require_once __DIR__ . '/RuleInterface.php';
require_once __DIR__ . '/ValidationErrors.php';
require_once __DIR__ . '/ValidationException.php';
require_once __DIR__ . '/ValidationResult.php';

final class Validator
{
    /** @param array<string, string|list<string|RuleInterface>> $rules */
    public static function make(array $data, array $rules, array $messages = [], array $attributes = []): ValidationResult
    {
        return new ValidationResult($data, $rules, $messages, $attributes);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @return array<string, list<string>>
     */
    public static function errors(array $data, array $rules): array
    {
        foreach ($rules as $field => $definition) {
            foreach (explode('|', $definition) as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
                if (!in_array($name, ['required', 'string', 'email', 'integer', 'min', 'max', 'confirmed'], true)) {
                    throw new InvalidArgumentException("Unknown validation rule: $name");
                }
                if (in_array($name, ['min', 'max'], true) && ($argument === null || !ctype_digit($argument))) {
                    throw new InvalidArgumentException("$name requires a nonnegative integer.");
                }
            }
        }
        return (new ValidationResult($data, $rules, [], [], legacy: true))->errors()->toArray();
    }
}
