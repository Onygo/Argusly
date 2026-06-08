<?php

return [
    'queue' => env('ARGUSLY_CONTENT_NETWORK_QUEUE', 'content-network'),

    'analysis' => [
        'min_published_items' => max(1, (int) env('ARGUSLY_CONTENT_NETWORK_MIN_PUBLISHED_ITEMS', 3)),
        'max_opportunities_per_source' => max(1, (int) env('ARGUSLY_CONTENT_NETWORK_MAX_OPPS_PER_SOURCE', 5)),
        'max_gap_suggestions' => max(1, (int) env('ARGUSLY_CONTENT_NETWORK_MAX_GAPS', 24)),
    ],
];
