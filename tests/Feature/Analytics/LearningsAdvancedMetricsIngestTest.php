<?php

use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['domains.base' => 'publishlayer.local']);
    config(['analytics.enabled' => true]);
    config(['analytics.privacy.salt' => 'test-salt']);
});

it('shows non zero avg scroll and avg read after a tracked visit with engagement events', function () {
    [$user, $site, $analyticsSite] = createLearningsAdvancedMetricsContext();

    postTrackingEvents($this, $analyticsSite, [
        [
            'event_type' => 'pageview',
            'url' => 'https://EXAMPLE.com/Article/?utm=launch',
            'canonical_url' => 'https://example.com/article/',
            'occurred_at' => now()->toIso8601String(),
        ],
        [
            'event_type' => 'scroll_depth',
            'url' => 'https://example.com/article?utm=scroll',
            'depth' => 75,
            'session_id' => 'session-live-metrics',
            'occurred_at' => now()->toIso8601String(),
        ],
        [
            'event_type' => 'read_time',
            'url' => 'https://example.com/article#read',
            'seconds' => 42,
            'session_id' => 'session-live-metrics',
            'occurred_at' => now()->toIso8601String(),
        ],
    ]);

    $response = $this->actingAs($user)
        ->get(route('app.sites.learnings.index', ['site' => $site, 'scope' => 'all']) . '&days=7');

    $response->assertOk();
    $trending = $response->viewData('trending');

    expect($trending)->not->toBeEmpty();
    expect((int) $trending->first()['views'])->toBe(1);
    expect((float) $trending->first()['avg_scroll_depth'])->toBeGreaterThan(0.0);
    expect((float) $trending->first()['avg_read_time'])->toBeGreaterThan(0.0);
});

function createLearningsAdvancedMetricsContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Learnings Advanced Metrics Org',
        'slug' => 'learnings-advanced-' . Str::random(8),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Advanced Metrics BV',
        'billing_address_line1' => 'Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Learnings Advanced Metrics Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'learnings-advanced-metrics-test-plan'],
        [
            'name' => 'Learnings Advanced Metrics Plan',
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
        'name' => 'Learnings Metrics User',
        'email' => 'learnings-advanced-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Learnings Advanced Metrics Site',
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

function postTrackingEvents(object $testCase, AnalyticsSite $analyticsSite, array $events): void
{
    $baseDomain = config('domains.base', 'publishlayer.local');
    $host = "track.{$baseDomain}";
    $url = "http://{$host}/api/tracking/events";

    $response = $testCase
        ->withHeaders([
            'Host' => $host,
            'Origin' => 'https://example.com',
        ])
        ->postJson($url, [
            'site_key' => $analyticsSite->public_key,
            'events' => $events,
        ]);

    $response->assertOk();
}
