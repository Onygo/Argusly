<?php

return [
    'http_insecure_local' => (bool) env('PUBLISHLAYER_HTTP_INSECURE_LOCAL', false),
    'tracking_url' => env('PUBLISHLAYER_TRACKING_URL', 'https://track.argusly.com'),
    'tracking_script_version' => env('PUBLISHLAYER_TRACKING_SCRIPT_VERSION', '1.2.1'),

    /*
    |--------------------------------------------------------------------------
    | Admin / internal access
    |--------------------------------------------------------------------------
    */
    'admin_key' => env('PUBLISHLAYER_ADMIN_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | AI generation
    |--------------------------------------------------------------------------
    |
    */
    'ai' => [
        'drafts' => [
            'generation_lock_timeout_minutes' => (int) env('PUBLISHLAYER_AI_DRAFT_LOCK_TIMEOUT_MINUTES', 15),
        ],

        'images' => [
            'provider' => env('PUBLISHLAYER_AI_IMAGE_PROVIDER', 'openai'),
            'credit_cost' => (int) env('PUBLISHLAYER_AI_IMAGE_CREDIT_COST', 6),
            'generation_lock_timeout_minutes' => (int) env('PUBLISHLAYER_AI_IMAGE_LOCK_TIMEOUT_MINUTES', 5),
            'storage_disk' => env('PUBLISHLAYER_AI_IMAGE_STORAGE_DISK', 'public'),
            'og' => [
                'width' => (int) env('PUBLISHLAYER_OG_IMAGE_WIDTH', 1200),
                'height' => (int) env('PUBLISHLAYER_OG_IMAGE_HEIGHT', 630),
                'font_path' => env('PUBLISHLAYER_OG_FONT_PATH', resource_path('fonts/Inter-SemiBold.ttf')),
            ],
            'openai' => [
                'api_key' => env('OPENAI_API_KEY', ''),
                'base_url' => env('PUBLISHLAYER_AI_IMAGE_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'model' => env('PUBLISHLAYER_AI_IMAGE_MODEL', 'gpt-image-1'),
                'size' => env('PUBLISHLAYER_AI_IMAGE_SIZE', '1536x1024'),
                'quality' => env('PUBLISHLAYER_AI_IMAGE_QUALITY', 'medium'),
                'request_timeout_seconds' => (int) env('PUBLISHLAYER_AI_IMAGE_TIMEOUT_SECONDS', 90),
            ],
            'gemini' => [
                'api_key' => env('GEMINI_API_KEY', ''),
                'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
                'model' => env('PUBLISHLAYER_AI_IMAGE_GEMINI_MODEL', 'gemini-2.5-flash-image'),
                'request_timeout_seconds' => (int) env('PUBLISHLAYER_AI_IMAGE_GEMINI_TIMEOUT_SECONDS', 90),
            ],
        ],
        'seo_fix' => [
            'credit_cost' => (int) env('PUBLISHLAYER_AI_SEO_FIX_CREDIT_COST', 2),
        ],
    ],

    'plugin_updates' => [
        'disk' => env('PUBLISHLAYER_PLUGIN_UPDATES_DISK', 'local'),
        'download_token_ttl_seconds' => (int) env('PUBLISHLAYER_PLUGIN_DOWNLOAD_TOKEN_TTL', 86400),
        'signature_ttl_seconds' => (int) env('PUBLISHLAYER_PLUGIN_SIGNATURE_TTL', 300),
    ],

    'webhooks' => [
        'secret' => env('PL_WEBHOOK_SECRET', env('PUBLISHLAYER_WEBHOOK_SECRET')),
        // Keep current delivery behavior unless explicitly overridden.
        'queue' => env('PL_WEBHOOK_QUEUE', env('PUBLISHLAYER_WEBHOOK_QUEUE', 'deliveries')),
        'connector_public_url' => env('PL_CONNECTOR_PUBLIC_URL', env('PUBLISHLAYER_CONNECTOR_PUBLIC_URL', env('APP_URL'))),
    ],

    'images' => [
        'enabled' => (bool) env('PL_IMAGES_ENABLED', env('PUBLISHLAYER_IMAGES_ENABLED', true)),
        'disk' => env(
            'PL_IMAGES_DISK',
            env('PUBLISHLAYER_IMAGES_DISK', env('PUBLISHLAYER_AI_IMAGE_STORAGE_DISK', 'public'))
        ),
    ],

    'stock_images' => [
        'unsplash' => [
            'access_key' => env('UNSPLASH_ACCESS_KEY', ''),
            'base_url' => env('UNSPLASH_BASE_URL', 'https://api.unsplash.com'),
            'timeout_seconds' => (int) env('UNSPLASH_TIMEOUT_SECONDS', 12),
        ],
    ],

    'translations' => [
        'stale_lock_timeout_minutes' => (int) env('PUBLISHLAYER_TRANSLATION_STALE_LOCK_TIMEOUT_MINUTES', 10),
    ],

    'wp_connector' => [
        'require_timestamp_nonce' => (bool) env('PUBLISHLAYER_WP_REQUIRE_TS_NONCE', false),
        'timestamp_ttl_seconds' => (int) env('PUBLISHLAYER_WP_TS_NONCE_TTL', 300),
        'allow_insecure_local_tls' => (bool) env('PUBLISHLAYER_WP_ALLOW_INSECURE_LOCAL_TLS', true),
        'recent_activity_window_minutes' => (int) env('PUBLISHLAYER_WP_RECENT_ACTIVITY_WINDOW_MINUTES', 15),
        'sync_debug' => (bool) env('PUBLISHLAYER_WP_SYNC_DEBUG', false),
        'sync_debug_site_id' => env('PUBLISHLAYER_WP_SYNC_DEBUG_SITE_ID', ''),
        'featured_image_b64_fallback' => (bool) env('PUBLISHLAYER_WP_FEATURED_IMAGE_B64_FALLBACK', true),
        'featured_image_b64_max_bytes' => (int) env('PUBLISHLAYER_WP_FEATURED_IMAGE_B64_MAX_BYTES', 8 * 1024 * 1024),
    ],

    'contact' => [
        'mailer' => env('PUBLISHLAYER_CONTACT_MAILER', 'mailgun'),
        'recipient_email' => env('PUBLISHLAYER_CONTACT_RECIPIENT_EMAIL', env('MAIL_FROM_ADDRESS')),
        'schedule_call_url' => env('PUBLISHLAYER_CONTACT_SCHEDULE_CALL_URL', ''),
    ],

    'public_blog' => [
        // Optional scoping for connector-synced public blog posts.
        'client_site_id' => env('PUBLISHLAYER_PUBLIC_BLOG_CLIENT_SITE_ID', ''),
        'workspace_id' => env('PUBLISHLAYER_PUBLIC_BLOG_WORKSPACE_ID', ''),
        'max_posts' => (int) env('PUBLISHLAYER_PUBLIC_BLOG_MAX_POSTS', 300),
    ],

    'answer_blocks' => [
        'default_max_visible' => (int) env('PUBLISHLAYER_ANSWER_BLOCKS_MAX_VISIBLE', 3),
    ],

    'onboarding' => [
        'require_email_verification' => (bool) env('PUBLISHLAYER_REQUIRE_EMAIL_VERIFICATION', false),
        'trial_ending_enabled' => (bool) env('PUBLISHLAYER_ONBOARDING_TRIAL_ENDING_ENABLED', false),
    ],

    'launch' => [
        'soft_launch_mode' => (bool) env('APP_SOFT_LAUNCH', false),
        'public_registration_enabled' => (bool) env('PUBLIC_REGISTRATION_ENABLED', true),
        'public_pricing_enabled' => (bool) env('PUBLIC_PRICING_ENABLED', true),
        'registration_block_mode' => (string) env('PUBLIC_REGISTRATION_BLOCK_MODE', 'redirect'),
    ],

    'auth' => [
        'email_code' => [
            'expiry_minutes' => (int) env('PUBLISHLAYER_EMAIL_CODE_EXPIRY_MINUTES', 15),
            'resend_cooldown_seconds' => (int) env('PUBLISHLAYER_EMAIL_CODE_RESEND_COOLDOWN_SECONDS', 60),
            'verify_max_attempts' => (int) env('PUBLISHLAYER_EMAIL_CODE_VERIFY_MAX_ATTEMPTS', 5),
            'verify_decay_seconds' => (int) env('PUBLISHLAYER_EMAIL_CODE_VERIFY_DECAY_SECONDS', 900),
            'resend_max_attempts' => (int) env('PUBLISHLAYER_EMAIL_CODE_RESEND_MAX_ATTEMPTS', 5),
            'resend_decay_seconds' => (int) env('PUBLISHLAYER_EMAIL_CODE_RESEND_DECAY_SECONDS', 900),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics Tracking
    |--------------------------------------------------------------------------
    |
    | Control analytics tracking behavior per environment.
    |
    */
    'analytics' => [
        'allow_tracking_on_local' => (bool) env('PUBLISHLAYER_ANALYTICS_ALLOW_LOCAL', false),
        'allow_tracking_on_staging' => (bool) env('PUBLISHLAYER_ANALYTICS_ALLOW_STAGING', true),
        'allow_tracking_in_testing' => (bool) env('PUBLISHLAYER_ANALYTICS_ALLOW_TESTING', false),

        /*
        |--------------------------------------------------------------------------
        | Internal Verified Domains
        |--------------------------------------------------------------------------
        |
        | Domains owned by Argusly that are automatically verified without
        | requiring meta tag verification. These are first-party domains where
        | analytics tracking is injected automatically.
        |
        */
        'internal_verified_domains' => array_filter(array_map('trim', explode(',', env(
            'PUBLISHLAYER_INTERNAL_VERIFIED_DOMAINS',
            'publishlayer.com,www.publishlayer.com'
        )))),
    ],

];
