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
$queue = Env::get('QUEUE_DEFAULT', 'default');
if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $queue) !== 1) {
    throw new RuntimeException('QUEUE_DEFAULT must be a queue name of 1..80 ASCII letters, digits, underscores, or hyphens.');
}

$queueSettings = [
    'driver' => 'database',
    'default_queue' => $queue,
    'default_tries' => $integer('QUEUE_TRIES', 3, 1, 100),
    'poll_interval' => $integer('QUEUE_POLL_INTERVAL', 1, 1, 60),
    'heartbeat_interval' => $integer('WORKER_HEARTBEAT_INTERVAL', 15, 1, 60),
    'stale_after' => $integer('WORKER_STALE_AFTER', 90, 10, 3600),
    'timeout' => $integer('QUEUE_TIMEOUT', 300, 1, 86400),
    'priority_aging_seconds' => $integer('QUEUE_PRIORITY_AGING', 60, 1, 3600),
    'payload_max_bytes' => $integer('QUEUE_PAYLOAD_MAX_BYTES', 65536, 1, 65536),
    'retry_uncertain' => false,
];
if ($queueSettings['stale_after'] <= $queueSettings['heartbeat_interval'] * 2) {
    throw new RuntimeException('WORKER_STALE_AFTER must exceed twice WORKER_HEARTBEAT_INTERVAL.');
}
return $queueSettings;
