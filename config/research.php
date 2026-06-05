<?php

return [
    'queue' => env('PUBLISHLAYER_RESEARCH_QUEUE', 'research'),

    'source_fetch' => [
        'timeout_seconds' => (int) env('PUBLISHLAYER_RESEARCH_FETCH_TIMEOUT', 20),
        'max_content_chars' => (int) env('PUBLISHLAYER_RESEARCH_FETCH_MAX_CHARS', 60000),
    ],

    'extraction' => [
        'max_content_chars' => (int) env('PUBLISHLAYER_RESEARCH_EXTRACTION_MAX_CHARS', 14000),
    ],

    'summary' => [
        'min_confidence' => (float) env('PUBLISHLAYER_RESEARCH_SUMMARY_MIN_CONFIDENCE', 0.65),
        'max_findings' => (int) env('PUBLISHLAYER_RESEARCH_SUMMARY_MAX_FINDINGS', 60),
    ],

    'billing' => [
        'enabled_by_default' => (bool) env('PUBLISHLAYER_RESEARCH_BILLING_ENABLED', false),
        'credits_per_source' => max(0, (int) env('PUBLISHLAYER_RESEARCH_CREDITS_PER_SOURCE', 1)),
    ],
];
