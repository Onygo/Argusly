<?php

use App\Models\ClientSite;
use App\Models\Content;
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

    return [$user, $organization, $workspace, $site];
};

it('duplicates a series as a new unlocked draft without strategy or generated content', function () use ($makeContext) {
    [$user, $organization, $workspace, $site] = $makeContext('Series Duplicate');

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Original Cluster',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'supporting_keywords' => ['governance policy', 'workflow controls'],
        'audience' => 'B2B SaaS',
        'tone' => 'practical',
        'funnel_stage' => 'consideration',
        'articles_count' => 4,
        'status' => ContentSeries::STATUS_PUBLISHED,
        'is_locked' => true,
        'strategy_json' => ['angle' => 'Original angle', 'articles' => [['article_number' => 1, 'title' => 'One']]],
        'publish_plan_json' => ['publish_history' => [['run_at' => now()->toIso8601String()]]],
        'created_by' => $user->id,
    ]);

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'series_id' => $series->id,
        'title' => 'Generated from source',
        'primary_keyword' => 'ai governance workflow',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $this->actingAs($user)
        ->post(route('app.content.series.duplicate', $series))
        ->assertRedirect();

    $cloned = ContentSeries::query()
        ->where('organization_id', $organization->id)
        ->where('id', '!=', $series->id)
        ->latest('created_at')
        ->first();

    expect($cloned)->not->toBeNull()
        ->and((string) $cloned->status)->toBe(ContentSeries::STATUS_DRAFT)
        ->and((bool) $cloned->is_locked)->toBeFalse()
        ->and((string) $cloned->main_topic)->toBe((string) $series->main_topic)
        ->and((string) $cloned->primary_keyword)->toBe((string) $series->primary_keyword)
        ->and((array) $cloned->supporting_keywords)->toBe((array) $series->supporting_keywords)
        ->and((string) $cloned->audience)->toBe((string) $series->audience)
        ->and((string) $cloned->tone)->toBe((string) $series->tone)
        ->and((string) $cloned->funnel_stage)->toBe((string) $series->funnel_stage)
        ->and((int) $cloned->articles_count)->toBe((int) $series->articles_count)
        ->and($cloned->strategy_json)->toBeNull()
        ->and($cloned->publish_plan_json)->toBeNull();

    expect(Content::query()->where('series_id', $cloned->id)->count())->toBe(0);
});
