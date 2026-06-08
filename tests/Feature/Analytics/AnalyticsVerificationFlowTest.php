<?php

use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('verifies an enabled analytics site when the verification meta tag matches', function () {
    [$user, $site, $analyticsSite] = createAnalyticsVerificationContext();

    Http::fake([
        'https://analytics-verify.example.com/' => Http::response(
            '<html><head><meta name="argusly-site-verification" content="' . $analyticsSite->verification_token . '"></head></html>',
            200
        ),
    ]);

    $response = $this->actingAs($user)
        ->post(route('app.sites.analytics.verify', $site));

    $response->assertRedirect(route('app.sites.analytics.show', $site))
        ->assertSessionHas('status', 'Domain verified successfully');

    expect($analyticsSite->fresh()->verified_at)->not->toBeNull();
});

it('returns a setup error when the analytics settings record is missing', function () {
    [$user, $site] = createAnalyticsVerificationContext(withAnalyticsSite: false);

    $response = $this->actingAs($user)
        ->post(route('app.sites.analytics.verify', $site));

    $response->assertRedirect(route('app.sites.analytics.show', $site))
        ->assertSessionHas('error', 'Please enable analytics first.')
        ->assertSessionHas('analytics_error_action', 'enable_analytics');
});

it('returns a setup error when site settings are missing a valid site url', function () {
    [$user, $site] = createAnalyticsVerificationContext();

    $site->update([
        'site_url' => '',
        'base_url' => '',
    ]);

    $response = $this->actingAs($user)
        ->post(route('app.sites.analytics.verify', $site));

    $response->assertRedirect(route('app.sites.analytics.show', $site))
        ->assertSessionHas('error', 'This site is missing a valid site URL. Review site setup and try again.')
        ->assertSessionHas('analytics_error_action', 'review_site_settings');
});

it('returns a setup error when analytics config is missing the verification token', function () {
    [$user, $site, $analyticsSite] = createAnalyticsVerificationContext();

    $analyticsSite->forceFill([
        'verification_token' => '',
    ])->save();

    $response = $this->actingAs($user)
        ->post(route('app.sites.analytics.verify', $site));

    $response->assertRedirect(route('app.sites.analytics.show', $site))
        ->assertSessionHas('error', 'Verification token is missing. Regenerate the token and try again.')
        ->assertSessionHas('analytics_error_action', 'regenerate_token');
});

it('returns a friendly retry state when the external verification request fails', function () {
    [$user, $site] = createAnalyticsVerificationContext();

    Http::fake([
        'https://analytics-verify.example.com/' => Http::response('Upstream error', 502),
    ]);

    $response = $this->actingAs($user)
        ->post(route('app.sites.analytics.verify', $site));

    $response->assertRedirect(route('app.sites.analytics.show', $site))
        ->assertSessionHas('error', 'We could not verify the domain right now. The site returned HTTP 502.')
        ->assertSessionHas('analytics_error_action', 'retry_verification');
});

it('fails safely instead of throwing when insecure local verification is enabled in production', function () {
    [$user, $site] = createAnalyticsVerificationContext();

    config()->set('app.env', 'production');
    config()->set('argusly.http_insecure_local', true);

    $site->update([
        'site_url' => 'https://argusly.local',
        'base_url' => 'https://argusly.local',
    ]);

    $response = $this->actingAs($user)
        ->post(route('app.sites.analytics.verify', $site));

    $response->assertRedirect(route('app.sites.analytics.show', $site))
        ->assertSessionHas('error', 'Verification is temporarily unavailable because the server verification configuration is invalid.');
});

it('rejects the wrong http method for analytics verification', function () {
    [$user, $site] = createAnalyticsVerificationContext();

    $this->actingAs($user)
        ->get(route('app.sites.analytics.verify', $site))
        ->assertStatus(405);
});

it('forbids verifying analytics for a site outside the user organization', function () {
    [$user, $site] = createAnalyticsVerificationContext();
    [$otherUser] = createAnalyticsVerificationContext(organizationSlugPrefix: 'other-analytics-verification-org');

    $this->actingAs($otherUser)
        ->post(route('app.sites.analytics.verify', $site))
        ->assertNotFound();
});

function createAnalyticsVerificationContext(
    bool $withAnalyticsSite = true,
    string $organizationSlugPrefix = 'analytics-verification-org'
): array {
    $organization = Organization::query()->create([
        'name' => 'Analytics Verification Org',
        'slug' => $organizationSlugPrefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Analytics Verification BV',
        'billing_address_line1' => 'Verification Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Analytics Verification Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'analytics-verification-plan'],
        [
            'name' => 'Analytics Verification Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Analytics Verify Site',
        'site_url' => 'https://analytics-verify.example.com',
        'base_url' => 'https://analytics-verify.example.com',
        'allowed_domains' => ['analytics-verify.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $analyticsSite = null;

    if ($withAnalyticsSite) {
        $analyticsSite = AnalyticsSite::query()->create([
            'client_site_id' => $site->id,
            'allowed_domains' => ['analytics-verify.example.com'],
            'is_enabled' => true,
        ]);
    }

    return [$user, $site, $analyticsSite, $workspace, $organization];
}
