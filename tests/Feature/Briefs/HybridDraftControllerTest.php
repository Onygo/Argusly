<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonVariant;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use App\Services\DraftComparison\HybridDraftEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeHybridControllerContext(string $prefix = 'hybrid-controller'): array
{
    $organization = Organization::query()->create([
        'name' => 'Hybrid Controller Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Hybrid Controller BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Hybrid Controller Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Hybrid Controller Site',
        'site_url' => 'https://hybrid-controller.example.com',
        'allowed_domains' => ['hybrid-controller.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'hybrid-controller-test-plan'],
        [
            'name' => 'Hybrid Controller Test Plan',
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

    $user = User::query()->create([
        'name' => 'Hybrid Controller User',
        'email' => $prefix . '+' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Hybrid controller content',
        'primary_keyword' => 'controller test',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
        'generation_mode' => 'balanced',
        'preferred_length' => 'medium',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Hybrid controller brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'controller test',
    ]);

    return [$organization, $workspace, $site, $user, $brief, $content];
}

function createControllerComparisonWithVariants(
    string $briefId,
    string $contentId,
    string $siteId,
    string $workspaceId,
    int $userId,
    int $successfulVariants = 2,
): DraftComparison {
    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'brief_id' => $briefId,
        'content_id' => $contentId,
        'client_site_id' => $siteId,
        'created_by_user_id' => $userId,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_COMPLETED,
        'items_total' => $successfulVariants,
        'items_done' => $successfulVariants,
        'items_failed' => 0,
    ]);

    for ($i = 0; $i < $successfulVariants; $i++) {
        $draft = Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => $briefId,
            'content_id' => $contentId,
            'client_site_id' => $siteId,
            'status' => 'generated',
            'title' => "Controller Variant $i",
            'output_type' => 'kb_article',
            'content_html' => "<h2>Variant $i</h2><p>This is successful variant content with enough text.</p>",
        ]);

        DraftComparisonVariant::query()->create([
            'id' => (string) Str::uuid(),
            'draft_comparison_id' => $comparison->id,
            'provider_key' => 'openai',
            'model_key' => 'gpt-4.1-mini',
            'status' => DraftComparisonVariant::STATUS_COMPLETED,
            'draft_id' => $draft->id,
        ]);
    }

    return $comparison;
}

it('returns hybrid estimate when eligible', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridControllerContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createControllerComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $response = $this->actingAs($user)
        ->getJson(route('app.briefs.compare.hybrid.estimate', [$brief, $comparison]));

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'comparison_id',
                'eligible',
                'reason',
                'reason_message',
                'can_retry',
                'successful_variant_count',
                'required_variant_count',
                'estimated_credit_cost',
                'available_credits',
                'hybrid_status',
                'hybrid_draft_id',
            ],
        ]);

    expect($response->json('data.eligible'))->toBeTrue()
        ->and($response->json('data.successful_variant_count'))->toBe(2)
        ->and($response->json('data.available_credits'))->toBe(98);
});

it('returns ineligible estimate when not enough variants', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridControllerContext('hybrid-controller-ineligible');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createControllerComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 1,
    );

    $response = $this->actingAs($user)
        ->getJson(route('app.briefs.compare.hybrid.estimate', [$brief, $comparison]));

    $response->assertOk();

    expect($response->json('data.eligible'))->toBeFalse()
        ->and($response->json('data.reason'))->toBe(HybridDraftEligibilityService::REASON_NOT_ENOUGH_SUCCESSFUL_VARIANTS)
        ->and($response->json('data.can_retry'))->toBeTrue();
});

it('queues hybrid draft when eligible', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridControllerContext('hybrid-controller-queue');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createControllerComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    \Illuminate\Support\Facades\Queue::fake();

    $response = $this->actingAs($user)
        ->postJson(route('app.briefs.compare.hybrid', [$brief, $comparison]));

    $response->assertRedirect();

    $comparison->refresh();
    expect((string) $comparison->hybrid_status)->toBe('queued');

    \Illuminate\Support\Facades\Queue::assertPushed(
        \App\Jobs\DraftComparison\GenerateHybridDraftFromComparisonJob::class
    );
});

it('returns error when queueing hybrid with insufficient variants', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridControllerContext('hybrid-controller-error');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createControllerComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 1,
    );

    $response = $this->actingAs($user)
        ->postJson(route('app.briefs.compare.hybrid', [$brief, $comparison]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['draft_compare']);
});

it('returns error when queueing hybrid before comparison is terminal', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridControllerContext('hybrid-controller-not-terminal');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createControllerComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $comparison->status = DraftComparison::STATUS_PROCESSING;
    $comparison->save();

    $response = $this->actingAs($user)
        ->postJson(route('app.briefs.compare.hybrid', [$brief, $comparison]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['draft_compare']);
});

it('shows hybrid status in comparison status endpoint', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridControllerContext('hybrid-controller-status');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createControllerComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $comparison->hybrid_status = 'generating';
    $comparison->hybrid_started_at = now();
    $comparison->save();

    $response = $this->actingAs($user)
        ->getJson(route('app.briefs.compare.status', [$brief, $comparison]));

    $response->assertOk()
        ->assertJsonPath('data.hybrid_status', 'generating');
});

it('prevents duplicate hybrid queueing', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridControllerContext('hybrid-controller-duplicate');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createControllerComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $comparison->hybrid_status = 'queued';
    $comparison->save();

    \Illuminate\Support\Facades\Queue::fake();

    $response = $this->actingAs($user)
        ->postJson(route('app.briefs.compare.hybrid', [$brief, $comparison]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['draft_compare']);

    // Should not queue a new job when already queued
    \Illuminate\Support\Facades\Queue::assertNotPushed(
        \App\Jobs\DraftComparison\GenerateHybridDraftFromComparisonJob::class
    );
});
