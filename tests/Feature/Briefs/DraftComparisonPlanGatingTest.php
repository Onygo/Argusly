<?php

use App\Jobs\DraftComparison\GenerateHybridDraftFromComparisonJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\DraftComparison\DraftComparisonModelCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDraftCompareGatingContext(string $prefix = 'draft-compare-gating'): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Compare Gating Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Draft Compare Gating BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Gating Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Draft Compare Gating Site',
        'site_url' => 'https://draft-compare-gating.example.com',
        'allowed_domains' => ['draft-compare-gating.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'slug' => 'gating-plan-' . Str::random(6),
        'key' => 'gating-plan-' . Str::random(6),
        'name' => 'Draft Compare Gating Plan',
        'interval' => 'month',
        'monthly_price_cents' => 7900,
        'price_cents' => 7900,
        'currency' => 'EUR',
        'vat_included' => true,
        'included_credits' => 300,
        'included_credits_per_interval' => 300,
        'seat_limit' => 3,
        'limits' => ['sites' => 3, 'users' => 3],
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 7900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 300,
        'seat_limit' => 3,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    $user = User::query()->create([
        'name' => 'Draft Compare Gating User',
        'email' => $prefix . '+' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Draft compare gating brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'draft compare gating keyword',
    ]);

    return [$organization, $workspace, $site, $plan, $subscription, $user, $brief];
}

function setDraftCompareWorkspaceFeature(
    Workspace $workspace,
    Organization $organization,
    Subscription $subscription,
    Plan $plan,
    string $featureKey,
    string $valueType,
    mixed $value,
): void {
    WorkspaceEntitlement::query()->updateOrCreate(
        [
            'workspace_id' => $workspace->id,
            'feature_key' => $featureKey,
        ],
        [
            'organization_id' => $organization->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'value_type' => $valueType,
            'value_bool' => $valueType === 'bool' ? (bool) $value : null,
            'value_int' => $valueType === 'int' ? (int) $value : null,
            'value_string' => null,
            'value_json' => null,
            'source' => 'test',
            'effective_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
            'refreshed_at' => now(),
        ]
    );
}

it('shows upgrade state and blocks compare creation when draft compare is disabled', function () {
    [$organization, $workspace, , $plan, $subscription, $user, $brief] = makeDraftCompareGatingContext();

    setDraftCompareWorkspaceFeature($workspace, $organization, $subscription, $plan, 'draft_compare_enabled', 'bool', false);

    $setup = $this->actingAs($user)->get(route('app.content.workspace.compare.setup', $brief));

    $setup->assertOk();
    $setup->assertSee('Unlock AI Draft Comparison');

    $modelKeys = collect(app(DraftComparisonModelCatalog::class)->options())
        ->pluck('key')
        ->take(2)
        ->all();

    $response = $this->actingAs($user)->post(route('app.briefs.compare.store', $brief), [
        'mode' => 'compare_two',
        'model_keys' => $modelKeys,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('model_keys.0');
    expect(DraftComparison::query()->count())->toBe(0);
});

it('enforces the max model entitlement in estimate validation', function () {
    [$organization, $workspace, , $plan, $subscription, $user, $brief] = makeDraftCompareGatingContext('draft-compare-gating-max-models');

    setDraftCompareWorkspaceFeature($workspace, $organization, $subscription, $plan, 'draft_compare_enabled', 'bool', true);
    setDraftCompareWorkspaceFeature($workspace, $organization, $subscription, $plan, 'draft_compare_max_models', 'int', 1);

    $options = app(DraftComparisonModelCatalog::class)->options();
    expect(count($options))->toBeGreaterThanOrEqual(2);

    $response = $this->actingAs($user)->postJson(route('app.briefs.compare.estimate', $brief), [
        'mode' => 'compare_two',
        'model_keys' => [
            (string) $options[0]['key'],
            (string) $options[1]['key'],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['model_keys']);
});

it('blocks hybrid generation when hybrid entitlement is disabled', function () {
    Queue::fake();

    [$organization, $workspace, $site, $plan, $subscription, $user, $brief] = makeDraftCompareGatingContext('draft-compare-gating-hybrid');

    setDraftCompareWorkspaceFeature($workspace, $organization, $subscription, $plan, 'draft_compare_enabled', 'bool', true);
    setDraftCompareWorkspaceFeature($workspace, $organization, $subscription, $plan, 'draft_compare_hybrid_enabled', 'bool', false);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_COMPLETED,
        'items_total' => 2,
        'items_done' => 2,
    ]);

    $draftA = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Hybrid candidate A',
        'output_type' => 'kb_article',
        'content_html' => '<p>A</p>',
    ]);

    $draftB = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Hybrid candidate B',
        'output_type' => 'kb_article',
        'content_html' => '<p>B</p>',
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftA->id,
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftB->id,
    ]);

    $response = $this->actingAs($user)->post(route('app.briefs.compare.hybrid', [$brief, $comparison]));

    $response->assertRedirect();
    $response->assertSessionHasErrors('draft_compare');

    Queue::assertNotPushed(GenerateHybridDraftFromComparisonJob::class);
});

it('hides premium models on setup when premium models are not allowed', function () {
    [$organization, $workspace, , $plan, $subscription, $user, $brief] = makeDraftCompareGatingContext('draft-compare-gating-premium');

    $allOptions = app(DraftComparisonModelCatalog::class)->options();
    expect($allOptions)->not->toBeEmpty();

    $premiumModel = (string) data_get($allOptions, '0.model', '');
    config()->set('credits.draft_compare.premium_model_patterns', [$premiumModel]);

    setDraftCompareWorkspaceFeature($workspace, $organization, $subscription, $plan, 'draft_compare_enabled', 'bool', true);
    setDraftCompareWorkspaceFeature($workspace, $organization, $subscription, $plan, 'draft_compare_premium_models_enabled', 'bool', false);

    $response = $this->actingAs($user)->get(route('app.content.workspace.compare.setup', $brief));

    $response->assertOk();
    if ($premiumModel !== '') {
        $response->assertDontSee($premiumModel);
    }
});
