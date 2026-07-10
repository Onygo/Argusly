<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Website Content Inventory
    |--------------------------------------------------------------------------
    |
    | Phase 1 keeps inventory infrastructure conservative. Observed pages are
    | evaluated and linked, but promotion to Content remains explicit.
    |
    */

    'auto_promotion_enabled' => false,

    'analytics_observed' => [
        'enabled' => env('WEBSITE_CONTENT_ANALYTICS_DISCOVERY_ENABLED', true),
        'source_type' => 'analytics_observed',
        'page_event_types' => ['page_view'],
        'included_page_types' => [null, '', 'other_page', 'site_page'],
        'chunk_size' => 250,
        'max_chunk_size' => 2000,
        'max_urls_per_run' => 500,
        'preserve_allowlisted_query_parameters' => false,
        'query_parameter_allowlist' => [
            'page',
            'p',
            'lang',
            'locale',
        ],
        'automatic_fetch_after_discovery' => true,
        'queue' => env('WEBSITE_CONTENT_INVENTORY_QUEUE', 'page_intelligence_discover'),
    ],

    'sitemap' => [
        'enabled' => env('WEBSITE_CONTENT_SITEMAP_SETUP_ENABLED', true),
        'source_type' => 'xml_sitemap',
        'candidate_paths' => [
            '/sitemap.xml',
            '/sitemap_index.xml',
            '/sitemap-index.xml',
            '/wp-sitemap.xml',
        ],
        'max_urls' => 500,
        'fetch_priority_threshold' => 80,
        'allow_unverified_domains' => false,
        'allow_cross_domain_overrides' => false,
        'dispatch_discovery_after_setup' => false,
    ],

    'schedules' => [
        'analytics_observed' => env('WEBSITE_CONTENT_ANALYTICS_DISCOVERY_SCHEDULE', 'hourly'),
        'sitemap_setup' => env('WEBSITE_CONTENT_SITEMAP_SETUP_SCHEDULE', 'daily'),
        'stale_refresh' => env('WEBSITE_CONTENT_REFRESH_SCHEDULE', 'everyFourHours'),
    ],

    'availability' => [
        'persistent_failure_threshold' => 3,
    ],

    'excluded_paths' => [
        '/login',
        '/logout',
        '/register',
        '/password',
        '/reset-password',
        '/forgot-password',
        '/verify',
        '/checkout',
        '/cart',
        '/account',
        '/profile',
        '/dashboard',
        '/admin',
        '/wp-admin',
        '/wp-login.php',
        '/my-account',
        '/billing',
        '/invoice',
        '/orders',
        '/settings',
        '/api',
        '/oauth',
        '/sso',
        '/auth',
        '/portal',
        '/private',
        '/members',
    ],

    'excluded_page_patterns' => [
        '*/login*',
        '*/logout*',
        '*/register*',
        '*/checkout*',
        '*/cart*',
        '*/account*',
        '*/admin*',
        '*/wp-admin*',
        '*/wp-login.php*',
        '*/api/*',
        '*/oauth/*',
        '*/sso/*',
        '*/auth/*',
        '*/private/*',
        '*/members/*',
    ],

    'query_parameter_allowlist' => [
        'page',
        'p',
        'lang',
        'locale',
    ],

    'eligibility' => [
        'default_review_status' => 'pending_review',
        'allow_unfetched_pages' => true,
        'require_public_url' => true,
        'require_successful_http_status' => false,
        'eligible_http_statuses' => [200],
        'respect_robots' => true,
        'require_robots_allowed' => false,
        'eligible_indexability_statuses' => [
            null,
            '',
            'unknown',
            'indexable',
            'allowed',
        ],
        'ineligible_indexability_statuses' => [
            'noindex',
            'non_indexable',
            'blocked',
            'robots_blocked',
            'robots_disallowed',
            'disallowed',
        ],
    ],

    'campaign_defaults' => [
        'campaign_eligible_by_default' => true,
        'eligible_page_types' => [
            null,
            '',
            'article',
            'blog',
            'blog_post',
            'page',
            'landing_page',
            'seo_page',
            'press_release',
            'knowledge_base',
        ],
        'ineligible_page_types' => [
            'login',
            'checkout',
            'cart',
            'account',
            'dashboard',
            'admin',
            'api',
        ],
    ],

    'refresh_intervals' => [
        'observed_page_refresh_hours' => 24,
        'temporary_failure_retry_hours' => 6,
        'linked_content_refresh_hours' => 12,
        'recent_traffic_refresh_hours' => 12,
        'activation_metadata_refresh_hours' => 24,
        'eligibility_recheck_hours' => 12,
        'diagnostics_cache_minutes' => 15,
    ],

    'backfill' => [
        'chunk_size' => 100,
        'max_chunk_size' => 1000,
    ],

    'refresh' => [
        'limit' => 100,
        'queue' => env('WEBSITE_CONTENT_REFRESH_QUEUE', 'page_intelligence_fetch'),
        'automatic_extraction_after_fetch' => true,
    ],
];
