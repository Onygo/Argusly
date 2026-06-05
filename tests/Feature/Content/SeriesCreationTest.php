<?php

use App\Models\ClientSite;
use App\Models\ContentSeries;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates a content series in the user organization scope', function () {
    $organization = Organization::query()->create([
        'name' => 'Series Org',
        'slug' => 'series-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Site',
        'site_url' => 'https://series.example.com',
        'base_url' => 'https://series.example.com',
        'allowed_domains' => ['series.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-plan-' . Str::random(6),
        'name' => 'Series Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'limits' => ['users' => 5],
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'status' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
    ]);

    $organization->update(['active_subscription_id' => $subscription->id]);

    $user = User::query()->create([
        'name' => 'Series Owner',
        'email' => 'series-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.series.store'), [
            'site_id' => $site->id,
            'name' => 'Q2 Chained Engine',
            'main_topic' => 'AI governance',
            'primary_keyword' => 'ai governance workflow',
            'supporting_keywords' => "ai policy\ncontent workflow\nbrand controls",
            'intents' => ['educate', 'commercial'],
            'audience' => 'B2B SaaS marketers',
            'tone' => 'clear and practical',
            'funnel_stage' => 'consideration',
            'articles_count' => 4,
        ])
        ->assertRedirect();

    $series = ContentSeries::query()->first();

    expect($series)->not->toBeNull()
        ->and((int) $series->organization_id)->toBe($organization->id)
        ->and((string) $series->site_id)->toBe((string) $site->id)
        ->and((string) $series->status)->toBe('draft')
        ->and((int) $series->articles_count)->toBe(4)
        ->and((array) $series->supporting_keywords)->toContain('ai policy')
        ->and((array) $series->intent_keys)->toBe(['educate', 'commercial']);
});

it('renders the series setup form with tag-based content intent selection', function () {
    $organization = Organization::query()->create([
        'name' => 'Series Setup Org',
        'slug' => 'series-setup-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Setup BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Setup Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Setup Site',
        'site_url' => 'https://series-setup.example.com',
        'base_url' => 'https://series-setup.example.com',
        'allowed_domains' => ['series-setup.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-setup-plan-' . Str::random(6),
        'name' => 'Series Setup Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'limits' => ['users' => 5],
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'status' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
    ]);

    $organization->update(['active_subscription_id' => $subscription->id]);

    $user = User::query()->create([
        'name' => 'Series Setup Owner',
        'email' => 'series-setup-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('app.content.series.create'))
        ->assertOk()
        ->assertSee('Step 1: Series setup')
        ->assertSee('Select one or more intents')
        ->assertSee('Commercial')
        ->assertDontSee('Make pillar');
});
