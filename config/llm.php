<?php

return [
    'default_provider' => env('LLM_DEFAULT_PROVIDER', 'openai'),

    'defaults' => [
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 1800),
        'temperature' => (float) env('LLM_TEMPERATURE', 0.3),
    ],

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
            'default_model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'organization' => env('OPENAI_ORGANIZATION_ID'),
            'project' => env('OPENAI_PROJECT_ID'),
            'auto_recharge_enabled' => (bool) env('OPENAI_AUTO_RECHARGE_ENABLED', false),
            'billing_url' => env('OPENAI_BILLING_URL', 'https://platform.openai.com/settings/organization/billing/overview'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'default_model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-latest'),
            'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'default_model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        ],
        'mistral' => [
            'api_key' => env('MISTRAL_API_KEY'),
            'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
            'default_model' => env('MISTRAL_MODEL', 'mistral-large-latest'),
        ],
    ],

    'timeouts' => [
        'connect_seconds' => (int) env('LLM_CONNECT_TIMEOUT_SECONDS', 10),
        'request_seconds' => (int) env('LLM_REQUEST_TIMEOUT_SECONDS', 180),
    ],

    'retries' => [
        'max_attempts' => (int) env('LLM_RETRY_ATTEMPTS', 2),
        'base_backoff_ms' => (int) env('LLM_RETRY_BACKOFF_MS', 800),
    ],

    'pricing' => [
        'token_factor' => [
            'openai' => (float) env('LLM_TOKEN_FACTOR_OPENAI', 1.0),
            'anthropic' => (float) env('LLM_TOKEN_FACTOR_ANTHROPIC', 1.0),
            'gemini' => (float) env('LLM_TOKEN_FACTOR_GEMINI', 1.0),
            'mistral' => (float) env('LLM_TOKEN_FACTOR_MISTRAL', 1.0),
        ],
        'currency' => env('LLM_COST_CURRENCY', 'EUR'),
        'usd_to_eur_rate' => (float) env('LLM_USD_TO_EUR_RATE', 0.92),
        'model_rates_usd_per_1m' => [
            'openai' => [
                'default' => ['input' => 2.00, 'output' => 8.00],
                'gpt-5.1' => ['input' => 1.25, 'output' => 10.00],
                'gpt-5' => ['input' => 1.25, 'output' => 10.00],
                'gpt-5-mini' => ['input' => 0.25, 'output' => 2.00],
                'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
                'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
                'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
                'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
            ],
            'anthropic' => [
                'default' => ['input' => 3.00, 'output' => 15.00],
                'claude-3-5-sonnet' => ['input' => 3.00, 'output' => 15.00],
                'claude-sonnet' => ['input' => 3.00, 'output' => 15.00],
                'claude-haiku' => ['input' => 0.80, 'output' => 4.00],
                'claude-opus' => ['input' => 15.00, 'output' => 75.00],
            ],
            'gemini' => [
                'default' => ['input' => 0.10, 'output' => 0.40],
                'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
                'gemini-2.5-flash-lite' => ['input' => 0.10, 'output' => 0.40],
                'gemini-2.0-flash' => ['input' => 0.10, 'output' => 0.40],
            ],
            'mistral' => [
                'default' => ['input' => 0.50, 'output' => 1.50],
                'mistral-large' => ['input' => 0.50, 'output' => 1.50],
                'mistral-medium' => ['input' => 0.40, 'output' => 2.00],
                'mistral-small' => ['input' => 0.20, 'output' => 0.60],
            ],
        ],
    ],

    'json' => [
        'fix_retry_enabled' => (bool) env('LLM_JSON_FIX_RETRY_ENABLED', false),
    ],

    'fallback' => [
        'default_enabled' => (bool) env('LLM_DEFAULT_FALLBACK_ENABLED', true),
        'default_provider' => env('LLM_DEFAULT_FALLBACK_PROVIDER', 'openai'),
    ],

    'capabilities' => [
        'openai' => ['text', 'image'],
        'anthropic' => ['text'],
        'gemini' => ['text', 'image'],
        'mistral' => ['text'],
    ],

    'features' => [
        'brief_generation' => ['label' => 'Brief generation', 'modality' => 'text'],
        'draft_generation' => ['label' => 'Draft generation', 'modality' => 'text'],
        'rewrite' => ['label' => 'Rewrite', 'modality' => 'text'],
        'seo_optimization' => ['label' => 'SEO optimization', 'modality' => 'text'],
        'intelligence_analysis' => ['label' => 'Intelligence analysis', 'modality' => 'text'],
        'link_suggestions' => ['label' => 'Link suggestions', 'modality' => 'text'],
        'llm_tracking' => ['label' => 'LLM tracking', 'modality' => 'text'],
        'social_distribution' => ['label' => 'Social distribution', 'modality' => 'text'],
        'image_generation' => ['label' => 'Image generation', 'modality' => 'image'],
    ],
];
