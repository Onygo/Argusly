<?php

return [
    'queues' => [
        'discover' => env('PAGE_INTELLIGENCE_DISCOVER_QUEUE', env('PAGE_INTELLIGENCE_DISCOVERY_QUEUE', 'page_intelligence_discover')),
        'fetch' => env('PAGE_INTELLIGENCE_FETCH_QUEUE', 'page_intelligence_fetch'),
        'extract' => env('PAGE_INTELLIGENCE_EXTRACT_QUEUE', 'page_intelligence_extract'),
        'analyze' => env('PAGE_INTELLIGENCE_ANALYZE_QUEUE', env('PAGE_INTELLIGENCE_ANALYSIS_QUEUE', 'page_intelligence_analyze')),
        'score' => env('PAGE_INTELLIGENCE_SCORE_QUEUE', 'page_intelligence_score'),
        'signal' => env('PAGE_INTELLIGENCE_SIGNAL_QUEUE', 'page_intelligence_signal'),
        'alert' => env('PAGE_INTELLIGENCE_ALERT_QUEUE', 'page_intelligence_alert'),
        'reports' => env('PAGE_INTELLIGENCE_REPORTS_QUEUE', 'page_intelligence_reports'),
    ],

    'queue' => [
        'host_rate_limit_per_minute' => (int) env('PAGE_INTELLIGENCE_HOST_RATE_LIMIT_PER_MINUTE', 12),
        'host_rate_limit_release_seconds' => (int) env('PAGE_INTELLIGENCE_HOST_RATE_LIMIT_RELEASE_SECONDS', 30),
    ],

    'fetch' => [
        'timeout_seconds' => (int) env('PAGE_INTELLIGENCE_FETCH_TIMEOUT', env('SOURCE_EXTRACTION_DIRECT_TIMEOUT', 30)),
        'connect_timeout_seconds' => (int) env('PAGE_INTELLIGENCE_FETCH_CONNECT_TIMEOUT', env('SOURCE_EXTRACTION_CONNECT_TIMEOUT', 10)),
        'max_html_bytes' => (int) env('PAGE_INTELLIGENCE_FETCH_MAX_HTML_BYTES', env('SOURCE_EXTRACTION_MAX_HTML_BYTES', 3000000)),
        'redirect_limit' => (int) env('PAGE_INTELLIGENCE_FETCH_REDIRECT_LIMIT', 5),
        'queue' => env('PAGE_INTELLIGENCE_FETCH_QUEUE', env('PAGE_INTELLIGENCE_QUEUE', 'page_intelligence_fetch')),
        'raw_html_storage' => env('PAGE_INTELLIGENCE_RAW_HTML_STORAGE', 'disk'),
        'raw_html_disk' => env('PAGE_INTELLIGENCE_RAW_HTML_DISK', 'local'),
        'raw_html_path' => env('PAGE_INTELLIGENCE_RAW_HTML_PATH', 'page-snapshots'),
        'raw_html_preview_bytes' => (int) env('PAGE_INTELLIGENCE_RAW_HTML_PREVIEW_BYTES', 2000),
        'user_agent' => env('PAGE_INTELLIGENCE_FETCH_USER_AGENT', 'ArguslyPageIntelligence/1.0 (+https://argusly.com)'),
    ],

    'storage' => [
        'extracted_text_storage' => env('PAGE_INTELLIGENCE_EXTRACTED_TEXT_STORAGE', 'disk'),
        'extracted_text_disk' => env('PAGE_INTELLIGENCE_EXTRACTED_TEXT_DISK', env('PAGE_INTELLIGENCE_RAW_HTML_DISK', 'local')),
        'extracted_text_path' => env('PAGE_INTELLIGENCE_EXTRACTED_TEXT_PATH', 'page-extractions'),
        'extracted_text_preview_bytes' => (int) env('PAGE_INTELLIGENCE_EXTRACTED_TEXT_PREVIEW_BYTES', 2000),
    ],

    'safety' => [
        'allow_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAGE_INTELLIGENCE_ALLOW_DOMAINS', ''))))),
        'deny_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('PAGE_INTELLIGENCE_DENY_DOMAINS', ''))))),
        'blocked_host_suffixes' => ['.localhost', '.local', '.internal', '.intranet', '.test'],
        'allowed_content_types' => [
            'text/html',
            'application/xhtml+xml',
            'text/plain',
            'application/rss+xml',
            'application/xml',
            'text/xml',
        ],
        'respect_robots_txt' => (bool) env('PAGE_INTELLIGENCE_RESPECT_ROBOTS_TXT', true),
        'robots_cache_seconds' => (int) env('PAGE_INTELLIGENCE_ROBOTS_CACHE_SECONDS', 86400),
        'robots_max_bytes' => (int) env('PAGE_INTELLIGENCE_ROBOTS_MAX_BYTES', 500000),
        'dns_overrides' => [],
    ],

    'retention' => [
        'raw_html_days' => (int) env('PAGE_INTELLIGENCE_RETENTION_RAW_HTML_DAYS', 30),
        'snapshot_days' => (int) env('PAGE_INTELLIGENCE_RETENTION_SNAPSHOT_DAYS', 180),
        'serp_observation_days' => (int) env('PAGE_INTELLIGENCE_RETENTION_SERP_OBSERVATION_DAYS', 180),
        'geo_observation_days' => (int) env('PAGE_INTELLIGENCE_RETENTION_GEO_OBSERVATION_DAYS', 180),
        'alert_days' => (int) env('PAGE_INTELLIGENCE_RETENTION_ALERT_DAYS', 365),
    ],

    'discovery' => [
        'queue' => env('PAGE_INTELLIGENCE_DISCOVERY_QUEUE', env('PAGE_INTELLIGENCE_DISCOVER_QUEUE', 'page_intelligence_discover')),
        'timeout_seconds' => (int) env('PAGE_INTELLIGENCE_DISCOVERY_TIMEOUT', 15),
        'max_urls' => (int) env('PAGE_INTELLIGENCE_DISCOVERY_MAX_URLS', 100),
        'fetch_priority_threshold' => (int) env('PAGE_INTELLIGENCE_DISCOVERY_FETCH_PRIORITY_THRESHOLD', 80),
    ],

    'extract' => [
        'queue' => env('PAGE_INTELLIGENCE_EXTRACT_QUEUE', 'page_intelligence_extract'),
    ],

    'analysis' => [
        'queue' => env('PAGE_INTELLIGENCE_ANALYSIS_QUEUE', env('PAGE_INTELLIGENCE_ANALYZE_QUEUE', 'page_intelligence_analyze')),
    ],

    'signals' => [
        'queue' => env('PAGE_INTELLIGENCE_SIGNAL_QUEUE', 'page_intelligence_signal'),
    ],

    'pr_value' => [
        'queue' => env('PAGE_INTELLIGENCE_PR_VALUE_QUEUE', env('PAGE_INTELLIGENCE_SCORE_QUEUE', 'page_intelligence_score')),
    ],

    'providers' => [
        'serp' => [
            'manual' => App\Services\PageIntelligence\Serp\ManualSerpProviderAdapter::class,
            'import' => App\Services\PageIntelligence\Serp\ManualSerpProviderAdapter::class,
        ],
        'answer_engines' => [],
    ],

    'market_packs' => [],
];
