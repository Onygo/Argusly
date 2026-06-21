<?php

use App\Models\Plan;
use App\Services\SiteSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('repairs pricing plans, content, and localized route output', function () {
    $this->artisan('marketing:repair-pricing-page')
        ->expectsOutputToContain('Pricing repair completed.')
        ->expectsOutputToContain('platform_250')
        ->expectsOutputToContain('platform_500')
        ->expectsOutputToContain('/en/pricing')
        ->expectsOutputToContain('/nl/prijzen')
        ->assertExitCode(0);

    expect(Plan::query()->where('slug', 'platform_250')->where('price_monthly_cents', 9900)->exists())->toBeTrue()
        ->and(Plan::query()->where('slug', 'platform_500')->where('included_credits_per_interval', 500)->exists())->toBeTrue()
        ->and(app(SiteSettingsService::class)->get('marketing_pricing_page'))->not->toBeNull();
});
