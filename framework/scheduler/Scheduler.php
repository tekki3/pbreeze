<?php
declare(strict_types=1);

namespace PBreeze\Scheduler;

use DateTimeZone;
use InvalidArgumentException;
use PBreeze\Application;
use PBreeze\Clock;
use PBreeze\Database;
use PBreeze\Process;
use PBreeze\SystemClock;
use PBreeze\Queue\ExecutionContext;
use PBreeze\Queue\JobFailure;
use PBreeze\Queue\Queue;
use RuntimeException;
use Throwable;

final class Scheduler
{
    private readonly Clock $clock;
    private readonly int $dueTolerance;
    private readonly int $catchUpLimit;
    private readonly ?string $configuredTimezone;
    private readonly SchedulerRepository $repository;

    /** @param array{timezone?: string, poll_interval?: int, due_tolerance?: int, catch_up_limit?: int} $config */
    public function __construct(
        private readonly Database $db,
        private readonly Queue $queue,
        private readonly Schedule $schedule,
        array $config = [],
        ?Clock $clock = null,
        private readonly ?Application $app = null,
    ) {
        $pollInterval = $config['poll_interval'] ?? 1;
        $dueTolerance = $config['due_tolerance'] ?? 5;
        $catchUpLimit = $config['catch_up_limit'] ?? 100;
        if (!is_int($pollInterval) || $pollInterval < 1 || $pollInterval > 86400) {
            throw new InvalidArgumentException('Scheduler poll_interval must be between 1 and 86400 seconds.');
        }
        if (!is_int($dueTolerance) || $dueTolerance < 0 || $dueTolerance > 60) {
            throw new InvalidArgumentException('Scheduler due_tolerance must be between 0 and 60 seconds.');
        }
        if (!is_int($catchUpLimit) || $catchUpLimit < 1 || $catchUpLimit > 100) {
            throw new InvalidArgumentException('Scheduler catch_up_limit must be between 1 and 100.');
        }
        $timezone = $config['timezone'] ?? null;
        if ($timezone !== null) {
            if (!is_string($timezone) || $timezone === '') {
                throw new InvalidArgumentException('Scheduler timezone must be a valid timezone name.');
            }
            try {
                $timezone = (new DateTimeZone($timezone))->getName();
            } catch (Throwable $error) {
                throw new InvalidArgumentException('Scheduler timezone must be a valid timezone name.', 0, $error);
            }
        }
        $this->configuredTimezone = $timezone;
        $this->dueTolerance = $dueTolerance;
        $this->catchUpLimit = $catchUpLimit;
        $this->clock = $clock ?? new SystemClock();
        $this->repository = new SchedulerRepository($db);
    }

    /** @return list<string> */
    public function run(): array
    {
        $now = $this->clock->now();
        $ids = [];
        foreach ($this->schedule->tasks() as $task) {
            if (!$task->hasAction()) {
                throw new InvalidArgumentException("Scheduled task '{$task->key}' has no callback or command.");
            }
            $timezoneName = $this->timezoneFor($task);
            $timezone = new DateTimeZone($timezoneName);
            $expression = $task->expression();
            $definitionHash = $task->definitionHash($timezoneName);
            array_push($ids, ...$this->runTask($task, $expression, $timezone, $definitionHash, $now));
        }
        return array_values(array_unique($ids));
    }

    /** @return list<array<string, mixed>> */
    public function listing(): array
    {
        $now = $this->clock->now();
        $entries = [];
        foreach ($this->schedule->tasks() as $task) {
            $timezoneName = $this->timezoneFor($task);
            $cursor = $this->repository->cursor($task->key);
            $entries[] = [
                'task_key' => $task->key,
                'definition_hash' => $task->definitionHash($timezoneName),
                'expression' => $task->expression()->fingerprint(),
                'timezone' => $timezoneName,
                'next_run_at' => is_array($cursor)
                    ? (int) $cursor['next_run_at']
                    : $this->initialCursor($task->expression(), new DateTimeZone($timezoneName), $now),
                'last_scheduled_at' => is_array($cursor) && $cursor['last_scheduled_at'] !== null
                    ? (int) $cursor['last_scheduled_at'] : null,
                'updated_at' => is_array($cursor) ? (int) $cursor['updated_at'] : null,
                'delivery' => $task->delivery(),
                'queue' => $task->queueName(),
                'without_overlapping' => $task->preventsOverlap(),
                'on_one_server' => $task->clusterUnique(),
                'retries' => $task->tries() - 1,
                'timeout' => $task->timeoutSeconds(),
            ];
        }
        return $entries;
    }

    public function executeTask(string $taskKey, string $definitionHash, ExecutionContext $context): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $definitionHash)) {
            throw new InvalidArgumentException('Invalid scheduled task definition hash.');
        }
        $task = $this->schedule->find($taskKey);
        if ($task === null || !$task->hasAction()) {
            throw new JobFailure('Scheduled task is not registered in the current application.');
        }
        $effectiveTimezone = $this->timezoneFor($task);
        if (!hash_equals($task->definitionHash($effectiveTimezone), $definitionHash)) {
            throw new JobFailure('Scheduled task definition changed; this queued occurrence cannot run.');
        }

        $context->throwIfCancelled();
        if ($task->callback() !== null) {
            ($task->callback())($context);
            $context->heartbeat();
            return;
        }
        $argv = $task->commandArguments();
        if ($argv === null) {
            throw new JobFailure('Scheduled task has no executable action.');
        }
        if ($this->app === null) {
            throw new JobFailure('Scheduled CLI commands require an Application instance.');
        }
        $status = Process::run([PHP_BINARY, $this->app->root . '/breeze', ...$argv], $this->app->root);
        $context->heartbeat();
        if ($status !== 0) {
            throw new RuntimeException('Scheduled CLI command failed with exit code ' . $status . '.');
        }
    }

    /** @return list<string> */
    private function runTask(
        ScheduledTask $task,
        ScheduleExpression $expression,
        DateTimeZone $timezone,
        string $definitionHash,
        int $now,
    ): array {
        return $this->repository->transaction(function () use ($task, $expression, $timezone, $definitionHash, $now): array {
            $initialCursor = $this->initialCursor($expression, $timezone, $now);
            $this->repository->initialize($task->key, $definitionHash, $initialCursor, $now);
            $row = $this->repository->cursor($task->key, true);
            if ($row === null) {
                throw new RuntimeException('Cannot initialize scheduler task state.');
            }
            $oldHash = (string) $row['definition_hash'];
            $oldCursor = (int) $row['next_run_at'];
            $cursor = $oldHash === $definitionHash ? $oldCursor : min($oldCursor, $initialCursor);
            $occurrences = [];
            $limit = $task->skipsMissed() || $task->preventsOverlap()
                ? 1 : min($task->missedLimit(), $this->catchUpLimit);
            $earliest = $task->skipsMissed() ? max($cursor, $now - $this->dueTolerance) : $cursor;
            $latest = $now;
            while (count($occurrences) < $limit) {
                $due = $this->latestDue($expression, $timezone, $earliest, $latest);
                if ($due === null) {
                    break;
                }
                $occurrences[] = $due;
                $latest = $due - 1;
            }
            sort($occurrences, SORT_NUMERIC);
            $nextCursor = $cursor <= $now ? $this->next($expression, $now, $timezone) : $cursor;
            if ($nextCursor === null) {
                throw new RuntimeException('The schedule expression did not provide a next occurrence.');
            }

            if ($occurrences !== [] && $task->preventsOverlap() && $this->repository->hasOutstandingTask($task->key)) {
                $occurrences = [];
            }
            $lastScheduled = $row['last_scheduled_at'] === null ? null : (int) $row['last_scheduled_at'];
            $oldLastScheduled = $lastScheduled;
            if ($occurrences !== []) {
                $lastScheduled = $occurrences[array_key_last($occurrences)];
            }
            $stateChanged = $oldHash !== $definitionHash || $oldCursor !== $nextCursor
                || $oldLastScheduled !== $lastScheduled || (int) $row['updated_at'] !== $now;
            if ($stateChanged && !$this->repository->compareAndSwap(
                $task->key,
                $oldHash,
                $oldCursor,
                $definitionHash,
                $nextCursor,
                $lastScheduled,
                $now,
            )) {
                throw new RuntimeException('Scheduler cursor changed during an occurrence claim.');
            }

            $ids = [];
            foreach ($occurrences as $scheduledAt) {
                $ids[] = $this->queue->enqueueScheduled(
                    $task->key,
                    $definitionHash,
                    $scheduledAt,
                    $task->delivery(),
                    $task->queueName(),
                    $task->tries(),
                    $task->backoff(),
                    $task->timeoutSeconds(),
                    $task->isRetrySafe(),
                    $task->preventsOverlap(),
                );
            }
            return $ids;
        });
    }

    private function initialCursor(ScheduleExpression $expression, DateTimeZone $timezone, int $now): int
    {
        $candidate = $this->latestDue($expression, $timezone, max(0, $now - $this->dueTolerance), $now);
        if ($candidate !== null) {
            return $candidate;
        }
        $next = $this->next($expression, $now, $timezone);
        if ($next === null) {
            throw new RuntimeException('The schedule expression did not provide a next occurrence.');
        }
        return $next;
    }

    private function latestDue(ScheduleExpression $expression, DateTimeZone $timezone, int $earliest, int $latest): ?int
    {
        if ($earliest > $latest) {
            return null;
        }
        $candidate = $this->next($expression, $earliest - 1, $timezone);
        if ($candidate === null || $candidate > $latest) {
            return null;
        }
        // Binary search avoids replaying every historical tick after prolonged downtime.
        $low = $earliest - 1;
        $high = $latest;
        while ($low < $high) {
            $middle = $low + intdiv($high - $low + 1, 2);
            $next = $this->next($expression, $middle, $timezone);
            if ($next !== null && $next <= $latest) {
                $candidate = $next;
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }
        return $candidate;
    }

    private function next(ScheduleExpression $expression, int $timestamp, DateTimeZone $timezone): ?int
    {
        $next = $expression->nextAfter($timestamp, $timezone);
        if ($next !== null && $next <= $timestamp) {
            throw new RuntimeException('The schedule expression must return an occurrence strictly after its input.');
        }
        return $next;
    }

    private function timezoneFor(ScheduledTask $task): string
    {
        return !$task->hasExplicitTimezone() && $this->configuredTimezone !== null
            ? $this->configuredTimezone
            : $task->timezoneName();
    }
}
