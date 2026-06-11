<?php

use Database\Seeders\MarketingPricingPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

describe('Pricing page localization', function () {
    beforeEach(function () {
        $this->seed(MarketingPricingPageSeeder::class);
        Cache::flush();
    });

    it('serves the english pricing route with canonical and hreflang output', function () {
        $response = $this->get('/en/pricing');

        $response->assertOk()
            ->assertSee('Scale autonomous content operations beyond AI writing', false)
            ->assertSee('/en/pricing', false)
            ->assertSee('/nl/prijzen', false);
    });

    it('serves the dutch pricing route with localized hero copy', function () {
        $response = $this->get('/nl/prijzen');

        $response->assertOk()
            ->assertSee('Schaal autonome content operations voorbij AI writing', false)
            ->assertSee('Research, plan, genereer, optimaliseer, lokaliseer en publiceer content vanuit één klantgestuurd platform.', false)
            ->assertSee('Meest gekozen', false)
            ->assertSee('Prijs op aanvraag', false)
            ->assertSee('/nl/prijzen', false)
            ->assertSee('/en/pricing', false);
    });
});
