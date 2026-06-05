<?php

return [
    'early_bird' => [
        'inherit_plan_key' => env('PUBLISHLAYER_EARLY_BIRD_INHERIT_PLAN_KEY', ''),
        'features' => [
            'can_generate_briefs' => true,
            'can_generate_drafts' => true,
            'can_push_to_wp' => true,
            'credit_pack_purchase_enabled' => true,
        ],
        'limits' => [
            'articles_generated' => 999,
            'workspaces' => 1,
            'users' => 3,
            'sites' => 1,
        ],
    ],
];
