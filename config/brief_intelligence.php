<?php

return [
    'queue' => env('PUBLISHLAYER_BRIEF_INTELLIGENCE_QUEUE', 'brief-intelligence'),

    'summary' => [
        'max_findings' => (int) env('PUBLISHLAYER_BRIEF_INTELLIGENCE_MAX_FINDINGS', 24),
        'max_terms' => (int) env('PUBLISHLAYER_BRIEF_INTELLIGENCE_MAX_TERMS', 20),
    ],

    'billing' => [
        'enabled_by_default' => (bool) env('PUBLISHLAYER_BRIEF_INTELLIGENCE_BILLING_ENABLED', false),
        'credits_per_run' => max(0, (int) env('PUBLISHLAYER_BRIEF_INTELLIGENCE_CREDITS_PER_RUN', 0)),
    ],
];
