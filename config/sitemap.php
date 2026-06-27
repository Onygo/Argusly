<?php

return [
    'enabled' => (bool) env('SITEMAP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Generation
    |--------------------------------------------------------------------------
    */
    'chunk_size' => (int) env('SITEMAP_CHUNK_SIZE', 500),
    'cache_store' => env('SITEMAP_CACHE_STORE'),
    'cache_ttl' => (int) env('SITEMAP_CACHE_TTL', 3600),
    'cache_prefix' => env('SITEMAP_CACHE_PREFIX', 'argusly:sitemap'),

    /*
    |--------------------------------------------------------------------------
    | Content types
    |--------------------------------------------------------------------------
    */
    'include_static' => (bool) env('SITEMAP_INCLUDE_STATIC', true),
    'include_topics' => (bool) env('SITEMAP_INCLUDE_TOPICS', true),

    /*
    |--------------------------------------------------------------------------
    | Future placeholders
    |--------------------------------------------------------------------------
    */
    'markdown_support' => (bool) env('SITEMAP_MARKDOWN_SUPPORT', false),

    /*
    |--------------------------------------------------------------------------
    | Static route registry
    |--------------------------------------------------------------------------
    |
    | Keep static sitemap entries centralized here instead of scattering paths
    | across controllers. Only include canonical, non-redirect public routes.
    |
    */
    'static_routes' => [
        'landing',
        'pricing',
        'public.early-access.show',
        'public.solutions.opportunity-intelligence',
        'public.solutions.ai-visibility',
        'public.solutions.competitive-intelligence',
        'public.solutions.marketing-without-large-team',
        'public.agentic-marketing',
        'public.agentic-marketing-operating-system',
        'public.markets.it-services-saas',
        'public.markets.consulting-professional-services',
        'public.markets.recruitment-staffing',
        'public.markets.telecom-connectivity',
        'public.markets.logistics-supply-chain',
        'public.markets.manufacturing',
        'public.markets.energy-industrial-services',
        'public.markets.automotive',
        'public.product.platform',
        'public.product.overview',
        'public.company.about',
        'public.company.contact',
        'public.company.roadmap',
        'public.blog.index',
        'public.legal.index',
        'public.legal.privacy',
        'public.legal.terms',
        'public.legal.security',
        'public.legal.cookies',
        'public.legal.subprocessors',
    ],
];
