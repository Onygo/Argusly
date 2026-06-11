<?php

return [
    'enabled' => (bool) env('LEGACY_PUBLISHLAYER_REDIRECTS_ENABLED', true),

    'source_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', env('LEGACY_PUBLISHLAYER_REDIRECT_HOSTS', 'publishlayer.com,www.publishlayer.com'))
    ))),

    'target_base_url' => rtrim(env('LEGACY_PUBLISHLAYER_REDIRECT_TARGET', 'https://argusly.com'), '/'),

    'default_locale' => 'en',

    'locales' => ['en', 'nl'],

    'exact_paths' => [
        '/' => '/en/',
        '/en' => '/en/',
        '/nl' => '/nl/',
        '/pricing' => '/en/pricing',
        '/prijzen' => '/nl/prijzen',
        '/blog' => '/en/blog',
        '/contact' => '/en/company/contact',
        '/early-access' => '/en/early-access',
        '/vroege-toegang' => '/nl/vroege-toegang',
        '/agentic-marketing' => '/en/agentic-marketing',
        '/knowledge-base' => '/en/blog',
        '/kennisbank' => '/nl/blog',
        '/product/overview' => '/en/product/overview',
        '/product/platform' => '/en/product/platform',
        '/product/capabilities' => '/en/product/platform#capabilities',
        '/product/governance' => '/en/product/platform#governance',
        '/product/intelligence' => '/en/product/platform#intelligence',
        '/company/about' => '/en/company/about',
        '/company/contact' => '/en/company/contact',
        '/company/roadmap' => '/en/company/roadmap',
        '/legal' => '/en/legal',
        '/legal/privacy' => '/en/legal/privacy',
        '/legal/terms' => '/en/legal/terms',
        '/legal/security' => '/en/legal/security',
        '/legal/cookies' => '/en/legal/cookies',
        '/legal/subprocessors' => '/en/legal/subprocessors',
    ],

    'localized_aliases' => [
        'en' => [
            'knowledge-base' => 'blog',
            'product/capabilities' => 'product/platform#capabilities',
            'product/governance' => 'product/platform#governance',
            'product/intelligence' => 'product/platform#intelligence',
        ],
        'nl' => [
            'pricing' => 'prijzen',
            'early-access' => 'vroege-toegang',
            'knowledge-base' => 'blog',
            'kennisbank' => 'blog',
            'blog/category' => 'blog/categorie',
            'product/overview' => 'product/overzicht',
            'product/capabilities' => 'product/platform#capabilities',
            'product/mogelijkheden' => 'product/platform#capabilities',
            'product/governance' => 'product/platform#governance',
            'product/intelligence' => 'product/platform#intelligence',
            'company' => 'bedrijf',
            'company/about' => 'bedrijf/over-ons',
            'company/contact' => 'bedrijf/contact',
            'company/roadmap' => 'bedrijf/roadmap',
            'legal' => 'juridisch',
            'legal/privacy' => 'juridisch/privacy',
            'legal/terms' => 'juridisch/voorwaarden',
            'legal/security' => 'juridisch/beveiliging',
            'legal/cookies' => 'juridisch/cookies',
            'legal/subprocessors' => 'juridisch/subverwerkers',
        ],
    ],
];
