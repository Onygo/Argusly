<?php

use App\Support\LocalizedMarketingUrl;
use App\Support\MarketingNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\MarketingPageSeeder::class);
});

it('redirects legacy pricing paths to canonical localized urls', function () {
    $this->get('/pricing')->assertRedirect('/en/pricing');
    $this->get('/prijzen')->assertRedirect('/nl/prijzen');
});

it('resolves localized marketing pages by translated slug', function () {
    $englishCanonical = url('/en/llm-visibility');
    $dutchCanonical = url('/nl/llm-zichtbaarheid');

    $this->get('/en/llm-visibility')
        ->assertOk()
        ->assertSee('LLM visibility: when AI mentions your brand', false)
        ->assertSee('rel="canonical" href="' . $englishCanonical . '"', false);

    $this->get('/nl/llm-zichtbaarheid')
        ->assertOk()
        ->assertSee('LLM zichtbaarheid: wanneer noemt AI jouw merk?', false)
        ->assertSee('rel="alternate" hreflang="en" href="' . $englishCanonical . '"', false)
        ->assertSee('rel="alternate" hreflang="nl" href="' . $dutchCanonical . '"', false);
});

it('generates localized urls through the helper', function () {
    expect(LocalizedMarketingUrl::route('pricing', [], 'en', false))->toBe('/en/pricing')
        ->and(LocalizedMarketingUrl::route('pricing', [], 'nl', false))->toBe('/nl/prijzen')
        ->and(LocalizedMarketingUrl::page('seo', 'nl', false))->toBe('/nl/seo')
        ->and(LocalizedMarketingUrl::page('ai_search', 'en', false))->toBe('/en/ai-search')
        ->and(LocalizedMarketingUrl::page('ai_search', 'nl', false))->toBe('/nl/ai-zoekmachines');
});

it('renders automotive industry pages in English and Dutch', function () {
    $this->get('/en/markets/automotive')
        ->assertOk()
        ->assertSee('Accelerate automotive growth with AI visibility and market intelligence.', false)
        ->assertSee('Increasing competition between dealers, importers and marketplaces', false)
        ->assertSee('Request AI Visibility Scan', false);

    $this->get('/nl/markten/automotive')
        ->assertOk()
        ->assertSee('Versnel groei in automotive met AI zichtbaarheid en marktintelligentie.', false)
        ->assertSee('Toenemende concurrentie tussen dealers, importeurs en platformen', false)
        ->assertSee('Vraag een AI Visibility Scan aan', false);
});

it('uses the requested primary industry navigation order per locale', function () {
    app()->setLocale('en');

    expect(collect(MarketingNavigation::marketItems())->pluck('label')->all())->toBe([
        'Telecom & Connectivity',
        'Energy, Oil & Gas',
        'Logistics & Supply Chain',
        'Manufacturing',
        'IT Services & SaaS',
        'Consultancy & Professional Services',
        'Automotive',
    ]);

    app()->setLocale('nl');

    expect(collect(MarketingNavigation::marketItems())->pluck('label')->all())->toBe([
        'Telecom & Connectiviteit',
        'Energie, Olie & Gas',
        'Logistiek & Supply Chain',
        'Productie & Manufacturing',
        'IT Dienstverlening & SaaS',
        'Consultancy & Zakelijke Dienstverlening',
        'Automotive',
    ]);
});
