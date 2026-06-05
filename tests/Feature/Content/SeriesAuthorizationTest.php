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

$makeOrgContext = function (string $prefix): array {
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

it('restricts series access to organization scope while allowing support-mode superadmin view', function () use ($makeOrgContext) {
    [$ownerA, $orgA, $siteA] = $makeOrgContext('Series Auth A');
    [$ownerB] = $makeOrgContext('Series Auth B');

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgA->id,
        'site_id' => $siteA->id,
        'name' => 'Org A Series',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'supporting_keywords' => ['governance policy'],
        'articles_count' => 2,
        'status' => ContentSeries::STATUS_DRAFT,
        'is_locked' => false,
        'created_by' => $ownerA->id,
    ]);

    $this->actingAs($ownerB)
        ->get(route('app.content.series.show', $series))
        ->assertForbidden();

    $superadmin = User::query()->create([
        'name' => 'Support Superadmin',
        'email' => 'support-superadmin-' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => null,
        'role' => 'admin',
        'is_admin' => true,
        'admin_role' => 'superadmin',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($superadmin)
        ->withSession([
            'support_mode_enabled' => true,
            'support_target_company_id' => $orgA->id,
            'support_target_user_id' => $ownerA->id,
            'support_started_by_admin_id' => $superadmin->id,
            'support_started_at' => now()->toIso8601String(),
            'support_reason' => 'Authorization test',
        ])
        ->get(route('app.content.series.show', $series))
        ->assertOk()
        ->assertSee($series->name);
});

it('allows delete only for draft series', function () use ($makeOrgContext) {
    [$owner, $organization, $site] = $makeOrgContext('Series Auth Delete');

    $draftSeries = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Draft Series',
        'main_topic' => 'Draft topic',
        'primary_keyword' => 'draft keyword',
        'supporting_keywords' => [],
        'articles_count' => 2,
        'status' => ContentSeries::STATUS_DRAFT,
        'is_locked' => false,
        'created_by' => $owner->id,
    ]);

    $publishedSeries = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Published Series',
        'main_topic' => 'Published topic',
        'primary_keyword' => 'published keyword',
        'supporting_keywords' => [],
        'articles_count' => 2,
        'status' => ContentSeries::STATUS_PUBLISHED,
        'is_locked' => true,
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('app.content.series.destroy', $publishedSeries))
        ->assertForbidden();

    $this->actingAs($owner)
        ->delete(route('app.content.series.destroy', $draftSeries))
        ->assertRedirect(route('app.content.series.index'));

    expect(ContentSeries::query()->whereKey($draftSeries->id)->exists())->toBeFalse();
});
