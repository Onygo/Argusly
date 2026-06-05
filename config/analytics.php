<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Analytics Feature Toggle
    |--------------------------------------------------------------------------
    |
    | Enable or disable the analytics tracking system.
    |
    */

    'enabled' => env('ANALYTICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Privacy Settings
    |--------------------------------------------------------------------------
    |
    | Settings for cookieless tracking and privacy compliance.
    |
    */

    'privacy' => [
        // Daily rotating salt for visitor hash generation
        'salt' => env('ANALYTICS_SALT', ''),

        // Respect Do Not Track header by default
        'respect_dnt' => env('ANALYTICS_RESPECT_DNT', true),

        // Default data retention in days (0 = forever)
        'default_retention_days' => env('ANALYTICS_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion Limits
    |--------------------------------------------------------------------------
    |
    | Rate limiting and batch size constraints for event ingestion.
    |
    */

    'ingestion' => [
        // Maximum events per batch request
        'max_events_per_batch' => 50,

        // Maximum string length for paths/titles
        'max_string_length' => 2000,

        // Reject payloads above this size
        'max_payload_bytes' => 32768,

        // Rate limit: requests per minute per site
        'rate_limit_per_minute' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rollup Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the hourly rollup job.
    |
    */

    'rollup' => [
        // How many days back to rebuild on each run (for late events)
        'lookback_days' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Script Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the tracking script.
    |
    */

    'script' => [
        // Cache duration for pl.js in seconds (1 hour)
        'cache_seconds' => 3600,

        // Script version for cache busting
        'version' => '1.2.1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tracking Thresholds
    |--------------------------------------------------------------------------
    |
    | Default thresholds used by the client script and Learnings descriptions.
    |
    */
    'tracking' => [
        'engaged_after_seconds' => env('ANALYTICS_ENGAGED_AFTER_SECONDS', 10),
        'read_through_scroll_percent' => env('ANALYTICS_READ_THROUGH_SCROLL_PERCENT', 75),
        'read_through_fallback_seconds' => env('ANALYTICS_READ_THROUGH_FALLBACK_SECONDS', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Refresh
    |--------------------------------------------------------------------------
    |
    | Controls how quickly derived content metrics are refreshed after ingest.
    |
    */
    'metrics' => [
        'refresh_on_ingest' => env('ANALYTICS_REFRESH_METRICS_ON_INGEST', true),
        'refresh_throttle_seconds' => env('ANALYTICS_REFRESH_METRICS_THROTTLE_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI SEO Score
    |--------------------------------------------------------------------------
    |
    | Deterministic score composition based on stored ROI + AI visibility.
    | Weights are normalized by the calculator and can be tuned per environment.
    |
    */
    'ai_seo_score' => [
        'weights' => [
            'content_roi' => (float) env('ANALYTICS_AI_SEO_WEIGHT_CONTENT_ROI', 0.55),
            'ai_visibility_normalized' => (float) env('ANALYTICS_AI_SEO_WEIGHT_AI_VISIBILITY', 0.45),
        ],
    ],

];
