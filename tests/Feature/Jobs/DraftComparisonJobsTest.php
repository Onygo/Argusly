<?php

use App\Jobs\DraftComparison\FinalizeDraftComparisonJob;
use App\Jobs\DraftComparison\GenerateDraftComparisonVariantJob;
use App\Jobs\DraftComparison\StartDraftComparisonJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\DraftComparisonItem;
use App\Models\DraftComparisonScore;
use App\Models\DraftComparisonVariant;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\CreditWalletService;
use App\Services\DraftComparison\DraftComparisonCreditManager;
use App\Services\DraftComparison\DraftComparisonModelCatalog;
use App\Services\DraftComparison\DraftComparisonPromptSnapshotBuilder;
use App\Services\DraftComparison\DraftComparisonScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDraftComparisonJobContext(string $prefix = 'draft-compare-job'): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Compare Jobs Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Jobs Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Draft Compare Jobs Site',
        'site_url' => 'https://draft-compare-jobs.example.com',
        'allowed_domains' => ['draft-compare-jobs.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Draft Compare Jobs User',
        'email' => $prefix . '+' . Str::random(6) . '@example.com',
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
        'title' => 'Draft compare jobs brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    return [$organization, $workspace, $site, $user, $brief];
}

it('start job creates variant rows and dispatches generation jobs idempotently', function () {
    Queue::fake();

    [, $workspace, $site, $user, $brief] = makeDraftComparisonJobContext();

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_PENDING,
        'requested_models_json' => [
            ['provider' => 'openai', 'model' => 'gpt-4.1-mini', 'label' => 'OpenAI - gpt-4.1-mini'],
            ['provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest', 'label' => 'Anthropic - claude-3-5-sonnet-latest'],
        ],
        'requested_model_count' => 2,
        'estimated_credit_cost' => 24,
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'queued',
        'credit_cost' => 12,
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'sort_order' => 2,
        'provider' => 'anthropic',
        'model' => 'claude-3-5-sonnet-latest',
        'status' => 'queued',
        'credit_cost' => 12,
    ]);

    $startJob = new StartDraftComparisonJob((string) $comparison->id);
    $startJob->handle(
        app(DraftComparisonModelCatalog::class),
        app(DraftComparisonCreditManager::class),
    );
    $startJob->handle(
        app(DraftComparisonModelCatalog::class),
        app(DraftComparisonCreditManager::class),
    );

    $comparison->refresh();

    expect((string) $comparison->status)->toBe(DraftComparison::STATUS_PROCESSING)
        ->and((int) DraftComparisonVariant::query()->where('draft_comparison_id', $comparison->id)->count())->toBe(2);

    $reservation = CreditReservation::query()
        ->where('context_type', DraftComparison::class)
        ->where('context_id', $comparison->id)
        ->first();

    expect($reservation)->not->toBeNull()
        ->and((string) $reservation->status)->toBe(CreditReservation::STATUS_RESERVED);

    Queue::assertPushed(GenerateDraftComparisonVariantJob::class);
});

it('variant job completes with an already generated draft and enqueues finalize', function () {
    Queue::fake();

    [, $workspace, $site, $user, $brief] = makeDraftComparisonJobContext('draft-compare-job-variant');

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'single',
        'status' => DraftComparison::STATUS_PROCESSING,
        'estimated_credit_cost' => 12,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Generated variant draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Generated content.</p>',
        'credit_cost' => 12,
        'meta' => [
            'generation' => [
                'charged_credits' => 12,
                'input_tokens' => 111,
                'output_tokens' => 222,
                'tokens' => 333,
                'provider' => 'openai',
                'model' => 'gpt-4.1-mini',
                'model_used' => 'gpt-4.1-mini',
            ],
        ],
    ]);

    $item = DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'draft_id' => $draft->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'queued',
        'credit_cost' => 12,
    ]);

    $variant = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'display_name' => 'OpenAI - gpt-4.1-mini',
        'sort_order' => 1,
        'status' => DraftComparisonVariant::STATUS_QUEUED,
        'draft_id' => $draft->id,
        'credit_cost' => 12,
    ]);

    $variantJob = new GenerateDraftComparisonVariantJob((string) $variant->id);
    $variantJob->handle(
        app(DraftComparisonCreditManager::class),
        app(\App\Services\DraftComparison\DraftComparisonFeatureGate::class),
        app(DraftComparisonPromptSnapshotBuilder::class),
        app(DraftComparisonScoringService::class),
    );

    $variant->refresh();
    $item->refresh();
    $draft->refresh();
    $scoreRows = DraftComparisonScore::query()
        ->where('draft_comparison_variant_id', $variant->id)
        ->get()
        ->keyBy('metric_key');

    expect((string) $variant->status)->toBe(DraftComparisonVariant::STATUS_COMPLETED)
        ->and((int) $variant->input_tokens)->toBe(111)
        ->and((int) $variant->output_tokens)->toBe(222)
        ->and((int) $variant->credit_cost)->toBe(12)
        ->and((string) data_get($variant->prompt_snapshot_json, 'provider_key'))->toBe('openai')
        ->and((string) data_get($variant->prompt_snapshot_json, 'model_key'))->toBe('gpt-4.1-mini')
        ->and((string) data_get($variant->prompt_snapshot_json, 'shared_inputs_hash'))->not->toBe('')
        ->and((string) $item->status)->toBe('generated')
        ->and((int) $item->charged_credits)->toBe(12)
        ->and((string) $draft->draft_comparison_id)->toBe((string) $comparison->id)
        ->and((string) $draft->draft_comparison_variant_id)->toBe((string) $variant->id)
        ->and($scoreRows)->toHaveCount(12)
        ->and($scoreRows->has('seo_score'))->toBeTrue()
        ->and($scoreRows->has('ai_seo_score'))->toBeTrue()
        ->and($scoreRows->has('conversion_focus'))->toBeTrue();

    $comparison->refresh();
    expect((string) data_get($comparison->comparison_summary_json, 'prompt_audit.shared_inputs_hash'))->toBe((string) data_get($variant->prompt_snapshot_json, 'shared_inputs_hash'));

    Queue::assertPushed(FinalizeDraftComparisonJob::class, 1);
});

it('marks variant as failed and queues finalize when retries are exhausted', function () {
    Queue::fake();

    [, $workspace, $site, $user, $brief] = makeDraftComparisonJobContext('draft-compare-job-retries-exhausted');

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'single',
        'status' => DraftComparison::STATUS_PROCESSING,
        'estimated_credit_cost' => 12,
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'queued',
        'credit_cost' => 12,
    ]);

    $variant = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'display_name' => 'OpenAI - gpt-4.1-mini',
        'sort_order' => 1,
        'status' => DraftComparisonVariant::STATUS_QUEUED,
        'credit_cost' => 12,
    ]);

    $job = new GenerateDraftComparisonVariantJob((string) $variant->id);
    $job->failed(new RuntimeException('timeout while calling provider'));

    $variant->refresh();
    $item = DraftComparisonItem::query()
        ->where('draft_comparison_id', $comparison->id)
        ->where('provider', 'openai')
        ->where('model', 'gpt-4.1-mini')
        ->firstOrFail();

    expect((string) $variant->status)->toBe(DraftComparisonVariant::STATUS_FAILED)
        ->and((string) $variant->error_message)->toContain('Generation retries exhausted')
        ->and((string) $item->status)->toBe('failed')
        ->and((string) $item->error_message)->toContain('Generation retries exhausted');

    Queue::assertPushed(FinalizeDraftComparisonJob::class, 1);
});

it('skips comparison scoring when scoring analysis is disabled by plan entitlement', function () {
    Queue::fake();

    [$organization, $workspace, $site, $user, $brief] = makeDraftComparisonJobContext('draft-compare-job-no-scoring');

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'slug' => 'draft-compare-job-no-scoring-plan',
        'key' => 'draft-compare-job-no-scoring-plan',
        'name' => 'Draft Compare Job No Scoring Plan',
        'interval' => 'month',
        'monthly_price_cents' => 7900,
        'price_cents' => 7900,
        'currency' => 'EUR',
        'vat_included' => true,
        'included_credits' => 200,
        'included_credits_per_interval' => 200,
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
        'included_credits_per_interval' => 200,
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
        'feature_key' => 'draft_compare_scoring_enabled',
        'value_type' => 'bool',
        'value_bool' => false,
        'source' => 'test',
        'effective_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
        'refreshed_at' => now(),
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'single',
        'status' => DraftComparison::STATUS_PROCESSING,
        'estimated_credit_cost' => 12,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'No scoring variant draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Generated content without scoring rows.</p>',
        'credit_cost' => 12,
        'meta' => [
            'generation' => [
                'charged_credits' => 12,
                'input_tokens' => 101,
                'output_tokens' => 202,
                'tokens' => 303,
                'provider' => 'openai',
                'model' => 'gpt-4.1-mini',
            ],
        ],
    ]);

    $item = DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'draft_id' => $draft->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'queued',
        'credit_cost' => 12,
    ]);

    $variant = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'display_name' => 'OpenAI - gpt-4.1-mini',
        'sort_order' => 1,
        'status' => DraftComparisonVariant::STATUS_QUEUED,
        'draft_id' => $draft->id,
        'credit_cost' => 12,
    ]);

    $variantJob = new GenerateDraftComparisonVariantJob((string) $variant->id);
    $variantJob->handle(
        app(DraftComparisonCreditManager::class),
        app(\App\Services\DraftComparison\DraftComparisonFeatureGate::class),
        app(DraftComparisonPromptSnapshotBuilder::class),
        app(DraftComparisonScoringService::class),
    );

    $variant->refresh();
    $item->refresh();

    expect((string) $variant->status)->toBe(DraftComparisonVariant::STATUS_COMPLETED)
        ->and((int) DraftComparisonScore::query()->where('draft_comparison_variant_id', $variant->id)->count())->toBe(0)
        ->and((string) data_get($item->metrics, 'scoring_status'))->toBe('disabled_by_plan');

    Queue::assertPushed(FinalizeDraftComparisonJob::class, 1);
});

it('finalize job settles credits for partial failure using variant outcomes', function () {
    [, $workspace, $site, $user, $brief] = makeDraftComparisonJobContext('draft-compare-job-finalize');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_PROCESSING,
        'estimated_credit_cost' => 24,
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'sort_order' => 1,
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'credit_cost' => 12,
        'input_tokens' => 100,
        'output_tokens' => 200,
        'completed_at' => now(),
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'sort_order' => 2,
        'status' => DraftComparisonVariant::STATUS_FAILED,
        'credit_cost' => 12,
        'error_message' => 'Provider rejected request',
        'completed_at' => now(),
    ]);

    $creditManager = app(DraftComparisonCreditManager::class);
    $creditManager->reserveForComparison($comparison, 24, $user->id);

    FinalizeDraftComparisonJob::dispatchSync((string) $comparison->id);
    FinalizeDraftComparisonJob::dispatchSync((string) $comparison->id);

    $comparison->refresh();

    $reservation = CreditReservation::query()
        ->where('context_type', DraftComparison::class)
        ->where('context_id', $comparison->id)
        ->firstOrFail();

    $usageLedgerCount = CreditLedgerEntry::query()
        ->where('idempotency_key', 'capture-usage:' . (string) $reservation->id)
        ->count();

    expect((string) $comparison->status)->toBe(DraftComparison::STATUS_PARTIALLY_FAILED)
        ->and((int) $comparison->items_total)->toBe(2)
        ->and((int) $comparison->items_done)->toBe(1)
        ->and((int) $comparison->items_failed)->toBe(1)
        ->and((int) $comparison->final_credit_cost)->toBe(12)
        ->and((int) $comparison->credits_used)->toBe(12)
        ->and((string) $reservation->status)->toBe(CreditReservation::STATUS_CAPTURED)
        ->and((int) data_get($reservation->metadata, 'captured_amount'))->toBe(12)
        ->and($usageLedgerCount)->toBe(1);
});

it('finalize job marks comparison failed when all variants fail and releases reservation', function () {
    [, $workspace, $site, $user, $brief] = makeDraftComparisonJobContext('draft-compare-job-all-failed');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_PROCESSING,
        'estimated_credit_cost' => 24,
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'sort_order' => 1,
        'status' => DraftComparisonVariant::STATUS_FAILED,
        'credit_cost' => 12,
        'error_message' => 'Provider rejected request',
        'completed_at' => now(),
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'sort_order' => 2,
        'status' => DraftComparisonVariant::STATUS_FAILED,
        'credit_cost' => 12,
        'error_message' => 'Provider timeout',
        'completed_at' => now(),
    ]);

    $creditManager = app(DraftComparisonCreditManager::class);
    $creditManager->reserveForComparison($comparison, 24, $user->id);

    FinalizeDraftComparisonJob::dispatchSync((string) $comparison->id);

    $comparison->refresh();

    $reservation = CreditReservation::query()
        ->where('context_type', DraftComparison::class)
        ->where('context_id', $comparison->id)
        ->firstOrFail();

    expect((string) $comparison->status)->toBe(DraftComparison::STATUS_FAILED)
        ->and((int) $comparison->items_total)->toBe(2)
        ->and((int) $comparison->items_done)->toBe(0)
        ->and((int) $comparison->items_failed)->toBe(2)
        ->and((int) $comparison->final_credit_cost)->toBe(0)
        ->and((int) $comparison->credits_used)->toBe(0)
        ->and((string) $reservation->status)->toBe(CreditReservation::STATUS_RELEASED);
});

it('finalize job stores summary insights from variant scores', function () {
    [, $workspace, $site, $user, $brief] = makeDraftComparisonJobContext('draft-compare-job-summary');

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_PROCESSING,
        'estimated_credit_cost' => 24,
    ]);

    $draftA = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Summary draft A',
        'output_type' => 'kb_article',
        'content_html' => '<p>Summary A</p>',
    ]);

    $draftB = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Summary draft B',
        'output_type' => 'kb_article',
        'content_html' => '<p>Summary B</p>',
    ]);

    $variantA = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'sort_order' => 1,
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftA->id,
        'credit_cost' => 12,
        'completed_at' => now(),
    ]);

    $variantB = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'sort_order' => 2,
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftB->id,
        'credit_cost' => 12,
        'completed_at' => now(),
    ]);

    $metricsA = [
        'word_count' => 900,
        'reading_time' => 5,
        'seo_score' => 90,
        'ai_seo_score' => 88,
        'readability_score' => 70,
        'brand_voice_match' => 70,
        'cta_strength' => 60,
        'structure_quality' => 75,
        'topical_coverage' => 80,
        'entity_coverage' => 70,
        'factual_confidence' => 85,
        'conversion_focus' => 65,
    ];

    $metricsB = [
        'word_count' => 500,
        'reading_time' => 3,
        'seo_score' => 75,
        'ai_seo_score' => 70,
        'readability_score' => 68,
        'brand_voice_match' => 92,
        'cta_strength' => 95,
        'structure_quality' => 70,
        'topical_coverage' => 72,
        'entity_coverage' => 62,
        'factual_confidence' => 78,
        'conversion_focus' => 94,
    ];

    foreach ($metricsA as $metricKey => $value) {
        DraftComparisonScore::query()->create([
            'draft_comparison_variant_id' => $variantA->id,
            'metric_key' => $metricKey,
            'metric_label' => Str::headline($metricKey),
            'metric_group' => 'test',
            'source_type' => 'heuristic',
            'numeric_score' => $value,
            'explanation' => 'A metric',
        ]);
    }

    foreach ($metricsB as $metricKey => $value) {
        DraftComparisonScore::query()->create([
            'draft_comparison_variant_id' => $variantB->id,
            'metric_key' => $metricKey,
            'metric_label' => Str::headline($metricKey),
            'metric_group' => 'test',
            'source_type' => 'heuristic',
            'numeric_score' => $value,
            'explanation' => 'B metric',
        ]);
    }

    FinalizeDraftComparisonJob::dispatchSync((string) $comparison->id);

    $comparison->refresh();

    expect((string) $comparison->status)->toBe(DraftComparison::STATUS_COMPLETED)
        ->and((string) data_get($comparison->comparison_summary_json, 'scoring.version'))->toBe('draft_compare_summary_v1')
        ->and((string) data_get($comparison->comparison_summary_json, 'scoring.insights.best_for_seo.variant_id'))->toBe((string) $variantA->id)
        ->and((string) data_get($comparison->comparison_summary_json, 'scoring.insights.best_for_conversion.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($comparison->comparison_summary_json, 'scoring.insights.best_brand_voice_fit.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($comparison->comparison_summary_json, 'scoring.insights.most_concise.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($comparison->comparison_summary_json, 'scoring.insights.most_comprehensive.variant_id'))->toBe((string) $variantA->id)
        ->and((string) data_get($comparison->comparison_summary_json, 'recommendation.version'))->toBe('draft_compare_winner_v1')
        ->and((string) data_get($comparison->comparison_summary_json, 'recommendation.suggested_winner.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($comparison->comparison_summary_json, 'recommendation.best_for_brand_voice.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($comparison->comparison_summary_json, 'recommendation.best_conversion_focused_option.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($comparison->comparison_summary_json, 'recommendation.best_concise_option.variant_id'))->toBe((string) $variantB->id)
        ->and((string) data_get($comparison->comparison_summary_json, 'trust.version'))->toBe('draft_compare_trust_v1')
        ->and((int) data_get($comparison->comparison_summary_json, 'trust.status_summary.variant_total'))->toBe(2)
        ->and((int) data_get($comparison->comparison_summary_json, 'trust.usage_summary.credit_cost'))->toBe(24)
        ->and((string) data_get($comparison->comparison_summary_json, 'trust.variants.0.provider_model.requested_provider'))->toBe('openai')
        ->and((string) data_get($comparison->comparison_summary_json, 'trust.variants.0.score_details.seo_score.source_type'))->toBe('heuristic')
        ->and((string) data_get($comparison->comparison_summary_json, 'trust.recommendation_explanation'))->toContain('Highest weighted score')
        ->and($comparison->winner_draft_id)->toBeNull();
});

it('fails a variant when prompt shared inputs drift from comparison baseline', function () {
    Queue::fake();

    [, $workspace, $site, $user, $brief] = makeDraftComparisonJobContext('draft-compare-job-drift');

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_PROCESSING,
    ]);

    $draftA = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Drift draft A',
        'output_type' => 'kb_article',
        'content_html' => '<p>Generated A</p>',
        'credit_cost' => 12,
        'meta' => [
            'language' => 'en',
            'draft_compare' => [
                'comparison_id' => (string) $comparison->id,
                'is_hybrid' => false,
                'comparison_credit_managed' => true,
            ],
            'generation' => [
                'charged_credits' => 12,
                'input_tokens' => 100,
                'output_tokens' => 200,
                'tokens' => 300,
            ],
        ],
    ]);

    $draftB = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Drift draft B',
        'output_type' => 'kb_article',
        'content_html' => '<p>Generated B</p>',
        'credit_cost' => 12,
        'meta' => [
            'language' => 'nl',
            'draft_compare' => [
                'comparison_id' => (string) $comparison->id,
                'is_hybrid' => false,
                'comparison_credit_managed' => true,
            ],
            'generation' => [
                'charged_credits' => 12,
                'input_tokens' => 100,
                'output_tokens' => 200,
                'tokens' => 300,
            ],
        ],
    ]);

    $itemA = DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'draft_id' => $draftA->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'queued',
        'credit_cost' => 12,
    ]);

    $itemB = DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'draft_id' => $draftB->id,
        'sort_order' => 2,
        'provider' => 'anthropic',
        'model' => 'claude-3-5-sonnet-latest',
        'status' => 'queued',
        'credit_cost' => 12,
    ]);

    $variantA = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'sort_order' => 1,
        'status' => DraftComparisonVariant::STATUS_QUEUED,
        'draft_id' => $draftA->id,
        'credit_cost' => 12,
    ]);

    $variantB = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'sort_order' => 2,
        'status' => DraftComparisonVariant::STATUS_QUEUED,
        'draft_id' => $draftB->id,
        'credit_cost' => 12,
    ]);

    $variantJob = new GenerateDraftComparisonVariantJob((string) $variantA->id);
    $variantJob->handle(
        app(DraftComparisonCreditManager::class),
        app(\App\Services\DraftComparison\DraftComparisonFeatureGate::class),
        app(DraftComparisonPromptSnapshotBuilder::class),
        app(DraftComparisonScoringService::class),
    );

    $variantJobB = new GenerateDraftComparisonVariantJob((string) $variantB->id);
    $variantJobB->handle(
        app(DraftComparisonCreditManager::class),
        app(\App\Services\DraftComparison\DraftComparisonFeatureGate::class),
        app(DraftComparisonPromptSnapshotBuilder::class),
        app(DraftComparisonScoringService::class),
    );

    $variantA->refresh();
    $variantB->refresh();
    $itemA->refresh();
    $itemB->refresh();

    expect((string) $variantA->status)->toBe(DraftComparisonVariant::STATUS_COMPLETED)
        ->and((string) $variantB->status)->toBe(DraftComparisonVariant::STATUS_FAILED)
        ->and((string) $variantB->error_message)->toContain('Prompt snapshot drift detected')
        ->and((string) $itemB->status)->toBe('failed')
        ->and((string) data_get($variantA->prompt_snapshot_json, 'shared_inputs_hash'))->not->toBe('');
});
