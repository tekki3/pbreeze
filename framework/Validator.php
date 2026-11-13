<?php
declare(strict_types=1);

namespace PBreeze;

use InvalidArgumentException;

final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @return array<string, list<string>>
     */
    public static function errors(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $definition) {
            $value = $data[$field] ?? null;
            foreach (explode('|', $definition) as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
                if (!in_array($name, ['required', 'string', 'email', 'integer', 'min', 'max', 'confirmed'], true)) {
                    throw new InvalidArgumentException("Onbekende validatieregel: $name");
                }
                if (in_array($name, ['min', 'max'], true) && ($argument === null || !ctype_digit($argument))) {
                    throw new InvalidArgumentException("$name vereist een niet-negatief geheel getal.");
                }
                if ($name !== 'required' && ($value === null || $value === '')) {
                    continue;
                }
                $length = is_string($value) ? preg_match_all('/./us', $value) : false;
                $valid = match ($name) {
                    'required' => $value !== null && $value !== [] && (!is_string($value) || trim($value) !== ''),
                    'string' => is_string($value),
                    'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'integer' => (is_int($value) || is_string($value)) && filter_var($value, FILTER_VALIDATE_INT) !== false,
                    'min' => $length !== false && $length >= (int) $argument,
                    'max' => $length !== false && $length <= (int) $argument,
                    'confirmed' => is_string($value) && $value === ($data[$field . '_confirmation'] ?? null),
                };
                if (!$valid) {
                    $errors[$field][] = match ($name) {
                        'required' => 'Dit veld is verplicht.',
                        'string' => 'Vul tekst in.',
                        'email' => 'Vul een geldig e-mailadres in.',
                        'integer' => 'Vul een geheel getal in.',
                        'min' => "Gebruik minimaal $argument tekens.",
                        'max' => "Gebruik maximaal $argument tekens.",
                        'confirmed' => 'De bevestiging komt niet overeen.',
                    };
                }
            }
        }
        return $errors;
    }
}
