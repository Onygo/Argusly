<?php

return [
    'max_requests_per_cluster' => env('ARGUSLY_PROGRAMMATIC_MAX_REQUESTS_PER_CLUSTER', 25),
    'max_requests_per_growth_program' => env('ARGUSLY_PROGRAMMATIC_MAX_REQUESTS_PER_GROWTH_PROGRAM', 100),
    'max_auto_approved_requests' => env('ARGUSLY_PROGRAMMATIC_MAX_AUTO_APPROVED_REQUESTS', 0),
    'require_manual_approval' => env('ARGUSLY_PROGRAMMATIC_REQUIRE_MANUAL_APPROVAL', true),
    'allow_batch_generation' => env('ARGUSLY_PROGRAMMATIC_ALLOW_BATCH_GENERATION', false),
    'estimated_cost_warning_threshold' => env('ARGUSLY_PROGRAMMATIC_COST_WARNING_THRESHOLD', 25.00),
    'estimated_cost_per_1k_tokens' => env('ARGUSLY_PROGRAMMATIC_ESTIMATED_COST_PER_1K_TOKENS', 0.02),
    'max_plan_items_per_cluster' => env('ARGUSLY_PROGRAMMATIC_MAX_PLAN_ITEMS_PER_CLUSTER', 25),
    'max_plan_items_per_growth_program' => env('ARGUSLY_PROGRAMMATIC_MAX_PLAN_ITEMS_PER_GROWTH_PROGRAM', 100),
    'default_publication_cadence' => env('ARGUSLY_PROGRAMMATIC_DEFAULT_PUBLICATION_CADENCE', 'manual'),
    'require_plan_approval' => env('ARGUSLY_PROGRAMMATIC_REQUIRE_PLAN_APPROVAL', true),
    'allow_auto_scheduling' => env('ARGUSLY_PROGRAMMATIC_ALLOW_AUTO_SCHEDULING', false),
];
