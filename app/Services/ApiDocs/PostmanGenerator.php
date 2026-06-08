<?php

namespace App\Services\ApiDocs;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class PostmanGenerator
{
    protected array $config;

    protected array $spec = [];

    public function __construct()
    {
        $this->config = config('argusly-docs', []);
    }

    /**
     * Generate Postman collection from OpenAPI spec.
     */
    public function generateFromFile(string $openApiPath): array
    {
        $this->spec = $this->parseOpenApiFile($openApiPath);

        return $this->generateCollection();
    }

    /**
     * Generate Postman collection from OpenAPI array.
     */
    public function generateFromSpec(array $spec): array
    {
        $this->spec = $spec;

        return $this->generateCollection();
    }

    /**
     * Parse OpenAPI file.
     */
    protected function parseOpenApiFile(string $path): array
    {
        $fullPath = base_path($path);

        if (! File::exists($fullPath)) {
            throw new \RuntimeException("OpenAPI file not found: {$fullPath}");
        }

        $content = File::get($fullPath);

        // Detect format and parse
        if (Str::endsWith($path, '.json')) {
            return json_decode($content, true);
        }

        return Yaml::parse($content);
    }

    /**
     * Generate the Postman collection.
     */
    public function generateCollection(): array
    {
        $info = $this->spec['info'] ?? [];
        $paths = $this->spec['paths'] ?? [];

        return [
            'info' => [
                'name' => $this->config['postman']['collection_name'] ?? $info['title'] ?? 'API Collection',
                'description' => $info['description'] ?? '',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => [
                'type' => 'bearer',
                'bearer' => [
                    [
                        'key' => 'token',
                        'value' => '{{workspace_api_key}}',
                        'type' => 'string',
                    ],
                ],
            ],
            'variable' => [
                [
                    'key' => 'base_url',
                    'value' => $this->getBaseUrl(),
                    'type' => 'string',
                ],
            ],
            'item' => $this->buildFolders($paths),
        ];
    }

    /**
     * Generate Postman environment.
     */
    public function generateEnvironment(): array
    {
        $variables = $this->config['postman']['variables'] ?? [
            'base_url' => $this->getBaseUrl(),
            'workspace_api_key' => '',
        ];

        $values = [];
        foreach ($variables as $key => $value) {
            $values[] = [
                'key' => $key,
                'value' => $value,
                'type' => 'default',
                'enabled' => true,
            ];
        }

        return [
            'id' => Str::uuid()->toString(),
            'name' => $this->config['postman']['environment_name'] ?? 'Argusly API',
            'values' => $values,
            '_postman_variable_scope' => 'environment',
        ];
    }

    /**
     * Get base URL from spec.
     */
    protected function getBaseUrl(): string
    {
        $servers = $this->spec['servers'] ?? [];

        if (! empty($servers)) {
            return $servers[0]['url'] ?? 'https://api.argusly.com/api/v1';
        }

        return 'https://api.argusly.com/api/v1';
    }

    /**
     * Build Postman folders from paths.
     */
    protected function buildFolders(array $paths): array
    {
        $folders = [];
        $taggedItems = [];

        // Group by tags
        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $tags = $operation['tags'] ?? ['General'];
                $tag = $tags[0];

                if (! isset($taggedItems[$tag])) {
                    $taggedItems[$tag] = [];
                }

                $taggedItems[$tag][] = $this->buildRequest($path, $method, $operation);
            }
        }

        // Build folder structure
        foreach ($taggedItems as $tag => $items) {
            $folders[] = [
                'name' => $tag,
                'item' => $items,
            ];
        }

        // Sort folders alphabetically
        usort($folders, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $folders;
    }

    /**
     * Build a single Postman request.
     */
    protected function buildRequest(string $path, string $method, array $operation): array
    {
        $request = [
            'name' => $operation['summary'] ?? "{$method} {$path}",
            'request' => [
                'method' => strtoupper($method),
                'header' => $this->buildHeaders(),
                'url' => $this->buildUrl($path, $operation),
            ],
        ];

        // Add description
        if (isset($operation['description'])) {
            $request['request']['description'] = $operation['description'];
        }

        // Add request body
        if (isset($operation['requestBody'])) {
            $request['request']['body'] = $this->buildRequestBody($operation['requestBody']);
        }

        // Add response examples
        $request['response'] = $this->buildResponseExamples($operation);

        return $request;
    }

    /**
     * Build request headers.
     */
    protected function buildHeaders(): array
    {
        return [
            [
                'key' => 'Content-Type',
                'value' => 'application/json',
                'type' => 'text',
            ],
            [
                'key' => 'Accept',
                'value' => 'application/json',
                'type' => 'text',
            ],
        ];
    }

    /**
     * Build Postman URL structure.
     */
    protected function buildUrl(string $path, array $operation): array
    {
        // Convert OpenAPI path params to Postman format
        $postmanPath = preg_replace('/\{([^}]+)\}/', ':$1', $path);
        $pathSegments = explode('/', trim($postmanPath, '/'));

        $url = [
            'raw' => '{{base_url}}'.$postmanPath,
            'host' => ['{{base_url}}'],
            'path' => $pathSegments,
        ];

        // Add path variables
        $variables = [];
        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        foreach ($matches[1] as $param) {
            $variables[] = [
                'key' => $param,
                'value' => $this->getVariableValue($param),
                'description' => $this->getParameterDescription($param, $operation),
            ];
        }
        if (! empty($variables)) {
            $url['variable'] = $variables;
        }

        // Add query parameters
        $queryParams = $this->extractQueryParameters($operation);
        if (! empty($queryParams)) {
            $url['query'] = $queryParams;
        }

        return $url;
    }

    /**
     * Get variable value for path parameter.
     */
    protected function getVariableValue(string $param): string
    {
        $variableMap = [
            'id' => '{{brief_id}}',
            'destination' => '{{destination_id}}',
            'apiKey' => '{{api_key_id}}',
            'webhook' => '{{webhook_id}}',
            'operation' => '{{operation_id}}',
            'audit' => '{{seo_audit_id}}',
        ];

        return $variableMap[$param] ?? '';
    }

    /**
     * Get parameter description.
     */
    protected function getParameterDescription(string $param, array $operation): string
    {
        $parameters = $operation['parameters'] ?? [];

        foreach ($parameters as $p) {
            if ($p['name'] === $param) {
                return $p['description'] ?? '';
            }
        }

        return '';
    }

    /**
     * Extract query parameters from operation.
     */
    protected function extractQueryParameters(array $operation): array
    {
        $parameters = $operation['parameters'] ?? [];
        $query = [];

        foreach ($parameters as $param) {
            if (($param['in'] ?? '') === 'query') {
                $query[] = [
                    'key' => $param['name'],
                    'value' => $this->getDefaultQueryValue($param),
                    'description' => $param['description'] ?? '',
                    'disabled' => ! ($param['required'] ?? false),
                ];
            }
        }

        return $query;
    }

    /**
     * Get default value for query parameter.
     */
    protected function getDefaultQueryValue(array $param): string
    {
        $schema = $param['schema'] ?? [];

        if (isset($schema['default'])) {
            return (string) $schema['default'];
        }

        if (isset($schema['enum'])) {
            return $schema['enum'][0];
        }

        return '';
    }

    /**
     * Build request body.
     */
    protected function buildRequestBody(array $requestBody): array
    {
        $content = $requestBody['content']['application/json'] ?? [];

        $body = [
            'mode' => 'raw',
            'raw' => '',
            'options' => [
                'raw' => [
                    'language' => 'json',
                ],
            ],
        ];

        // Use example if available
        if (isset($content['example'])) {
            $body['raw'] = json_encode($content['example'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } elseif (isset($content['schema'])) {
            // Generate from schema
            $example = $this->generateExampleFromSchema($content['schema']);
            $body['raw'] = json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $body;
    }

    /**
     * Generate example from OpenAPI schema.
     */
    protected function generateExampleFromSchema(array $schema): array
    {
        // Handle $ref
        if (isset($schema['$ref'])) {
            $schema = $this->resolveRef($schema['$ref']);
        }

        if (($schema['type'] ?? '') !== 'object') {
            return [];
        }

        $example = [];
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        foreach ($properties as $name => $prop) {
            // Include required fields and some optional ones
            if (in_array($name, $required) || rand(0, 1) === 1) {
                $example[$name] = $this->generatePropertyExample($name, $prop);
            }
        }

        return $example;
    }

    /**
     * Generate example for a property.
     */
    protected function generatePropertyExample(string $name, array $prop): mixed
    {
        // Handle $ref
        if (isset($prop['$ref'])) {
            $prop = $this->resolveRef($prop['$ref']);
        }

        if (isset($prop['example'])) {
            return $prop['example'];
        }

        if (isset($prop['enum'])) {
            return $prop['enum'][0];
        }

        $type = $prop['type'] ?? 'string';

        return match ($type) {
            'integer' => 1,
            'number' => 1.0,
            'boolean' => true,
            'array' => $this->generateArrayExample($prop),
            'object' => $this->generateExampleFromSchema($prop),
            default => $this->generateStringExample($name, $prop),
        };
    }

    /**
     * Generate array example.
     */
    protected function generateArrayExample(array $prop): array
    {
        $items = $prop['items'] ?? ['type' => 'string'];

        return [$this->generatePropertyExample('item', $items)];
    }

    /**
     * Generate string example.
     */
    protected function generateStringExample(string $name, array $prop): string
    {
        $format = $prop['format'] ?? null;

        return match ($format) {
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'uri' => 'https://example.com',
            'email' => 'user@example.com',
            'date-time' => '2024-01-15T10:30:00Z',
            'date' => '2024-01-15',
            default => $this->getNameBasedExample($name),
        };
    }

    /**
     * Get example based on field name.
     */
    protected function getNameBasedExample(string $name): string
    {
        return match (true) {
            str_contains($name, 'title') => 'Example Title',
            str_contains($name, 'name') => 'Example Name',
            str_contains($name, 'url') => 'https://example.com',
            str_contains($name, 'email') => 'user@example.com',
            str_contains($name, 'language') => 'en',
            str_contains($name, 'keyword') => 'example keyword',
            str_contains($name, 'secret') => 'your-secret-value-here',
            str_contains($name, 'type') => 'blog',
            default => 'example',
        };
    }

    /**
     * Resolve a $ref to its schema.
     */
    protected function resolveRef(string $ref): array
    {
        if (! str_starts_with($ref, '#/')) {
            return [];
        }

        $path = explode('/', substr($ref, 2));
        $current = $this->spec;

        foreach ($path as $segment) {
            if (! isset($current[$segment])) {
                return [];
            }
            $current = $current[$segment];
        }

        return is_array($current) ? $current : [];
    }

    /**
     * Build response examples.
     */
    protected function buildResponseExamples(array $operation): array
    {
        $responses = [];
        $operationResponses = $operation['responses'] ?? [];

        foreach ($operationResponses as $code => $response) {
            if (! isset($response['content']['application/json']['schema'])) {
                continue;
            }

            $schema = $response['content']['application/json']['schema'];
            $example = $this->generateExampleFromSchema($schema);

            $responses[] = [
                'name' => $response['description'] ?? "Response {$code}",
                'status' => (string) $code,
                'code' => (int) $code,
                'header' => [
                    [
                        'key' => 'Content-Type',
                        'value' => 'application/json',
                    ],
                ],
                'body' => json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ];
        }

        return $responses;
    }

    /**
     * Write collection to file.
     */
    public function writeCollection(array $collection, string $outputPath): string
    {
        $fullPath = base_path($outputPath);
        $directory = dirname($fullPath);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($fullPath, json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $fullPath;
    }

    /**
     * Write environment to file.
     */
    public function writeEnvironment(array $environment, string $outputPath): string
    {
        $fullPath = base_path($outputPath);
        $directory = dirname($fullPath);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($fullPath, json_encode($environment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $fullPath;
    }
}
