<?php
declare(strict_types=1);

namespace PBreeze;

use Closure;
use InvalidArgumentException;
use LogicException;

final class Router
{
    /** @var list<array{method: string, path: string, regex: string, names: list<string>, handler: Closure}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'], true)
            || !str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Invalid route definition.');
        }
        $path = self::normalize($path);
        $names = [];
        $segments = explode('/', $path);
        foreach ($segments as &$segment) {
            if (preg_match('/^\{([a-zA-Z][a-zA-Z0-9_]*)\}$/D', $segment, $match)) {
                if (in_array($match[1], $names, true)) {
                    throw new InvalidArgumentException('Duplicate route parameter.');
                }
                $names[] = $match[1];
                $segment = '([^/]+)';
            } else {
                if (str_contains($segment, '{') || str_contains($segment, '}')) {
                    throw new InvalidArgumentException('Parameters must occupy a complete path segment.');
                }
                $segment = preg_quote($segment, '~');
            }
        }
        unset($segment);
        $regex = '~^' . implode('/', $segments) . '$~D';
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['regex'] === $regex) {
                throw new LogicException("Route already exists: $method $path");
            }
        }
        $this->routes[] = ['method' => $method, 'path' => $path, 'regex' => $regex, 'names' => $names, 'handler' => Closure::fromCallable($handler)];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        $method = $request->method;
        $matches = array_filter($this->routes, static fn (array $route): bool => preg_match($route['regex'], self::normalize($request->path)) === 1);
        // An explicit HEAD route takes precedence over the GET fallback.
        if ($method === 'HEAD' && !array_filter($matches, static fn (array $route): bool => $route['method'] === 'HEAD')) {
            $method = 'GET';
        }
        foreach ($matches as $route) {
            $allowed[] = $route['method'];
            if ($route['method'] === 'GET') {
                $allowed[] = 'HEAD';
            }
            if ($route['method'] !== $method) {
                continue;
            }
            preg_match($route['regex'], self::normalize($request->path), $values);
            array_shift($values);
            $response = ($route['handler'])($request, ...$values);
            if (!$response instanceof Response) {
                throw new LogicException('A route must return a Response.');
            }
            return $response;
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'HTTP method not allowed.', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404, 'Page not found.');
    }

    /** @return list<array{method: string, path: string}> */
    public function listing(): array
    {
        return array_map(static fn (array $route): array => ['method' => $route['method'], 'path' => $route['path']], $this->routes);
    }

    private static function normalize(string $path): string
    {
        return rtrim($path, '/') ?: '/';
    }
}
