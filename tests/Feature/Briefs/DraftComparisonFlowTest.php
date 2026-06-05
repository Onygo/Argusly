<?php

use App\Jobs\DraftComparison\StartDraftComparisonJob;
use App\Jobs\DraftComparison\GenerateHybridDraftFromComparisonJob;
use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
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
use App\Services\CreditWalletService;
use App\Services\DraftComparison\DraftComparisonModelCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDraftCompareContext(string $prefix = 'draft-compare'): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Compare Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Draft Compare BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Draft Compare Site',
        'site_url' => 'https://draft-compare.example.com',
        'allowed_domains' => ['draft-compare.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
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
        'name' => 'Draft Compare User',
        'email' => $prefix . '+' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

it('creates a draft comparison run and queues one generation job per selected model', function () {
    Queue::fake();

    [, $workspace, $site, $user] = makeDraftCompareContext();
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 200,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Draft compare brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'draft compare keyword',
    ]);

    $options = app(DraftComparisonModelCatalog::class)->options();
    expect(count($options))->toBeGreaterThanOrEqual(2);

    $selected = collect($options)->take(2)->pluck('key')->all();
    expect($selected)->toHaveCount(2);
    expect((string) $selected[0])->not->toBe((string) $selected[1]);

    $response = $this->actingAs($user)->post(route('app.briefs.compare.store', $brief), [
        'mode' => 'compare_two',
        'model_keys' => $selected,
        'requested_max_output_tokens' => 10000,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $comparison = DraftComparison::query()->where('brief_id', $brief->id)->latest('created_at')->first();
    expect($comparison)->not->toBeNull();
    expect((string) $comparison->mode)->toBe('compare_two');
    expect((string) $comparison->status)->toBe('pending');
    expect((int) $comparison->items_total)->toBe(2);
    expect((int) $comparison->reserved_credit_amount)->toBeGreaterThan(0);
    expect((string) $brief->fresh()->content_id)->toBe((string) $comparison->content_id);

    $comparisonReservation = CreditReservation::query()
        ->where('context_type', DraftComparison::class)
        ->where('context_id', $comparison->id)
        ->first();
    expect($comparisonReservation)->not->toBeNull();
    expect((string) $comparisonReservation->status)->toBe(CreditReservation::STATUS_RESERVED);

    $items = DraftComparisonItem::query()
        ->where('draft_comparison_id', $comparison->id)
        ->orderBy('sort_order')
        ->get();

    expect($items)->toHaveCount(2);
    expect((string) $items[0]->status)->toBe('queued');
    expect((string) $items[1]->status)->toBe('queued');
    expect((string) $items[0]->provider . ':' . $items[0]->model)->toBe($selected[0]);
    expect((string) $items[1]->provider . ':' . $items[1]->model)->toBe($selected[1]);
    expect((string) $items[0]->draft_id)->not->toBe('');
    expect((string) $items[1]->draft_id)->not->toBe('');

    $draftIds = $items->pluck('draft_id')->all();
    $drafts = Draft::query()->whereIn('id', $draftIds)->get()->keyBy('id');

    foreach ($draftIds as $index => $draftId) {
        $draft = $drafts->get($draftId);
        expect($draft)->not->toBeNull();
        expect((string) $draft->draft_comparison_id)->toBe((string) $comparison->id);
        expect($draft->draft_comparison_variant_id)->toBeNull();
        expect((string) data_get($draft->meta, 'draft_compare.comparison_id'))->toBe((string) $comparison->id);
        expect((string) data_get($draft->meta, 'draft_compare.item_id', ''))->toBe('');
        expect((string) data_get($draft->meta, 'draft_compare.legacy_item_id'))->toBe((string) $items[$index]->id);
        expect((string) data_get($draft->meta, 'generation_provider_override'))->toBe((string) $items[$index]->provider);
        expect((string) data_get($draft->meta, 'generation_model_override'))->toBe((string) $items[$index]->model);
    }

    Queue::assertPushed(StartDraftComparisonJob::class, 1);
    Queue::assertNotPushed(GenerateDraftJob::class);
});

it('returns a draft comparison estimate payload for selected models', function () {
    [, , $site, $user] = makeDraftCompareContext('draft-compare-estimate');

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Draft compare estimate brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $selected = collect(app(DraftComparisonModelCatalog::class)->options())
        ->take(2)
        ->pluck('key')
        ->values()
        ->all();

    $response = $this->actingAs($user)->postJson(route('app.briefs.compare.estimate', $brief), [
        'mode' => 'compare_two',
        'model_keys' => $selected,
        'requested_max_output_tokens' => 12000,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.mode', 'compare_two')
        ->assertJsonPath('data.requested_model_count', 2)
        ->assertJsonPath('data.requested_max_output_tokens', 12000);

    expect((int) $response->json('data.estimated_credit_cost'))->toBeGreaterThan(0);
});

it('starts an existing comparison run through the explicit start endpoint', function () {
    Queue::fake();

    [, , $site, $user] = makeDraftCompareContext('draft-compare-start');
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Draft compare start brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $selected = collect(app(DraftComparisonModelCatalog::class)->options())
        ->take(2)
        ->values()
        ->all();

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_PENDING,
        'requested_models_json' => $selected,
        'requested_model_count' => 2,
        'estimated_credit_cost' => 20,
        'estimated_credits' => 20,
    ]);

    $response = $this->actingAs($user)->post(route('app.briefs.compare.start', [$brief, $comparison]));

    $response->assertRedirect();
    expect((string) $comparison->fresh()->status)->toBe(DraftComparison::STATUS_QUEUED);

    Queue::assertPushed(StartDraftComparisonJob::class, function (StartDraftComparisonJob $job) use ($comparison): bool {
        return (string) $job->comparisonId === (string) $comparison->id;
    });
});

it('returns comparison polling status payload', function () {
    [, $workspace, $site, $user] = makeDraftCompareContext('draft-compare-status');

    $content = \App\Models\Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Status content',
        'primary_keyword' => 'status keyword',
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
        'title' => 'Status brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Status draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Status payload draft.</p>',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_PROCESSING,
        'items_total' => 2,
        'items_done' => 1,
        'items_failed' => 0,
    ]);

    $variant = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'display_name' => 'OpenAI - gpt-4.1-mini',
        'sort_order' => 1,
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draft->id,
        'input_tokens' => 100,
        'output_tokens' => 800,
        'credit_cost' => 10,
        'latency_ms' => 1200,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->getJson(route('app.briefs.compare.status', [$brief, $comparison]));

    $response->assertOk()
        ->assertJsonPath('data.id', (string) $comparison->id)
        ->assertJsonPath('data.mode', 'compare_two')
        ->assertJsonPath('data.variants.0.id', (string) $variant->id)
        ->assertJsonPath('data.variants.0.draft_id', (string) $draft->id);
});

it('opens a variant draft from the comparison route', function () {
    [, $workspace, $site, $user] = makeDraftCompareContext('draft-compare-open-variant');

    $content = \App\Models\Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Open draft content',
        'primary_keyword' => 'open draft keyword',
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
        'title' => 'Open variant brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Variant draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Variant draft output.</p>',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'single',
        'status' => DraftComparison::STATUS_COMPLETED,
        'items_total' => 1,
        'items_done' => 1,
    ]);

    $variant = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draft->id,
    ]);

    $response = $this->actingAs($user)->get(route('app.briefs.compare.open-variant-draft', [$brief, $comparison, $variant]));

    $response->assertRedirect(route('app.drafts.show', $draft));
});

it('allows selecting a generated draft comparison winner', function () {
    [, , $site, $user] = makeDraftCompareContext('draft-compare-winner');

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Winner brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'mode' => 'compare_two',
        'status' => 'completed',
        'items_total' => 2,
        'items_done' => 2,
        'estimated_credits' => 20,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Winner candidate',
        'output_type' => 'kb_article',
        'content_html' => '<p>Generated.</p>',
        'meta' => [
            'draft_compare' => [
                'comparison_id' => $comparison->id,
                'is_hybrid' => false,
            ],
        ],
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'draft_id' => $draft->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'generated',
        'credit_cost' => 10,
    ]);

    $draftCountBefore = (int) Draft::query()->count();

    $response = $this->actingAs($user)->post(route('app.briefs.compare.select-winner', [$brief, $comparison]), [
        'draft_id' => $draft->id,
    ]);

    $response->assertRedirect();
    expect((string) $comparison->fresh()->winner_draft_id)->toBe((string) $draft->id);
    expect((int) Draft::query()->count())->toBe($draftCountBefore);
});

it('queues a hybrid draft from generated comparison items', function () {
    Queue::fake();

    [, $workspace, $site, $user] = makeDraftCompareContext('draft-compare-hybrid');
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 200,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $content = \App\Models\Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Hybrid content',
        'primary_keyword' => 'hybrid keyword',
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
        'title' => 'Hybrid brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'hybrid keyword',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => 'completed',
        'items_total' => 2,
        'items_done' => 2,
        'estimated_credits' => 20,
        'meta' => [
            'generation_type' => 'article',
            'per_draft_credits' => 10,
        ],
    ]);

    $draftA = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Candidate A',
        'output_type' => 'kb_article',
        'content_html' => '<h2>Intro</h2><p>Candidate A content with CTA: book a demo.</p>',
        'meta' => [
            'generation' => ['provider' => 'openai', 'model' => 'gpt-4.1-mini'],
        ],
    ]);

    $draftB = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Candidate B',
        'output_type' => 'kb_article',
        'content_html' => '<h2>Body</h2><p>Candidate B content with structure and clarity.</p>',
        'meta' => [
            'generation' => ['provider' => 'anthropic', 'model' => 'claude-3-5-sonnet-latest'],
        ],
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'draft_id' => $draftA->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'generated',
        'credit_cost' => 10,
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'draft_id' => $draftB->id,
        'sort_order' => 2,
        'provider' => 'anthropic',
        'model' => 'claude-3-5-sonnet-latest',
        'status' => 'generated',
        'credit_cost' => 10,
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftA->id,
        'completed_at' => now(),
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draftB->id,
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->post(route('app.briefs.compare.hybrid', [$brief, $comparison]));

    $response->assertRedirect();

    $comparison->refresh();
    expect((string) $comparison->hybrid_status)->toBe('queued');

    Queue::assertPushed(GenerateHybridDraftFromComparisonJob::class, function (GenerateHybridDraftFromComparisonJob $job) use ($comparison): bool {
        return (string) $job->comparisonId === (string) $comparison->id;
    });
});

it('does not queue a hybrid draft when fewer than two variants succeeded', function () {
    Queue::fake();

    [, $workspace, $site, $user] = makeDraftCompareContext('draft-compare-hybrid-not-eligible');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 200,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $content = \App\Models\Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Hybrid not eligible content',
        'primary_keyword' => 'hybrid not eligible keyword',
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
        'title' => 'Hybrid not eligible brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'hybrid not eligible keyword',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_COMPLETED,
        'items_total' => 2,
        'items_done' => 1,
        'items_failed' => 1,
        'estimated_credits' => 20,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Single candidate',
        'output_type' => 'kb_article',
        'content_html' => '<p>Only one successful variant.</p>',
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draft->id,
        'completed_at' => now(),
    ]);

    DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'anthropic',
        'model_key' => 'claude-3-5-sonnet-latest',
        'status' => DraftComparisonVariant::STATUS_FAILED,
        'error_message' => 'Provider failed',
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->post(route('app.briefs.compare.hybrid', [$brief, $comparison]));

    $response->assertRedirect();
    $response->assertSessionHasErrors('draft_compare');

    $comparison->refresh();
    expect((string) $comparison->hybrid_status)->not->toBe('queued');

    Queue::assertNotPushed(GenerateHybridDraftFromComparisonJob::class);
});

it('renders draft compare controls on the brief detail screen', function () {
    [, , $site, $user] = makeDraftCompareContext('draft-compare-view');

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Draft compare view brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $response = $this->actingAs($user)->get(route('app.content.workspace.show', $brief));

    $response->assertOk();
    $response->assertSee('Content workspace');
    $response->assertSee('Start comparison');
});

it('renders the draft compare setup screen', function () {
    [, , $site, $user] = makeDraftCompareContext('draft-compare-setup-screen');

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Draft compare setup brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $response = $this->actingAs($user)->get(route('app.content.workspace.compare.setup', $brief));

    $response->assertOk();
    $response->assertSee('Compare AI Drafts');
    $response->assertSee('Model selection');
    $response->assertSee('Start comparison');
});

it('redirects the legacy compare setup route to the content workspace compare setup route', function () {
    [, , $site, $user] = makeDraftCompareContext('draft-compare-setup-legacy-redirect');

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Draft compare legacy setup brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $response = $this->actingAs($user)->get(route('app.briefs.compare.setup', $brief));

    $response->assertRedirect(route('app.content.workspace.compare.setup', $brief));
});

it('renders the draft compare results and matrix sections', function () {
    [, $workspace, $site, $user] = makeDraftCompareContext('draft-compare-results-view');

    $content = \App\Models\Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Results content',
        'primary_keyword' => 'results keyword',
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
        'title' => 'Results brief',
        'language' => 'en',
        'content_type' => 'blog',
        'target_audience' => 'CTO and developers',
        'funnel_stage' => 'awareness',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Results draft',
        'output_type' => 'kb_article',
        'content_html' => '<h2>Intro</h2><p>Results output body.</p>',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'mode' => 'single',
        'status' => DraftComparison::STATUS_COMPLETED,
        'items_total' => 1,
        'items_done' => 1,
        'requested_model_count' => 1,
        'credits_used' => 10,
    ]);

    DraftComparisonItem::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'draft_id' => $draft->id,
        'sort_order' => 1,
        'provider' => 'openai',
        'model' => 'gpt-4.1-mini',
        'status' => 'generated',
        'credit_cost' => 10,
    ]);

    $variant = DraftComparisonVariant::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_id' => $comparison->id,
        'provider_key' => 'openai',
        'model_key' => 'gpt-4.1-mini',
        'status' => DraftComparisonVariant::STATUS_COMPLETED,
        'draft_id' => $draft->id,
        'credit_cost' => 10,
        'completed_at' => now(),
    ]);

    DraftComparisonScore::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_variant_id' => $variant->id,
        'metric_key' => 'seo_score',
        'metric_label' => 'SEO Score',
        'metric_group' => 'seo',
        'numeric_score' => 78.2,
    ]);

    DraftComparisonScore::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_variant_id' => $variant->id,
        'metric_key' => 'word_count',
        'metric_label' => 'Word Count',
        'metric_group' => 'content',
        'numeric_score' => 640,
    ]);

    DraftComparisonScore::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_variant_id' => $variant->id,
        'metric_key' => 'cta_strength',
        'metric_label' => 'CTA Strength',
        'metric_group' => 'conversion',
        'numeric_score' => 20,
    ]);

    DraftComparisonScore::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_variant_id' => $variant->id,
        'metric_key' => 'readability_score',
        'metric_label' => 'Readability',
        'metric_group' => 'content',
        'numeric_score' => 40,
    ]);

    DraftComparisonScore::query()->create([
        'id' => (string) Str::uuid(),
        'draft_comparison_variant_id' => $variant->id,
        'metric_key' => 'structure_quality',
        'metric_label' => 'Structure Quality',
        'metric_group' => 'content',
        'numeric_score' => 82,
    ]);

    $response = $this->actingAs($user)->get(route('app.content.workspace.compare.show', [$brief, $comparison]));

    $response->assertOk();
    $response->assertSee('Contextual Score Matrix');
    $response->assertSee('Scoring is interpreted against this content profile.');
    $response->assertSee('Content Strategy Fit');
    $response->assertSee('Correct for funnel stage');
    $response->assertSee('Good for technical audience');
    $response->assertSee('Full Text Comparison');
    $response->assertSee('Suggested winner');
});
