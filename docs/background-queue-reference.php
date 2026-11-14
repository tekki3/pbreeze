<?php
declare(strict_types=1);

return [
    'PBreeze\\SystemClock' => [
        'description' => 'UTC Unix seconds and monotonic milliseconds for background execution.',
        'setup' => '$clock = new \\PBreeze\\SystemClock();',
        'methods' => [
            'now' => ['Return current UTC Unix seconds.', '$seconds = $clock->now();'],
            'monotonic' => ['Return monotonic milliseconds; use differences, not calendar timestamps.', '$started = $clock->monotonic();'],
        ],
    ],
    'PBreeze\\Queue\\JobFailure' => [
        'description' => 'A handler declares a known terminal failure; the worker records failed instead of uncertain.',
        'setup' => '', 'methods' => [],
    ],
    'PBreeze\\Queue\\RetryableJobFailure' => [
        'description' => 'A handler explicitly declares a retryable known failure. Attempts and backoff remain bounded.',
        'setup' => '', 'methods' => [],
    ],
    'PBreeze\\Queue\\JobCancelled' => [
        'description' => 'Cooperative cancellation signal. Cancellation does not prove that earlier side effects were undone.',
        'setup' => '', 'methods' => [],
    ],
    'PBreeze\\Queue\\InvalidJob' => [
        'description' => 'A persisted type or payload fails validation before handler invocation; the worker records a known failed job.',
        'setup' => '', 'methods' => [],
    ],
    'PBreeze\\Queue\\JobRegistry' => [
        'description' => 'Explicit trusted job validators and handlers. Persisted job types never instantiate arbitrary classes.',
        'setup' => '$registry = new \\PBreeze\\Queue\\JobRegistry();',
        'methods' => [
            '__construct' => ['Optionally load trusted registrations once, lazily before validation or execution.', '$registry = new \\PBreeze\\Queue\\JobRegistry(static function ($jobs): void { $jobs->register("ping", static fn (array $p): bool => $p === [], static function (array $p, $context): void {}); });'],
            'register' => ['Register a unique type, payload validator, and handler. A validator may throw or return false to reject.', '$registry->register("ping", static fn (array $p): bool => $p === [], static function (array $p, $context): void {});'],
            'name' => ['Validate a job type: 1-190 ASCII letters, digits, dots, underscores, colons, or hyphens; first character alphanumeric.', '\\PBreeze\\Queue\\JobRegistry::name("reports");'],
            'queueName' => ['Validate a queue name: 1-80 ASCII letters, digits, underscores, or hyphens; first character alphanumeric.', '\\PBreeze\\Queue\\JobRegistry::queueName("reports");'],
            'validate' => ['Load registrations and validate a payload without executing its handler.', '$registry->validate("ping", []);'],
            'execute' => ['Revalidate a payload, check cancellation, and invoke its registered handler. Validation failure raises InvalidJob before invocation.', '$registry->execute("ping", [], new \\PBreeze\\Queue\\ExecutionContext(str_repeat("a", 32)));'],
        ],
    ],
    'PBreeze\\Queue\\ExecutionContext' => [
        'description' => 'Handler context containing the stable jobId/idempotencyKey and attempt number. Cancellation requires handler cooperation.',
        'setup' => '$context = new \\PBreeze\\Queue\\ExecutionContext(str_repeat("a", 32));',
        'methods' => [
            '__construct' => ['Create a context; optional cancellation and heartbeat closures are supplied by the worker.', '$context = new \\PBreeze\\Queue\\ExecutionContext(str_repeat("a", 32), 1);'],
            'isCancelled' => ['Check whether the worker cancelled the claim or the token is no longer valid.', 'if ($context->isCancelled()) { return; }'],
            'throwIfCancelled' => ['Throw JobCancelled when cancellation or fencing is detected.', '$context->throwIfCancelled();'],
            'heartbeat' => ['Refresh the claim using its trusted callback, then check cancellation.', '$context->heartbeat();'],
            'checkpoint' => ['Alias for heartbeat: refresh the claim and check cooperative cancellation between units of work.', '$context->checkpoint();'],
            'idempotencyKey' => ['Return the same stable job identifier across retries; use it for application-level deduplication.', '$key = $context->idempotencyKey();'],
        ],
    ],
    'PBreeze\\Queue\\Queue' => [
        'description' => 'Validated native database queue. Construction is lazy. All persistence joins an existing caller transaction.',
        'setup' => '$queue = $app->queue;',
        'methods' => [
            '__construct' => ['Create a queue with a trusted registry, logger, optional Clock, and lease_seconds (or stale_after) configuration.', '$queue = new \\PBreeze\\Queue\\Queue($app->db, $app->log, $registry, ["stale_after" => 30]);'],
            'registry' => ['Return the trusted job registry.', '$registry = $queue->registry();'],
            'store' => ['Return database persistence and monitoring methods.', '$counts = $queue->store()->status();'],
            'exponentialBackoff' => ['Build 1-100 integer delays by doubling a nonnegative base up to a cap of at most 604800 seconds, without overflow.', '$delays = \\PBreeze\\Queue\\Queue::exponentialBackoff(30, 5, 300);'],
            'dispatch' => ['Persist a registered job with a JSON payload capped by payload_max_bytes (maximum 64 KiB). Omitted queue, tries, and timeout use default_queue, default_tries, and timeout configuration. Unique keys deduplicate active work of the same job type; uncertain claims retain locks. Priority is 0-3, tries 1-100, timeout 1-86400 seconds, and backoff entries 0-604800 seconds.', '$id = $queue->dispatch("ping", [], "default", priority: 1, tries: 3, backoff: [60, 300], timeout: 300, unique: "daily-ping");'],
            'enqueueScheduled' => ['Atomically deduplicate task key and scheduled timestamp. Delivery is direct (scheduler worker) or queued (queue worker). Keep occurrence tombstones after pruning.', '$id = $queue->enqueueScheduled("daily", hash("sha256", "v1"), time(), "queued", "default", 3, [60, 300], 300, false, true);'],
            'runNow' => ['Validate and execute a registered handler synchronously without queue persistence or automatic retries.', '$queue->runNow("ping", []);'],
            'decodePayload' => ['Validate persisted JSON size and decode an array before handler invocation; malformed or oversized data raises InvalidJob.', '$payload = $queue->decodePayload("{\\"action\\":\\"ping\\"}");'],
        ],
    ],
    'PBreeze\\Queue\\DatabaseQueueStore' => [
        'description' => 'Token-fenced database persistence. Expired claims are quarantined, not stolen. Internal claim helpers are for trusted runtime integration.',
        'setup' => '$store = $app->queue->store();',
        'methods' => [
            '__construct' => ['Create persistence with a database, lease duration of 5-3600 seconds, and priority aging step of 1-86400 seconds. The optional fourth Clock argument is only for deterministic tests; production coordination uses actual database time.', '$store = new \\PBreeze\\Queue\\DatabaseQueueStore($app->db, 30, 60);'],
            'now' => ['Read actual database UTC epoch time for distributed coordination.', '$now = $store->now();'],
            'transaction' => ['Run atomically, starting a transaction only when the caller has none. The caller must roll back a caller-owned transaction after failure.', '$counts = $store->transaction(static fn () => $store->status());'],
            'insertOnce' => ['Internal primary-key insert-if-absent for background_locks or scheduled_occurrences without aborting PostgreSQL transactions.', '$inserted = $store->insertOnce("background_locks", "lock_key", ["lock_key" => hash("sha256", "maintenance"), "owner_id" => str_repeat("a", 32), "created_at" => $store->now()]);'],
            'get' => ['Return an internal job row or null. Rows contain payloads and private tokens; never expose them publicly.', '$row = $store->get($id);'],
            'status' => ['Return job counts keyed by current status, optionally filtered to one queue.', '$counts = $store->status("default");'],
            'jobs' => ['List safe job summaries by status and optional queue, excluding payloads and private tokens; limit 1-1000.', '$jobs = $store->jobs("pending", 50, "default");'],
            'history' => ['List safe attempts newest first with outcome, duration, and exception class/file/line; no payloads, private tokens, or exception messages.', '$attempts = $store->history($jobId, 50);'],
            'failed' => ['List safe failed/uncertain summaries without payloads or claim tokens; limit 1-1000.', '$failures = $store->failed(50);'],
            'claim' => ['Reserve one eligible job with an unpredictable private token and priority aging. Optional tries only lowers the persisted retry ceiling.', '$claim = $store->claim($workerId, "queue", ["default"]);'],
            'start' => ['Token-fenced transition from unexpired reserved to running; return null when fenced.', '$job = $store->start($id, $privateToken);'],
            'heartbeat' => ['Refresh a reserved/running claim lease and return whether the claim remains active and uncancelled.', '$active = $store->heartbeat($id, $privateToken);'],
            'cancelled' => ['Check claim cancellation, unknown job, stale token, or inactive state.', '$cancelled = $store->cancelled($id, $privateToken);'],
            'cancel' => ['Token-fenced cooperative cancellation request; it does not terminate a process.', '$store->cancel($id, $privateToken);'],
            'finish' => ['Token-fenced completion: completed, failed, retry, uncertain, or cancelled. Uncertain/cancelled retain exclusion locks.', '$saved = $store->finish($id, $privateToken, "completed");'],
            'quarantineExpired' => ['Mark expired reserved/running claims uncertain and keep locks. Never blindly replay expired work.', '$count = $store->quarantineExpired();'],
            'retry' => ['Explicitly requeue a known failed job for one additional attempt. Uncertain jobs require resolve instead.', '$store->retry($failedJobId);'],
            'resolve' => ['Resolve uncertain work as completed, failed, or retry only after externally verifying execution stopped. Clears the claim token to fence late completion.', '$store->resolve($uncertainJobId, "completed");'],
            'prune' => ['Delete at most 1-1000 old completed/failed jobs and their attempts. Keep uncertain work and occurrence tombstones.', '$deleted = $store->prune(time() - 2592000, 100);'],
        ],
    ],
    'PBreeze\\Worker\\LifecycleEvents' => [
        'description' => 'Small local lifecycle listener registry. Worker events contain identifiers and status metadata, not job payloads.',
        'setup' => '$events = $app->worker->events;',
        'methods' => [
            'on' => ['Listen for worker.started, worker.stopped, job.claimed, job.started, or job.finished.', '$events->on("job.finished", static function (array $metadata): void {});'],
            'emit' => ['Invoke every listener synchronously, then rethrow the first listener error. The Worker catches and reports it without changing job outcome or blocking subsequent listeners.', '$events->emit("worker.started", ["id" => $workerId, "kind" => "queue"]);'],
        ],
    ],
    'PBreeze\\Worker\\WorkerRegistry' => [
        'description' => 'Database worker heartbeats and cooperative stop requests. Stale rows are monitoring evidence, not proof a process is dead.',
        'setup' => '$workers = $app->worker->registry();',
        'methods' => [
            '__construct' => ['Create a registry using queue persistence and its database clock.', '$workers = new \\PBreeze\\Worker\\WorkerRegistry($app->queue->store());'],
            'register' => ['Persist a worker identity with process ID, kind, and queue selection.', '$workerId = $workers->register("queue", ["default"]);'],
            'listing' => ['List persisted workers ordered newest first, default limit 100 (1..1000), including hostname, heartbeat, stopped_at, and computed suspected_offline. Suspected offline is not proof of process death.', '$rows = $workers->listing();'],
            'heartbeat' => ['Update the worker heartbeat; return false when stopped or a stop was requested.', '$continue = $workers->heartbeat($workerId);'],
            'stop' => ['Request a cooperative stop for an existing worker; does not kill its process.', '$workers->stop($workerId);'],
            'stopped' => ['Record normal worker shutdown and its final database heartbeat.', '$workers->stopped($workerId);'],
        ],
    ],
    'PBreeze\\Worker\\Worker' => [
        'description' => 'Supervised shell-free PHP children, private stdin claim tokens, configurable heartbeats, safe lifecycle logging, and unknown-outcome quarantine. Caught handler errors retry only when explicitly retry-safe or RetryableJobFailure; ambiguous crashes remain uncertain. Arbitrary code is never force-killed and declared safely retryable.',
        'setup' => '$worker = $app->worker;',
        'methods' => [
            '__construct' => ['Use the Application root/breeze CLI, shared Queue, optional configuration, and Clock. Construction performs no database access.', '$worker = new \\PBreeze\\Worker\\Worker($app, $app->queue);'],
            'registry' => ['Return worker monitoring and cooperative stop controls.', '$workers = $worker->registry();'],
            'run' => ['Run queue or scheduler polling until stopped or limits are reached. Omitted sleep uses poll_interval. Scheduler polling materializes due work first and during direct child supervision. maxJobs/maxTime zero mean unlimited; limits do not terminate an active uncooperative child.', '$exit = $worker->run("queue", ["default"], sleep: 1, maxJobs: 100, maxTime: 3600, tries: 3);'],
            'runOnce' => ['Claim and supervise at most one eligible job. Return false when no work is available. Timeout requests cancellation and quarantines, but waits for an uncooperative child to exit.', '$worked = $worker->runOnce("queue", ["default"]);'],
            'executeChild' => ['Hidden CLI execution: validate ID/token and run only a currently reserved claim. Read a bounded token line from stdin when omitted; never send it as a process argument.', '$exit = $worker->executeChild($jobId);'],
        ],
    ],
];
