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
