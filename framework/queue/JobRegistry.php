<?php
declare(strict_types=1);

namespace PBreeze\Queue;

use InvalidArgumentException;
use RuntimeException;

class JobFailure extends RuntimeException {}
final class RetryableJobFailure extends JobFailure {}
final class JobCancelled extends RuntimeException {}
final class InvalidJob extends RuntimeException {}

final class JobRegistry
{
    private array $handlers = [];
    private bool $loaded = false;
    private ?\Closure $loader;

    public function __construct(?callable $loader = null)
    {
        $this->loader = $loader === null ? null : \Closure::fromCallable($loader);
    }

    private function load(): void
    {
        if (!$this->loaded) {
            $before = $this->handlers;
            $this->loaded = true;
            try {
                if ($this->loader !== null) {
                    ($this->loader)($this);
                }
            } catch (\Throwable $error) {
                $this->handlers = $before;
                $this->loaded = false;
                throw $error;
            }
        }
    }

    public function register(string $type, callable $validate, callable $handle): void
    {
        self::name($type);
        if (isset($this->handlers[$type])) {
            throw new InvalidArgumentException('The job type is already registered.');
        }
        $this->handlers[$type] = [$validate, $handle];
    }

    public static function name(string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,189}$/D', $name)) {
            throw new InvalidArgumentException('Invalid background work name.');
        }

    }

    public static function queueName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,79}$/D', $name)) {
            throw new InvalidArgumentException('Invalid background queue name.');
        }
    }

    public function validate(string $type, array $payload): void
    {
        $this->load();
        if (!isset($this->handlers[$type])) {
            throw new InvalidArgumentException('Unknown registered job type.');
        }
        if (($this->handlers[$type][0])($payload) === false) {
            throw new InvalidArgumentException('Invalid job payload.');
        }
    }

    public function execute(string $type, array $payload, ExecutionContext $context): void
    {
        try {
            $this->validate($type, $payload);
        } catch (\Throwable $error) {
            throw new InvalidJob('The persisted job type or payload is invalid.', 0, $error);
        }
        $context->throwIfCancelled();
        ($this->handlers[$type][1])($payload, $context);
    }
}
