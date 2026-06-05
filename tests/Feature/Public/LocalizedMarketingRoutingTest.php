<?php

use App\Support\LocalizedMarketingUrl;
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
