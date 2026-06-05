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
            ->assertSee('Scale content operations beyond AI writing', false)
            ->assertSee('href="http://localhost/en/pricing"', false)
            ->assertSee('hreflang="x-default"', false)
            ->assertSee('/nl/prijzen', false);
    });

    it('serves the dutch pricing route with localized hero copy', function () {
        $response = $this->get('/nl/prijzen');

        $response->assertOk()
            ->assertSee('Schaal content operations voorbij AI writing', false)
            ->assertSee('Plan, genereer, optimaliseer, lokaliseer en publiceer content vanuit één platform.', false)
            ->assertSee('Meest gekozen', false)
            ->assertSee('Prijs op aanvraag', false)
            ->assertSee('href="http://localhost/nl/prijzen"', false)
            ->assertSee('/en/pricing', false);
    });
});
