<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, params:string[], handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('#\{(\w+)\}#', function (array $m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $methodMatchedAnyPattern = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $request->path, $matches)) {
                continue;
            }

            if ($route['method'] !== $request->method) {
                $methodMatchedAnyPattern = true;
                continue;
            }

            array_shift($matches);
            $args = array_combine($route['params'], $matches);

            try {
                return ($route['handler'])($request, $args);
            } catch (ApiException $e) {
                return $e->toResponse();
            }
        }

        if ($methodMatchedAnyPattern) {
            return Response::error('not_found', 'Method not allowed for this path.', 405);
        }

        return Response::error('not_found', 'Route not found.', 404);
    }
}
