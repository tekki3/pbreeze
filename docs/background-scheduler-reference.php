<?php
declare(strict_types=1);

return [
    'PBreeze\\Scheduler\\SchedulerRepository' => [
        'description' => 'Database persistence for scheduler definition hashes and next-run cursors. The background-work migration creates its pbreeze_scheduler_tasks table; repository methods participate in the database transaction that also inserts occurrences.',
        'setup' => '$repository = new \\PBreeze\\Scheduler\\SchedulerRepository($app->db);',
        'methods' => [
            '__construct' => [
                'Bind the application database. The required background-work migration must be applied before repository calls.',
                '$repository = new \\PBreeze\\Scheduler\\SchedulerRepository($app->db);',
            ],
            'transaction' => [
                'Run a callback in a database transaction, committing on success and rolling back on failure. Scheduler queue inserts share this transaction.',
                '$result = $repository->transaction(static fn () => "claimed");',
            ],
            'initialize' => [
                'Insert the initial task cursor only when its key is absent. Return true only when this call inserted state; existing definitions and cursors are not overwritten.',
                '$inserted = $repository->initialize("reports:daily", $definitionHash, $nextRunAt, time());',
            ],
            'cursor' => [
                'Read a task cursor, optionally taking a row lock where the database supports it. Return null if the task has no persisted state.',
                '$cursor = $repository->cursor("reports:daily", lock: true);',
            ],
            'compareAndSwap' => [
                'Update a cursor only when its old definition hash and next timestamp still match. Return false when the claim lost a concurrent race.',
                '$saved = $repository->compareAndSwap("reports:daily", $oldHash, $oldAt, $newHash, $nextAt, $lastAt, time());',
            ],
            'hasOutstandingTask' => [
                'Return true while the task has a pending, reserved, running, or uncertain occurrence. Scheduler uses this to coalesce overlapping backlog.',
                '$busy = $repository->hasOutstandingTask("reports:daily");',
            ],
        ],
    ],
    'PBreeze\\Scheduler\\Scheduler' => [
        'description' => 'The persistent scheduler materializes due task occurrences into the native background queue. It stores task cursors in pbreeze_scheduler_tasks, and Queue enforces occurrence uniqueness by task key and scheduled timestamp. Apply the background-work migration before running or listing schedules.',
        'setup' => '$scheduler = $app->scheduler;',
        'methods' => [
            '__construct' => [
                'Build the scheduler with the application database, queue, registered Schedule, validated settings, optional Clock, and optional Application used for CLI commands. Settings are timezone, poll_interval (1..86400 seconds), due_tolerance (0..60 seconds), and catch_up_limit (1..100).',
                '$scheduler = new \\PBreeze\\Scheduler\\Scheduler($app->db, $app->queue, $schedule, $app->config["scheduler"] ?? [], app: $app);',
            ],
            'run' => [
                'Materialize occurrences due at the injected clock time and return their durable job IDs. This does not execute callbacks or commands. Catch-up work is bounded by the task limit and catch_up_limit; cursor advancement and queue insertion share one database transaction.',
                '$jobIds = $app->scheduler->run();',
            ],
            'listing' => [
                'Return one entry per registered task with its current definition hash, expression, timezone, next cursor, delivery, queue, and retry/overlap settings. Does not execute work.',
                '$entries = $app->scheduler->listing();',
            ],
            'executeTask' => [
                'Run a trusted registered callback with the queue ExecutionContext, or execute its validated argv list using PHP_BINARY and the application breeze entry point. A missing task or changed definition hash raises nonretryable JobFailure; command exit codes other than zero fail the job.',
                '$app->scheduler->executeTask($payload["task_key"], $payload["definition_hash"], $context);',
            ],
        ],
    ],
    'PBreeze\\Scheduler\\Schedule' => [
        'description' => 'An explicit in-memory registry of named ScheduledTask definitions, normally populated once by routes/schedule.php. Duplicate task keys are rejected.',
        'setup' => '$schedule = new \\PBreeze\\Scheduler\\Schedule("UTC");',
        'methods' => [
            '__construct' => [
                'Create an empty registry with the timezone used by tasks that do not choose their own timezone.',
                '$schedule = new \\PBreeze\\Scheduler\\Schedule("Europe/London");',
            ],
            'task' => [
                'Register a unique task key and optionally set its callback. The returned builder must be given one frequency and one action before execution.',
                '$task = $schedule->task("reports:daily", static fn (\\PBreeze\\Queue\\ExecutionContext $context) => buildReports($context));',
            ],
            'tasks' => [
                'Return the registered tasks in registration order.',
                '$tasks = $schedule->tasks();',
            ],
            'command' => [
                'Register a trusted PBreeze CLI command and argument list. The default task key is command: plus the command name; supply name for multiple schedules of the same command. No shell is used.',
                '$schedule->command("mail:work", ["50"])->everyMinute()->withoutOverlapping();',
            ],
            'find' => [
                'Return a task by key, or null when the key is not registered.',
                '$task = $schedule->find("reports:daily");',
            ],
        ],
    ],
    'PBreeze\\Scheduler\\ScheduledTask' => [
        'description' => 'A fluent named schedule definition. UTC-anchored interval frequencies and timezone-local calendar frequencies are supported. Each task has one frequency and one callback or command.',
        'setup' => '$task = $schedule->task("reports:daily");',
        'methods' => [
            '__construct' => [
                'Create a task with its unique key and default timezone. Prefer Schedule::task() so duplicate registration is checked.',
                '$task = new \\PBreeze\\Scheduler\\ScheduledTask("reports:daily", "UTC");',
            ],
            'everySecond' => ['Schedule each UTC-anchored second.', '$task->everySecond();'],
            'everySeconds' => ['Schedule on UTC epoch-aligned multiples of the positive interval in seconds.', '$task->everySeconds(15);'],
            'everyMinute' => ['Schedule on UTC epoch-aligned minute boundaries.', '$task->everyMinute();'],
            'everyMinutes' => ['Schedule on UTC epoch-aligned multiples of the positive minute interval.', '$task->everyMinutes(5);'],
            'hourly' => ['Schedule at the start of each local calendar hour; nonexistent DST hours are skipped and repeated hours run only at their first occurrence.', '$task->hourly();'],
            'everyHours' => ['Schedule on UTC epoch-aligned multiples of the positive hour interval.', '$task->everyHours(6);'],
            'daily' => ['Schedule at local midnight.', '$task->daily();'],
            'dailyAt' => ['Schedule at the local 24-hour HH:MM time. Nonexistent DST wall times are skipped.', '$task->dailyAt("02:30");'],
            'weekly' => ['Schedule each Sunday at local midnight.', '$task->weekly();'],
            'weeklyOn' => ['Schedule at an ISO weekday and 24-hour time; weekdays use 1 for Monday through 7 for Sunday.', '$task->weeklyOn(1, "09:15");'],
            'monthly' => ['Schedule on the selected local day of each month at HH:MM; months without that day are skipped.', '$task->monthly(1, "00:00");'],
            'using' => ['Install a custom ScheduleExpression implementation. It must return a strictly later occurrence or null and provide a stable fingerprint for definition hashing.', '$task->using(new \\PBreeze\\Scheduler\\IntervalExpression(45));'],
            'timezone' => ['Set a validated timezone for calendar evaluation on this task.', '$task->timezone("America/New_York");'],
            'withoutOverlapping' => ['Coalesce missed work to one latest occurrence, suppress new occurrences while earlier work is outstanding, and retain a per-task execution lock through uncertain outcomes.', '$task->withoutOverlapping();'],
            'onOneServer' => ['Declare cluster-unique scheduled occurrences. Occurrence uniqueness is always enforced by the database.', '$task->onOneServer();'],
            'runMissed' => ['Opt into the most recent 1..100 missed occurrences per scheduler pass, additionally bounded by scheduler catch_up_limit. Older history is skipped without iterating every tick.', '$task->runMissed(5);'],
            'skipMissed' => ['Default policy: materialize only the latest occurrence within due_tolerance, then advance to the next future occurrence. It does not suppress subsequent on-time executions.', '$task->skipMissed();'],
            'retries' => ['Set additional retries after the initial attempt (maximum 99, for at most 100 total attempts). New tasks default to zero retries; calling without an argument selects three. Retryable failures must be declared or the task explicitly marked retrySafe().', '$task->retries(2);'],
            'retrySafe' => ['Allow the queue to retry generic failures for this task. Opt in only when the callback or command is idempotent or uses the provided stable idempotency key; otherwise generic failures may be recorded as uncertain.', '$task->retrySafe();'],
            'retryAfter' => ['Set one retry delay or a nonempty list of delays in seconds (each 0..604800).', '$task->retryAfter([10, 60, 300]);'],
            'timeout' => ['Set the per-attempt timeout in seconds (1..86400).', '$task->timeout(120);'],
            'queue' => ['Select the named queue (1..80 ASCII letters, digits, underscores, or hyphens) and queued delivery rather than direct scheduler delivery.', '$task->queue("reports");'],
            'direct' => ['Select direct scheduler delivery.', '$task->direct();'],
            'queued' => ['Select queued delivery, optionally selecting its queue name.', '$task->queued("reports");'],
            'version' => ['Include an explicit printable version (1..128 bytes) in callback definition identity, useful when callback source cannot be inspected.', '$task->version("reports-v2");'],
            'run' => ['Set the trusted callback. It receives a PBreeze\\Queue\\ExecutionContext with idempotency and cancellation methods.', '$task->run(static function (\\PBreeze\\Queue\\ExecutionContext $context): void { $context->throwIfCancelled(); });'],
            'command' => ['Set a nonempty argv list for a trusted CLI command. Arguments are passed directly to PHP_BINARY and breeze without a shell.', '$task->command(["reports:build", "--format", "csv"]);'],
            'hasAction' => ['Return whether a callback or command has been assigned.', '$configured = $task->hasAction();'],
            'expression' => ['Return the configured ScheduleExpression or throw when no frequency was selected.', '$expression = $task->expression();'],
            'timezoneName' => ['Return the task timezone name.', '$zone = $task->timezoneName();'],
            'hasExplicitTimezone' => ['Return whether timezone() was called for this task.', '$explicit = $task->hasExplicitTimezone();'],
            'preventsOverlap' => ['Return whether withoutOverlapping() was enabled.', '$locked = $task->preventsOverlap();'],
            'clusterUnique' => ['Return whether onOneServer() was enabled; the occurrence store remains unique regardless.', '$unique = $task->clusterUnique();'],
            'skipsMissed' => ['Return whether skipMissed() was selected.', '$skip = $task->skipsMissed();'],
            'missedLimit' => ['Return the configured missed occurrence limit.', '$limit = $task->missedLimit();'],
            'tries' => ['Return total attempts including the initial attempt.', '$attempts = $task->tries();'],
            'isRetrySafe' => ['Return whether generic failures are safe to retry.', '$safe = $task->isRetrySafe();'],
            'backoff' => ['Return the configured list of retry delays in seconds.', '$delays = $task->backoff();'],
            'timeoutSeconds' => ['Return the per-attempt timeout in seconds.', '$seconds = $task->timeoutSeconds();'],
            'queueName' => ['Return the configured queue name.', '$queue = $task->queueName();'],
            'delivery' => ['Return direct or queued delivery.', '$delivery = $task->delivery();'],
            'callback' => ['Return the configured Closure, or null for a command task.', '$callback = $task->callback();'],
            'commandArguments' => ['Return the command argv list, or null for a callback task.', '$argv = $task->commandArguments();'],
            'description' => ['Return the canonical definition data used for hashing.', '$definition = $task->description("UTC");'],
            'definitionHash' => ['Return the SHA-256 hash of the task definition for the effective timezone.', '$hash = $task->definitionHash("UTC");'],
        ],
    ],
    'PBreeze\\Scheduler\\IntervalExpression' => [
        'description' => 'A UTC epoch-anchored interval expression used by seconds, minutes, and everyHours schedule methods.',
        'setup' => '$expression = new \\PBreeze\\Scheduler\\IntervalExpression(30);',
        'methods' => [
            '__construct' => ['Create an interval between 1 and 31536000 seconds.', '$expression = new \\PBreeze\\Scheduler\\IntervalExpression(30);'],
            'nextAfter' => ['Return the next aligned occurrence strictly after the timestamp. The timezone argument is unused for UTC intervals.', '$next = $expression->nextAfter(time(), new \\DateTimeZone("UTC"));'],
            'fingerprint' => ['Return the stable type and interval fields for task definition hashing.', '$identity = $expression->fingerprint();'],
        ],
    ],
    'PBreeze\\Scheduler\\CalendarExpression' => [
        'description' => 'Timezone-local hourly, daily, weekly, and monthly calendar recurrence with explicit handling of daylight-saving gaps and folds.',
        'setup' => '$expression = \\PBreeze\\Scheduler\\CalendarExpression::daily("09:00");',
        'methods' => [
            'hourly' => ['Create a local top-of-hour recurrence.', '$expression = \\PBreeze\\Scheduler\\CalendarExpression::hourly();'],
            'daily' => ['Create a local daily recurrence at HH:MM.', '$expression = \\PBreeze\\Scheduler\\CalendarExpression::daily("09:00");'],
            'weekly' => ['Create a local weekly recurrence for ISO weekday 1..7 at HH:MM; default Sunday.', '$expression = \\PBreeze\\Scheduler\\CalendarExpression::weekly(1, "09:00");'],
            'monthly' => ['Create a local monthly recurrence on day 1..31 at HH:MM.', '$expression = \\PBreeze\\Scheduler\\CalendarExpression::monthly(1, "09:00");'],
            'nextAfter' => ['Return the next valid local occurrence strictly after the timestamp. Nonexistent wall times are skipped and ambiguous local times use only their first occurrence.', '$next = $expression->nextAfter(time(), new \\DateTimeZone("America/New_York"));'],
            'fingerprint' => ['Return stable calendar fields for definition hashing.', '$identity = $expression->fingerprint();'],
        ],
    ],
];
