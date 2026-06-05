<?php

namespace App\Services\ApiDocs;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class RouteDocExtractor
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('publishlayer-docs', []);
    }

    /**
     * Get all API routes that should be documented.
     *
     * @return array<int, array>
     */
    public function getDocumentableRoutes(): array
    {
        $routes = [];
        $routePrefix = $this->config['openapi']['route_prefix'] ?? 'api/v1';
        $includeMiddleware = $this->config['openapi']['include_middleware'] ?? ['integration.token'];
        $excludePatterns = $this->config['openapi']['exclude_patterns'] ?? [];

        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();

            // Must start with route prefix
            if (! Str::startsWith($uri, $routePrefix)) {
                continue;
            }

            // Check if excluded
            if ($this->isExcluded($uri, $excludePatterns)) {
                continue;
            }

            // Must have required middleware (if specified)
            if (! empty($includeMiddleware)) {
                $routeMiddleware = $route->middleware();
                $hasRequired = false;
                foreach ($includeMiddleware as $required) {
                    if (in_array($required, $routeMiddleware, true)) {
                        $hasRequired = true;
                        break;
                    }
                }
                if (! $hasRequired) {
                    continue;
                }
            }

            $extracted = $this->extractRouteData($route);
            if ($extracted !== null) {
                $routes[] = $extracted;
            }
        }

        // Sort by path then method
        usort($routes, function ($a, $b) {
            $pathCmp = strcmp($a['path'], $b['path']);
            if ($pathCmp !== 0) {
                return $pathCmp;
            }

            return $this->methodOrder($a['method']) - $this->methodOrder($b['method']);
        });

        return $routes;
    }

    /**
     * Extract documentation data from a route.
     */
    public function extractRouteData(Route $route): ?array
    {
        $methods = $route->methods();
        $method = strtolower($methods[0]);

        // Skip HEAD and OPTIONS
        if (in_array($method, ['head', 'options'])) {
            return null;
        }

        $uri = $route->uri();
        $path = $this->normalizePathForOpenApi($uri);
        $action = $route->getAction();
        $middleware = $route->middleware();

        // Get controller and method
        $controller = null;
        $controllerMethod = null;
        if (isset($action['controller'])) {
            [$controller, $controllerMethod] = explode('@', $action['controller']);
        } elseif (isset($action['uses']) && is_string($action['uses'])) {
            if (str_contains($action['uses'], '@')) {
                [$controller, $controllerMethod] = explode('@', $action['uses']);
            } else {
                $controller = $action['uses'];
                $controllerMethod = '__invoke';
            }
        }

        return [
            'path' => $path,
            'original_uri' => $uri,
            'method' => $method,
            'name' => $route->getName(),
            'controller' => $controller,
            'action' => $controllerMethod,
            'controller_action' => $controller ? "{$controller}@{$controllerMethod}" : null,
            'middleware' => $middleware,
            'scopes' => $this->extractScopes($middleware),
            'tag' => $this->inferTag($path),
            'parameters' => $this->extractPathParameters($uri),
        ];
    }

    /**
     * Normalize Laravel route path to OpenAPI path format.
     * /api/v1/briefs/{id} -> /briefs/{id}
     */
    protected function normalizePathForOpenApi(string $uri): string
    {
        $routePrefix = $this->config['openapi']['route_prefix'] ?? 'api/v1';

        // Remove prefix
        if (Str::startsWith($uri, $routePrefix)) {
            $uri = Str::substr($uri, strlen($routePrefix));
        }

        // Ensure leading slash
        if (! Str::startsWith($uri, '/')) {
            $uri = '/'.$uri;
        }

        // Convert Laravel {param} to OpenAPI style (they're actually the same)
        // But normalize {param?} optional params by removing ?
        $uri = preg_replace('/\{([^}]+)\?\}/', '{$1}', $uri);

        return $uri;
    }

    /**
     * Extract path parameters from route URI.
     */
    protected function extractPathParameters(string $uri): array
    {
        $parameters = [];
        preg_match_all('/\{([^}]+)\}/', $uri, $matches);

        foreach ($matches[1] as $param) {
            $isOptional = Str::endsWith($param, '?');
            $name = rtrim($param, '?');

            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => ! $isOptional,
                'schema' => $this->inferParameterSchema($name),
            ];
        }

        return $parameters;
    }

    /**
     * Infer parameter schema from name.
     */
    protected function inferParameterSchema(string $name): array
    {
        // Common ID patterns
        if (in_array($name, ['id', 'destination', 'apiKey', 'webhook', 'operation', 'audit'])) {
            return ['type' => 'string', 'format' => 'uuid'];
        }

        // Token patterns
        if (str_contains($name, 'token')) {
            return ['type' => 'string'];
        }

        return ['type' => 'string'];
    }

    /**
     * Extract scope requirements from middleware.
     */
    public function extractScopes(array $middleware): array
    {
        $scopes = [];

        foreach ($middleware as $m) {
            if (Str::startsWith($m, 'integration.scope:')) {
                $scope = Str::after($m, 'integration.scope:');
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * Infer tag from route path using config mapping.
     */
    protected function inferTag(string $path): string
    {
        $routeTags = $this->config['route_tags'] ?? [];

        // Check exact match first
        if (isset($routeTags[$path])) {
            return $routeTags[$path];
        }

        // Check wildcard patterns
        foreach ($routeTags as $pattern => $tag) {
            if (Str::contains($pattern, '*')) {
                $regex = str_replace(['/', '*'], ['\\/', '.*'], $pattern);
                if (preg_match('/^'.$regex.'$/', $path)) {
                    return $tag;
                }
            }
        }

        // Fallback: use first path segment
        $segments = explode('/', trim($path, '/'));
        if (! empty($segments[0])) {
            return Str::title(str_replace(['-', '_'], ' ', $segments[0]));
        }

        return 'General';
    }

    /**
     * Check if route is excluded.
     */
    protected function isExcluded(string $uri, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::contains($pattern, '*')) {
                $regex = str_replace(['/', '*'], ['\\/', '.*'], $pattern);
                if (preg_match('/^'.$regex.'$/', $uri)) {
                    return true;
                }
            } elseif ($uri === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get method order for sorting.
     */
    protected function methodOrder(string $method): int
    {
        return match ($method) {
            'get' => 1,
            'post' => 2,
            'put' => 3,
            'patch' => 4,
            'delete' => 5,
            default => 6,
        };
    }

    /**
     * Get request class for a controller action.
     */
    public function getRequestClass(string $controllerAction): ?string
    {
        $requestClasses = $this->config['request_classes'] ?? [];

        return $requestClasses[$controllerAction] ?? null;
    }

    /**
     * Get response resource for a controller action.
     */
    public function getResponseResource(string $controllerAction): ?string
    {
        $responseResources = $this->config['response_resources'] ?? [];

        return $responseResources[$controllerAction] ?? null;
    }

    /**
     * Get endpoint description.
     */
    public function getEndpointDescription(string $method, string $path): ?string
    {
        $descriptions = $this->config['endpoint_descriptions'] ?? [];
        $key = strtoupper($method).' '.$path;

        return $descriptions[$key] ?? null;
    }
}
