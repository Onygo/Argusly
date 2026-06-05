<?php

return [
    'enabled' => (bool) env('PUBLISHLAYER_INTERNAL_LINKING_ENABLED', true),
    'max_links_per_article' => (int) env('PUBLISHLAYER_INTERNAL_LINKING_MAX_LINKS_PER_ARTICLE', 4),
    'max_links_per_paragraph' => (int) env('PUBLISHLAYER_INTERNAL_LINKING_MAX_LINKS_PER_PARAGRAPH', 1),
    'candidate_limit' => (int) env('PUBLISHLAYER_INTERNAL_LINKING_CANDIDATE_LIMIT', 12),
    'min_similarity_score' => (float) env('PUBLISHLAYER_INTERNAL_LINKING_MIN_SIMILARITY_SCORE', 0.18),
    'prefer_same_chain' => (bool) env('PUBLISHLAYER_INTERNAL_LINKING_PREFER_SAME_CHAIN', true),
    'inject_into_html' => (bool) env('PUBLISHLAYER_INTERNAL_LINKING_INJECT_INTO_HTML', true),
];
