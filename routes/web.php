<?php
declare(strict_types=1);

use App\Controllers\HomeController;
use App\Controllers\AuthController;
use PBreeze\Request;
use PBreeze\Response;

require_once $app->root . '/app/controllers/HomeController.php';
require_once $app->root . '/app/controllers/AuthController.php';

$home = new HomeController($app);
$router->get('/', [$home, 'index']);
$router->post('/greet', [$home, 'greet']);
$router->get('/health', static fn (Request $request): Response => Response::json(['status' => 'ok']));
$router->get('/captcha/{id}', static fn (Request $request, string $id): Response => $app->botProtection->image($id));

$auth = new AuthController($app);
$router->get('/register', [$auth, 'registerForm']);
$router->post('/register', [$auth, 'register']);
$router->get('/login', [$auth, 'loginForm']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/forgot-password', [$auth, 'forgotForm']);
$router->post('/forgot-password', [$auth, 'forgot']);
$router->get('/reset-password', [$auth, 'resetForm']);
$router->post('/reset-password', [$auth, 'reset']);
$router->get('/verify-email', [$auth, 'verificationLink']);
$router->post('/verify-email', [$auth, 'verify']);
$router->get('/email/verify', $app->auth->protect([$auth, 'verificationNotice'], false));
$router->post('/email/verification-notification', $app->auth->protect([$auth, 'resend'], false));
$router->get('/account', $app->auth->protect([$auth, 'account']));
