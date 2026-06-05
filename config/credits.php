<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Credit Reservations
    |--------------------------------------------------------------------------
    |
    | Configuration for the credit reservation system. Reservations hold credits
    | before AI operations complete, ensuring credits are only consumed on success.
    |
    */
    'reservation_ttl_minutes' => (int) env('PL_CREDIT_RESERVATION_TTL_MINUTES', 30),

    'generation_pricing' => [
        'article' => [
            'baseline_output_tokens' => (int) env('PL_ARTICLE_BASELINE_OUTPUT_TOKENS', 8000),
            'baseline_credits' => (int) env('PL_ARTICLE_BASELINE_CREDITS', 10),
            'step_tokens' => (int) env('PL_ARTICLE_STEP_TOKENS', 2000),
            'step_credits' => (int) env('PL_ARTICLE_STEP_CREDITS', 2),
            'max_credits' => (int) env('PL_ARTICLE_MAX_CREDITS', 16),
            'max_output_tokens' => (int) env('PL_ARTICLE_MAX_OUTPUT_TOKENS', 14000),
            'ui_long_output_tokens' => (int) env('PL_ARTICLE_UI_LONG_OUTPUT_TOKENS', 12000),
        ],
    ],
    'draft_compare' => [
        'max_models' => (int) env('PL_DRAFT_COMPARE_MAX_MODELS', 6),
        'absolute_max_models' => (int) env('PL_DRAFT_COMPARE_ABSOLUTE_MAX_MODELS', 8),
        'scoring_credits_per_variant' => (int) env('PL_DRAFT_COMPARE_SCORING_CREDITS_PER_VARIANT', 0),
        'hybrid_credit_multiplier' => (float) env('PL_DRAFT_COMPARE_HYBRID_CREDIT_MULTIPLIER', 1.0),
        'hybrid_scoring_credits' => (int) env('PL_DRAFT_COMPARE_HYBRID_SCORING_CREDITS', 0),
        'estimated_input_to_output_ratio' => (float) env('PL_DRAFT_COMPARE_ESTIMATED_INPUT_OUTPUT_RATIO', 0.6),
        'entitlements' => [
            'defaults' => [
                'enabled' => filter_var(env('PL_DRAFT_COMPARE_DEFAULT_ENABLED', true), FILTER_VALIDATE_BOOL),
                'hybrid_enabled' => filter_var(env('PL_DRAFT_COMPARE_DEFAULT_HYBRID_ENABLED', true), FILTER_VALIDATE_BOOL),
                'scoring_enabled' => filter_var(env('PL_DRAFT_COMPARE_DEFAULT_SCORING_ENABLED', true), FILTER_VALIDATE_BOOL),
                'premium_models_enabled' => filter_var(env('PL_DRAFT_COMPARE_DEFAULT_PREMIUM_MODELS_ENABLED', true), FILTER_VALIDATE_BOOL),
            ],
        ],
        'premium_model_patterns' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env(
                'PL_DRAFT_COMPARE_PREMIUM_MODEL_PATTERNS',
                'gpt-5*,gpt-4.1,claude-opus*,claude-3-7-sonnet*,claude-sonnet-4*,o1*,o3*,gemini-2.0-pro*,mistral-large*'
            ))
        ))),
        'winner_weights' => [
            'seo_score' => (float) env('PL_DRAFT_COMPARE_WINNER_WEIGHT_SEO', 20),
            'ai_seo_score' => (float) env('PL_DRAFT_COMPARE_WINNER_WEIGHT_AI_SEO', 15),
            'brand_voice_match' => (float) env('PL_DRAFT_COMPARE_WINNER_WEIGHT_BRAND_VOICE', 20),
            'structure_quality' => (float) env('PL_DRAFT_COMPARE_WINNER_WEIGHT_STRUCTURE', 15),
            'readability_score' => (float) env('PL_DRAFT_COMPARE_WINNER_WEIGHT_READABILITY', 10),
            'cta_strength' => (float) env('PL_DRAFT_COMPARE_WINNER_WEIGHT_CTA', 10),
            'conversion_focus' => (float) env('PL_DRAFT_COMPARE_WINNER_WEIGHT_CONVERSION', 10),
        ],
        'model_tier_multipliers' => [
            'mini' => (float) env('PL_DRAFT_COMPARE_MODEL_TIER_MINI_MULTIPLIER', 1.0),
            'flash' => (float) env('PL_DRAFT_COMPARE_MODEL_TIER_FLASH_MULTIPLIER', 1.0),
            'haiku' => (float) env('PL_DRAFT_COMPARE_MODEL_TIER_HAIKU_MULTIPLIER', 1.0),
            'sonnet' => (float) env('PL_DRAFT_COMPARE_MODEL_TIER_SONNET_MULTIPLIER', 1.0),
            'opus' => (float) env('PL_DRAFT_COMPARE_MODEL_TIER_OPUS_MULTIPLIER', 1.0),
            'large' => (float) env('PL_DRAFT_COMPARE_MODEL_TIER_LARGE_MULTIPLIER', 1.0),
        ],
    ],
    'llm_output_caps' => [
        'openai' => [
            'default' => (int) env('PL_OPENAI_DEFAULT_MAX_OUTPUT_TOKENS', 12000),
            'gpt-4.1-mini' => (int) env('PL_OPENAI_GPT41_MINI_MAX_OUTPUT_TOKENS', 12000),
        ],
        'anthropic' => [
            'default' => (int) env('PL_ANTHROPIC_DEFAULT_MAX_OUTPUT_TOKENS', 12000),
        ],
        'gemini' => [
            'default' => (int) env('PL_GEMINI_DEFAULT_MAX_OUTPUT_TOKENS', 12000),
        ],
        'mistral' => [
            'default' => (int) env('PL_MISTRAL_DEFAULT_MAX_OUTPUT_TOKENS', 12000),
        ],
    ],
    'warnings' => [
        'enabled' => filter_var(env('PL_LOW_CREDIT_WARNINGS_ENABLED', true), FILTER_VALIDATE_BOOL),
        'absolute_threshold' => (int) env('PL_LOW_CREDIT_WARNING_ABSOLUTE_THRESHOLD', 10),
        'percentage_threshold' => (float) env('PL_LOW_CREDIT_WARNING_PERCENTAGE_THRESHOLD', 15),
        'resend_cooldown_hours' => (int) env('PL_LOW_CREDIT_WARNING_RESEND_COOLDOWN_HOURS', 24),
        'minimum_automation_run_credits' => (int) env('PL_MINIMUM_AUTOMATION_RUN_CREDITS', 10),
    ],
];
