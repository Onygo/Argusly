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
use App\Models\WorkspaceEntitlement;
use App\Services\CreditWalletService;
use App\Services\DraftComparison\HybridDraftEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeHybridEligibilityContext(string $prefix = 'hybrid-eligibility'): array
{
    $organization = Organization::query()->create([
        'name' => 'Hybrid Eligibility Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Hybrid Eligibility Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Hybrid Eligibility Site',
        'site_url' => 'https://hybrid-eligibility.example.com',
        'allowed_domains' => ['hybrid-eligibility.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Hybrid Eligibility User',
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
        'title' => 'Hybrid eligibility content',
        'primary_keyword' => 'eligibility check',
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
        'title' => 'Hybrid eligibility brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'eligibility check',
    ]);

    return [$organization, $workspace, $site, $user, $brief, $content];
}

function createComparisonWithVariants(
    string $briefId,
    string $contentId,
    string $siteId,
    string $workspaceId,
    int $userId,
    int $successfulVariants = 2,
    int $failedVariants = 0,
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
        'items_total' => $successfulVariants + $failedVariants,
        'items_done' => $successfulVariants,
        'items_failed' => $failedVariants,
    ]);

    for ($i = 0; $i < $successfulVariants; $i++) {
        $draft = Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => $briefId,
            'content_id' => $contentId,
            'client_site_id' => $siteId,
            'status' => 'generated',
            'title' => "Variant $i",
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

    for ($i = 0; $i < $failedVariants; $i++) {
        DraftComparisonVariant::query()->create([
            'id' => (string) Str::uuid(),
            'draft_comparison_id' => $comparison->id,
            'provider_key' => 'openai',
            'model_key' => 'gpt-4.1-mini',
            'status' => DraftComparisonVariant::STATUS_FAILED,
            'error_message' => 'Generation failed',
        ]);
    }

    return $comparison;
}

it('returns eligible when comparison has 2+ successful variants and credits available', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridEligibilityContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $service = app(HybridDraftEligibilityService::class);
    $result = $service->checkEligibility($comparison);

    expect($result['eligible'])->toBeTrue()
        ->and($result['reason'])->toBeNull()
        ->and($result['successful_variant_count'])->toBe(2)
        ->and($result['estimated_credit_cost'])->toBeGreaterThan(0)
        ->and($result['available_credits'])->toBe(98);
});

it('returns ineligible when fewer than 2 successful variants', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridEligibilityContext('hybrid-eligibility-one-variant');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 1,
        failedVariants: 1,
    );

    $service = app(HybridDraftEligibilityService::class);
    $result = $service->checkEligibility($comparison);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reason'])->toBe(HybridDraftEligibilityService::REASON_NOT_ENOUGH_SUCCESSFUL_VARIANTS)
        ->and($result['can_retry'])->toBeTrue()
        ->and($result['successful_variant_count'])->toBe(1);
});

it('returns ineligible when hybrid generation is already running', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridEligibilityContext('hybrid-eligibility-running');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $comparison->hybrid_status = 'generating';
    $comparison->save();

    $service = app(HybridDraftEligibilityService::class);
    $result = $service->checkEligibility($comparison);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reason'])->toBe(HybridDraftEligibilityService::REASON_GENERATION_ALREADY_RUNNING)
        ->and($result['can_retry'])->toBeFalse();
});

it('returns ineligible when insufficient credits', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridEligibilityContext('hybrid-eligibility-no-credits');

    // No credits added

    $comparison = createComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $service = app(HybridDraftEligibilityService::class);
    $result = $service->checkEligibility($comparison);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reason'])->toBe(HybridDraftEligibilityService::REASON_INSUFFICIENT_CREDITS)
        ->and($result['can_retry'])->toBeFalse()
        ->and($result['available_credits'])->toBe(0);
});

it('returns ineligible when hybrid feature is disabled by entitlement', function () {
    [$organization, $workspace, $site, $user, $brief, $content] = makeHybridEligibilityContext('hybrid-eligibility-disabled');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'slug' => 'hybrid-disabled-plan',
        'key' => 'hybrid-disabled-plan',
        'name' => 'Hybrid Disabled Plan',
        'interval' => 'month',
        'monthly_price_cents' => 0,
        'price_cents' => 0,
        'currency' => 'EUR',
        'vat_included' => true,
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
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
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'organization_id' => $organization->id,
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'feature_key' => 'draft_compare_hybrid_enabled',
        'value_type' => 'bool',
        'value_bool' => false,
        'source' => 'test',
        'effective_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
        'refreshed_at' => now(),
    ]);

    $comparison = createComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $service = app(HybridDraftEligibilityService::class);
    $result = $service->checkEligibility($comparison);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reason'])->toBe(HybridDraftEligibilityService::REASON_FEATURE_NOT_AVAILABLE_ON_PLAN);
});

it('canGenerateHybrid returns boolean correctly', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridEligibilityContext('hybrid-eligibility-bool');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $service = app(HybridDraftEligibilityService::class);

    expect($service->canGenerateHybrid($comparison))->toBeTrue();
});

it('assertCanGenerateHybrid throws when ineligible', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridEligibilityContext('hybrid-eligibility-assert');

    // No credits

    $comparison = createComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $service = app(HybridDraftEligibilityService::class);

    expect(fn () => $service->assertCanGenerateHybrid($comparison))
        ->toThrow(RuntimeException::class, 'Insufficient credits');
});

it('returns ineligible when comparison is not terminal', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridEligibilityContext('hybrid-eligibility-not-terminal');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $comparison->status = DraftComparison::STATUS_PROCESSING;
    $comparison->save();

    $service = app(HybridDraftEligibilityService::class);
    $result = $service->checkEligibility($comparison);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reason'])->toBe(HybridDraftEligibilityService::REASON_COMPARISON_NOT_TERMINAL)
        ->and($result['can_retry'])->toBeTrue();
});

it('returns ineligible when hybrid draft already exists', function () {
    [, $workspace, $site, $user, $brief, $content] = makeHybridEligibilityContext('hybrid-eligibility-existing-hybrid');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = createComparisonWithVariants(
        briefId: (string) $brief->id,
        contentId: (string) $content->id,
        siteId: (string) $site->id,
        workspaceId: (string) $workspace->id,
        userId: $user->id,
        successfulVariants: 2,
    );

    $hybridDraft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'generated',
        'title' => 'Existing Hybrid',
        'output_type' => 'kb_article',
        'content_html' => '<p>Hybrid output already generated.</p>',
    ]);

    $comparison->hybrid_draft_id = (string) $hybridDraft->id;
    $comparison->hybrid_status = 'generated';
    $comparison->save();

    $service = app(HybridDraftEligibilityService::class);
    $result = $service->checkEligibility($comparison);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reason'])->toBe(HybridDraftEligibilityService::REASON_HYBRID_ALREADY_GENERATED)
        ->and($result['can_retry'])->toBeFalse();
});

it('provides user-friendly messages for all reason codes', function () {
    $service = app(HybridDraftEligibilityService::class);

    $reasons = [
        HybridDraftEligibilityService::REASON_NOT_ENOUGH_SUCCESSFUL_VARIANTS,
        HybridDraftEligibilityService::REASON_FEATURE_NOT_AVAILABLE_ON_PLAN,
        HybridDraftEligibilityService::REASON_GENERATION_ALREADY_RUNNING,
        HybridDraftEligibilityService::REASON_COMPARISON_NOT_FOUND,
        HybridDraftEligibilityService::REASON_NO_SOURCE_CONTENT_AVAILABLE,
        HybridDraftEligibilityService::REASON_INSUFFICIENT_CREDITS,
    ];

    foreach ($reasons as $reason) {
        $message = $service->messageForReason($reason);
        expect($message)->not->toBe('');
    }
});
