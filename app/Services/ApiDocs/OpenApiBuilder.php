<?php

namespace App\Services\ApiDocs;

class OpenApiBuilder
{
    protected array $config;

    protected FormRequestSchemaExtractor $requestExtractor;

    protected ApiResourceSchemaExtractor $resourceExtractor;

    protected RouteDocExtractor $routeExtractor;

    protected array $spec = [];

    protected array $usedSchemas = [];

    public function __construct(
        FormRequestSchemaExtractor $requestExtractor,
        ApiResourceSchemaExtractor $resourceExtractor,
        RouteDocExtractor $routeExtractor
    ) {
        $this->requestExtractor = $requestExtractor;
        $this->resourceExtractor = $resourceExtractor;
        $this->routeExtractor = $routeExtractor;
        $this->config = config('publishlayer-docs', []);
    }

    /**
     * Build the complete OpenAPI specification.
     */
    public function build(array $routes): array
    {
        $this->spec = [];
        $this->usedSchemas = [];

        // Build base structure
        $this->buildInfo();
        $this->buildServers();
        $this->buildSecurity();
        $this->buildTags();
        $this->buildPaths($routes);
        $this->buildComponents();

        return $this->spec;
    }

    /**
     * Build OpenAPI info section.
     */
    protected function buildInfo(): void
    {
        $info = $this->config['openapi']['info'] ?? [];

        $this->spec['openapi'] = '3.1.0';
        $this->spec['info'] = [
            'title' => $info['title'] ?? 'PublishLayer API',
            'version' => $info['version'] ?? '1.0.0',
            'description' => $info['description'] ?? '',
        ];
    }

    /**
     * Build servers section.
     */
    protected function buildServers(): void
    {
        $this->spec['servers'] = $this->config['openapi']['servers'] ?? [
            ['url' => 'https://api.publishlayer.com/api/v1'],
        ];
    }

    /**
     * Build security section.
     */
    protected function buildSecurity(): void
    {
        $this->spec['security'] = [
            ['bearerAuth' => []],
        ];
    }

    /**
     * Build tags section.
     */
    protected function buildTags(): void
    {
        $this->spec['tags'] = $this->config['tags'] ?? [];
    }

    /**
     * Build paths section from routes.
     */
    protected function buildPaths(array $routes): void
    {
        $paths = [];

        foreach ($routes as $route) {
            $path = $route['path'];
            $method = $route['method'];

            if (! isset($paths[$path])) {
                $paths[$path] = [];
            }

            $paths[$path][$method] = $this->buildPathOperation($route);
        }

        $this->spec['paths'] = $paths;
    }

    /**
     * Build a single path operation.
     */
    protected function buildPathOperation(array $route): array
    {
        $operation = [
            'summary' => $this->buildSummary($route),
            'tags' => [$route['tag']],
            'operationId' => $this->buildOperationId($route),
        ];

        // Add description
        $description = $this->routeExtractor->getEndpointDescription($route['method'], $route['path']);
        if ($description) {
            $operation['description'] = $description;
        }

        // Add security scopes
        if (! empty($route['scopes'])) {
            $operation['security'] = [
                ['bearerAuth' => $route['scopes']],
            ];
        }

        // Add path parameters
        if (! empty($route['parameters'])) {
            $operation['parameters'] = $this->buildParameters($route['parameters']);
        }

        // Add query parameters for index/list endpoints
        if ($route['method'] === 'get' && in_array($route['action'], ['index', 'show'])) {
            $queryParams = $this->buildQueryParameters($route);
            if (! empty($queryParams)) {
                $operation['parameters'] = array_merge(
                    $operation['parameters'] ?? [],
                    $queryParams
                );
            }
        }

        // Add request body
        if (in_array($route['method'], ['post', 'put', 'patch'])) {
            $requestBody = $this->buildRequestBody($route);
            if ($requestBody) {
                $operation['requestBody'] = $requestBody;
            }
        }

        // Add responses
        $operation['responses'] = $this->buildResponses($route);

        return $operation;
    }

    /**
     * Build operation summary.
     */
    protected function buildSummary(array $route): string
    {
        $action = $route['action'] ?? '';
        $tag = $route['tag'];

        return match ($action) {
            'index' => "List {$tag}",
            'show' => "Get {$tag} details",
            'store' => "Create {$tag}",
            'update' => "Update {$tag}",
            'destroy' => "Delete {$tag}",
            'me' => 'Get identity',
            'usage' => 'Get usage',
            'generate' => 'Generate draft',
            'regenerate' => 'Regenerate draft',
            'translate' => 'Translate draft',
            'export' => 'Export draft',
            'generateDraft' => 'Queue draft generation',
            'revoke' => 'Revoke API key',
            'ack' => 'Acknowledge draft',
            'feedback' => 'Submit feedback',
            'intents' => 'List intents',
            'audiences' => 'List audiences',
            'quote' => 'Get credit quote',
            default => ucfirst(str_replace(['_', '-'], ' ', $action ?: $route['method'])),
        };
    }

    /**
     * Build operation ID.
     */
    protected function buildOperationId(array $route): string
    {
        $path = str_replace(['/', '{', '}', '-'], ['_', '', '', '_'], $route['path']);
        $path = trim($path, '_');

        return $route['method'].'_'.strtolower($path);
    }

    /**
     * Build path parameters.
     */
    protected function buildParameters(array $params): array
    {
        $result = [];

        foreach ($params as $param) {
            $result[] = [
                'name' => $param['name'],
                'in' => $param['in'],
                'required' => $param['required'],
                'schema' => $param['schema'],
                'description' => $this->getParameterDescription($param['name']),
            ];
        }

        return $result;
    }

    /**
     * Get parameter description.
     */
    protected function getParameterDescription(string $name): string
    {
        return match ($name) {
            'id' => 'Resource ID',
            'destination' => 'Content destination ID',
            'apiKey' => 'API key ID',
            'webhook' => 'Webhook ID',
            'operation' => 'Async operation ID',
            'audit' => 'SEO audit ID',
            default => ucfirst(str_replace(['_', '-'], ' ', $name)),
        };
    }

    /**
     * Build query parameters for list endpoints.
     */
    protected function buildQueryParameters(array $route): array
    {
        $params = [];

        // Common pagination params for index actions
        if ($route['action'] === 'index') {
            $params[] = [
                'name' => 'page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'description' => 'Page number',
            ];

            $params[] = [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 15],
                'description' => 'Items per page',
            ];

            // Status filter for briefs and drafts
            if (str_contains($route['path'], 'briefs') || str_contains($route['path'], 'drafts')) {
                $params[] = [
                    'name' => 'status',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string'],
                    'description' => 'Filter by status',
                ];
            }
        }

        // Export format param
        if ($route['action'] === 'export') {
            $params[] = [
                'name' => 'format',
                'in' => 'query',
                'required' => false,
                'schema' => [
                    'type' => 'string',
                    'enum' => ['json', 'html', 'markdown', 'text'],
                    'default' => 'json',
                ],
                'description' => 'Export format',
            ];
        }

        return $params;
    }

    /**
     * Build request body.
     */
    protected function buildRequestBody(array $route): ?array
    {
        $controllerAction = $route['controller_action'];
        if (! $controllerAction) {
            return null;
        }

        $requestClass = $this->routeExtractor->getRequestClass($controllerAction);
        if (! $requestClass) {
            return null;
        }

        $schema = $this->requestExtractor->extract($requestClass);
        if (empty($schema['properties'])) {
            return null;
        }

        // Generate schema name
        $schemaName = $this->getSchemaNameFromClass($requestClass);
        $this->usedSchemas[$schemaName] = $schema;

        // Add example
        $example = $this->generateRequestExample($route, $schema);

        return [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => "#/components/schemas/{$schemaName}"],
                    'example' => $example,
                ],
            ],
        ];
    }

    /**
     * Build responses.
     */
    protected function buildResponses(array $route): array
    {
        $responses = [];

        // Success response
        $successCode = $this->getSuccessCode($route['method']);
        $responses[$successCode] = $this->buildSuccessResponse($route);

        // Error responses
        $responses['401'] = [
            'description' => 'Unauthorized - Invalid or missing API key',
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                ],
            ],
        ];

        $responses['403'] = [
            'description' => 'Forbidden - Insufficient scope permissions',
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                ],
            ],
        ];

        if (in_array($route['method'], ['post', 'put', 'patch'])) {
            $responses['422'] = [
                'description' => 'Validation error',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                    ],
                ],
            ];
        }

        if (! empty($route['parameters'])) {
            $responses['404'] = [
                'description' => 'Resource not found',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                    ],
                ],
            ];
        }

        return $responses;
    }

    /**
     * Build success response.
     */
    protected function buildSuccessResponse(array $route): array
    {
        $controllerAction = $route['controller_action'];
        $description = $this->getSuccessDescription($route);

        $response = ['description' => $description];

        // Get response resource
        if ($controllerAction) {
            $resourceClass = $this->routeExtractor->getResponseResource($controllerAction);
            if ($resourceClass) {
                $schemaName = $this->resourceExtractor->getSchemaName($resourceClass);
                $this->usedSchemas[$schemaName] = $this->resourceExtractor->extract($resourceClass);

                $isCollection = $route['action'] === 'index';

                $response['content'] = [
                    'application/json' => [
                        'schema' => $this->wrapResponseSchema($schemaName, $isCollection),
                    ],
                ];
            }
        }

        return $response;
    }

    /**
     * Wrap response in data envelope.
     */
    protected function wrapResponseSchema(string $schemaName, bool $isCollection): array
    {
        if ($isCollection) {
            return [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => "#/components/schemas/{$schemaName}"],
                    ],
                    'links' => [
                        'type' => 'object',
                        'properties' => [
                            'first' => ['type' => 'string', 'format' => 'uri'],
                            'last' => ['type' => 'string', 'format' => 'uri'],
                            'prev' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                            'next' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                        ],
                    ],
                    'meta' => [
                        'type' => 'object',
                        'properties' => [
                            'current_page' => ['type' => 'integer'],
                            'last_page' => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                            'total' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ];
        }

        return [
            'type' => 'object',
            'properties' => [
                'data' => ['$ref' => "#/components/schemas/{$schemaName}"],
            ],
        ];
    }

    /**
     * Get success status code.
     */
    protected function getSuccessCode(string $method): string
    {
        return match ($method) {
            'post' => '201',
            'delete' => '204',
            default => '200',
        };
    }

    /**
     * Get success description.
     */
    protected function getSuccessDescription(array $route): string
    {
        $action = $route['action'] ?? '';

        // Check for async operations
        if (in_array($action, ['generateDraft', 'regenerate', 'translate', 'store']) &&
            (str_contains($route['path'], 'generate') || str_contains($route['path'], 'seo-audit'))) {
            return 'Operation queued successfully';
        }

        return match ($route['method']) {
            'post' => 'Created successfully',
            'put', 'patch' => 'Updated successfully',
            'delete' => 'Deleted successfully',
            default => 'Success',
        };
    }

    /**
     * Build components section.
     */
    protected function buildComponents(): void
    {
        // Add security schemes
        $this->spec['components'] = [
            'securitySchemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'API Key',
                    'description' => 'Workspace API key obtained from the Developer portal',
                ],
            ],
            'schemas' => [],
        ];

        // Add error response schema
        $this->usedSchemas['ErrorResponse'] = $this->resourceExtractor->extract('App\Http\Resources\Api\V1\ErrorResource');

        // Add all used schemas
        foreach ($this->usedSchemas as $name => $schema) {
            $this->spec['components']['schemas'][$name] = $schema;
        }

        // Add any remaining resource schemas that might be needed
        $allSchemas = $this->resourceExtractor->getAllSchemas();
        foreach ($allSchemas as $name => $schema) {
            if (! isset($this->spec['components']['schemas'][$name])) {
                $this->spec['components']['schemas'][$name] = $schema;
            }
        }
    }

    /**
     * Get schema name from class name.
     */
    protected function getSchemaNameFromClass(string $class): string
    {
        $parts = explode('\\', $class);
        $name = end($parts);

        return str_replace('Request', '', $name);
    }

    /**
     * Generate example request payload.
     */
    protected function generateRequestExample(array $route, array $schema): array
    {
        // Predefined examples for common operations
        $examples = [
            'CreateBrief' => [
                'title' => 'AI integration in logistics',
                'primary_keyword' => 'AI logistics',
                'secondary_keywords' => ['machine learning logistics', 'AI supply chain'],
                'language' => 'en',
                'content_type' => 'blog',
                'audience' => 'technical',
                'audience_details' => 'CTOs and engineering leaders evaluating operational AI adoption.',
                'target_audience' => 'CTOs and engineering leaders',
                'tone_of_voice' => 'professional',
                'desired_length_min' => 900,
                'desired_length_max' => 1200,
            ],
            'UpdateBrief' => [
                'title' => 'Updated title',
                'notes' => 'Additional context for the AI',
            ],
            'CreateDestination' => [
                'name' => 'Production Blog',
                'type' => 'api',
                'environment' => 'production',
                'default_language' => 'en',
                'tracking_enabled' => true,
            ],
            'CreateWebhook' => [
                'name' => 'Draft notifications',
                'target_url' => 'https://example.com/webhooks/publishlayer',
                'secret' => 'your-webhook-signing-secret-min-16-chars',
                'events' => ['draft.generation.completed', 'draft.generation.failed'],
                'is_active' => true,
            ],
            'CreateApiKey' => [
                'name' => 'Production API Key',
                'scopes' => ['briefs:read', 'briefs:write', 'drafts:read'],
            ],
            'TranslateDraft' => [
                'target_language' => 'es',
            ],
            'IngestAnalyticsEvents' => [
                'events' => [
                    [
                        'event_type' => 'page_view',
                        'page_url' => 'https://example.com/blog/ai-logistics',
                        'timestamp' => '2024-01-15T10:30:00Z',
                        'article_identifier' => 'blog-123',
                    ],
                ],
            ],
        ];

        $schemaName = $this->getSchemaNameFromClass($route['controller_action'] ?? '');
        $schemaName = str_replace('@', '', $schemaName);

        // Map controller action to example key
        $action = $route['action'] ?? '';
        $exampleKey = match ($action) {
            'store' => 'Create'.ucfirst(str_replace(['/', '{', '}'], '', $route['path'])),
            default => ucfirst($action).$schemaName,
        };

        // Try to find a matching example
        foreach ($examples as $key => $example) {
            if (stripos($exampleKey, str_replace('Create', '', $key)) !== false ||
                stripos($key, str_replace('Create', '', $exampleKey)) !== false) {
                return $this->filterExampleBySchema($example, $schema);
            }
        }

        // Generate example from schema
        return $this->generateExampleFromSchema($schema);
    }

    /**
     * Filter example to only include properties in schema.
     */
    protected function filterExampleBySchema(array $example, array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        $filtered = [];

        foreach ($example as $key => $value) {
            if (isset($properties[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Generate example from schema.
     */
    protected function generateExampleFromSchema(array $schema): array
    {
        $example = [];
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        foreach ($properties as $name => $prop) {
            // Only include required fields in auto-generated examples
            if (! in_array($name, $required)) {
                continue;
            }

            $example[$name] = $this->generateExampleValue($name, $prop);
        }

        return $example;
    }

    /**
     * Generate example value for a property.
     */
    protected function generateExampleValue(string $name, array $prop): mixed
    {
        $type = $prop['type'] ?? 'string';

        if (isset($prop['enum'])) {
            return $prop['enum'][0];
        }

        if (isset($prop['example'])) {
            return $prop['example'];
        }

        return match ($type) {
            'integer' => 100,
            'number' => 10.5,
            'boolean' => true,
            'array' => [],
            'object' => (object) [],
            default => $this->generateStringExample($name, $prop),
        };
    }

    /**
     * Generate string example based on name and format.
     */
    protected function generateStringExample(string $name, array $prop): string
    {
        $format = $prop['format'] ?? null;

        if ($format === 'uuid') {
            return '550e8400-e29b-41d4-a716-446655440000';
        }

        if ($format === 'uri') {
            return 'https://example.com';
        }

        if ($format === 'email') {
            return 'user@example.com';
        }

        if ($format === 'date-time') {
            return '2024-01-15T10:30:00Z';
        }

        if ($format === 'date') {
            return '2024-01-15';
        }

        // Name-based examples
        return match (true) {
            str_contains($name, 'title') => 'Example Title',
            str_contains($name, 'name') => 'Example Name',
            str_contains($name, 'url') => 'https://example.com',
            str_contains($name, 'email') => 'user@example.com',
            str_contains($name, 'language') => 'en',
            str_contains($name, 'keyword') => 'example keyword',
            str_contains($name, 'secret') => 'your-secret-value-here',
            default => 'string',
        };
    }
}
