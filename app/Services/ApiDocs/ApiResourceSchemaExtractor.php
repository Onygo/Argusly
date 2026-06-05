<?php

namespace App\Services\ApiDocs;

class ApiResourceSchemaExtractor
{
    /**
     * Predefined schemas for API Resources.
     * These are manually defined since runtime introspection of toArray() is unreliable.
     */
    protected array $schemas = [];

    public function __construct()
    {
        $this->schemas = $this->defineSchemas();
    }

    /**
     * Extract OpenAPI schema for a resource class.
     */
    public function extract(string $resourceClass): array
    {
        $shortName = $this->getSchemaName($resourceClass);

        return $this->schemas[$shortName] ?? $this->defaultSchema();
    }

    /**
     * Get the schema name from a resource class.
     */
    public function getSchemaName(string $resourceClass): string
    {
        $parts = explode('\\', $resourceClass);
        $name = end($parts);

        return str_replace('Resource', '', $name);
    }

    /**
     * Get all defined schemas.
     */
    public function getAllSchemas(): array
    {
        return $this->schemas;
    }

    /**
     * Define all resource schemas.
     */
    protected function defineSchemas(): array
    {
        return [
            'Brief' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'client_site_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'content_destination_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'content_id' => ['type' => 'string', 'nullable' => true],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'ready', 'generating', 'completed', 'failed']],
                    'source' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'language' => ['type' => 'string'],
                    'content_type' => ['type' => 'string', 'nullable' => true],
                    'intent' => ['type' => 'string', 'nullable' => true],
                    'primary_keyword' => ['type' => 'string', 'nullable' => true],
                    'secondary_keywords' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'audience' => ['type' => 'string', 'nullable' => true],
                    'audience_details' => ['type' => 'string', 'nullable' => true],
                    'target_audience' => ['type' => 'string', 'nullable' => true],
                    'funnel_stage' => ['type' => 'string', 'nullable' => true],
                    'search_intent' => ['type' => 'string', 'nullable' => true],
                    'notes' => ['type' => 'string', 'nullable' => true],
                    'client_refs' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],

            'Draft' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'brief_id' => ['type' => 'string', 'format' => 'uuid'],
                    'content_id' => ['type' => 'string', 'nullable' => true],
                    'client_site_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'content_destination_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'status' => ['type' => 'string', 'enum' => ['pending', 'generating', 'ready', 'published', 'failed']],
                    'title' => ['type' => 'string'],
                    'language' => ['type' => 'string'],
                    'output_type' => ['type' => 'string'],
                    'content_html' => ['type' => 'string', 'nullable' => true],
                    'seo' => [
                        'type' => 'object',
                        'properties' => [
                            'slug' => ['type' => 'string', 'nullable' => true],
                            'meta_title' => ['type' => 'string', 'nullable' => true],
                            'meta_description' => ['type' => 'string', 'nullable' => true],
                            'canonical_url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                        ],
                    ],
                    'summary' => [
                        'type' => 'object',
                        'properties' => [
                            'excerpt' => ['type' => 'string', 'nullable' => true],
                            'key_takeaways' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'cta' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string', 'nullable' => true],
                            'url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                        ],
                    ],
                    'usage' => [
                        'type' => 'object',
                        'properties' => [
                            'credits_used' => ['type' => 'integer'],
                        ],
                    ],
                    'timestamps' => [
                        'type' => 'object',
                        'properties' => [
                            'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                ],
            ],

            'ContentDestination' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'name' => ['type' => 'string'],
                    'type' => ['type' => 'string', 'enum' => ['api', 'wordpress', 'laravel']],
                    'status' => ['type' => 'string', 'enum' => ['active', 'disabled']],
                    'environment' => ['type' => 'string', 'enum' => ['production', 'staging', 'development']],
                    'default_language' => ['type' => 'string', 'nullable' => true],
                    'default_content_type' => ['type' => 'string', 'nullable' => true],
                    'export_format' => ['type' => 'string', 'enum' => ['json', 'html', 'markdown', 'text'], 'nullable' => true],
                    'tracking_enabled' => ['type' => 'boolean'],
                    'seo_audit_enabled' => ['type' => 'boolean'],
                    'webhook_url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                    'config' => ['type' => 'object', 'additionalProperties' => true],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                    'last_used_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                ],
            ],

            'ApiKey' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'content_destination_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'name' => ['type' => 'string'],
                    'key_prefix' => ['type' => 'string', 'description' => 'First characters of the key for identification'],
                    'key' => ['type' => 'string', 'description' => 'Full API key (only returned on creation)'],
                    'scopes' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'last_used_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'revoked_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],

            'ApiWebhook' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'content_destination_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'name' => ['type' => 'string'],
                    'target_url' => ['type' => 'string', 'format' => 'uri'],
                    'events' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'is_active' => ['type' => 'boolean'],
                    'last_delivered_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'last_failure_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],

            'SeoAudit' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'client_site_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'content_destination_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'status' => ['type' => 'string', 'enum' => ['pending', 'running', 'completed', 'failed']],
                    'pages_crawled' => ['type' => 'integer'],
                    'issue_counts' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'integer'],
                    ],
                    'error_message' => ['type' => 'string', 'nullable' => true],
                    'started_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'finished_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],

            'AsyncOperation' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'content_destination_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'api_key_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'operation_type' => ['type' => 'string', 'enum' => ['draft_generation', 'draft_regeneration', 'translation', 'seo_audit', 'image_generation']],
                    'status' => ['type' => 'string', 'enum' => ['pending', 'running', 'completed', 'failed']],
                    'resource_type' => ['type' => 'string', 'nullable' => true],
                    'resource_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'request_payload' => ['type' => 'object', 'additionalProperties' => true],
                    'result_payload' => ['type' => 'object', 'additionalProperties' => true],
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string', 'nullable' => true],
                            'message' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                    'started_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'completed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'failed_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'updated_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],

            'Identity' => [
                'type' => 'object',
                'properties' => [
                    'workspace' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'name' => ['type' => 'string'],
                            'slug' => ['type' => 'string'],
                        ],
                    ],
                    'api_key' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'name' => ['type' => 'string'],
                            'scopes' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'content_destination' => [
                        'type' => 'object',
                        'nullable' => true,
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'name' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],

            'Usage' => [
                'type' => 'object',
                'properties' => [
                    'period' => [
                        'type' => 'object',
                        'properties' => [
                            'start' => ['type' => 'string', 'format' => 'date-time'],
                            'end' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                    'credits' => [
                        'type' => 'object',
                        'properties' => [
                            'used' => ['type' => 'integer'],
                            'remaining' => ['type' => 'integer'],
                            'limit' => ['type' => 'integer'],
                        ],
                    ],
                    'requests' => [
                        'type' => 'object',
                        'properties' => [
                            'total' => ['type' => 'integer'],
                            'by_endpoint' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],

            'Credits' => [
                'type' => 'object',
                'properties' => [
                    'balance' => ['type' => 'integer'],
                    'used_this_period' => ['type' => 'integer'],
                    'period_start' => ['type' => 'string', 'format' => 'date-time'],
                    'period_end' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],

            'CreditQuote' => [
                'type' => 'object',
                'properties' => [
                    'estimated_credits' => ['type' => 'integer'],
                    'operation' => ['type' => 'string'],
                    'parameters' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ],

            'Taxonomy' => [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'value' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'description' => ['type' => 'string', 'nullable' => true],
                            ],
                        ],
                    ],
                ],
            ],

            'GenerationOptions' => [
                'type' => 'object',
                'properties' => [
                    'models' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'output_types' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'languages' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'code' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],

            'ExportedDraft' => [
                'type' => 'object',
                'properties' => [
                    'format' => ['type' => 'string', 'enum' => ['json', 'html', 'markdown', 'text']],
                    'content' => ['type' => 'string'],
                    'metadata' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'language' => ['type' => 'string'],
                            'seo' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],

            'AnalyticsEventIngestion' => [
                'type' => 'object',
                'properties' => [
                    'accepted' => ['type' => 'integer'],
                    'rejected' => ['type' => 'integer'],
                    'errors' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'index' => ['type' => 'integer'],
                                'error' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],

            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                        ],
                    ],
                    'code' => ['type' => 'string', 'nullable' => true],
                ],
            ],
        ];
    }

    /**
     * Get default schema for unknown resources.
     */
    protected function defaultSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => true,
        ];
    }
}
