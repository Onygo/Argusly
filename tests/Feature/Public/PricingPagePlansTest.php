<?php

use Database\Seeders\MarketingPricingPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('renders one platform pricing card plus the enterprise block', function () {
    $this->seed(MarketingPricingPageSeeder::class);
    Cache::flush();

    $response = $this->get(route('pricing'));
    $html = $response->getContent();

    $response->assertOk()
        ->assertSee('Argusly Platform', false)
        ->assertSee('€99', false)
        ->assertSee('250 credits / month', false)
        ->assertSee('1', false)
        ->assertSee('5', false)
        ->assertSee('Extra sites €29 / month each.', false)
        ->assertSee('Start subscription', false)
        ->assertSee('Request a pilot', false)
        ->assertSee('Scale your operation', false)
        ->assertSee('Extra Site', false)
        ->assertSee('Need temporary capacity? Purchase additional credits without changing your subscription.', false)
        ->assertSee('Custom pricing', false)
        ->assertDontSee('Scale usage when needed', false)
        ->assertDontSee('Solo operation', false)
        ->assertDontSee('Team workflow', false)
        ->assertDontSee('Operational scale', false);

    expect(substr_count($html, 'data-pricing-card'))->toBe(1)
        ->and(substr_count($html, 'data-enterprise-block'))->toBe(1)
        ->and(substr_count($html, '100 credits'))->toBe(1)
        ->and(substr_count($html, '500 credits'))->toBe(1)
        ->and(substr_count($html, '1,000 credits'))->toBe(1);
});

it('uses credits and sites as pricing units instead of article quotas', function () {
    $this->seed(MarketingPricingPageSeeder::class);
    Cache::flush();

    $response = $this->get(route('pricing'));

    $response->assertOk()
        ->assertSee('250 credits / month', false)
        ->assertSee('Extra Site', false)
        ->assertSee('€29', false)
        ->assertSee('What are credits?', false)
        ->assertDontSee('Scale usage when needed', false)
        ->assertDontSee('5 articles / month', false)
        ->assertDontSee('20 articles / month', false)
        ->assertDontSee('75 articles / month', false);
});
