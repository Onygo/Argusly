<?php

use Database\Seeders\MarketingPricingPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

describe('Public pricing page content', function () {
    it('renders the pricing page with autonomous content operations positioning', function () {
        $this->seed(MarketingPricingPageSeeder::class);
        Cache::flush();

        $response = $this->get(route('pricing'));

        $response->assertOk()
            ->assertSee('Simple pricing for autonomous marketing', false)
            ->assertSee('Start with the Argusly Platform. Scale with credits. Add sites when your operation grows.', false)
            ->assertSee('More than AI writing', false)
            ->assertSee('Argusly Platform', false)
            ->assertSee('Extra Sites', false)
            ->assertSee('What are credits?', false)
            ->assertSee('Enterprise', false)
            ->assertSee('Scale when needed', false)
            ->assertSee('Start subscription', false)
            ->assertSee('Request a pilot', false)
            ->assertDontSee('Starter', false)
            ->assertDontSee('Solo operation', false)
            ->assertDontSee('Team workflow', false)
            ->assertDontSee('Operational scale', false)
            ->assertDontSee('Agency', false)
            ->assertDontSee('articles/month', false);
    });

    it('keeps localized hreflang metadata while not surfacing old plan language', function () {
        $this->seed(MarketingPricingPageSeeder::class);
        Cache::flush();

        $response = $this->get(route('pricing'));

        $response->assertOk()
            ->assertSee('hreflang="en"', false)
            ->assertSee('hreflang="nl"', false)
            ->assertDontSee('Starter', false)
            ->assertDontSee('>Pro<', false);
    });

    it('shows credit packs and faq entries on the pricing page', function () {
        $this->seed(MarketingPricingPageSeeder::class);
        Cache::flush();

        $response = $this->get(route('pricing'));

        $response->assertOk()
            ->assertSee('100 credits', false)
            ->assertSee('500 credits', false)
            ->assertSee('1,000 credits', false)
            ->assertSee('What are credits?', false)
            ->assertSee('Credit packs for temporary peaks', false);
    });
});
