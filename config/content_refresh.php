<?php

return [
    'weights' => [
        'content_age' => 24,
        'missing_seo_structure' => 20,
        'title_h1_mismatch' => 8,
        'duplicate_title_risk' => 14,
        'short_content' => 12,
        'missing_faq' => 8,
        'weak_internal_linking' => 10,
        'outdated_references' => 8,
        'translation_inconsistency' => 6,
        'chain_outdated' => 8,
    ],
    'thresholds' => [
        'aging_days' => 90,
        'stale_days' => 180,
        'very_stale_days' => 365,
        'weak_internal_link_count' => 2,
        'score_medium' => 35,
        'score_high' => 65,
        'year_pattern_stale_before' => (int) date('Y') - 1,
        'type_word_count_targets' => [
            'article' => 900,
            'knowledge_base' => 700,
            'seo_page' => 600,
            'press_release' => 500,
        ],
    ],
];
