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
            throw new RuntimeException('Kan het CLI-proces niet starten.');
        }
        $status = proc_close($process);
        if ($status < 0) {
            throw new RuntimeException('Kan de exitcode van het CLI-proces niet bepalen.');
        }
        return $status;
    }
}
