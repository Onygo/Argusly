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
            ->assertSee('Scale autonomous content operations beyond AI writing', false)
            ->assertSee('Research, plan, generate, optimize, localize and publish content from one customer-controlled platform.', false)
            ->assertSee('More than AI writing. Argusly manages the full content lifecycle across CMS, API and LinkedIn channels.', false)
            ->assertSee('Creator', false)
            ->assertSee('Growth', false)
            ->assertSee('Scale', false)
            ->assertSee('Enterprise', false)
            ->assertSee('Flexible AI credits', false)
            ->assertSee('Scale usage when needed', false)
            ->assertSee('Replace fragmented content workflows', false)
            ->assertSee('FAQ', false)
            ->assertDontSee('Starter', false)
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
            ->assertSee('Can I buy extra credits?', false)
            ->assertSee('Can multiple team members collaborate?', false)
            ->assertSee('Can I publish directly to WordPress and LinkedIn?', false);
    });
});
