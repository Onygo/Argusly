<?php

return [
    'score' => [
        'weights' => [
            'presence' => (float) env('LLM_TRACKING_WEIGHT_PRESENCE', 0.30),
            'position' => (float) env('LLM_TRACKING_WEIGHT_POSITION', 0.25),
            'context' => (float) env('LLM_TRACKING_WEIGHT_CONTEXT', 0.20),
            'citation' => (float) env('LLM_TRACKING_WEIGHT_CITATION', 0.15),
            'competitor_share' => (float) env('LLM_TRACKING_WEIGHT_COMPETITOR_SHARE', 0.10),
        ],
        'component_weights' => [
            'owned_visibility' => (float) env('LLM_TRACKING_WEIGHT_OWNED_VISIBILITY', 0.18),
            'earned_visibility' => (float) env('LLM_TRACKING_WEIGHT_EARNED_VISIBILITY', 0.24),
            'competitor_pressure' => (float) env('LLM_TRACKING_WEIGHT_COMPETITOR_PRESSURE', 0.18),
            'citation_diversity' => (float) env('LLM_TRACKING_WEIGHT_CITATION_DIVERSITY', 0.14),
            'model_confidence' => (float) env('LLM_TRACKING_WEIGHT_MODEL_CONFIDENCE', 0.12),
            'real_world_gap' => (float) env('LLM_TRACKING_WEIGHT_REAL_WORLD_GAP', 0.14),
        ],
    ],

    'providers' => [
        'openai' => filter_var(env('LLM_TRACKING_PROVIDER_OPENAI', true), FILTER_VALIDATE_BOOL),
        'anthropic' => filter_var(env('LLM_TRACKING_PROVIDER_ANTHROPIC', true), FILTER_VALIDATE_BOOL),
        'gemini' => filter_var(env('LLM_TRACKING_PROVIDER_GEMINI', true), FILTER_VALIDATE_BOOL),
        'mistral' => filter_var(env('LLM_TRACKING_PROVIDER_MISTRAL', true), FILTER_VALIDATE_BOOL),
    ],

    'geo' => [
        // LLM tracking runs retain their raw_response, answer_text,
        // normalized_response, and answer_json fields for run analysis.
        // PageGeoObservation retention is intentionally separate: by default it
        // stores a bounded answer summary and omits raw answer/provider payloads.
        'retention' => [
            'default' => [
                'policy' => env('LLM_TRACKING_GEO_DEFAULT_RETENTION_POLICY', 'summary_only'),
                'store_answer_summary' => filter_var(env('LLM_TRACKING_GEO_STORE_ANSWER_SUMMARY', true), FILTER_VALIDATE_BOOL),
                'max_answer_summary_chars' => (int) env('LLM_TRACKING_GEO_MAX_ANSWER_SUMMARY_CHARS', 500),
                'store_raw_payload' => filter_var(env('LLM_TRACKING_GEO_STORE_RAW_PAYLOAD', false), FILTER_VALIDATE_BOOL),
            ],
            'providers' => [
                'openai' => [
                    'policy' => env('LLM_TRACKING_GEO_OPENAI_RETENTION_POLICY', 'summary_only'),
                    'store_answer_summary' => filter_var(env('LLM_TRACKING_GEO_OPENAI_STORE_ANSWER_SUMMARY', true), FILTER_VALIDATE_BOOL),
                    'max_answer_summary_chars' => (int) env('LLM_TRACKING_GEO_OPENAI_MAX_ANSWER_SUMMARY_CHARS', 500),
                    'store_raw_payload' => filter_var(env('LLM_TRACKING_GEO_OPENAI_STORE_RAW_PAYLOAD', false), FILTER_VALIDATE_BOOL),
                ],
            ],
        ],
    ],

    'analysis' => [
        'ignore_words' => [
            'the',
            'and',
            'for',
            'with',
            'from',
            'that',
            'this',
            'best',
            'what',
            'which',
            'when',
            'where',
            'about',
        ],
        'positive_keywords' => [
            'best',
            'recommended',
            'strong',
            'stronger',
            'useful',
            'helpful',
            'powerful',
            'reliable',
            'trusted',
            'leader',
            'leading',
            'great',
            'good',
            'effective',
            'flexible',
            'comprehensive',
        ],
        'negative_keywords' => [
            'weak',
            'poor',
            'bad',
            'limited',
            'confusing',
            'expensive',
            'slow',
            'lacking',
            'missing',
            'outdated',
            'worse',
            'inferior',
            'hard',
            'difficult',
        ],
    ],
];
