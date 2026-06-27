<?php

return [
    'corpus_diversity' => [
        'recent_limit' => (int) env('HUMAN_CONTENT_CORPUS_DIVERSITY_RECENT_LIMIT', 8),
        'lookback_days' => (int) env('HUMAN_CONTENT_CORPUS_DIVERSITY_LOOKBACK_DAYS', 180),
        'similarity_threshold' => (int) env('HUMAN_CONTENT_CORPUS_DIVERSITY_SIMILARITY_THRESHOLD', 62),
        'high_similarity_threshold' => (int) env('HUMAN_CONTENT_CORPUS_DIVERSITY_HIGH_THRESHOLD', 78),
        'penalty_max' => (int) env('HUMAN_CONTENT_CORPUS_DIVERSITY_PENALTY_MAX', 24),
    ],
];
