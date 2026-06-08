<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAPI Configuration
    |--------------------------------------------------------------------------
    */

    'openapi' => [
        'output' => 'docs/openapi/argusly.yaml',
        'format' => 'yaml',

        'info' => [
            'title' => 'Argusly API',
            'version' => '1.0.0',
            'description' => 'Headless and hybrid API for Argusly workspaces. Create briefs, generate AI-powered drafts, manage content destinations, and integrate with your content workflow.',
        ],

        'servers' => [
            [
                'url' => 'https://api.argusly.com/api/v1',
                'description' => 'Production',
            ],
        ],

        // Only include routes matching these middleware
        'include_middleware' => [
            'integration.token',
        ],

        // Exclude routes matching these patterns
        'exclude_patterns' => [
            'api/v1/admin/*',
            'api/v1/plugin/*',
            'api/v1/webhooks/mollie',
            'api/v1/auth/*',
            'api/v1/clients/*',
            'connector/*',
            'wp/*',
        ],

        // Route prefix to document
        'route_prefix' => 'api/v1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Postman Configuration
    |--------------------------------------------------------------------------
    */

    'postman' => [
        'output_dir' => 'docs/postman/',
        'collection_name' => 'Argusly API',
        'environment_name' => 'Argusly API',

        'variables' => [
            'base_url' => 'https://api.argusly.com/api/v1',
            'workspace_api_key' => '',
            'workspace_id' => '',
            'destination_id' => '',
            'brief_id' => '',
            'draft_id' => '',
            'operation_id' => '',
            'seo_audit_id' => '',
            'webhook_id' => '',
            'api_key_id' => '',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tags Configuration
    |--------------------------------------------------------------------------
    */

    'tags' => [
        [
            'name' => 'Identity',
            'description' => 'Workspace identity and API usage information',
        ],
        [
            'name' => 'Destinations',
            'description' => 'Content delivery destinations for API-only, WordPress, or custom CMS integrations',
        ],
        [
            'name' => 'API Keys',
            'description' => 'Manage workspace API keys with scoped permissions',
        ],
        [
            'name' => 'Webhooks',
            'description' => 'Outbound webhook subscriptions for lifecycle events',
        ],
        [
            'name' => 'Briefs',
            'description' => 'Content briefs that define what AI should generate',
        ],
        [
            'name' => 'Drafts',
            'description' => 'AI-generated content drafts with SEO metadata',
        ],
        [
            'name' => 'Operations',
            'description' => 'Poll async operation status for long-running tasks',
        ],
        [
            'name' => 'SEO Audits',
            'description' => 'Technical SEO audits for content destinations',
        ],
        [
            'name' => 'Analytics',
            'description' => 'Content analytics event ingestion',
        ],
        [
            'name' => 'Taxonomy',
            'description' => 'Content taxonomy options (intents, audiences)',
        ],
        [
            'name' => 'Credits',
            'description' => 'Workspace credit balance and usage quotes',
        ],
        [
            'name' => 'Images',
            'description' => 'AI image generation',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route to Tag Mapping
    |--------------------------------------------------------------------------
    | Maps route path patterns to documentation tags
    */

    'route_tags' => [
        '/me' => 'Identity',
        '/usage' => 'Identity',
        '/destinations' => 'Destinations',
        '/destinations/*' => 'Destinations',
        '/api-keys' => 'API Keys',
        '/api-keys/*' => 'API Keys',
        '/webhooks' => 'Webhooks',
        '/webhooks/*' => 'Webhooks',
        '/briefs' => 'Briefs',
        '/briefs/*' => 'Briefs',
        '/drafts' => 'Drafts',
        '/drafts/*' => 'Drafts',
        '/operations/*' => 'Operations',
        '/seo-audits' => 'SEO Audits',
        '/seo-audits/*' => 'SEO Audits',
        '/analytics/*' => 'Analytics',
        '/taxonomy/*' => 'Taxonomy',
        '/credits' => 'Credits',
        '/credits/*' => 'Credits',
        '/images/*' => 'Images',
        '/events' => 'Analytics',
        '/generation/*' => 'Taxonomy',
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Class Mappings
    |--------------------------------------------------------------------------
    | Maps controller actions to their Form Request classes
    */

    'request_classes' => [
        'App\Http\Controllers\Api\V1\BriefController@store' => 'App\Http\Requests\Api\V1\Headless\CreateBriefRequest',
        'App\Http\Controllers\Api\V1\BriefController@update' => 'App\Http\Requests\Api\V1\Headless\UpdateBriefRequest',
        'App\Http\Controllers\Api\V1\BriefController@generateDraft' => 'App\Http\Requests\Api\V1\Headless\GenerateBriefDraftRequest',
        'App\Http\Controllers\Api\V1\DraftController@regenerate' => 'App\Http\Requests\Api\V1\Headless\RegenerateDraftRequest',
        'App\Http\Controllers\Api\V1\DraftController@translate' => 'App\Http\Requests\Api\V1\Headless\TranslateDraftRequest',
        'App\Http\Controllers\Api\V1\DraftController@export' => 'App\Http\Requests\Api\V1\Headless\ExportDraftRequest',
        'App\Http\Controllers\Api\V1\Headless\DestinationController@store' => 'App\Http\Requests\Api\V1\Headless\CreateDestinationRequest',
        'App\Http\Controllers\Api\V1\Headless\DestinationController@update' => 'App\Http\Requests\Api\V1\Headless\UpdateDestinationRequest',
        'App\Http\Controllers\Api\V1\Headless\WebhookController@store' => 'App\Http\Requests\Api\V1\Headless\CreateWebhookRequest',
        'App\Http\Controllers\Api\V1\Headless\WebhookController@update' => 'App\Http\Requests\Api\V1\Headless\UpdateWebhookRequest',
        'App\Http\Controllers\Api\V1\Headless\ApiKeyController@store' => 'App\Http\Requests\Api\V1\Headless\CreateApiKeyRequest',
        'App\Http\Controllers\Api\V1\Headless\SeoAuditController@store' => 'App\Http\Requests\Api\V1\Headless\StartSeoAuditRequest',
        'App\Http\Controllers\Api\V1\Headless\AnalyticsIngestController@store' => 'App\Http\Requests\Api\V1\Headless\IngestAnalyticsEventsRequest',
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Resource Mappings
    |--------------------------------------------------------------------------
    | Maps controller actions to their API Resource classes
    */

    'response_resources' => [
        'App\Http\Controllers\Api\V1\BriefController@store' => 'App\Http\Resources\Api\V1\BriefResource',
        'App\Http\Controllers\Api\V1\BriefController@index' => 'App\Http\Resources\Api\V1\BriefResource',
        'App\Http\Controllers\Api\V1\BriefController@show' => 'App\Http\Resources\Api\V1\BriefResource',
        'App\Http\Controllers\Api\V1\BriefController@update' => 'App\Http\Resources\Api\V1\BriefResource',
        'App\Http\Controllers\Api\V1\DraftController@index' => 'App\Http\Resources\Api\V1\DraftResource',
        'App\Http\Controllers\Api\V1\DraftController@show' => 'App\Http\Resources\Api\V1\DraftResource',
        'App\Http\Controllers\Api\V1\Headless\DestinationController@index' => 'App\Http\Resources\Api\V1\ContentDestinationResource',
        'App\Http\Controllers\Api\V1\Headless\DestinationController@store' => 'App\Http\Resources\Api\V1\ContentDestinationResource',
        'App\Http\Controllers\Api\V1\Headless\DestinationController@show' => 'App\Http\Resources\Api\V1\ContentDestinationResource',
        'App\Http\Controllers\Api\V1\Headless\DestinationController@update' => 'App\Http\Resources\Api\V1\ContentDestinationResource',
        'App\Http\Controllers\Api\V1\Headless\ApiKeyController@index' => 'App\Http\Resources\Api\V1\ApiKeyResource',
        'App\Http\Controllers\Api\V1\Headless\ApiKeyController@store' => 'App\Http\Resources\Api\V1\ApiKeyResource',
        'App\Http\Controllers\Api\V1\Headless\WebhookController@index' => 'App\Http\Resources\Api\V1\ApiWebhookResource',
        'App\Http\Controllers\Api\V1\Headless\WebhookController@store' => 'App\Http\Resources\Api\V1\ApiWebhookResource',
        'App\Http\Controllers\Api\V1\Headless\WebhookController@update' => 'App\Http\Resources\Api\V1\ApiWebhookResource',
        'App\Http\Controllers\Api\V1\Headless\SeoAuditController@store' => 'App\Http\Resources\Api\V1\SeoAuditResource',
        'App\Http\Controllers\Api\V1\Headless\SeoAuditController@show' => 'App\Http\Resources\Api\V1\SeoAuditResource',
        'App\Http\Controllers\Api\V1\Headless\OperationController@show' => 'App\Http\Resources\Api\V1\AsyncOperationResource',
        'App\Http\Controllers\Api\V1\BriefController@generateDraft' => 'App\Http\Resources\Api\V1\AsyncOperationResource',
        'App\Http\Controllers\Api\V1\DraftController@regenerate' => 'App\Http\Resources\Api\V1\AsyncOperationResource',
        'App\Http\Controllers\Api\V1\DraftController@translate' => 'App\Http\Resources\Api\V1\AsyncOperationResource',
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoint Descriptions
    |--------------------------------------------------------------------------
    | Custom descriptions for endpoints
    */

    'endpoint_descriptions' => [
        'GET /me' => 'Returns the workspace and API key identity associated with the current authentication token.',
        'GET /usage' => 'Returns API usage statistics for the current billing period.',
        'GET /destinations' => 'List all content destinations in the workspace.',
        'POST /destinations' => 'Create a new content destination for API-only, WordPress, or custom CMS integration.',
        'GET /destinations/{destination}' => 'Get details of a specific content destination.',
        'PATCH /destinations/{destination}' => 'Update a content destination.',
        'GET /api-keys' => 'List all API keys in the workspace.',
        'POST /api-keys' => 'Create a new scoped API key. The plain key is only returned once.',
        'DELETE /api-keys/{apiKey}' => 'Permanently delete an API key.',
        'POST /api-keys/{apiKey}/revoke' => 'Revoke an API key without deleting it.',
        'GET /webhooks' => 'List all webhook subscriptions in the workspace.',
        'POST /webhooks' => 'Create a new outbound webhook subscription.',
        'PATCH /webhooks/{webhook}' => 'Update a webhook subscription.',
        'DELETE /webhooks/{webhook}' => 'Delete a webhook subscription.',
        'GET /briefs' => 'List briefs with optional status filtering.',
        'POST /briefs' => 'Create a new content brief. Optionally queue immediate draft generation.',
        'GET /briefs/{id}' => 'Get details of a specific brief.',
        'PATCH /briefs/{id}' => 'Update a brief.',
        'POST /briefs/{id}/generate-draft' => 'Queue draft generation for a brief. Returns an async operation to poll.',
        'GET /drafts' => 'List drafts with optional status filtering.',
        'GET /drafts/{id}' => 'Get details of a specific draft including content.',
        'POST /drafts/{id}/regenerate' => 'Queue draft regeneration. Returns an async operation to poll.',
        'POST /drafts/{id}/translate' => 'Queue draft translation. Returns an async operation to poll.',
        'GET /drafts/{id}/export' => 'Export draft content in the requested format (json, html, markdown, text).',
        'POST /drafts/{id}/ack' => 'Acknowledge draft delivery to the destination.',
        'POST /drafts/{id}/feedback' => 'Submit feedback for a draft.',
        'GET /operations/{operation}' => 'Poll the status of an async operation.',
        'POST /seo-audits' => 'Start an SEO audit for a content destination. Returns an async operation.',
        'GET /seo-audits/{audit}' => 'Get SEO audit results.',
        'POST /analytics/events' => 'Ingest custom analytics events for content performance tracking.',
        'GET /taxonomy/intents' => 'Get available content intent options.',
        'GET /taxonomy/audiences' => 'Get available target audience options.',
        'GET /generation/options' => 'Get available generation options and models.',
        'GET /credits' => 'Get current workspace credit balance.',
        'GET /credits/quote' => 'Get a credit usage quote for a planned operation.',
        'POST /images/generate' => 'Generate an AI image.',
        'POST /events' => 'Submit lifecycle events.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'enabled' => true,
        'ttl' => 3600, // 1 hour
        'key' => 'argusly:openapi:spec',
    ],

];
