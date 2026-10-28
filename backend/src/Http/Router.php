<?php
declare(strict_types=1);

namespace Blissful\Http;

final class Router
{
    /** @var array<int,array{0:string,1:string,2:callable,3:array}> */
    private array $routes = [];

    /** $opts: auth (bool), role ('client'|'musician'|null), rate ([bucket, limit, windowSeconds]), idempotent (bool) */
    public function add(string $method, string $pattern, callable $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler, $opts + ['auth' => true]];
    }

    /** @return array{0:callable,1:array,2:array}|null  [handler, params, opts] */
    public function match(string $method, string $path, bool &$pathExists = false): ?array
    {
        foreach ($this->routes as [$m, $regex, $handler, $opts]) {
            if (preg_match($regex, $path, $mm)) {
                $pathExists = true;
                if ($m === $method) {
                    $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
                    return [$handler, $params, $opts];
                }
            }
        }
        return null;
    }
}
