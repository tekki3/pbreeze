<?php
declare(strict_types=1);

require $argv[1];
$views = new \PBreeze\View($argv[2], $argv[2] . '/compiled');
echo $views->render('index', ['value' => '<concurrent>']);
