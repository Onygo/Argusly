<?php

return [
    'http_insecure_local' => (bool) env('ARGUSLY_HTTP_INSECURE_LOCAL', false),
    'tracking_url' => env('ARGUSLY_TRACKING_URL', 'https://track.argusly.com'),
    'tracking_script_version' => env('ARGUSLY_TRACKING_SCRIPT_VERSION', '1.2.1'),

    /*
    |--------------------------------------------------------------------------
    | Admin / internal access
    |--------------------------------------------------------------------------
    */
    'admin_key' => env('ARGUSLY_ADMIN_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | AI generation
    |--------------------------------------------------------------------------
    |
    */
    'ai' => [
        'drafts' => [
            'generation_lock_timeout_minutes' => (int) env('ARGUSLY_AI_DRAFT_LOCK_TIMEOUT_MINUTES', 15),
        ],

        'images' => [
            'provider' => env('ARGUSLY_AI_IMAGE_PROVIDER', 'openai'),
            'credit_cost' => (int) env('ARGUSLY_AI_IMAGE_CREDIT_COST', 6),
            'generation_lock_timeout_minutes' => (int) env('ARGUSLY_AI_IMAGE_LOCK_TIMEOUT_MINUTES', 5),
            'storage_disk' => env('ARGUSLY_AI_IMAGE_STORAGE_DISK', 'content_images'),
            'og' => [
                'width' => (int) env('ARGUSLY_OG_IMAGE_WIDTH', 1200),
                'height' => (int) env('ARGUSLY_OG_IMAGE_HEIGHT', 630),
                'font_path' => env('ARGUSLY_OG_FONT_PATH', resource_path('fonts/Inter-SemiBold.ttf')),
            ],
            'openai' => [
                'api_key' => env('OPENAI_API_KEY', ''),
                'base_url' => env('ARGUSLY_AI_IMAGE_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'model' => env('ARGUSLY_AI_IMAGE_MODEL', 'gpt-image-1'),
                'size' => env('ARGUSLY_AI_IMAGE_SIZE', '1536x1024'),
                'quality' => env('ARGUSLY_AI_IMAGE_QUALITY', 'medium'),
                'request_timeout_seconds' => (int) env('ARGUSLY_AI_IMAGE_TIMEOUT_SECONDS', 90),
            ],
            'gemini' => [
                'api_key' => env('GEMINI_API_KEY', ''),
                'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
                'model' => env('ARGUSLY_AI_IMAGE_GEMINI_MODEL', 'gemini-2.5-flash-image'),
                'request_timeout_seconds' => (int) env('ARGUSLY_AI_IMAGE_GEMINI_TIMEOUT_SECONDS', 90),
            ],
        ],
        'seo_fix' => [
            'credit_cost' => (int) env('ARGUSLY_AI_SEO_FIX_CREDIT_COST', 2),
        ],
    ],

    'plugin_updates' => [
        'disk' => env('ARGUSLY_PLUGIN_UPDATES_DISK', 'local'),
        'download_token_ttl_seconds' => (int) env('ARGUSLY_PLUGIN_DOWNLOAD_TOKEN_TTL', 86400),
        'signature_ttl_seconds' => (int) env('ARGUSLY_PLUGIN_SIGNATURE_TTL', 300),
    ],

    'webhooks' => [
        'secret' => env('ARGUSLY_WEBHOOK_SECRET'),
        // Keep current delivery behavior unless explicitly overridden.
        'queue' => env('ARGUSLY_WEBHOOK_QUEUE', 'deliveries'),
        'connector_public_url' => env('ARGUSLY_CONNECTOR_PUBLIC_URL', env('APP_URL')),
    ],

    'images' => [
        'enabled' => (bool) env('ARGUSLY_IMAGES_ENABLED', true),
        'disk' => env('ARGUSLY_IMAGES_DISK', env('ARGUSLY_AI_IMAGE_STORAGE_DISK', 'content_images')),
        'path' => env('ARGUSLY_IMAGES_PATH', 'content-images'),
    ],

    'mail' => [
        'asset_url' => env('ARGUSLY_MAIL_ASSET_URL', 'https://app.argusly.com'),
    ],

    'stock_images' => [
        'unsplash' => [
            'access_key' => env('UNSPLASH_ACCESS_KEY', ''),
            'base_url' => env('UNSPLASH_BASE_URL', 'https://api.unsplash.com'),
            'timeout_seconds' => (int) env('UNSPLASH_TIMEOUT_SECONDS', 12),
        ],
    ],

    'translations' => [
        'stale_lock_timeout_minutes' => (int) env('ARGUSLY_TRANSLATION_STALE_LOCK_TIMEOUT_MINUTES', 10),
    ],

    'wp_connector' => [
        'require_timestamp_nonce' => (bool) env('ARGUSLY_WP_REQUIRE_TS_NONCE', false),
        'timestamp_ttl_seconds' => (int) env('ARGUSLY_WP_TS_NONCE_TTL', 300),
        'allow_insecure_local_tls' => (bool) env('ARGUSLY_WP_ALLOW_INSECURE_LOCAL_TLS', true),
        'recent_activity_window_minutes' => (int) env('ARGUSLY_WP_RECENT_ACTIVITY_WINDOW_MINUTES', 15),
        'sync_debug' => (bool) env('ARGUSLY_WP_SYNC_DEBUG', false),
        'sync_debug_site_id' => env('ARGUSLY_WP_SYNC_DEBUG_SITE_ID', ''),
        'featured_image_b64_fallback' => (bool) env('ARGUSLY_WP_FEATURED_IMAGE_B64_FALLBACK', true),
        'featured_image_b64_max_bytes' => (int) env('ARGUSLY_WP_FEATURED_IMAGE_B64_MAX_BYTES', 8 * 1024 * 1024),
    ],

    'contact' => [
        'mailer' => env('ARGUSLY_CONTACT_MAILER', 'mailgun'),
        'recipient_email' => env('ARGUSLY_CONTACT_RECIPIENT_EMAIL', env('MAIL_FROM_ADDRESS')),
        'schedule_call_url' => env('ARGUSLY_CONTACT_SCHEDULE_CALL_URL', ''),
    ],

    'public_blog' => [
        // Optional scoping for connector-synced public blog posts.
        'client_site_id' => env('ARGUSLY_PUBLIC_BLOG_CLIENT_SITE_ID', ''),
        'workspace_id' => env('ARGUSLY_PUBLIC_BLOG_WORKSPACE_ID', ''),
        'max_posts' => (int) env('ARGUSLY_PUBLIC_BLOG_MAX_POSTS', 300),
    ],

    'answer_blocks' => [
        'default_max_visible' => (int) env('ARGUSLY_ANSWER_BLOCKS_MAX_VISIBLE', 3),
    ],

    'onboarding' => [
        'require_email_verification' => (bool) env('ARGUSLY_REQUIRE_EMAIL_VERIFICATION', false),
        'trial_ending_enabled' => (bool) env('ARGUSLY_ONBOARDING_TRIAL_ENDING_ENABLED', false),
    ],

    'launch' => [
        'soft_launch_mode' => (bool) env('APP_SOFT_LAUNCH', false),
        'public_registration_enabled' => (bool) env('PUBLIC_REGISTRATION_ENABLED', true),
        'public_pricing_enabled' => (bool) env('PUBLIC_PRICING_ENABLED', true),
        'registration_block_mode' => (string) env('PUBLIC_REGISTRATION_BLOCK_MODE', 'redirect'),
    ],

    'auth' => [
        'email_code' => [
            'expiry_minutes' => (int) env('ARGUSLY_EMAIL_CODE_EXPIRY_MINUTES', 15),
            'resend_cooldown_seconds' => (int) env('ARGUSLY_EMAIL_CODE_RESEND_COOLDOWN_SECONDS', 60),
            'verify_max_attempts' => (int) env('ARGUSLY_EMAIL_CODE_VERIFY_MAX_ATTEMPTS', 5),
            'verify_decay_seconds' => (int) env('ARGUSLY_EMAIL_CODE_VERIFY_DECAY_SECONDS', 900),
            'resend_max_attempts' => (int) env('ARGUSLY_EMAIL_CODE_RESEND_MAX_ATTEMPTS', 5),
            'resend_decay_seconds' => (int) env('ARGUSLY_EMAIL_CODE_RESEND_DECAY_SECONDS', 900),
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
        'allow_tracking_on_local' => (bool) env('ARGUSLY_ANALYTICS_ALLOW_LOCAL', false),
        'allow_tracking_on_staging' => (bool) env('ARGUSLY_ANALYTICS_ALLOW_STAGING', true),
        'allow_tracking_in_testing' => (bool) env('ARGUSLY_ANALYTICS_ALLOW_TESTING', false),

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
            'ARGUSLY_INTERNAL_VERIFIED_DOMAINS',
            'argusly.com,www.argusly.com'
        )))),
    ],

];
