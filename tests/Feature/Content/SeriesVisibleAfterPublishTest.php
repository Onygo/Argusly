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

$makeContext = function (string $prefix): array {
    $organization = Organization::query()->create([
        'name' => $prefix . ' Org',
        'slug' => Str::slug($prefix) . '-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => $prefix . ' BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => $prefix . ' Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => $prefix . ' Site',
        'site_url' => 'https://' . Str::slug($prefix) . '.example.com',
        'base_url' => 'https://' . Str::slug($prefix) . '.example.com',
        'allowed_domains' => [Str::slug($prefix) . '.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => Str::slug($prefix) . '-plan-' . Str::random(6),
        'name' => $prefix . ' Plan',
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
        'name' => $prefix . ' Owner',
        'email' => Str::slug($prefix) . '-owner-' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return [$user, $organization, $site];
};

it('keeps published series visible in index and only hides archived by default', function () use ($makeContext) {
    [$user, $organization, $site] = $makeContext('Series Visible');

    $publishedSeries = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Published Cluster',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'supporting_keywords' => ['governance policy'],
        'articles_count' => 3,
        'status' => ContentSeries::STATUS_PUBLISHED,
        'is_locked' => true,
        'created_by' => $user->id,
    ]);

    $archivedSeries = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Archived Cluster',
        'main_topic' => 'Legacy governance',
        'primary_keyword' => 'legacy governance',
        'supporting_keywords' => ['legacy policy'],
        'articles_count' => 2,
        'status' => ContentSeries::STATUS_ARCHIVED,
        'is_locked' => true,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('app.content.series.index'))
        ->assertOk()
        ->assertSee($publishedSeries->name)
        ->assertDontSee($archivedSeries->name);

    $this->actingAs($user)
        ->get(route('app.content.series.index', ['filter' => 'archived']))
        ->assertOk()
        ->assertSee($archivedSeries->name);
});
