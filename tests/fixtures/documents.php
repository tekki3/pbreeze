<?php
declare(strict_types=1);

use PBreeze\Documents\Documents;

require dirname(__DIR__, 2) . '/bootstrap.php';

$extension = basename(explode('?', $_SERVER['REQUEST_URI'], 2)[0]);
$file = match ($extension) {
    'json' => Documents::json(['name' => 'Ada']),
    'csv' => Documents::csv([['name'], ['Ada']]),
    'xml' => Documents::xml(['name' => 'Ada']),
    'pdf' => Documents::pdf()->paragraph('Ada')->build(),
    'xlsx' => Documents::excel()->sheet('Names', [['Ada']])->build(),
    'docx' => Documents::word()->paragraph('Ada')->build(),
    default => throw new InvalidArgumentException('Unknown document fixture.'),
};
$file->download('report.' . $extension)->send($_SERVER['REQUEST_METHOD'] === 'HEAD');
