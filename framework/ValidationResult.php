<?php
declare(strict_types=1);

namespace PBreeze;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ValidationResult
{
    private const NO_ARGUMENT = [
        'required', 'present', 'nullable', 'sometimes', 'string', 'integer', 'numeric', 'float', 'boolean',
        'array', 'email', 'url', 'uuid', 'ip', 'ipv4', 'ipv6', 'json', 'alpha', 'alpha_num', 'confirmed', 'date', 'file',
    ];
    private const ARGUMENT = [
        'required_if', 'required_unless', 'min', 'max', 'length', 'between', 'regex', 'same', 'different',
        'in', 'not_in', 'date_format', 'before', 'after', 'mimes', 'mimetypes', 'max_size',
    ];
    private array $rules = [];
    private ?ValidationErrors $errorBag = null;
    private array $output = [];

    public function __construct(
        private readonly array $data,
        array $rules,
        private readonly array $messages = [],
        private readonly array $attributes = [],
        private readonly bool $legacy = false,
    ) {
        foreach ([$messages, $attributes] as $labels) {
            foreach ($labels as $key => $value) {
                if (!is_string($key) || !is_string($value) || $value === '' || preg_match('//u', $value) !== 1) {
                    throw new InvalidArgumentException('Validation messages and attributes require nonempty UTF-8 string values and string keys.');
                }
            }
        }
        if (count($rules) > 1000) {
            throw new InvalidArgumentException('Validation supports at most 1000 field definitions.');
        }
        foreach ($rules as $field => $definition) {
            self::path($field);
            $definitions = is_string($definition) ? explode('|', $definition) : $definition;
            if (!is_array($definitions) || !array_is_list($definitions) || $definitions === []) {
                throw new InvalidArgumentException('Field rules must be a nonempty pipe string or rule list.');
            }
            $normalized = [];
            foreach ($definitions as $rule) {
                if ($rule instanceof RuleInterface) {
                    $normalized[] = $rule;
                    continue;
                }
                if (!is_string($rule)) {
                    throw new InvalidArgumentException('Rules must be strings or RuleInterface objects.');
                }
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
                $this->checkRule($name, $argument);
                $normalized[] = [$name, $argument];
            }
            $this->rules[$field] = $normalized;
        }
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    public function fails(): bool
    {
        return $this->errors()->all() !== [];
    }

    public function errors(): ValidationErrors
    {
        if ($this->errorBag === null) {
            $this->evaluate();
        }
        return $this->errorBag;
    }

    public function validated(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors());
        }
        return $this->output;
    }

    public function validate(): array
    {
        return $this->validated();
    }

    private static function path(mixed $path): void
    {
        if (!is_string($path) || strlen($path) > 512 || preg_match('//u', $path) !== 1
            || preg_match('/[\x00-\x20\x7f]/', $path) || count(explode('.', $path)) > 32) {
            throw new InvalidArgumentException('Validation paths must be UTF-8 strings of at most 32 segments and 512 bytes without controls or spaces.');
        }
        foreach (explode('.', $path) as $segment) {
            if ($segment === '' || ($segment !== '*' && str_contains($segment, '*'))) {
                throw new InvalidArgumentException('Use nonempty dot-separated field names and complete wildcard segments.');
            }
        }
    }

    private function checkRule(string $name, ?string $argument): void
    {
        if (in_array($name, self::NO_ARGUMENT, true)) {
            if ($argument !== null) {
                throw new InvalidArgumentException("$name does not accept arguments.");
            }
            return;
        }
        if (!in_array($name, self::ARGUMENT, true) || $argument === null || $argument === '') {
            throw new InvalidArgumentException("Unknown rule or missing argument: $name");
        }
        if (in_array($name, ['min', 'max', 'length', 'between', 'max_size'], true)) {
            $parts = explode(',', $argument);
            if (count($parts) !== ($name === 'between' ? 2 : 1)) {
                throw new InvalidArgumentException("$name has an invalid number of bounds.");
            }
            foreach ($parts as $part) {
                if (!preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D', $part) || !is_finite((float) $part)
                    || (in_array($name, ['length', 'max_size'], true)
                        && (!ctype_digit($part) || filter_var($part, FILTER_VALIDATE_INT) === false))) {
                    throw new InvalidArgumentException("$name requires finite numeric bounds (length/max_size require nonnegative integers).");
                }
            }
            if ($name === 'between' && self::number($parts[0]) > self::number($parts[1])) {
                throw new InvalidArgumentException('between requires ascending bounds.');
            }
        }
        if (in_array($name, ['same', 'different', 'required_if', 'required_unless'], true)) {
            $parts = explode(',', $argument);
            self::path($parts[0]);
            if (in_array($name, ['required_if', 'required_unless'], true) && count($parts) < 2) {
                throw new InvalidArgumentException("$name requires a field and at least one comparison value.");
            }
        }
        if ($name === 'regex') {
            self::regex($argument, '');
        }
        if ($name === 'mimes' && (!class_exists(UploadedFile::class, false)
            || array_diff(explode(',', $argument), UploadedFile::extensions()) !== [])) {
            throw new InvalidArgumentException('mimes supports the existing UploadedFile extension allowlist only.');
        }
        if ($name === 'mimetypes') {
            foreach (explode(',', $argument) as $type) {
                if (!preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+$~D', $type)) {
                    throw new InvalidArgumentException('mimetypes requires exact lowercase MIME types, not wildcards.');
                }
            }
        }
        if (in_array($name, ['before', 'after'], true) && !str_starts_with($argument, '@') && self::date($argument) === null) {
            throw new InvalidArgumentException('Date comparisons require an ISO date/time or @field reference.');
        }
        if (in_array($name, ['before', 'after'], true) && str_starts_with($argument, '@')) {
            self::path(substr($argument, 1));
        }
        if ($name === 'date_format' && (strlen($argument) > 128 || str_contains($argument, "\0"))) {
            throw new InvalidArgumentException('date_format requires a PHP date format of at most 128 bytes.');
        }
    }

    private function evaluate(): void
    {
        $errors = [];
        $covered = [];
        $budget = 0;
        foreach ($this->rules as $pattern => $rules) {
            $fields = $this->expand(explode('.', $pattern), $this->data, [], $budget);
            foreach ($fields as $segments) {
                $field = implode('.', $segments);
                [$exists, $value] = $this->lookup($field);
                $names = array_map(static fn (array|RuleInterface $rule): string => is_array($rule) ? $rule[0] : '', $rules);
                if (!$exists && in_array('sometimes', $names, true)) {
                    continue;
                }
                $numeric = !$this->legacy && (is_int($value) || is_float($value)
                    || array_intersect(['integer', 'numeric', 'float'], $names) !== []);
                foreach ($rules as $rule) {
                    if ($rule instanceof RuleInterface) {
                        if ($exists && !($value === null && in_array('nullable', $names, true)) && !$rule->validate($field, $value, $this->data)) {
                            $errors[$field][] = $this->message($field, $pattern, 'custom', null, $rule->message(), $numeric, $value);
                        }
                        continue;
                    }
                    [$name, $argument] = $rule;
                    if (in_array($name, ['nullable', 'sometimes'], true)) {
                        continue;
                    }
                    $presence = in_array($name, ['required', 'present', 'required_if', 'required_unless'], true);
                    if (!$presence && (!$exists || ($value === null && in_array('nullable', $names, true))
                        || ($this->legacy && ($value === null || $value === '')))) {
                        continue;
                    }
                    if (!$this->accepts($name, $argument, $field, $value, $exists, $numeric)) {
                        $errors[$field][] = $this->message($field, $pattern, $name, $argument, null, $numeric, $value);
                    }
                }
                if ($exists && $value instanceof UploadedFile && array_intersect(['file', 'mimes', 'mimetypes', 'max_size'], $names) === []) {
                    throw new InvalidArgumentException('UploadedFile output requires an explicit file validation rule.');
                }
                if ($exists) {
                    $covered[$field] = $value;
                }
            }
        }
        if ($errors === []) {
            uksort($covered, static fn (string $a, string $b): int => substr_count($a, '.') <=> substr_count($b, '.'));
            foreach ($covered as $field => $value) {
                if (is_array($value)) {
                    $value = [];
                } elseif ((is_object($value) && !$value instanceof UploadedFile) || is_resource($value)) {
                    throw new InvalidArgumentException('Validated output supports scalar/null values, arrays, and validated UploadedFile objects; map DTOs to arrays first.');
                }
                $target = &$this->output;
                foreach (explode('.', (string) $field) as $segment) {
                    $target = &$target[$segment];
                }
                $target = $value;
                unset($target);
            }
        }
        $this->errorBag = new ValidationErrors($errors);
    }

    private function expand(array $segments, mixed $data, array $prefix, int &$budget): array
    {
        if (++$budget > 100000) {
            throw new InvalidArgumentException('Validation wildcard expansion exceeds the 100000-step limit.');
        }
        if ($segments === []) {
            return [$prefix];
        }
        $segment = array_shift($segments);
        if ($segment !== '*') {
            return $this->expand($segments, is_array($data) ? ($data[$segment] ?? null) : null, [...$prefix, $segment], $budget);
        }
        $result = [];
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (str_contains((string) $key, '.') || str_contains((string) $key, '*')) {
                    throw new InvalidArgumentException('Wildcard data keys cannot contain dots or asterisks.');
                }
                self::path((string) $key);
                array_push($result, ...$this->expand($segments, $value, [...$prefix, (string) $key], $budget));
            }
        }
        return $result;
    }

    private function lookup(string $field): array
    {
        $value = $this->data;
        foreach (explode('.', $field) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }
        return [true, $value];
    }

    private function relative(string $path, string $field): string
    {
        $actual = explode('.', $field);
        $reference = explode('.', $path);
        foreach ($reference as $index => $segment) {
            if ($segment === '*') {
                $reference[$index] = $actual[$index] ?? '*';
            }
        }
        return implode('.', $reference);
    }

    private function accepts(string $name, ?string $argument, string $field, mixed $value, bool $exists, bool $numeric): bool
    {
        if (in_array($name, ['required_if', 'required_unless'], true)) {
            $parts = explode(',', $argument);
            $other = array_shift($parts);
            [$hasOther, $otherValue] = $this->lookup($this->relative($other, $field));
            $matches = $hasOther && self::oneOf($otherValue, $parts);
            $required = $name === 'required_if' ? $matches : !$matches;
            return !$required || ($exists && !self::empty($value));
        }
        if (in_array($name, ['same', 'different', 'confirmed'], true)) {
            $other = $name === 'confirmed' ? $field . '_confirmation' : $this->relative($argument, $field);
            [$hasOther, $otherValue] = $this->lookup($other);
            if ($this->legacy && $name === 'confirmed' && !is_string($value)) {
                return false;
            }
            return $hasOther && ($name === 'different' ? $value !== $otherValue : $value === $otherValue);
        }
        if (in_array($name, ['file', 'mimes', 'mimetypes', 'max_size'], true)) {
            return $this->file($value, $name, $argument);
        }
        $text = is_string($value) && preg_match('//u', $value) === 1;
        return match ($name) {
            'required' => $exists && !self::empty($value),
            'present' => $exists,
            'string' => $text,
            'integer' => (is_int($value) || is_string($value)) && filter_var($value, FILTER_VALIDATE_INT) !== false
                && ($this->legacy || !is_string($value) || preg_match('/^[+-]?(?:0|[1-9]\d*)$/D', $value) === 1),
            'numeric', 'float' => self::numeric($value),
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1'], true),
            'array' => is_array($value),
            'min', 'max', 'between', 'length' => $this->size($name, $argument, $value, $name === 'length' ? false : $numeric),
            'email' => $text && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => $text && filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                && !preg_match('/[\x00-\x20\x7f\\\\]/', $value) && parse_url($value, PHP_URL_USER) === null,
            'uuid' => $text && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) === 1,
            'ip' => $text && filter_var($value, FILTER_VALIDATE_IP) !== false,
            'ipv4' => $text && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6' => $text && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'json' => $text && self::json($value),
            'alpha' => $text && preg_match('/^\p{L}+$/uD', $value) === 1,
            'alpha_num' => $text && preg_match('/^[\p{L}\p{N}]+$/uD', $value) === 1,
            'regex' => $text && self::regex($argument, $value),
            'in' => self::oneOf($value, explode(',', $argument)),
            'not_in' => (is_scalar($value) || $value === null) && !self::oneOf($value, explode(',', $argument)),
            'date' => $text && self::date($value) !== null,
            'date_format' => $text && self::formattedDate($value, $argument),
            'before', 'after' => $text && $this->dateComparison($name, $value, $argument, $field),
        };
    }

    private static function empty(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }

    private static function numeric(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || (is_string($value)
            && preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value)))
            && is_numeric($value) && is_finite((float) $value);
    }

    private function size(string $name, string $argument, mixed $value, bool $numeric): bool
    {
        $size = $numeric ? (self::numeric($value) ? self::number($value) : null)
            : (is_array($value) && !$this->legacy ? count($value)
                : (is_string($value) && preg_match('//u', $value) === 1 ? preg_match_all('/./us', $value) : null));
        if ($size === null) {
            return false;
        }
        $bounds = array_map(self::number(...), explode(',', $argument));
        return match ($name) {
            'min' => $size >= $bounds[0],
            'max' => $size <= $bounds[0],
            'length' => $size == $bounds[0],
            'between' => $size >= $bounds[0] && $size <= $bounds[1],
        };
    }

    private static function number(string|int|float $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer === false ? (float) $value : $integer;
    }

    private static function oneOf(mixed $value, array $choices): bool
    {
        if (is_bool($value)) {
            return in_array($value ? 'true' : 'false', $choices, true);
        }
        if ($value === null) {
            return in_array('null', $choices, true);
        }
        return (is_string($value) || is_int($value) || is_float($value)) && in_array((string) $value, $choices, true);
    }

    private static function regex(string $pattern, string $value): bool
    {
        set_error_handler(static function (): never {
            throw new InvalidArgumentException('Invalid validation regex configuration.');
        });
        try {
            $matched = preg_match($pattern, $value);
        } finally {
            restore_error_handler();
        }
        if ($matched === false) {
            throw new RuntimeException('Validation regex execution failed: ' . preg_last_error_msg());
        }
        return $matched === 1;
    }

    private static function json(string $value): bool
    {
        try {
            json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            return true;
        } catch (JsonException) {
            return false;
        }
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)?)?$/D', $value)) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\DateMalformedStringException) {
            return null;
        }
        $errors = DateTimeImmutable::getLastErrors();
        return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0) ? $date : null;
    }

    private static function formattedDate(string $value, string $format): bool
    {
        if (str_contains($value, "\0")) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format($format) === $value;
    }

    private function dateComparison(string $name, string $value, string $argument, string $field): bool
    {
        if (str_starts_with($argument, '@')) {
            [, $argument] = $this->lookup($this->relative(substr($argument, 1), $field));
        }
        $date = self::date($value);
        $other = is_string($argument) ? self::date($argument) : null;
        return $date !== null && $other !== null && ($name === 'before' ? $date < $other : $date > $other);
    }

    private function file(mixed $value, string $name, ?string $argument): bool
    {
        if (!$value instanceof UploadedFile) {
            return false;
        }
        try {
            $info = $value->validate(
                $name === 'mimes' ? explode(',', $argument) : UploadedFile::extensions(),
                $name === 'max_size' ? max(1, (int) $argument) : PHP_INT_MAX,
            );
        } catch (HttpException $error) {
            if (!in_array($error->status, [413, 422], true)) {
                throw $error;
            }
            return false;
        }
        return $name === 'mimetypes' ? in_array($info['mime'], explode(',', $argument), true)
            : ($name !== 'max_size' || $info['size'] <= (int) $argument);
    }

    private function message(string $field, string $pattern, string $rule, ?string $argument, ?string $custom, bool $numeric, mixed $value): string
    {
        $attribute = $this->attributes[$field] ?? $this->attributes[$pattern] ?? str_replace('_', ' ', $field);
        if ($this->legacy) {
            return match ($rule) {
                'required' => 'This field is required.',
                'string' => 'Enter text.',
                'email' => 'Enter a valid email address.',
                'integer' => 'Enter an integer.',
                'min' => "Use at least $argument characters.",
                'max' => "Use no more than $argument characters.",
                'confirmed' => 'The confirmation does not match.',
            };
        }
        $unit = $numeric ? '' : (is_array($value) ? ' items' : ' characters');
        $default = $custom ?? match ($rule) {
            'required', 'required_if', 'required_unless' => 'The :attribute field is required.',
            'present' => 'The :attribute field must be present.',
            'min' => "The :attribute field must be at least :min$unit.",
            'max' => "The :attribute field must be at most :max$unit.",
            'length' => "The :attribute field must have length :length.",
            'between' => "The :attribute field must be between :min and :max$unit.",
            'same', 'confirmed' => 'The :attribute field does not match its confirmation or comparison field.',
            'different' => 'The :attribute field must differ from its comparison field.',
            'before', 'after' => "The :attribute field must be $rule the comparison date.",
            'in', 'not_in' => 'The :attribute field has an invalid selection.',
            'regex', 'alpha', 'alpha_num', 'date_format' => 'The :attribute field has an invalid format.',
            'file', 'mimes', 'mimetypes', 'max_size' => 'The :attribute field must be a valid upload matching the allowed type and size.',
            default => "The :attribute field must be a valid $rule value.",
        };
        $text = $this->messages[$field . '.' . $rule] ?? $this->messages[$pattern . '.' . $rule]
            ?? $this->messages[$rule] ?? $default;
        $parts = explode(',', $argument ?? '');
        return strtr($text, [':attribute' => $attribute, ':field' => $field, ':rule' => $rule,
            ':min' => $parts[0], ':max' => $parts[1] ?? $parts[0], ':length' => $parts[0]]);
    }
}
