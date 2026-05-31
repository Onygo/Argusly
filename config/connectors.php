<?php

use App\Models\ConnectorCapability;

return [
    'types' => [
        'wordpress' => 'WordPress',
        'laravel' => 'Laravel',
        'api' => 'API',
        'webhook' => 'Webhook',
        'headless' => 'Headless CMS',
        'shopify' => 'Shopify',
        'webflow' => 'Webflow',
        'ghost' => 'Ghost',
    ],

    'capabilities' => ConnectorCapability::CAPABILITIES,

    'manifests' => [
        'wordpress' => [
            'type' => 'wordpress',
            'name' => 'WordPress',
            'description' => 'Argusly-side registration for future WordPress connector installations.',
            'version' => '0.1.0',
            'capabilities' => [
                'receive_content',
                'publish_content',
                'update_content',
                'delete_content',
                'sync_content',
                'sync_taxonomies',
                'sync_authors',
                'health_check',
                'webhooks',
                'media_upload',
                'preview_url',
            ],
        ],
        'laravel' => [
            'type' => 'laravel',
            'name' => 'Laravel',
            'description' => 'Argusly-side registration for future Laravel app connector installations.',
            'version' => '0.1.0',
            'capabilities' => [
                'receive_content',
                'publish_content',
                'update_content',
                'delete_content',
                'sync_content',
                'health_check',
                'webhooks',
                'media_upload',
                'preview_url',
            ],
        ],
        'api' => [
            'type' => 'api',
            'name' => 'Custom API',
            'description' => 'Generic API registration for custom publishing and sync targets.',
            'version' => '0.1.0',
            'capabilities' => ['receive_content', 'publish_content', 'update_content', 'health_check', 'webhooks'],
        ],
        'webhook' => [
            'type' => 'webhook',
            'name' => 'Webhook',
            'description' => 'Outbound webhook registration for delivery and health callbacks.',
            'version' => '0.1.0',
            'capabilities' => ['receive_content', 'publish_content', 'health_check', 'webhooks'],
        ],
        'headless' => [
            'type' => 'headless',
            'name' => 'Headless CMS',
            'description' => 'Headless CMS registration for content, taxonomy and media synchronization.',
            'version' => '0.1.0',
            'capabilities' => ['publish_content', 'update_content', 'sync_content', 'sync_taxonomies', 'media_upload', 'preview_url'],
        ],
        'shopify' => [
            'type' => 'shopify',
            'name' => 'Shopify',
            'description' => 'Shopify content and store publishing connector registration.',
            'version' => '0.1.0',
            'capabilities' => ['publish_content', 'update_content', 'sync_content', 'health_check', 'webhooks', 'media_upload', 'preview_url'],
        ],
        'webflow' => [
            'type' => 'webflow',
            'name' => 'Webflow',
            'description' => 'Webflow CMS publishing and preview connector registration.',
            'version' => '0.1.0',
            'capabilities' => ['publish_content', 'update_content', 'delete_content', 'sync_content', 'health_check', 'media_upload', 'preview_url'],
        ],
        'ghost' => [
            'type' => 'ghost',
            'name' => 'Ghost',
            'description' => 'Ghost publishing connector registration for posts, media and previews.',
            'version' => '0.1.0',
            'capabilities' => ['publish_content', 'update_content', 'delete_content', 'sync_content', 'health_check', 'media_upload', 'preview_url'],
        ],
    ],
];
