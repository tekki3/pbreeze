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
        ]));
    }

    public function greet(Request $request): Response
    {
        $errors = Validator::errors($request->all(), ['name' => 'required|string|min:2|max:80']);
        return new Response($this->app->views->render('home', [
            'name' => $this->app->config['name'],
            'session' => $this->app->session,
            'user' => $this->app->auth->user(),
            'errors' => $errors,
            'greeting' => $errors === [] ? 'Hallo, ' . $request->input('name') . '!' : null,
            'oldName' => is_string($request->input('name')) ? $request->input('name') : '',
        ]), $errors === [] ? 200 : 422);
    }
}
