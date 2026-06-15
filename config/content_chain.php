<?php

return [
    'suggestions' => [
        'max_growth_suggestions_per_content' => (int) env('CONTENT_CHAIN_MAX_GROWTH_SUGGESTIONS', 6),
        'max_footer_links_per_content' => (int) env('CONTENT_CHAIN_MAX_FOOTER_LINKS', 0),
        'source_min_score' => (float) env('CONTENT_CHAIN_SOURCE_MIN_SCORE', 45),
        'confidence_threshold' => (float) env('CONTENT_CHAIN_CONFIDENCE_THRESHOLD', 0.58),
        'scoring' => [
            'weights' => [
                'quality_score' => (float) env('CONTENT_CHAIN_WEIGHT_QUALITY', 0.24),
                'page_views' => (float) env('CONTENT_CHAIN_WEIGHT_PAGE_VIEWS', 0.18),
                'engagement_rate' => (float) env('CONTENT_CHAIN_WEIGHT_ENGAGEMENT', 0.17),
                'recency' => (float) env('CONTENT_CHAIN_WEIGHT_RECENCY', 0.08),
                'chain_gap' => (float) env('CONTENT_CHAIN_WEIGHT_CHAIN_GAP', 0.14),
                'manual_priority' => (float) env('CONTENT_CHAIN_WEIGHT_MANUAL_PRIORITY', 0.12),
                'topical_gap' => (float) env('CONTENT_CHAIN_WEIGHT_TOPICAL_GAP', 0.07),
            ],
            'page_views_ceiling' => (int) env('CONTENT_CHAIN_PAGE_VIEWS_CEILING', 1000),
            'recency_window_days' => (int) env('CONTENT_CHAIN_RECENCY_WINDOW_DAYS', 120),
        ],
        'types' => [
            'deep_dive' => [
                'label' => 'Deep dive',
                'goal_type' => 'deepening',
                'title_prefix' => 'Deep dive:',
            ],
            'comparison' => [
                'label' => 'Comparison',
                'goal_type' => 'comparison',
                'title_prefix' => 'Comparison:',
            ],
            'how_to' => [
                'label' => 'How-to',
                'goal_type' => 'how_to',
                'title_prefix' => 'How to',
            ],
            'use_case' => [
                'label' => 'Use case',
                'goal_type' => 'use_case',
                'title_prefix' => 'Use case:',
            ],
            'mistakes' => [
                'label' => 'Common mistakes',
                'goal_type' => 'faq',
                'title_prefix' => 'Common mistakes in',
            ],
            'support' => [
                'label' => 'Cluster support',
                'goal_type' => 'cluster_support',
                'title_prefix' => 'Supporting angle:',
            ],
            'alternative' => [
                'label' => 'Alternative perspective',
                'goal_type' => 'alternative_perspective',
                'title_prefix' => 'Alternative perspective on',
            ],
        ],
    ],
    'inline_links' => [
        'default_mode' => env('CONTENT_CHAIN_INLINE_MODE', 'review'),
        'default_max_links' => (int) env('CONTENT_CHAIN_DEFAULT_MAX_INLINE_LINKS', 4),
        'allow_heading_links' => (bool) env('CONTENT_CHAIN_ALLOW_HEADING_LINKS', false),
        'footer_heading' => env('CONTENT_CHAIN_FOOTER_HEADING', 'Further reading'),
        'generic_terms' => [
            'article',
            'articles',
            'blog',
            'blogs',
            'content',
            'guide',
            'guides',
            'page',
            'pages',
            'post',
            'posts',
            'read more',
            'click here',
            'learn more',
            'overview',
        ],
        'allowed_tags' => ['p', 'li', 'blockquote'],
    ],
];
