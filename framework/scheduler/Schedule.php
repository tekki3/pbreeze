<?php
declare(strict_types=1);

namespace PBreeze\Scheduler;

use InvalidArgumentException;

final class Schedule
{
    /** @var array<string, ScheduledTask> */
    private array $tasks = [];

    public function __construct(public readonly string $defaultTimezone = 'UTC')
    {
        try {
            new \DateTimeZone($defaultTimezone);
        } catch (\Throwable $error) {
            throw new InvalidArgumentException('The default schedule timezone must be valid.', 0, $error);
        }
    }

    public function task(string $key, ?callable $callback = null): ScheduledTask
    {
        if (isset($this->tasks[$key])) {
            throw new InvalidArgumentException('The scheduled task key is already registered.');
        }
        $task = new ScheduledTask($key, $this->defaultTimezone);
        $this->tasks[$key] = $task;
        if ($callback !== null) {
            $task->run($callback);
        }
        return $task;
    }

    /** @return list<ScheduledTask> */
    public function tasks(): array
    {
        return array_values($this->tasks);
    }

    /** @param list<string> $arguments */
    public function command(string $command, array $arguments = [], ?string $name = null): ScheduledTask
    {
        return $this->task($name ?? 'command:' . $command)->command([$command, ...$arguments]);
    }

    public function find(string $key): ?ScheduledTask
    {
        return $this->tasks[$key] ?? null;
    }
}
