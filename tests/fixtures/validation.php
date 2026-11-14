<?php
declare(strict_types=1);

use PBreeze\Api;
use PBreeze\Request;
use PBreeze\Response;
use PBreeze\Validator;

require dirname(__DIR__, 2) . '/bootstrap.php';

try {
    $request = Request::capture();
    if ($request->path === '/__validation/json') {
        $data = $request->validate([
            'users' => 'required|array|min:1', 'users.*.email' => 'required|email',
        ]);
        Response::json(['data' => $data])->send();
        return;
    }
    $rules = match ($request->query('check')) {
        'extension' => 'required|file|mimes:csv',
        'mime' => 'required|file|mimetypes:image/png',
        'size' => 'required|file|max_size:4',
        default => 'required|file|mimes:txt|mimetypes:text/plain|max_size:100',
    };
    $validated = Validator::make(['document' => $request->file('document')], ['document' => $rules])->validate();
    Response::json(['name' => $validated['document']->originalName])->send();
} catch (Throwable $error) {
    Api::errorResponse($error)->send();
}
