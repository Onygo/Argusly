<?php

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('filters learnings data by scope', function () {
    [$user, $site, $analyticsSite] = createLearningsScopeContext();

    $content = Content::query()->create([
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'title' => 'Mapped Article',
        'published_url' => 'https://example.com/article-1',
    ]);

    $now = now()->subHour();

    AnalyticsEvent::query()->create([
        'analytics_site_id' => $analyticsSite->id,
        'event_type' => 'pageview',
        'visitor_hash' => hash('sha256', 'visitor-mapped'),
        'session_hash' => hash('sha256', 'session-mapped'),
        'path' => '/article-1',
        'path_hash' => hash('sha256', '/article-1'),
        'title' => 'Mapped Article',
        'host' => 'example.com',
        'url' => 'https://example.com/article-1',
        'canonical_url' => 'https://example.com/article-1',
        'canonical_url_hash' => hash('sha256', 'https://example.com/article-1'),
        'url_key' => 'example.com/article-1',
        'content_id' => $content->id,
        'page_type' => 'argusly_content',
        'event_hash' => hash('sha256', 'mapped-event'),
        'event_time' => $now,
        'received_at' => $now,
    ]);

    AnalyticsEvent::query()->create([
        'analytics_site_id' => $analyticsSite->id,
        'event_type' => 'pageview',
        'visitor_hash' => hash('sha256', 'visitor-other'),
        'session_hash' => hash('sha256', 'session-other'),
        'path' => '/pricing',
        'path_hash' => hash('sha256', '/pricing'),
        'title' => 'Pricing',
        'host' => 'example.com',
        'url' => 'https://example.com/pricing',
        'canonical_url' => 'https://example.com/pricing',
        'canonical_url_hash' => hash('sha256', 'https://example.com/pricing'),
        'url_key' => 'example.com/pricing',
        'content_id' => null,
        'page_type' => 'other_page',
        'event_hash' => hash('sha256', 'other-event'),
        'event_time' => $now,
        'received_at' => $now,
    ]);

    $defaultResponse = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', $site) . '?days=7');
    $defaultResponse->assertOk();
    expect($defaultResponse->viewData('summary')['total_views'])->toBe(1);
    expect($defaultResponse->viewData('trending')->count())->toBe(1);

    $allResponse = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', $site) . '?days=7&scope=all');
    $allResponse->assertOk();
    expect($allResponse->viewData('summary')['total_views'])->toBe(2);
    expect($allResponse->viewData('trending')->count())->toBe(2);

    $otherResponse = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', $site) . '?days=7&scope=other_page');
    $otherResponse->assertOk();
    expect($otherResponse->viewData('summary')['total_views'])->toBe(1);
    expect($otherResponse->viewData('trending')->count())->toBe(1);
});

function createLearningsScopeContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Learnings Scope Org',
        'slug' => 'learnings-scope-org-' . Str::random(8),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Scope Org BV',
        'billing_address_line1' => 'Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Learnings Scope Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'learnings-scope-test-plan'],
        [
            'name' => 'Learnings Scope Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::query()->create([
        'name' => 'Scope Owner',
        'email' => 'scope-owner-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Scope Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $analyticsSite = AnalyticsSite::query()->create([
        'client_site_id' => $site->id,
        'allowed_domains' => ['example.com'],
        'is_enabled' => true,
        'verified_at' => now(),
    ]);

    return [$user, $site, $analyticsSite];
}
