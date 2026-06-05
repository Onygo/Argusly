<?php

return [
    'direct_timeout_seconds' => (int) env('SOURCE_EXTRACTION_DIRECT_TIMEOUT', 30),
    'relaxed_timeout_seconds' => (int) env('SOURCE_EXTRACTION_RELAXED_TIMEOUT', 60),
    'connect_timeout_seconds' => (int) env('SOURCE_EXTRACTION_CONNECT_TIMEOUT', 10),
    'min_text_chars' => (int) env('SOURCE_EXTRACTION_MIN_TEXT_CHARS', 800),
    'max_text_chars' => (int) env('SOURCE_EXTRACTION_MAX_TEXT_CHARS', 120000),
    'max_html_bytes' => (int) env('SOURCE_EXTRACTION_MAX_HTML_BYTES', 3000000),
    'jina_enabled' => (bool) env('SOURCE_EXTRACTION_JINA_ENABLED', true),
    'jina_api_key' => env('SOURCE_EXTRACTION_JINA_API_KEY'),
    'browser_enabled' => (bool) env('SOURCE_BROWSER_ENABLED', false),
    'cache_ttl_days' => (int) env('SOURCE_EXTRACTION_CACHE_TTL_DAYS', 14),
    'blocked_domains' => [],
    'allowed_schemes' => ['http', 'https'],
];
