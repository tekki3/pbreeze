<?php
declare(strict_types=1);

use App\Controllers\HomeController;
use PBreeze\Request;
use PBreeze\Response;

require_once $app->root . '/app/controllers/HomeController.php';

$home = new HomeController($app);
$router->get('/', [$home, 'index']);
$router->post('/greet', [$home, 'greet']);
$router->get('/health', static fn (Request $request): Response => Response::json(['status' => 'ok']));
