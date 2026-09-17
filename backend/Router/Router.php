<?php

declare(strict_types=1);

namespace TelegramBanking\App\Router;

use TelegramBanking\App\Http\Request;
use TelegramBanking\App\Http\Response;

class Router
{
    /**
     * @var array<int, array{
     * method: string, 
     * path: string, 
     * pattern: string,
     * handler: array|callable
     * }>
     */
    public array $routes = [];

    public function get(string $path, array|callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array|callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, array|callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, array|callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, array|callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, array|callable $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $this->compileRoute($path),
            'handler' => $handler
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $uri = $request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->match($route['pattern'], $uri);
            if ($params === null) {
                continue;
            }

            return $this->callHandler(
                $route['handler'],
                $request,
                $params
            );
        }

        return Response::json([
            'error' => 'Route not found',
        ], 404);
    }

    private function compileRoute(string $routePath): string
    {
        $pattern = preg_replace_callback(
            '/\{([^}]+)\}/',
            fn($matches) => '(?P<' . $matches[1] . '>[^/]+)',
            $routePath
        );

        return "~^" . $pattern . "$~";
    }

    private function match(string $pattern, string $requestPath): ?array
    {
        $matches = [];
        if (!preg_match($pattern, $requestPath, $matches)) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    private function callHandler(
        array|callable $handler,
        Request $request,
        array $params
    ): Response {
        if (is_callable($handler)) {
            return $handler($request, $params);
        }

        [$controllerClass, $method] = $handler;

        $controller = new $controllerClass();

        return $controller->$method($request, $params);
    }
}
