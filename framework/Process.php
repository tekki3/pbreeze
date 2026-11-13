<?php
declare(strict_types=1);

namespace PBreeze;

use RuntimeException;

final class Process
{
    /** @param non-empty-list<string> $command */
    public static function run(array $command, string $directory): int
    {
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start the CLI process.');
        }
        $status = proc_close($process);
        if ($status < 0) {
            throw new RuntimeException('Cannot determine the CLI process exit code.');
        }
        return $status;
    }
}
