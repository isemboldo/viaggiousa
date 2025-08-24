<?php
declare(strict_types=1);

namespace App\Router;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void {
        $this->routes['GET'][$this->normalize($pattern)] = $handler;
    }
public function post(string $pattern, callable|array $handler): void {
    $this->routes['POST'][$this->normalize($pattern)] = $handler;
}

    public function dispatch(string $method, string $uri): void {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = $this->stripBase($path);

        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            $regex = $this->toRegex($pattern, $params);
            if (preg_match($regex, $path, $matches)) {
                $args = [];
                foreach ($params as $name) {
                    $args[] = isset($matches[$name]) ? $matches[$name] : null;
                }
                $this->invoke($handler, $args);
                return;
            }
        }

       http_response_code(404);
// usa la view 404 se disponibile
try {
    $twig = \View::env();
    echo $twig->render('errors/404.twig', ['path' => $path]);
} catch (\Throwable $e) {
    echo "404 Not Found";
}

    }

    private function invoke(callable|array $handler, array $args): void {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            $instance = new $class();
            $instance->{$method}(...$args);
        } else {
            $handler(...$args);
        }
    }

    private function toRegex(string $pattern, ?array &$params = []): string {
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function($m) use (&$params) {
            $params[] = $m[1];
            return '(?P<' . $m[1] . '>[A-Za-z0-9_-]+)';
        }, $pattern);
        return '#^' . $regex . '$#';
    }

    private function normalize(string $pattern): string {
        return '/' . trim($pattern, '/');
    }

    private function stripBase(string $path): string {
        $base = $_ENV['APP_URL_BASE'] ?? '';
        $base = rtrim($base, '/');
        if ($base && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        return $path === '' ? '/' : $path;
    }
}
