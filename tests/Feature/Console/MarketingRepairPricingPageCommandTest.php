<?php

use App\Models\Plan;
use App\Services\SiteSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('repairs pricing plans, content, and localized route output', function () {
    $this->artisan('marketing:repair-pricing-page')
        ->expectsOutputToContain('Pricing repair completed.')
        ->expectsOutputToContain('creator')
        ->expectsOutputToContain('growth')
        ->expectsOutputToContain('/en/pricing')
        ->expectsOutputToContain('/nl/prijzen')
        ->assertExitCode(0);

    expect(Plan::query()->where('slug', 'creator')->exists())->toBeTrue()
        ->and(Plan::query()->where('slug', 'growth')->exists())->toBeTrue()
        ->and(app(SiteSettingsService::class)->get('marketing_pricing_page'))->not->toBeNull();
});
