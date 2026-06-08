<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['argusly.launch.soft_launch_mode' => false]);
    $this->seed(\Database\Seeders\MarketingPageSeeder::class);
});

function marketingTopicPages(): array
{
    return [
        [
            'path' => '/nl/ai-zoekmachines',
            'title' => 'Win AI-zoekmachines met Answer Engine Optimization (AEO)',
            'meta_title' => 'Answer Engine Optimization (AEO) voor AI-zoekmachines | Argusly',
            'meta_description' => 'Ontdek wat Answer Engine Optimization (AEO) is, hoe het verschilt van SEO en hoe Argusly AI-zichtbaarheid verbetert met AEO-score en gestructureerde antwoordblokken.',
            'canonical' => url('/nl/ai-zoekmachines'),
            'alternate_en' => url('/en/ai-search'),
            'alternate_nl' => url('/nl/ai-zoekmachines'),
        ],
        [
            'path' => '/nl/seo',
            'title' => 'SEO uitgelegd: hoe je gevonden wordt in zoekmachines',
            'meta_title' => 'Wat is SEO en hoe werkt het in 2026?',
            'meta_description' => 'Leer wat SEO is, hoe zoekmachines werken en waarom traditionele SEO verandert door AI en nieuwe zoekervaringen.',
            'canonical' => url('/nl/seo'),
            'alternate_en' => url('/en/seo'),
            'alternate_nl' => url('/nl/seo'),
        ],
        [
            'path' => '/nl/geo',
            'title' => 'GEO: optimaliseren voor AI en generative search',
            'meta_title' => 'Wat is GEO (Generative Engine Optimization)?',
            'meta_description' => 'Ontdek hoe je content optimaliseert voor AI-systemen zoals ChatGPT en Gemini en zichtbaar wordt in gegenereerde antwoorden.',
            'canonical' => url('/nl/geo'),
            'alternate_en' => url('/en/geo'),
            'alternate_nl' => url('/nl/geo'),
        ],
        [
            'path' => '/nl/llm-zichtbaarheid',
            'title' => 'LLM zichtbaarheid: wanneer noemt AI jouw merk?',
            'meta_title' => 'Wat is LLM visibility en hoe vergroot je het?',
            'meta_description' => 'Begrijp hoe en wanneer jouw merk verschijnt in AI-antwoorden en hoe je dit actief kunt beïnvloeden.',
            'canonical' => url('/nl/llm-zichtbaarheid'),
            'alternate_en' => url('/en/llm-visibility'),
            'alternate_nl' => url('/nl/llm-zichtbaarheid'),
        ],
        [
            'path' => '/nl/ai-visibility-score',
            'title' => 'AI Visibility Score: meet je zichtbaarheid in AI',
            'meta_title' => 'AI Visibility Score: meten van AI zichtbaarheid',
            'meta_description' => 'Leer hoe je zichtbaarheid in AI-systemen meet op basis van aanwezigheid, positie en context.',
            'canonical' => url('/nl/ai-visibility-score'),
            'alternate_en' => url('/en/ai-visibility-score'),
            'alternate_nl' => url('/nl/ai-visibility-score'),
        ],
        [
            'path' => '/nl/seo-en-geo-verschil',
            'title' => 'SEO vs GEO: wat is het verschil en wat heb je nodig?',
            'meta_title' => 'SEO vs GEO uitgelegd: de verschillen en overlap',
            'meta_description' => 'Ontdek het verschil tussen SEO en GEO en hoe je beide combineert voor maximale online zichtbaarheid.',
            'canonical' => url('/nl/seo-en-geo-verschil'),
            'alternate_en' => url('/en/seo-vs-geo'),
            'alternate_nl' => url('/nl/seo-en-geo-verschil'),
        ],
        [
            'path' => '/nl/ai-zoekmachine-optimalisatie',
            'title' => 'AI search optimization: de toekomst van vindbaarheid',
            'meta_title' => 'AI search optimization: SEO en GEO gecombineerd',
            'meta_description' => 'Begrijp hoe AI search werkt en hoe je jouw content optimaliseert voor zowel zoekmachines als AI-systemen.',
            'canonical' => url('/nl/ai-zoekmachine-optimalisatie'),
            'alternate_en' => url('/en/ai-search-optimization'),
            'alternate_nl' => url('/nl/ai-zoekmachine-optimalisatie'),
        ],
        [
            'path' => '/en/ai-search',
            'title' => 'Win AI Search with Answer Engine Optimization (AEO)',
            'meta_title' => 'Answer Engine Optimization (AEO) for AI search | Argusly',
            'meta_description' => 'Learn what Answer Engine Optimization (AEO) is, how it differs from SEO, and how Argusly helps teams improve AI visibility with AEO Score and Structured Answer Blocks.',
            'canonical' => url('/en/ai-search'),
            'alternate_en' => url('/en/ai-search'),
            'alternate_nl' => url('/nl/ai-zoekmachines'),
        ],
        [
            'path' => '/en/seo',
            'title' => 'SEO explained: how search engines rank your content',
            'meta_title' => 'What is SEO and how does it work in 2026?',
            'meta_description' => 'Learn how SEO works, how search engines rank content, and why SEO is changing in the age of AI.',
            'canonical' => url('/en/seo'),
            'alternate_en' => url('/en/seo'),
            'alternate_nl' => url('/nl/seo'),
        ],
        [
            'path' => '/en/geo',
            'title' => 'GEO: optimizing for AI and generative search',
            'meta_title' => 'What is GEO (Generative Engine Optimization)?',
            'meta_description' => 'Learn how to optimize your content for AI systems like ChatGPT and Gemini and appear in generated answers.',
            'canonical' => url('/en/geo'),
            'alternate_en' => url('/en/geo'),
            'alternate_nl' => url('/nl/geo'),
        ],
        [
            'path' => '/en/llm-visibility',
            'title' => 'LLM visibility: when AI mentions your brand',
            'meta_title' => 'What is LLM visibility and how to improve it',
            'meta_description' => 'Understand how and when your brand appears in AI-generated answers and how to influence it.',
            'canonical' => url('/en/llm-visibility'),
            'alternate_en' => url('/en/llm-visibility'),
            'alternate_nl' => url('/nl/llm-zichtbaarheid'),
        ],
        [
            'path' => '/en/ai-visibility-score',
            'title' => 'AI Visibility Score: measure your AI presence',
            'meta_title' => 'AI Visibility Score explained',
            'meta_description' => 'Learn how to measure your visibility in AI systems based on presence, ranking, and context.',
            'canonical' => url('/en/ai-visibility-score'),
            'alternate_en' => url('/en/ai-visibility-score'),
            'alternate_nl' => url('/nl/ai-visibility-score'),
        ],
        [
            'path' => '/en/seo-vs-geo',
            'title' => 'SEO vs GEO: key differences explained',
            'meta_title' => 'SEO vs GEO: what is the difference?',
            'meta_description' => 'Discover the differences between SEO and GEO and how to combine them for maximum visibility.',
            'canonical' => url('/en/seo-vs-geo'),
            'alternate_en' => url('/en/seo-vs-geo'),
            'alternate_nl' => url('/nl/seo-en-geo-verschil'),
        ],
        [
            'path' => '/en/ai-search-optimization',
            'title' => 'AI search optimization: the future of discovery',
            'meta_title' => 'AI search optimization explained',
            'meta_description' => 'Learn how to optimize your content for both search engines and AI-driven discovery systems.',
            'canonical' => url('/en/ai-search-optimization'),
            'alternate_en' => url('/en/ai-search-optimization'),
            'alternate_nl' => url('/nl/ai-zoekmachine-optimalisatie'),
        ],
    ];
}

it('renders every localized marketing topic page with the expected seo metadata', function () {
    foreach (marketingTopicPages() as $page) {
        $this->get($page['path'])
            ->assertOk()
            ->assertSee($page['title'], false)
            ->assertSee('<title>' . e($page['meta_title']) . '</title>', false)
            ->assertSee('<meta name="description" content="' . e($page['meta_description']) . '" />', false)
            ->assertSee('rel="canonical" href="' . $page['canonical'] . '"', false)
            ->assertSee('rel="alternate" hreflang="en" href="' . $page['alternate_en'] . '"', false)
            ->assertSee('rel="alternate" hreflang="nl" href="' . $page['alternate_nl'] . '"', false);
    }
});

it('renders localized resources navigation and hub links without using the blog system', function () {
    $english = $this->get('/en/ai-search');

    $english->assertOk()
        ->assertSee('Resources', false)
        ->assertSee(url('/en/ai-search'), false)
        ->assertSee(url('/en/seo'), false)
        ->assertSee(url('/en/geo'), false)
        ->assertSee(url('/en/llm-visibility'), false)
        ->assertSee(url('/en/ai-visibility-score'), false)
        ->assertSee(url('/en/seo-vs-geo'), false)
        ->assertSee(url('/en/ai-search-optimization'), false)
        ->assertDontSee('/en/blog/seo', false);

    $dutch = $this->get('/nl/ai-zoekmachines');

    $dutch->assertOk()
        ->assertSee('Resources', false)
        ->assertSee(url('/nl/ai-zoekmachines'), false)
        ->assertSee(url('/nl/seo'), false)
        ->assertSee(url('/nl/geo'), false)
        ->assertSee(url('/nl/llm-zichtbaarheid'), false)
        ->assertSee(url('/nl/ai-visibility-score'), false)
        ->assertSee(url('/nl/seo-en-geo-verschil'), false)
        ->assertSee(url('/nl/ai-zoekmachine-optimalisatie'), false)
        ->assertDontSee('/nl/blog/seo', false);
});

it('renders aeo faq schema and marketing markdown routes for ai search pages', function () {
    $english = $this->get('/en/ai-search');

    $english->assertOk()
        ->assertSee('FAQPage', false)
        ->assertSee('What is AEO?', false)
        ->assertSee('How is AEO different from SEO?', false);

    $this->get('/en/ai-search.md')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertSee('# Win AI Search with Answer Engine Optimization (AEO)', false)
        ->assertSee('## Measure your AI visibility with AEO Score', false);

    $this->get('/nl/ai-zoekmachines.md')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertSee('# Win AI-zoekmachines met Answer Engine Optimization (AEO)', false)
        ->assertSee('## Meet je AI-zichtbaarheid met AEO-score', false);
});
