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
            ->assertSee('Simple pricing for autonomous marketing', false)
            ->assertSee('Start with the Argusly Platform. Scale with credits. Add sites when your operation grows.', false)
            ->assertSee('/en/pricing', false)
            ->assertSee('/nl/prijzen', false);
    });

    it('serves the dutch pricing route with localized hero copy', function () {
        $response = $this->get('/nl/prijzen');

        $response->assertOk()
            ->assertSee('Eenvoudige pricing voor autonome marketing', false)
            ->assertSee('Start met het Argusly Platform. Schaal met credits. Voeg sites toe wanneer je organisatie groeit.', false)
            ->assertSee('Argusly Platform', false)
            ->assertSee('Start abonnement', false)
            ->assertSee('Pilot aanvragen', false)
            ->assertSee('/nl/prijzen', false)
            ->assertSee('/en/pricing', false);
    });
});
