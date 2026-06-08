<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Translation Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the multilingual translation system. Translation uses
    | cheaper AI models than full draft generation to reduce costs while
    | maintaining quality.
    |
    */

    'default_model' => env('PL_TRANSLATION_DEFAULT_MODEL', 'gpt-4.1-mini'),

    'default_credit_cost' => (int) env('PL_TRANSLATION_DEFAULT_CREDIT_COST', 6),

    'max_output_tokens' => (int) env('PL_TRANSLATION_MAX_OUTPUT_TOKENS', 12000),

    'min_output_tokens' => (int) env('PL_TRANSLATION_MIN_OUTPUT_TOKENS', 2000),

    'temperature' => (float) env('PL_TRANSLATION_TEMPERATURE', 0.3),

    'model_tiers' => [
        'economy' => [
            'model' => env('PL_TRANSLATION_ECONOMY_MODEL', 'gpt-4.1-mini'),
            'credit_multiplier' => 1.0,
        ],
        'standard' => [
            'model' => env('PL_TRANSLATION_STANDARD_MODEL', 'gpt-4.1'),
            'credit_multiplier' => 1.5,
        ],
        'premium' => [
            'model' => env('PL_TRANSLATION_PREMIUM_MODEL', 'claude-sonnet-4'),
            'credit_multiplier' => 2.0,
        ],
    ],

    'queue' => [
        'name' => env('PL_TRANSLATION_QUEUE', 'default'),
        'connection' => env('PL_TRANSLATION_QUEUE_CONNECTION', env('QUEUE_CONNECTION', null)),
        'retry_after' => (int) env('PL_TRANSLATION_RETRY_AFTER', 300),
        'max_tries' => (int) env('PL_TRANSLATION_MAX_TRIES', 3),
    ],

    'processing_lock_ttl_seconds' => (int) env(
        'PL_TRANSLATION_PROCESSING_LOCK_TTL',
        max(
            ((int) env('PL_TRANSLATION_RETRY_AFTER', 300)) * 3,
            ((int) env('ARGUSLY_TRANSLATION_STALE_LOCK_TIMEOUT_MINUTES', 10)) * 60
        )
    ),

    'bulk' => [
        'max_languages_per_batch' => (int) env('PL_TRANSLATION_BULK_MAX_LANGUAGES', 5),
        'delay_between_jobs_seconds' => (int) env('PL_TRANSLATION_BULK_DELAY', 2),
    ],

    'seo' => [
        'auto_localize' => (bool) env('PL_TRANSLATION_AUTO_LOCALIZE_SEO', true),
        'preserve_brand_names' => (bool) env('PL_TRANSLATION_PRESERVE_BRAND_NAMES', true),
        'max_seo_title_length' => 60,
        'max_meta_description_length' => 160,
    ],
];
