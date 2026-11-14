<?php
declare(strict_types=1);

namespace PBreeze\Scheduler;

use InvalidArgumentException;
use PBreeze\Application;
use RuntimeException;

final class SchedulerConsole
{
    public function __construct(private readonly Application $app)
    {
    }

    /** @param list<string> $arguments */
    public function run(string $command, array $arguments): int
    {
        if ($command === '_background:execute') {
            if (count($arguments) !== 1 || preg_match('/^[a-f0-9]{32}$/D', $arguments[0]) !== 1) {
                throw new InvalidArgumentException('Invalid background execution identifier.');
            }
            $token = stream_get_contents(STDIN, 67);
            if (!is_string($token) || preg_match('/^[a-f0-9]{64}(?:\r?\n)?$/D', $token) !== 1) {
                throw new InvalidArgumentException('A private execution credential is required on standard input.');
            }
            return $this->app->worker->executeChild($arguments[0], rtrim($token, "\r\n"));
        }
        [$positionals, $options] = $this->arguments($arguments);
        $allowed = match ($command) {
            'scheduler:work' => ['sleep', 'max-jobs', 'max-time', 'once'],
            'queue:work' => ['sleep', 'queue', 'tries', 'max-jobs', 'max-time', 'once'],
            'queue:retry-all', 'queue:retry' => ['force'],
            'queue:resolve' => ['force'],
            'queue:prune' => ['days', 'limit', 'force'],
            'queue:failed' => ['limit'],
            default => [],
        };
        foreach (array_keys($options) as $option) {
            if (!in_array($option, $allowed, true)) {
                throw new InvalidArgumentException("Unknown option for $command: --$option");
            }
        }
        $count = match ($command) {
            'queue:retry', 'workers:stop' => 1,
            'queue:resolve' => 2,
            default => 0,
        };
        if (count($positionals) !== $count) {
            throw new InvalidArgumentException("Invalid arguments for $command. Use php breeze help.");
        }
        switch ($command) {
            case 'scheduler:install':
                $this->processSupport();
                $this->app->background();
                echo "Native scheduling uses no cron, Task Scheduler, Composer, or external queue server.\n";
                echo "1. Configure the application database and review routes/schedule.php and routes/jobs.php.\n";
                echo "2. Run php breeze migrate (production: back up first, then add --force).\n";
                echo "3. Run php breeze scheduler:work and, for queued work, php breeze queue:work.\n";
                echo "No files or schema were changed. Use a process supervisor for restart after reboot/crash.\n";
                return 0;
            case 'scheduler:list':
                $this->app->background();
                $this->rows($this->app->scheduler->listing());
                return 0;
            case 'scheduler:status':
                $this->app->background();
                $this->rows($this->app->scheduler->listing());
                $this->rows($this->app->queue->store()->status());
                return 0;
            case 'scheduler:run':
                $this->processSupport();
                $this->app->background();
                $ids = $this->app->scheduler->run();
                $processed = 0;
                while ($processed < 1000 && $this->app->worker->runOnce('scheduler')) {
                    $processed++;
                }
                echo count($ids) . " occurrence(s) materialized; $processed direct execution(s) processed.\n";
                if ($processed === 1000) {
                    throw new RuntimeException('One-shot safety limit reached; remaining work is durable. Run again or use scheduler:work.');
                }
                return 0;
            case 'scheduler:work':
            case 'queue:work':
                $this->processSupport();
                $kind = $command === 'scheduler:work' ? 'scheduler' : 'queue';
                $config = $this->app->config[$kind === 'scheduler' ? 'scheduler' : 'queue'] ?? [];
                $sleep = $this->number($options, 'sleep', $config['poll_interval'] ?? 1, 1, 60);
                $maxJobs = $this->number($options, 'max-jobs', 0, 0, 1000000);
                $maxTime = $this->number($options, 'max-time', 0, 0, 604800);
                $tries = isset($options['tries']) ? $this->number($options, 'tries', 3, 1, 100) : null;
                $defaultQueue = $kind === 'scheduler' ? 'default' : ($this->app->config['queue']['default_queue'] ?? 'default');
                $queues = explode(',', $options['queue'] ?? $defaultQueue);
                foreach ($queues as $queue) {
                    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,79}$/D', $queue) !== 1) {
                        throw new InvalidArgumentException('Queue names require 1..80 ASCII letters, digits, underscores, or hyphens.');
                    }
                }
                $this->app->background();
                if (isset($options['once'])) {
                    if ($kind === 'scheduler') {
                        $this->app->scheduler->run();
                    }
                    $worked = $this->app->worker->runOnce($kind, $queues, tries: $tries);
                    echo $worked ? "One execution processed.\n" : "No eligible work.\n";
                    return 0;
                }
                echo "PBreeze $kind worker starting. Request a graceful stop with php breeze workers:stop ID.\n";
                return $this->app->worker->run($kind, $queues, (float) $sleep, $maxJobs, $maxTime, $tries);
            case 'queue:status':
                $this->rows($this->app->queue->store()->status());
                return 0;
            case 'queue:failed':
                $rows = $this->app->queue->store()->failed($this->number($options, 'limit', 50, 1, 1000));
                $this->rows(array_map(static fn (array $row): array => array_intersect_key($row, array_flip([
                    'id', 'job_type', 'queue_name', 'status', 'attempts', 'max_tries', 'failure_code', 'updated_at',
                ])), $rows));
                return 0;
            case 'queue:retry':
                $id = $this->id($positionals[0]);
                $this->production($options);
                $this->app->queue->store()->retry($id);
                echo "Job requeued.\n";
                return 0;
            case 'queue:retry-all':
                $this->production($options);
                $count = 0;
                foreach ($this->app->queue->store()->failed(100) as $row) {
                    if ($row['status'] === 'failed') {
                        $this->app->queue->store()->retry($row['id']);
                        $count++;
                    }
                }
                echo "$count failed job(s) requeued (maximum 100 per call; uncertain work excluded).\n";
                return 0;
            case 'queue:resolve':
                if (!isset($options['force']) || !in_array($positionals[1], ['retry', 'complete'], true)) {
                    throw new InvalidArgumentException('Usage: queue:resolve ID retry|complete --force. First fence the old execution and verify external effects.');
                }
                $this->app->queue->store()->resolve($this->id($positionals[0]), $positionals[1] === 'complete' ? 'completed' : 'retry');
                echo "Uncertain execution resolved by explicit operator decision.\n";
                return 0;
            case 'queue:prune':
                $this->production($options);
                $days = $this->number($options, 'days', 30, 1, 3650);
                $limit = $this->number($options, 'limit', 100, 1, 1000);
                $count = $this->app->queue->store()->prune(time() - $days * 86400, $limit);
                echo "$count terminal job(s) pruned.\n";
                return 0;
            case 'workers:list':
                $this->rows($this->app->worker->registry()->listing());
                return 0;
            case 'workers:stop':
                $this->app->worker->registry()->stop($this->id($positionals[0]));
                echo "Graceful stop requested; running work is not forcibly terminated.\n";
                return 0;
        }
        throw new InvalidArgumentException("Unknown command: $command. Use php breeze help.");
    }

    /** @param list<string> $arguments
     *  @return array{list<string>, array<string, string>}
     */
    private function arguments(array $arguments): array
    {
        $positionals = [];
        $options = [];
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--')) {
                if (str_starts_with($argument, '-')) {
                    throw new InvalidArgumentException("Unknown option: $argument");
                }
                $positionals[] = $argument;
                continue;
            }
            [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);
            if ($key === '' || isset($options[$key]) || ($value === null && !in_array($key, ['force', 'once'], true))
                || ($value !== null && in_array($key, ['force', 'once'], true))) {
                throw new InvalidArgumentException("Invalid or duplicate option: $argument. Use --name=value.");
            }
            $options[$key] = $value ?? '1';
        }
        return [$positionals, $options];
    }

    /** @param array<string, string> $options */
    private function number(array $options, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = filter_var($options[$key] ?? $default, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);
        if ($value === false) {
            throw new InvalidArgumentException("--$key must be an integer from $minimum through $maximum.");
        }
        return $value;
    }

    private function id(string $id): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Use a 32-character hexadecimal identifier.');
        }
        return $id;
    }

    private function processSupport(): void
    {
        foreach (['proc_open', 'proc_get_status', 'proc_close', 'stream_set_blocking'] as $function) {
            if (!is_callable($function)) {
                throw new RuntimeException('Background workers require enabled native PHP process and stream functions.');
            }
        }
    }

    /** @param array<string, string> $options */
    private function production(array $options): void
    {
        if ($this->app->config['environment'] === 'production' && !isset($options['force'])) {
            throw new RuntimeException('Production is protected. Review affected work before explicitly adding --force.');
        }
    }

    private function rows(array $rows): void
    {
        if ($rows === []) {
            echo "No records.\n";
            return;
        }
        foreach ($rows as $key => $row) {
            $text = json_encode(is_array($row) ? $row : [$key => $row], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            echo $text . "\n";
        }
    }
}
