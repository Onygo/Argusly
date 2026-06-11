<?php

use Database\Seeders\MarketingPricingPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('renders three fixed pricing cards plus the enterprise block with growth featured', function () {
    $this->seed(MarketingPricingPageSeeder::class);
    Cache::flush();

    $response = $this->get(route('pricing'));
    $html = $response->getContent();

    $response->assertOk()
        ->assertSeeInOrder(['Creator', 'Growth', 'Scale'])
        ->assertSee('Most popular', false)
        ->assertSee('Custom pricing', false)
        ->assertSee('Approx. 7 to 10 standard SEO articles', false)
        ->assertSee('Approx. 35 to 50 standard SEO articles', false)
        ->assertSee('Approx. 140 to 200 standard SEO articles', false);

    expect(substr_count($html, 'data-pricing-card'))->toBe(3)
        ->and(substr_count($html, 'data-enterprise-block'))->toBe(1);
});

it('uses credits as the primary pricing unit instead of article quotas', function () {
    $this->seed(MarketingPricingPageSeeder::class);
    Cache::flush();

    $response = $this->get(route('pricing'));

    $response->assertOk()
        ->assertSee('100 credits / month', false)
        ->assertSee('500 credits / month', false)
        ->assertSee('2,000 credits / month', false)
        ->assertDontSee('5 articles / month', false)
        ->assertDontSee('20 articles / month', false)
        ->assertDontSee('75 articles / month', false);
});
