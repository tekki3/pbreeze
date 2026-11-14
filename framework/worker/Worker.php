<?php
declare(strict_types=1);

namespace PBreeze\Worker;

use PBreeze\Application;
use PBreeze\Clock;
use PBreeze\Logger;
use PBreeze\SystemClock;
use PBreeze\Queue\ExecutionContext;
use PBreeze\Queue\JobCancelled;
use PBreeze\Queue\JobFailure;
use PBreeze\Queue\InvalidJob;
use PBreeze\Queue\Queue;
use PBreeze\Queue\RetryableJobFailure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__) . '/queue/Queue.php';
require_once __DIR__ . '/WorkerRegistry.php';
require_once __DIR__ . '/LifecycleEvents.php';

final class Worker
{
    private readonly Clock $clock;
    private readonly WorkerRegistry $workers;
    public readonly LifecycleEvents $events;
    private ?string $workerId = null;
    private ?int $tries = null;
    private bool $stopping = false;
    private bool $continuousScheduler = false;
    private float $nextHeartbeat = 0;
    private bool $workerActive = true;
    private readonly float $heartbeatMilliseconds;

    public function __construct(private readonly Application $app, private readonly Queue $queue,
        private readonly array $config = [], ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->workers = new WorkerRegistry($queue->store(), (int) ($config['stale_after'] ?? 30));
        $this->events = new LifecycleEvents();
        $heartbeat = $config['heartbeat_interval'] ?? 5;
        if (!is_numeric($heartbeat) || (float) $heartbeat < 0.1 || (float) $heartbeat > 3600) {
            throw new InvalidArgumentException('Heartbeat interval must be between 0.1 and 3600 seconds.');
        }
        $this->heartbeatMilliseconds = (float) $heartbeat * 1000;
    }

    public function registry(): WorkerRegistry { return $this->workers; }

    public function run(string $kind = 'queue', array $queues = ['default'], ?float $sleep = null,
        int $maxJobs = 0, int $maxTime = 0, ?int $tries = null): int
    {
        $sleep ??= (float) ($this->config['poll_interval'] ?? 1);
        if (!is_finite($sleep) || $sleep <= 0 || $sleep > 3600 || $maxJobs < 0 || $maxTime < 0
            || ($tries !== null && ($tries < 1 || $tries > 100))) {
            throw new InvalidArgumentException('Invalid worker limits.');
        }
        $this->selection($kind, $queues);
        $this->tries = $tries;
        $this->stopping = false;
        $this->continuousScheduler = $kind === 'scheduler';
        $this->workerId = $this->workers->register($kind, $queues);
        $this->nextHeartbeat = 0;
        $this->workerActive = true;
        $signals = [];
        if (function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler')) {
            foreach ([SIGTERM, SIGINT] as $signal) {
                $signals[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, function (): void { $this->stopping = true; });
            }
        }
        $start = $this->clock->monotonic();
        $processed = 0;
        try {
            $this->event('worker.started', ['id' => $this->workerId, 'kind' => $kind]);
            while (!$this->stopping && ($maxJobs === 0 || $processed < $maxJobs)
                && ($maxTime === 0 || $this->clock->monotonic() - $start < $maxTime * 1000)) {
                $this->signals();
                if (!$this->heartbeat()) {
                    break;
                }
                if ($kind === 'scheduler') {
                    $this->app->background();
                    $this->app->scheduler->run();
                }
                if ($this->runOnce($kind, $queues)) {
                    $processed++;
                } else {
                    $until = $this->clock->monotonic() + $sleep * 1000;
                    do {
                        $this->signals();
                        if ($this->stopping || !$this->heartbeat()) {
                            $this->stopping = true;
                            break;
                        }
                        usleep((int) min(250000, max(0, ($until - $this->clock->monotonic()) * 1000)));
                    } while ($this->clock->monotonic() < $until);
                }
            }
            return 0;
        } finally {
            $id = $this->workerId;
            $this->workerId = null;
            $this->tries = null;
            $this->continuousScheduler = false;
            foreach ($signals as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            $this->workers->stopped($id);
            $this->event('worker.stopped', ['id' => $id]);
        }
    }

    private function selection(string $kind, array $queues): void
    {
        if (!in_array($kind, ['queue', 'scheduler'], true) || $queues === [] || count($queues) > 100) {
            throw new InvalidArgumentException('Invalid worker kind or queue selection.');
        }
        foreach ($queues as $queue) {
            \PBreeze\Queue\JobRegistry::queueName($queue);
        }
    }

    private function signals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

    }

    private function heartbeat(): bool
    {
        if ($this->clock->monotonic() >= $this->nextHeartbeat) {
            $this->workerActive = $this->workers->heartbeat($this->workerId);
            $this->nextHeartbeat = $this->clock->monotonic() + $this->heartbeatMilliseconds;
        }
        return $this->workerActive;
    }

    public function runOnce(string $kind = 'queue', array $queues = ['default'], ?string $onlyJobId = null, ?int $tries = null): bool
    {
        $this->selection($kind, $queues);
        if ($tries !== null && ($tries < 1 || $tries > 100)) {
            throw new InvalidArgumentException('Worker tries must be between 1 and 100.');
        }
        $standalone = $this->workerId === null;
        if ($standalone) {
            $this->stopping = false;
            $this->workerId = $this->workers->register($kind, $queues);
            $this->nextHeartbeat = 0;
            $this->workerActive = true;
            $this->event('worker.started', ['id' => $this->workerId, 'kind' => $kind]);
        }
        try {
            $row = $this->queue->store()->claim($this->workerId, $kind, $queues, $onlyJobId, $tries ?? $this->tries);
            if ($row === null) {
                return false;
            }
            $this->event('job.claimed', ['id' => $row['id'], 'attempt' => (int) $row['attempts']]);
            $this->supervise($row);
            $this->event('job.finished', ['id' => $row['id'], 'status' => $this->queue->store()->get($row['id'])['status'] ?? 'unknown']);
            return true;
        } finally {
            if ($standalone) {
                $id = $this->workerId;
                $this->workerId = null;
                $this->workers->stopped($id);
                $this->event('worker.stopped', ['id' => $id]);
            }
        }
    }

    private function supervise(array $row): void
    {
        $store = $this->queue->store();
        $process = null;
        $pipes = [];
        $launched = false;
        $discardedOutput = false;
        try {
            $entry = $this->app->root . '/breeze';
            if (!is_file($entry)) {
                throw new RuntimeException('The background CLI entry point does not exist.');
            }
            $process = proc_open([PHP_BINARY, $entry, '_background:execute', $row['id']],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
                $this->app->root, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start background child execution.');
            }
            $launched = true;
            $secret = $row['claim_token'] . "\n";
            if (fwrite($pipes[0], $secret) !== strlen($secret)) {
                throw new RuntimeException('Cannot authenticate background child execution.');
            }
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $started = $this->clock->monotonic();
            $scheduleInterval = max(0.1, (float) ($this->app->config['scheduler']['poll_interval'] ?? 1)) * 1000;
            $nextSchedule = $started + $scheduleInterval;
            $nextClaimHeartbeat = $started;
            $quarantined = false;
            do {
                $this->signals();
                $running = proc_get_status($process)['running'];
                foreach ($pipes as $pipe) {
                    for ($chunks = 0; $chunks < 8; $chunks++) {
                        $chunk = fread($pipe, 8192);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $discardedOutput = true;
                    }
                }
                $keepWorking = $this->heartbeat();
                if ($this->continuousScheduler && $this->clock->monotonic() >= $nextSchedule) {
                    try {
                        $this->app->scheduler->run();
                    } catch (Throwable $error) {
                        $this->report($error);
                        // Failed materialization backs off while supervision and heartbeats continue.
                        $nextSchedule = $this->clock->monotonic() + max(1000, $scheduleInterval * 2);
                    }
                    if ($nextSchedule <= $this->clock->monotonic()) {
                        $nextSchedule = $this->clock->monotonic() + $scheduleInterval;
                    }
                }
                if ($this->stopping || !$keepWorking) {
                    $this->stopping = true;
                }
                if (!$quarantined && $this->clock->monotonic() - $started >= (int) $row['timeout_seconds'] * 1000) {
                    $store->cancel($row['id'], $row['claim_token']);
                    $store->finish($row['id'], $row['claim_token'], 'uncertain');
                    $quarantined = true;
                }
                if (!$quarantined && $this->clock->monotonic() >= $nextClaimHeartbeat) {
                    $store->heartbeat($row['id'], $row['claim_token']);
                    $nextClaimHeartbeat = $this->clock->monotonic() + $this->heartbeatMilliseconds;
                }
                if ($running) {
                    usleep(100000);
                }
            } while ($running);
            // A child disappearing without a persisted result has an unknown outcome, regardless of exit code.
            $store->finish($row['id'], $row['claim_token'], 'uncertain');
        } catch (Throwable $error) {
            $store->finish($row['id'], $row['claim_token'], $launched ? 'uncertain' : 'retry');
            $this->report($error);
        } finally {
            if ($discardedOutput) {
                try {
                    $this->app->log->warning('Background child output was discarded; use the application logger for diagnostics.', ['job_id' => $row['id']]);
                } catch (Throwable $error) {
                    $this->report($error);
                }
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                // Never force-kill arbitrary user code and call it a safe retry.
                proc_close($process);
            }
        }
    }

    public function executeChild(string $id, #[\SensitiveParameter] ?string $claimToken = null): int
    {
        if ($claimToken === null) {
            $line = fgets(STDIN, 67);
            $claimToken = is_string($line) ? rtrim($line, "\r\n") : '';
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !preg_match('/^[a-f0-9]{64}$/D', $claimToken)) {
            return 2;
        }
        $store = $this->queue->store();
        $row = $store->start($id, $claimToken);
        if ($row === null) {
            return 2;
        }
        $this->event('job.started', ['id' => $id, 'attempt' => (int) $row['attempts']]);
        $started = $this->clock->monotonic();
        try {
            $payload = $this->queue->decodePayload($row['payload']);
            $context = new ExecutionContext($id, (int) $row['attempts'],
                fn (): bool => $store->cancelled($id, $claimToken),
                static function () use ($store, $id, $claimToken): void { $store->heartbeat($id, $claimToken); });
            $this->queue->registry()->execute($row['job_type'], $payload, $context);
            $context->throwIfCancelled();
            $saved = $store->finish($id, $claimToken, 'completed', durationMs: $this->clock->monotonic() - $started);
            $this->event('job.finished', ['id' => $id, 'status' => $saved ? 'completed' : 'fenced']);
            return $saved ? 0 : 2;
        } catch (RetryableJobFailure $error) {
            $store->finish($id, $claimToken, 'retry', $error, $this->clock->monotonic() - $started);
            $this->event('job.finished', ['id' => $id, 'status' => 'retry']);
            $this->report($error);
            return 1;
        } catch (JobFailure|InvalidJob $error) {
            $store->finish($id, $claimToken, 'failed', $error, $this->clock->monotonic() - $started);
            $this->event('job.finished', ['id' => $id, 'status' => 'failed']);
            $this->report($error);
            return 1;
        } catch (Throwable $error) {
            $outcome = $error instanceof JobCancelled ? 'cancelled' : ((bool) $row['retry_safe'] ? 'retry' : 'uncertain');
            $store->finish($id, $claimToken, $outcome, $error, $this->clock->monotonic() - $started);
            $this->event('job.finished', ['id' => $id, 'status' => $outcome]);
            $this->report($error);
            return 1;
        }
    }

    private function report(Throwable $error): void
    {
        Logger::report($error, $this->app->log);
    }

    private function event(string $name, array $metadata): void
    {
        try {
            $this->app->log->info('Background lifecycle: ' . $name . '.', $metadata);
        } catch (Throwable $error) {
            $this->report($error);
        }
        try {
            $this->events->emit($name, $metadata);
        } catch (Throwable $error) {
            $this->report($error);
        }
    }
}
