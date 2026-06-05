<?php

/*
|--------------------------------------------------------------------------
| LLMs.txt Configuration
|--------------------------------------------------------------------------
|
| This configuration defines which pages appear in the llms.txt file,
| which is used for AI crawler and LLM-oriented agent discovery.
|
| Only include public, indexable pages that provide value for understanding
| the product, company, or content.
|
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Site Info
    |--------------------------------------------------------------------------
    |
    | Basic site information shown at the top of llms.txt
    |
    */
    'site_name' => env('APP_NAME', 'Argusly'),
    'site_description' => 'public.landing.meta_description',

    /*
    |--------------------------------------------------------------------------
    | Important Pages
    |--------------------------------------------------------------------------
    |
    | Core pages that should always appear in the llms.txt file.
    | Format: ['label' => 'Human readable label', 'route' => 'route.name']
    |
    | Excluded pages:
    | - /product-updates (removed from public access)
    | - /login, /register (auth pages)
    | - /app/* (authenticated app routes)
    | - /admin/* (admin routes)
    | - /billing/* (billing routes)
    |
    */
    'pages' => [
        // Product pages
        [
            'label' => 'Homepage',
            'route' => 'landing',
        ],
        [
            'label' => 'Product overview',
            'route' => 'public.product.overview',
        ],
        [
            'label' => 'Platform features',
            'route' => 'public.product.platform',
            'requires_full_marketing' => true,
        ],
        [
            'label' => 'Pricing',
            'route' => 'pricing',
            'requires_full_marketing' => true,
        ],

        // Content pages
        [
            'label' => 'Blog',
            'route' => 'public.blog.index',
            'requires_full_marketing' => true,
        ],

        // Company pages
        [
            'label' => 'About us',
            'route' => 'public.company.about',
        ],
        [
            'label' => 'Contact',
            'route' => 'public.company.contact',
        ],
        [
            'label' => 'Roadmap',
            'route' => 'public.company.roadmap',
            'requires_full_marketing' => true,
        ],

        // Legal / Trust pages
        [
            'label' => 'Legal hub',
            'route' => 'public.legal.index',
        ],
        [
            'label' => 'Privacy policy',
            'route' => 'public.legal.privacy',
        ],
        [
            'label' => 'Terms of service',
            'route' => 'public.legal.terms',
        ],
        [
            'label' => 'Security',
            'route' => 'public.legal.security',
        ],
        [
            'label' => 'Cookies policy',
            'route' => 'public.legal.cookies',
        ],
        [
            'label' => 'Subprocessors',
            'route' => 'public.legal.subprocessors',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Include Blog Articles
    |--------------------------------------------------------------------------
    |
    | Whether to include individual blog article links in llms.txt
    |
    */
    'include_blog_articles' => true,

    /*
    |--------------------------------------------------------------------------
    | Blog Article Limits
    |--------------------------------------------------------------------------
    |
    | Maximum number of blog articles to include in summary vs full modes
    |
    */
    'blog_limit_summary' => 30,
    'blog_limit_full' => 200,

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long to cache the llms.txt content (in minutes)
    |
    */
    'cache_ttl' => 10,

    /*
    |--------------------------------------------------------------------------
    | Base URL Override
    |--------------------------------------------------------------------------
    |
    | Force a specific base URL for all links. Leave null to use APP_URL.
    | This ensures production URLs are used even in development.
    |
    */
    'base_url' => env('LLMS_BASE_URL', null),
];
