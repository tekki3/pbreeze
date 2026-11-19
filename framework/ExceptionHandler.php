<?php
declare(strict_types=1);

namespace PBreeze;

use Throwable;

final class ExceptionHandler
{
    public function __construct(
        private readonly string $environment = 'production',
        private readonly bool $debug = false,
        private readonly ?Logger $logger = null,
        private readonly ?View $views = null,
    ) {
    }

    public function render(Throwable $error, ?Request $request = null, bool $json = false): Response
    {
        $status = match (true) {
            $error instanceof HttpException, $error instanceof AuthException => $error->status,
            $error instanceof ApiValidationException, $error instanceof ValidationException => 422,
            $error instanceof RecordNotFoundException => 404,
            default => 500,
        };
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        $serverError = $status >= 500;
        if ($serverError) {
            Logger::report($error, $this->logger);
        }
        $headers = $error instanceof HttpException ? $error->headers : [];
        if ($serverError) {
            $headers = isset($headers['Retry-After']) ? ['Retry-After' => $headers['Retry-After']] : [];
        }
        if ($error instanceof AuthException && $error->retryAfter > 0) {
            $headers['Retry-After'] = (string) $error->retryAfter;
        }
        if ($serverError && $this->environment === 'local' && $this->debug) {
            return new Response($this->diagnostic($error, $request), $status, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ] + $headers);
        }
        $message = match (true) {
            $serverError => 'An unexpected error occurred.',
            $error instanceof AuthException, $error instanceof ApiValidationException,
                $error instanceof ValidationException => 'Check your input.',
            $error instanceof RecordNotFoundException => 'Record not found.',
            default => $error->getMessage(),
        };
        $json = $json || ($request !== null && (Api::matches($request->path) || $request->isJson()));
        if (!$json && $this->views !== null) {
            return $this->errorPage($this->views, $status, $message, $headers);
        }
        if ($serverError || $json) {
            $body = ['error' => ['code' => $serverError ? 'INTERNAL_ERROR' : 'HTTP_' . $status, 'message' => $message]];
            if ($json) {
                $body['error']['status'] = $status;
            }
            if (!$serverError) {
                if ($error instanceof AuthException || $error instanceof ApiValidationException) {
                    $body['error']['details'] = $error->errors;
                } elseif ($error instanceof ValidationException) {
                    $body['error']['details'] = $error->errors->toArray();
                }
            }
            $response = Response::json($body, $status);
            return new Response($response->body, $status, $response->headers + $headers);
        }
        return new Response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8'] + $headers);
    }

    /** @param array<string, string> $headers */
    private function errorPage(View $views, int $status, string $message, array $headers): Response
    {
        $title = match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Page Not Found',
            405 => 'Method Not Allowed',
            413 => 'Content Too Large',
            415 => 'Unsupported Media Type',
            419 => 'Page Expired',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            default => $status >= 500 ? 'Server Error' : 'Request Error',
        };
        $headers = ['Content-Type' => 'text/html; charset=UTF-8'] + array_filter(
            $headers,
            static fn (string $name): bool => !in_array(strtolower($name), ['content-type', 'content-length', 'content-encoding'], true),
            ARRAY_FILTER_USE_KEY,
        );
        $view = "errors.http-$status";
        $body = null;
        try {
            if ($views->exists($view)) {
                $body = $views->render($view, compact('status', 'title', 'message'));
            }
        } catch (Throwable $error) {
            Logger::report($error, $this->logger);
        }
        if ($body !== null) {
            return new Response($body, $status, $headers);
        }

        $heading = e("$status $title");
        $message = e($message);
        return new Response(<<<HTML
<!doctype html>
<html lang="en-US">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>$heading</title>
</head>
<body>
    <main>
        <h1>$heading</h1>
        <p>$message</p>
        <p><a href="/">Go to the home page</a></p>
    </main>
</body>
</html>
HTML, $status, $headers);
    }

    private function diagnostic(Throwable $error, ?Request $request): string
    {
        $lines = [
            'Exception: ' . $error::class,
            'Message: ' . $error->getMessage(),
            'File: ' . $error->getFile(),
            'Line: ' . $error->getLine(),
            '',
            'Stack trace:',
        ];
        foreach ($error->getTrace() as $index => $frame) {
            $lines[] = '#' . $index . ' ' . ($frame['file'] ?? '[internal]') . ':' . ($frame['line'] ?? 0)
                . ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
        }
        $lines[] = '';
        $lines[] = 'Request:';
        $lines[] = $request === null ? 'Unavailable (failure before request capture).'
            : 'Method: ' . $request->method() . "\nPath: " . $request->path . "\nIP: " . $request->ip();
        return implode("\n", $lines) . "\n";
    }
}
