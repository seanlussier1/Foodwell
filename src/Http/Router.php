<?php
declare(strict_types=1);

namespace Foodwell\Http;

final class Router
{
    /** @var array<int,array{method:string,regex:string,handler:callable}> */
    private array $routes = [];

    /** Register a GET route. "{id}" matches a run of digits. */
    public function get(string $pattern, callable $handler, array $produces = [Negotiation::JSON]): void
    {
        $this->add('GET', $pattern, $handler, $produces);
    }

    public function post(string $pattern, callable $handler, array $produces = [Negotiation::JSON]): void
    {
        $this->add('POST', $pattern, $handler, $produces);
    }

    public function put(string $pattern, callable $handler, array $produces = [Negotiation::JSON]): void
    {
        $this->add('PUT', $pattern, $handler, $produces);
    }

    public function patch(string $pattern, callable $handler, array $produces = [Negotiation::JSON]): void
    {
        $this->add('PATCH', $pattern, $handler, $produces);
    }

    /** Register a DELETE route. */
    public function delete(string $pattern, callable $handler, array $produces = [Negotiation::JSON]): void
    {
        $this->add('DELETE', $pattern, $handler, $produces);
    }

    private function add(string $method, string $pattern, callable $handler, array $produces = [Negotiation::JSON]): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'produces' => $produces];
    }

    public function dispatch(string $method, string $path): void
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] === $method) {
                header('Vary: Accept', false);
                $format = Negotiation::choose((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), $route['produces']);
                if ($format === null) {
                    Response::error(406, 'Not acceptable. This route can return: ' . implode(', ', $route['produces']) . '.');
                    return;
                }
                Response::useFormat($format);

                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                ($route['handler'])($params);
                return;
            }
            $allowed[] = $route['method'];
        }

        if ($allowed !== []) {
            Response::error(405, 'Method not allowed (not implemented yet).', ['Allow' => implode(', ', $allowed)]);
            return;
        }
        Response::error(404, 'Route not found.');
    }
}
