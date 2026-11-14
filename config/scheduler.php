<?php
declare(strict_types=1);

use PBreeze\Env;

$integer = static function (string $key, int $default, int $minimum, int $maximum): int {
    $value = filter_var(Env::get($key, (string) $default), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $minimum, 'max_range' => $maximum],
    ]);
    if ($value === false) {
        throw new RuntimeException("$key must be an integer from $minimum through $maximum.");
    }
    return $value;
};
$timezone = Env::get('SCHEDULER_TIMEZONE', 'UTC');
try {
    new DateTimeZone($timezone);
} catch (Exception $error) {
    throw new RuntimeException('SCHEDULER_TIMEZONE must be a valid time zone.', previous: $error);
}

return [
    'timezone' => $timezone,
    'poll_interval' => $integer('SCHEDULER_POLL_INTERVAL', 1, 1, 60),
    'due_tolerance' => $integer('SCHEDULER_DUE_TOLERANCE', 5, 0, 60),
    'catch_up_limit' => $integer('SCHEDULER_CATCH_UP_LIMIT', 100, 1, 100),
];
