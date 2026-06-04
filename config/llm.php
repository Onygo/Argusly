<?php

return [
    'default_provider' => env('LLM_DEFAULT_PROVIDER', 'openai'),
    'default_model' => env('LLM_DEFAULT_MODEL', env('OPENAI_MODEL', 'gpt-4.1-mini')),
    'fallback_provider' => env('LLM_FALLBACK_PROVIDER'),
    'fallback_model' => env('LLM_FALLBACK_MODEL'),
    'temperature' => env('LLM_TEMPERATURE'),
    'max_tokens' => env('LLM_MAX_TOKENS'),

    'credit_cost_keys' => [
        'content_generation' => 'blog_generation',
        'translation' => 'translation',
        'answer_block' => 'answer_block_generation',
        'audit' => 'content_audit',
        'visibility_check' => 'visibility_check',
        'social_post' => 'social_post_generation',
        'newsletter' => 'newsletter_generation',
        'agent_task' => 'agent_task',
        'briefing_execution' => 'marketing_plan_generation',
        'url_to_draft' => 'url_to_draft',
        'chained_content' => 'content_chain_execution',
        'agentic_marketing' => 'marketing_plan_generation',
    ],

    'providers' => [
        'openai' => [
            'name' => 'OpenAI',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key_env' => 'OPENAI_API_KEY',
        ],
        'anthropic' => [
            'name' => 'Anthropic',
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'api_key_env' => 'ANTHROPIC_API_KEY',
        ],
        'google' => [
            'name' => 'Google Gemini',
            'base_url' => env('GOOGLE_AI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'api_key_env' => 'GOOGLE_AI_API_KEY',
        ],
        'mistral' => [
            'name' => 'Mistral AI',
            'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
            'api_key_env' => 'MISTRAL_API_KEY',
        ],
        'groq' => [
            'name' => 'Groq',
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'api_key_env' => 'GROQ_API_KEY',
        ],
        'openrouter' => [
            'name' => 'OpenRouter',
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key_env' => 'OPENROUTER_API_KEY',
        ],
    ],
];
