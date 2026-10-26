<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $normPattern = $pattern === '/' ? '/' : '/' . trim($pattern, '/');
        $regex = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static function (array $m): string {
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $normPattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $normPattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $pathMatchedOtherMethod = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches)) {
                if ($route['method'] !== $method) {
                    $pathMatchedOtherMethod = true;
                    continue;
                }
                $params = [];
                foreach ($matches as $k => $v) {
                    if (is_string($k)) {
                        $params[$k] = $v;
                    }
                }
                $request->params = $params;
                return ($route['handler'])($request);
            }
        }

        if ($pathMatchedOtherMethod) {
            return Response::html('<h1>405 Method Not Allowed</h1>', 405);
        }

        return Response::html('<h1>404 Not Found</h1>', 404);
    }
}
