<?php
declare(strict_types=1);

namespace PBreeze\Scheduler;

use Closure;
use DateTimeZone;
use InvalidArgumentException;
use ReflectionFunction;

final class ScheduledTask
{
    private ?ScheduleExpression $expression = null;
    private string $timezone;
    private bool $timezoneExplicit = false;
    private bool $withoutOverlapping = false;
    private bool $onOneServer = true;
    private bool $skipMissed = true;
    private int $missedLimit = 1;
    private int $retries = 0;
    private bool $retrySafe = false;
    /** @var list<int> */
    private array $backoff = [60];
    private int $timeout = 300;
    private string $queue = 'default';
    private string $delivery = 'direct';
    private ?Closure $callback = null;
    /** @var list<string>|null */
    private ?array $command = null;
    private ?string $version = null;

    public function __construct(public readonly string $key, string $defaultTimezone = 'UTC')
    {
        self::validateKey($key);
        $this->timezone = self::validateTimezone($defaultTimezone);
    }

    public function everySecond(): self { return $this->interval(1); }
    public function everySeconds(int $seconds): self { return $this->interval($seconds); }
    public function everyMinute(): self { return $this->interval(60); }
    public function everyMinutes(int $minutes): self { return $this->interval(self::scaledInterval($minutes, 60)); }
    public function hourly(): self { return $this->calendar(CalendarExpression::hourly()); }
    public function everyHours(int $hours): self { return $this->interval(self::scaledInterval($hours, 3600)); }
    public function daily(): self { return $this->calendar(CalendarExpression::daily()); }
    public function dailyAt(string $time): self { return $this->calendar(CalendarExpression::daily($time)); }
    public function weekly(): self { return $this->calendar(CalendarExpression::weekly()); }
    public function weeklyOn(int $weekday, string $time = '00:00'): self
    {
        return $this->calendar(CalendarExpression::weekly($weekday, $time));
    }
    public function monthly(int $day = 1, string $time = '00:00'): self
    {
        return $this->calendar(CalendarExpression::monthly($day, $time));
    }

    public function using(ScheduleExpression $expression): self
    {
        return $this->setExpression($expression);
    }

    public function timezone(string $timezone): self
    {
        $this->timezone = self::validateTimezone($timezone);
        $this->timezoneExplicit = true;
        return $this;
    }

    public function withoutOverlapping(bool $enabled = true): self
    {
        $this->withoutOverlapping = $enabled;
        return $this;
    }

    public function onOneServer(bool $enabled = true): self
    {
        $this->onOneServer = $enabled;
        return $this;
    }

    public function runMissed(int $limit = 1): self
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('A missed-run limit must be between 1 and 100.');
        }
        $this->missedLimit = $limit;
        $this->skipMissed = false;
        return $this;
    }

    public function skipMissed(): self
    {
        $this->skipMissed = true;
        return $this;
    }

    public function retries(int $retries = 3): self
    {
        if ($retries < 0 || $retries > 99) {
            throw new InvalidArgumentException('Retries must be between 0 and 99.');
        }
        $this->retries = $retries;
        return $this;
    }

    public function retrySafe(bool $safe = true): self
    {
        $this->retrySafe = $safe;
        return $this;
    }

    /** @param int|list<int> $seconds */
    public function retryAfter(int|array $seconds): self
    {
        $values = is_int($seconds) ? [$seconds] : $seconds;
        if (!array_is_list($values) || $values === [] || count($values) > 100) {
            throw new InvalidArgumentException('Retry backoff must be an integer or a nonempty list of at most 100 integers.');
        }
        foreach ($values as $value) {
            if (!is_int($value) || $value < 0 || $value > 604800) {
                throw new InvalidArgumentException('Each retry delay must be between 0 and 604800 seconds.');
            }
        }
        $this->backoff = $values;
        return $this;
    }

    public function timeout(int $seconds): self
    {
        if ($seconds < 1 || $seconds > 86400) {
            throw new InvalidArgumentException('A scheduled task timeout must be between 1 and 86400 seconds.');
        }
        $this->timeout = $seconds;
        return $this;
    }

    public function queue(string $queue): self
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $queue)) {
            throw new InvalidArgumentException('A queue name must contain 1 to 80 safe characters.');
        }
        $this->queue = $queue;
        $this->delivery = 'queued';
        return $this;
    }

    public function direct(): self
    {
        $this->delivery = 'direct';
        return $this;
    }

    public function queued(?string $queue = null): self
    {
        if ($queue !== null) {
            return $this->queue($queue);
        }
        $this->delivery = 'queued';
        return $this;
    }

    public function version(string $version): self
    {
        if ($version === '' || strlen($version) > 128 || preg_match('/[\x00-\x1f\x7f]/', $version)) {
            throw new InvalidArgumentException('A task version must contain 1 to 128 printable bytes.');
        }
        $this->version = $version;
        return $this;
    }

    public function run(callable $callback): self
    {
        if ($this->callback !== null || $this->command !== null) {
            throw new InvalidArgumentException('A scheduled task can have only one action.');
        }
        $this->callback = Closure::fromCallable($callback);
        return $this;
    }

    /** @param non-empty-list<string> $arguments */
    public function command(array $arguments): self
    {
        if ($this->callback !== null || $this->command !== null || !array_is_list($arguments) || $arguments === []) {
            throw new InvalidArgumentException('A scheduled command must be a nonempty argument list and the task must not already have an action.');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || $argument === '' || strlen($argument) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $argument)) {
                throw new InvalidArgumentException('Scheduled command arguments must be nonempty strings without control characters.');
            }
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9:_-]{0,100}$/D', $arguments[0])) {
            throw new InvalidArgumentException('A scheduled command must begin with a CLI command name.');
        }
        $this->command = $arguments;
        return $this;
    }

    public function hasAction(): bool
    {
        return $this->callback !== null || $this->command !== null;
    }

    public function expression(): ScheduleExpression
    {
        if ($this->expression === null) {
            throw new InvalidArgumentException('A scheduled task must define one frequency.');
        }
        return $this->expression;
    }

    public function timezoneName(): string { return $this->timezone; }
    public function hasExplicitTimezone(): bool { return $this->timezoneExplicit; }
    public function preventsOverlap(): bool { return $this->withoutOverlapping; }
    public function clusterUnique(): bool { return $this->onOneServer; }
    public function skipsMissed(): bool { return $this->skipMissed; }
    public function missedLimit(): int { return $this->missedLimit; }
    public function tries(): int { return $this->retries + 1; }
    public function isRetrySafe(): bool { return $this->retrySafe; }
    /** @return list<int> */
    public function backoff(): array { return $this->backoff; }
    public function timeoutSeconds(): int { return $this->timeout; }
    public function queueName(): string { return $this->queue; }
    public function delivery(): string { return $this->delivery; }
    public function callback(): ?Closure { return $this->callback; }
    /** @return list<string>|null */
    public function commandArguments(): ?array { return $this->command; }

    /** @return array<string, mixed> */
    public function description(string $effectiveTimezone): array
    {
        if ($this->callback === null) {
            $action = ['type' => 'command', 'argv' => $this->command, 'version' => $this->version];
        } else {
            $identity = self::callbackIdentity($this->callback);
            if ($identity['source'] === null && $this->version === null) {
                throw new InvalidArgumentException('Callbacks without readable source must declare a task version.');
            }
            $action = ['type' => 'callback', 'identity' => $identity, 'version' => $this->version];
        }
        $expression = $this->expression();
        return [
            'key' => $this->key,
            'expression' => ['class' => $expression::class, 'fingerprint' => $expression->fingerprint()],
            'timezone' => $effectiveTimezone,
            'action' => $action,
            'without_overlapping' => $this->withoutOverlapping,
            'on_one_server' => $this->onOneServer,
            'skip_missed' => $this->skipMissed,
            'missed_limit' => $this->missedLimit,
            'tries' => $this->tries(),
            'retry_safe' => $this->retrySafe,
            'backoff' => $this->backoff,
            'timeout' => $this->timeout,
            'delivery' => $this->delivery,
            'queue' => $this->queue,
        ];
    }

    public function definitionHash(string $effectiveTimezone): string
    {
        return hash('sha256', json_encode($this->description($effectiveTimezone), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function interval(int $seconds): self
    {
        return $this->setExpression(new IntervalExpression($seconds));
    }

    private function calendar(CalendarExpression $expression): self
    {
        return $this->setExpression($expression);
    }

    private function setExpression(ScheduleExpression $expression): self
    {
        if ($this->expression !== null) {
            throw new InvalidArgumentException('A scheduled task can have only one frequency.');
        }
        $this->expression = $expression;
        return $this;
    }

    private static function scaledInterval(int $value, int $scale): int
    {
        if ($value < 1 || $value > intdiv(31536000, $scale)) {
            throw new InvalidArgumentException('A schedule interval must be between 1 and 31536000 seconds.');
        }
        return $value * $scale;
    }

    private static function validateKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,189}$/D', $key)) {
            throw new InvalidArgumentException('A scheduled task key must contain 1 to 190 safe characters.');
        }
    }

    private static function validateTimezone(string $timezone): string
    {
        if ($timezone === '' || strlen($timezone) > 128) {
            throw new InvalidArgumentException('A schedule timezone must be a valid timezone name.');
        }
        try {
            return (new DateTimeZone($timezone))->getName();
        } catch (\Throwable $error) {
            throw new InvalidArgumentException('A schedule timezone must be a valid timezone name.', 0, $error);
        }
    }

    /** @return array<string, int|string|null> */
    private static function callbackIdentity(Closure $callback): array
    {
        $reflection = new ReflectionFunction($callback);
        $file = $reflection->getFileName();
        $sourceHash = null;
        if (is_string($file) && is_file($file)) {
            $lines = file($file);
            if ($lines !== false) {
                $source = implode('', array_slice($lines, $reflection->getStartLine() - 1,
                    $reflection->getEndLine() - $reflection->getStartLine() + 1));
                $sourceHash = hash('sha256', $source);
            }
        }
        return [
            'name' => $reflection->getName(),
            'scope' => $reflection->getClosureScopeClass()?->getName(),
            'source' => is_string($sourceHash) ? $sourceHash : null,
            'start' => $reflection->getStartLine(),
            'end' => $reflection->getEndLine(),
        ];
    }
}
