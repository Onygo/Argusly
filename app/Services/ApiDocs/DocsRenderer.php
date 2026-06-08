<?php

namespace App\Services\ApiDocs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class DocsRenderer
{
    protected array $config;

    protected ?array $spec = null;

    public function __construct()
    {
        $this->config = config('argusly-docs', []);
    }

    /**
     * Get the parsed OpenAPI specification.
     */
    public function getSpec(): array
    {
        if ($this->spec !== null) {
            return $this->spec;
        }

        $cacheEnabled = $this->config['cache']['enabled'] ?? true;
        $cacheTtl = $this->config['cache']['ttl'] ?? 3600;
        $cacheKey = $this->config['cache']['key'] ?? 'argusly:openapi:spec';

        if ($cacheEnabled) {
            $this->spec = Cache::remember($cacheKey, $cacheTtl, fn () => $this->loadSpec());
        } else {
            $this->spec = $this->loadSpec();
        }

        return $this->spec;
    }

    /**
     * Load the OpenAPI spec from file.
     */
    protected function loadSpec(): array
    {
        $path = base_path($this->config['openapi']['output'] ?? 'docs/openapi/argusly.yaml');

        if (! File::exists($path)) {
            return [];
        }

        $content = File::get($path);

        if (Str::endsWith($path, '.json')) {
            return json_decode($content, true) ?: [];
        }

        return Yaml::parse($content) ?: [];
    }

    /**
     * Check if spec exists.
     */
    public function specExists(): bool
    {
        $path = base_path($this->config['openapi']['output'] ?? 'docs/openapi/argusly.yaml');

        return File::exists($path);
    }

    /**
     * Get API info.
     */
    public function getInfo(): array
    {
        $spec = $this->getSpec();

        return $spec['info'] ?? [];
    }

    /**
     * Get all tags with their descriptions.
     */
    public function getTags(): array
    {
        $spec = $this->getSpec();

        return $spec['tags'] ?? [];
    }

    /**
     * Get endpoints grouped by tag.
     */
    public function getEndpointsByTag(?string $filterTag = null): array
    {
        $spec = $this->getSpec();
        $paths = $spec['paths'] ?? [];
        $grouped = [];

        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $tags = $operation['tags'] ?? ['General'];
                $tag = $tags[0];

                if ($filterTag !== null && $tag !== $filterTag) {
                    continue;
                }

                if (! isset($grouped[$tag])) {
                    $grouped[$tag] = [];
                }

                $grouped[$tag][] = [
                    'path' => $path,
                    'method' => $method,
                    'summary' => $operation['summary'] ?? '',
                    'description' => $operation['description'] ?? '',
                    'operationId' => $operation['operationId'] ?? '',
                    'scopes' => $this->extractScopes($operation),
                    'parameters' => $operation['parameters'] ?? [],
                    'requestBody' => $this->processRequestBody($operation['requestBody'] ?? null),
                    'responses' => $this->processResponses($operation['responses'] ?? []),
                ];
            }
        }

        return $grouped;
    }

    /**
     * Get all endpoints as a flat list.
     */
    public function getAllEndpoints(): array
    {
        $grouped = $this->getEndpointsByTag();
        $endpoints = [];

        foreach ($grouped as $tag => $tagEndpoints) {
            foreach ($tagEndpoints as $endpoint) {
                $endpoint['tag'] = $tag;
                $endpoints[] = $endpoint;
            }
        }

        return $endpoints;
    }

    /**
     * Get a single endpoint.
     */
    public function getEndpoint(string $path, string $method): ?array
    {
        $spec = $this->getSpec();
        $paths = $spec['paths'] ?? [];

        if (! isset($paths[$path][$method])) {
            return null;
        }

        $operation = $paths[$path][$method];

        return [
            'path' => $path,
            'method' => $method,
            'summary' => $operation['summary'] ?? '',
            'description' => $operation['description'] ?? '',
            'operationId' => $operation['operationId'] ?? '',
            'tags' => $operation['tags'] ?? [],
            'scopes' => $this->extractScopes($operation),
            'parameters' => $operation['parameters'] ?? [],
            'requestBody' => $this->processRequestBody($operation['requestBody'] ?? null),
            'responses' => $this->processResponses($operation['responses'] ?? []),
        ];
    }

    /**
     * Extract scopes from operation security.
     */
    protected function extractScopes(array $operation): array
    {
        $security = $operation['security'] ?? [];

        foreach ($security as $scheme) {
            if (isset($scheme['bearerAuth'])) {
                return $scheme['bearerAuth'];
            }
        }

        return [];
    }

    /**
     * Process request body for display.
     */
    protected function processRequestBody(?array $requestBody): ?array
    {
        if ($requestBody === null) {
            return null;
        }

        $content = $requestBody['content']['application/json'] ?? null;
        if ($content === null) {
            return null;
        }

        $schema = $content['schema'] ?? [];
        $example = $content['example'] ?? null;

        // Resolve $ref if present
        if (isset($schema['$ref'])) {
            $schema = $this->resolveRef($schema['$ref']);
        }

        // Generate example if not provided
        if ($example === null && ! empty($schema)) {
            $example = $this->generateExample($schema);
        }

        return [
            'required' => $requestBody['required'] ?? true,
            'schema' => $schema,
            'example' => $example,
            'exampleJson' => $example ? json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null,
        ];
    }

    /**
     * Process responses for display.
     */
    protected function processResponses(array $responses): array
    {
        $processed = [];

        foreach ($responses as $code => $response) {
            $content = $response['content']['application/json'] ?? null;
            $schema = null;
            $example = null;

            if ($content) {
                $schema = $content['schema'] ?? [];

                // Resolve $ref if present
                if (isset($schema['$ref'])) {
                    $schema = $this->resolveRef($schema['$ref']);
                }

                $example = $content['example'] ?? null;
                if ($example === null && ! empty($schema)) {
                    $example = $this->generateExample($schema);
                }
            }

            $processed[$code] = [
                'description' => $response['description'] ?? '',
                'schema' => $schema,
                'example' => $example,
                'exampleJson' => $example ? json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null,
            ];
        }

        return $processed;
    }

    /**
     * Resolve a $ref to its schema.
     */
    public function resolveRef(string $ref): array
    {
        $spec = $this->getSpec();

        if (! str_starts_with($ref, '#/')) {
            return [];
        }

        $path = explode('/', substr($ref, 2));
        $current = $spec;

        foreach ($path as $segment) {
            if (! isset($current[$segment])) {
                return [];
            }
            $current = $current[$segment];
        }

        return is_array($current) ? $current : [];
    }

    /**
     * Get a schema by name.
     */
    public function getSchema(string $name): ?array
    {
        $spec = $this->getSpec();

        return $spec['components']['schemas'][$name] ?? null;
    }

    /**
     * Get all schemas.
     */
    public function getAllSchemas(): array
    {
        $spec = $this->getSpec();

        return $spec['components']['schemas'] ?? [];
    }

    /**
     * Generate example from schema.
     */
    public function generateExample(array $schema): mixed
    {
        // Handle $ref
        if (isset($schema['$ref'])) {
            $schema = $this->resolveRef($schema['$ref']);
        }

        // Handle different types
        $type = $schema['type'] ?? 'object';

        if (isset($schema['example'])) {
            return $schema['example'];
        }

        if (isset($schema['enum'])) {
            return $schema['enum'][0];
        }

        return match ($type) {
            'object' => $this->generateObjectExample($schema),
            'array' => $this->generateArrayExample($schema),
            'integer' => 1,
            'number' => 1.0,
            'boolean' => true,
            'string' => $this->generateStringExample($schema),
            default => null,
        };
    }

    /**
     * Generate object example.
     */
    protected function generateObjectExample(array $schema): array
    {
        $example = [];
        $properties = $schema['properties'] ?? [];

        foreach ($properties as $name => $prop) {
            // Handle nested $ref
            if (isset($prop['$ref'])) {
                $prop = $this->resolveRef($prop['$ref']);
            }

            $example[$name] = $this->generateExample($prop);
        }

        return $example;
    }

    /**
     * Generate array example.
     */
    protected function generateArrayExample(array $schema): array
    {
        $items = $schema['items'] ?? ['type' => 'string'];

        return [$this->generateExample($items)];
    }

    /**
     * Generate string example.
     */
    protected function generateStringExample(array $schema): string
    {
        $format = $schema['format'] ?? null;

        return match ($format) {
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'uri' => 'https://example.com',
            'email' => 'user@example.com',
            'date-time' => '2024-01-15T10:30:00Z',
            'date' => '2024-01-15',
            default => 'string',
        };
    }

    /**
     * Get authentication info.
     */
    public function getAuthInfo(): array
    {
        $spec = $this->getSpec();
        $securitySchemes = $spec['components']['securitySchemes'] ?? [];

        return [
            'type' => 'Bearer Token',
            'header' => 'Authorization',
            'format' => 'Bearer {api_key}',
            'description' => $securitySchemes['bearerAuth']['description'] ?? 'API key from the Developer portal',
        ];
    }

    /**
     * Get server URLs.
     */
    public function getServers(): array
    {
        $spec = $this->getSpec();

        return $spec['servers'] ?? [];
    }

    /**
     * Get statistics.
     */
    public function getStatistics(): array
    {
        $spec = $this->getSpec();
        $paths = $spec['paths'] ?? [];

        $endpointCount = 0;
        $methodCounts = [];

        foreach ($paths as $methods) {
            foreach ($methods as $method => $operation) {
                $endpointCount++;
                $methodCounts[$method] = ($methodCounts[$method] ?? 0) + 1;
            }
        }

        return [
            'paths' => count($paths),
            'endpoints' => $endpointCount,
            'schemas' => count($spec['components']['schemas'] ?? []),
            'tags' => count($spec['tags'] ?? []),
            'methods' => $methodCounts,
        ];
    }

    /**
     * Format schema for display.
     */
    public function formatSchemaForDisplay(array $schema, int $indent = 0): string
    {
        $output = '';
        $indentStr = str_repeat('  ', $indent);

        foreach ($schema['properties'] ?? [] as $name => $prop) {
            $type = $prop['type'] ?? 'any';
            $required = in_array($name, $schema['required'] ?? []);
            $nullable = $prop['nullable'] ?? false;

            $line = $indentStr.$name.': '.$type;

            if ($required) {
                $line .= ' (required)';
            }
            if ($nullable) {
                $line .= ' (nullable)';
            }
            if (isset($prop['format'])) {
                $line .= ' ['.$prop['format'].']';
            }
            if (isset($prop['enum'])) {
                $line .= ' enum: '.implode(', ', $prop['enum']);
            }

            $output .= $line."\n";

            // Handle nested objects
            if ($type === 'object' && isset($prop['properties'])) {
                $output .= $this->formatSchemaForDisplay($prop, $indent + 1);
            }

            // Handle arrays
            if ($type === 'array' && isset($prop['items'])) {
                $itemType = $prop['items']['type'] ?? 'any';
                if ($itemType === 'object' && isset($prop['items']['properties'])) {
                    $output .= $indentStr."  (array items):\n";
                    $output .= $this->formatSchemaForDisplay($prop['items'], $indent + 2);
                }
            }
        }

        return $output;
    }
}
