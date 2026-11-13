<?php
declare(strict_types=1);

namespace App\Controllers;

use PBreeze\Application;
use PBreeze\Request;
use PBreeze\Response;
use PBreeze\Validator;

final class HomeController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        return new Response($this->app->views->render('home', [
            'name' => $this->app->config['name'],
            'session' => $this->app->session,
            'user' => $this->app->auth->user(),
            'captcha' => $this->app->botProtection->challenge('greet'),
        ]));
    }

    public function greet(Request $request): Response
    {
        $refresh = $request->input('_captcha_refresh') === '1';
        if ($refresh) {
            $this->app->botProtection->forget($request, 'greet');
            $errors = [];
        } else {
            $errors = $this->app->botProtection->errors($request, 'greet')
                + Validator::errors($request->all(), ['name' => 'required|string|min:2|max:80']);
        }
        return new Response($this->app->views->render('home', [
            'name' => $this->app->config['name'],
            'session' => $this->app->session,
            'user' => $this->app->auth->user(),
            'errors' => $errors,
            'greeting' => !$refresh && $errors === [] ? 'Hallo, ' . $request->input('name') . '!' : null,
            'oldName' => is_string($request->input('name')) ? $request->input('name') : '',
            'captcha' => $this->app->botProtection->challenge('greet'),
            'captchaRefreshed' => $refresh,
        ]), $errors === [] ? 200 : 422);
    }
}
