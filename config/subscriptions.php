<?php

return [
    'billing_intervals' => [
        'monthly',
        'yearly',
    ],

    'modules' => [
        'core' => 'Core',
        'visibility' => 'Visibility',
        'content' => 'Content',
        'connectors' => 'Connectors',
        'social' => 'Social',
        'campaigns' => 'Campaigns',
        'marketing_os' => 'Marketing OS',
        'competitive_intelligence' => 'Competitive Intelligence',
        'lead_intelligence' => 'Lead Intelligence',
        'agentic_content' => 'Agentic Content',
        'agentic_social' => 'Agentic Social',
    ],

    'plans' => [
        'starter_monthly' => [
            'name' => 'Starter',
            'interval' => 'monthly',
            'currency' => 'EUR',
            'amount' => 9900,
            'modules' => ['core', 'visibility', 'content', 'connectors', 'marketing_os'],
            'limits' => ['brands' => 1, 'competitors' => 3, 'credits' => 5000],
        ],
        'starter_yearly' => [
            'name' => 'Starter',
            'interval' => 'yearly',
            'currency' => 'EUR',
            'amount' => 99000,
            'modules' => ['core', 'visibility', 'content', 'connectors', 'marketing_os'],
            'limits' => ['brands' => 1, 'competitors' => 3, 'credits' => 5000],
        ],
        'growth_monthly' => [
            'name' => 'Growth',
            'interval' => 'monthly',
            'currency' => 'EUR',
            'amount' => 24900,
            'modules' => ['core', 'visibility', 'content', 'connectors', 'social', 'campaigns', 'marketing_os', 'competitive_intelligence'],
            'limits' => ['brands' => 3, 'competitors' => 20, 'credits' => 25000],
        ],
        'growth_yearly' => [
            'name' => 'Growth',
            'interval' => 'yearly',
            'currency' => 'EUR',
            'amount' => 249000,
            'modules' => ['core', 'visibility', 'content', 'connectors', 'social', 'campaigns', 'marketing_os', 'competitive_intelligence'],
            'limits' => ['brands' => 3, 'competitors' => 20, 'credits' => 25000],
        ],
        'scale_monthly' => [
            'name' => 'Scale',
            'interval' => 'monthly',
            'currency' => 'EUR',
            'amount' => 49900,
            'modules' => ['core', 'visibility', 'content', 'connectors', 'social', 'campaigns', 'marketing_os', 'competitive_intelligence', 'lead_intelligence', 'agentic_content', 'agentic_social'],
            'limits' => ['brands' => 10, 'competitors' => 100, 'credits' => 100000],
        ],
        'scale_yearly' => [
            'name' => 'Scale',
            'interval' => 'yearly',
            'currency' => 'EUR',
            'amount' => 499000,
            'modules' => ['core', 'visibility', 'content', 'connectors', 'social', 'campaigns', 'marketing_os', 'competitive_intelligence', 'lead_intelligence', 'agentic_content', 'agentic_social'],
            'limits' => ['brands' => 10, 'competitors' => 100, 'credits' => 100000],
        ],
        'enterprise_monthly' => [
            'name' => 'Enterprise',
            'interval' => 'monthly',
            'currency' => 'EUR',
            'amount' => 0,
            'modules' => ['core', 'visibility', 'content', 'connectors', 'social', 'campaigns', 'marketing_os', 'competitive_intelligence', 'lead_intelligence', 'agentic_content', 'agentic_social'],
            'limits' => ['brands' => null, 'competitors' => null, 'credits' => null],
        ],
        'enterprise_yearly' => [
            'name' => 'Enterprise',
            'interval' => 'yearly',
            'currency' => 'EUR',
            'amount' => 0,
            'modules' => ['core', 'visibility', 'content', 'connectors', 'social', 'campaigns', 'marketing_os', 'competitive_intelligence', 'lead_intelligence', 'agentic_content', 'agentic_social'],
            'limits' => ['brands' => null, 'competitors' => null, 'credits' => null],
        ],
    ],

    'limit_features' => [
        'brands' => 'core',
        'competitors' => 'competitive_intelligence',
        'credits' => 'credits',
    ],
];
