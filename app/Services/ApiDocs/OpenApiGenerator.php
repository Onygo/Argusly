<?php

namespace App\Services\ApiDocs;

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class OpenApiGenerator
{
    protected RouteDocExtractor $routeExtractor;

    protected OpenApiBuilder $builder;

    protected array $config;

    public function __construct(
        RouteDocExtractor $routeExtractor,
        OpenApiBuilder $builder
    ) {
        $this->routeExtractor = $routeExtractor;
        $this->builder = $builder;
        $this->config = config('publishlayer-docs', []);
    }

    /**
     * Generate the OpenAPI specification.
     */
    public function generate(): array
    {
        // Get all documentable routes
        $routes = $this->routeExtractor->getDocumentableRoutes();

        // Build OpenAPI spec
        return $this->builder->build($routes);
    }

    /**
     * Generate and write to file.
     */
    public function generateAndWrite(?string $outputPath = null, string $format = 'yaml'): string
    {
        $spec = $this->generate();

        $outputPath = $outputPath ?? $this->config['openapi']['output'] ?? 'docs/openapi/publishlayer.yaml';
        $format = $format ?: ($this->config['openapi']['format'] ?? 'yaml');

        return $this->write($spec, $outputPath, $format);
    }

    /**
     * Write specification to file.
     */
    public function write(array $spec, string $outputPath, string $format = 'yaml'): string
    {
        $fullPath = base_path($outputPath);

        // Ensure directory exists
        $directory = dirname($fullPath);
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // Convert to output format
        $content = match ($format) {
            'json' => json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            default => Yaml::dump($spec, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
        };

        File::put($fullPath, $content);

        // Clear cache
        $this->clearCache();

        return $fullPath;
    }

    /**
     * Validate the generated specification.
     */
    public function validate(array $spec): array
    {
        $errors = [];

        // Check required fields
        if (! isset($spec['openapi'])) {
            $errors[] = 'Missing required field: openapi';
        }

        if (! isset($spec['info'])) {
            $errors[] = 'Missing required field: info';
        } else {
            if (! isset($spec['info']['title'])) {
                $errors[] = 'Missing required field: info.title';
            }
            if (! isset($spec['info']['version'])) {
                $errors[] = 'Missing required field: info.version';
            }
        }

        if (! isset($spec['paths']) || empty($spec['paths'])) {
            $errors[] = 'No paths defined';
        }

        // Validate paths
        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                // Check for required operation fields
                if (! isset($operation['responses'])) {
                    $errors[] = "Missing responses for {$method} {$path}";
                }

                // Validate $ref references
                $refs = $this->findRefs($operation);
                foreach ($refs as $ref) {
                    if (! $this->refExists($ref, $spec)) {
                        $errors[] = "Invalid reference: {$ref} in {$method} {$path}";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Find all $ref values in an array recursively.
     */
    protected function findRefs(array $data): array
    {
        $refs = [];

        foreach ($data as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
            } elseif (is_array($value)) {
                $refs = array_merge($refs, $this->findRefs($value));
            }
        }

        return $refs;
    }

    /**
     * Check if a reference exists in the spec.
     */
    protected function refExists(string $ref, array $spec): bool
    {
        // Parse ref: #/components/schemas/Brief
        if (! str_starts_with($ref, '#/')) {
            return true; // External refs - assume valid
        }

        $path = explode('/', substr($ref, 2));
        $current = $spec;

        foreach ($path as $segment) {
            if (! isset($current[$segment])) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    /**
     * Get routes statistics.
     */
    public function getStatistics(): array
    {
        $routes = $this->routeExtractor->getDocumentableRoutes();

        $byTag = [];
        $byMethod = [];

        foreach ($routes as $route) {
            $tag = $route['tag'];
            $method = $route['method'];

            $byTag[$tag] = ($byTag[$tag] ?? 0) + 1;
            $byMethod[$method] = ($byMethod[$method] ?? 0) + 1;
        }

        return [
            'total_routes' => count($routes),
            'by_tag' => $byTag,
            'by_method' => $byMethod,
        ];
    }

    /**
     * Clear OpenAPI cache.
     */
    public function clearCache(): void
    {
        $cacheKey = $this->config['cache']['key'] ?? 'publishlayer:openapi:spec';
        cache()->forget($cacheKey);
    }
}
