<?php

return [
    'queue' => env('PL_DRAFT_INTELLIGENCE_QUEUE', 'ai-low'),
    'analysis_action_key' => env('PL_DRAFT_ANALYSIS_ACTION_KEY', 'draft.analysis'),
    'improvement_action_key' => env('PL_DRAFT_IMPROVEMENT_ACTION_KEY', 'draft.improvement'),
    'analysis_model' => env('PL_DRAFT_INTELLIGENCE_ANALYSIS_MODEL', ''),
    'analysis_temperature' => (float) env('PL_DRAFT_INTELLIGENCE_ANALYSIS_TEMPERATURE', 0.0),
    'improvement_model' => env('PL_DRAFT_INTELLIGENCE_IMPROVEMENT_MODEL', ''),
    'improvement_temperature' => (float) env('PL_DRAFT_INTELLIGENCE_IMPROVEMENT_TEMPERATURE', 0.1),
    'improvement_max_tokens' => (int) env('PL_DRAFT_INTELLIGENCE_IMPROVEMENT_MAX_TOKENS', 0),
    'improvement_retry_max_tokens' => (int) env('PL_DRAFT_INTELLIGENCE_IMPROVEMENT_RETRY_MAX_TOKENS', 0),
    'analysis_display_credits' => (float) env('PL_DRAFT_INTELLIGENCE_ANALYSIS_DISPLAY_CREDITS', 0.2),
    'improvement_display_credits' => (float) env('PL_DRAFT_INTELLIGENCE_IMPROVEMENT_DISPLAY_CREDITS', 0.5),
];
