<?php
declare(strict_types=1);

$app = require $argv[1];

if (($argv[2] ?? '') === 'nested') {
    exit(\PBreeze\Process::run(
        [PHP_BINARY, __FILE__, $argv[1], 'arguments', ...array_slice($argv, 3)],
        getcwd(),
    ));
}

echo json_encode(['arguments' => array_slice($argv, 3), 'directory' => getcwd()], JSON_THROW_ON_ERROR);
exit(7);
