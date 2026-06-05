<?php

use App\Jobs\GenerateBatchItemBriefJob;
use App\Jobs\GenerateBatchItemDraftJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentBatch;
use App\Models\ContentBatchItem;
use App\Models\ContentRevision;
use App\Models\CreditAction;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUsage;
use App\Services\BatchGenerationService;
use App\Services\Content\ContentLifecycleService;
use App\Services\CreditWalletService;
use App\Services\DraftGenerationService;
use App\Services\PlanQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeBatchAuthContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Batch Org',
        'slug' => 'batch-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Batch Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Batch Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Batch Site',
        'site_url' => 'https://batch.example.com',
        'allowed_domains' => ['batch.example.com'],
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
        'name' => 'Batch User',
        'email' => 'batch+' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

it('creates a batch with items from client form input', function () {
    [, $workspace, $site, $user] = makeBatchAuthContext();

    $response = $this->actingAs($user)->post(route('app.content.batches.store'), [
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'main_keyword' => 'CRM migratie',
        'language' => 'nl',
        'tone' => 'zakelijk',
        'preferred_length' => 'medium',
        'subkeywords_text' => "crm migratie checklist|Implementatie|commercial\ncrm data mapping template|Technisch|technical",
    ]);

    $response->assertRedirect();

    $batch = ContentBatch::query()->first();
    expect($batch)->not->toBeNull();
    expect($batch->main_keyword)->toBe('CRM migratie');
    expect($batch->items_total)->toBe(2);
    expect(ContentBatchItem::query()->where('batch_id', $batch->id)->count())->toBe(2);
});

it('starts a batch and dispatches per-item brief jobs', function () {
    Queue::fake();
    [, $workspace, $site, $user] = makeBatchAuthContext();

    $batch = app(BatchGenerationService::class)->createBatch(
        workspace: $workspace,
        user: $user,
        clientSite: $site,
        mainKeyword: 'Main keyword',
        subkeywords: [
            ['subkeyword' => 'topic a', 'angle' => 'a1', 'intent' => 'commercial'],
            ['subkeyword' => 'topic b', 'angle' => 'b1', 'intent' => 'technical'],
        ],
        settings: ['language' => 'en']
    );

    $this->actingAs($user)->post(route('app.content.batches.start', $batch))
        ->assertRedirect();

    Queue::assertPushed(GenerateBatchItemBriefJob::class, 2);
    expect($batch->fresh()->status)->toBe('running');
});

it('completes a batch item and links brief and draft using existing generation services', function () {
    Queue::fake();

    [$organization, $workspace, $site, $user] = makeBatchAuthContext();

    CreditAction::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'content.article',
        'category' => 'content',
        'credits_cost' => 4,
        'label_nl' => 'Article',
        'label_en' => 'Article',
        'is_active' => true,
        'meta' => [],
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'batch-plan-' . Str::random(4),
        'slug' => 'batch-plan-' . Str::random(4),
        'name' => 'Batch Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'limits' => ['users' => 3, 'sites' => 3, 'workspaces' => 1],
        'is_active' => true,
    ]);

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 3,
        'status' => 'active',
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $batchService = app(BatchGenerationService::class);
    $batch = $batchService->createBatch(
        workspace: $workspace,
        user: $user,
        clientSite: $site,
        mainKeyword: 'Main keyword',
        subkeywords: [
            ['subkeyword' => 'topic a', 'angle' => 'angle a', 'intent' => 'commercial'],
        ],
        settings: ['language' => 'en', 'preferred_length' => 'medium']
    );

    $item = $batch->items()->firstOrFail();

    $briefJob = new GenerateBatchItemBriefJob((string) $item->id);
    $briefJob->handle($batchService);

    $item->refresh();
    expect($item->brief_id)->not->toBeNull();
    expect($item->draft_id)->not->toBeNull();

    $draftGeneration = \Mockery::mock(DraftGenerationService::class);
    $draftGeneration->shouldReceive('generateWithRepair')->once()->andReturn([
        'title' => 'Generated title',
        'content_html' => '<h2>Intro</h2><p>' . str_repeat('Word ', 120) . '</p>',
        'meta' => ['description' => 'Meta'],
        'links' => [],
    ]);

    $lifecycle = \Mockery::mock(ContentLifecycleService::class);
    $lifecycle->shouldReceive('ensureRevisionFromDraft')->once()->andReturn(new ContentRevision());

    $quota = \Mockery::mock(PlanQuotaService::class);
    $quota->shouldNotReceive('assertCanGenerateArticle');
    $quota->shouldReceive('incrementUsage')->once()->andReturn(new WorkspaceUsage());

    $draftJob = new GenerateBatchItemDraftJob((string) $item->id);
    $draftJob->handle($batchService, $draftGeneration, app(CreditWalletService::class), $lifecycle, $quota);

    $item->refresh();
    $batch->refresh();

    expect($item->status)->toBe('done');
    expect($batch->status)->toBe('completed');
    expect($batch->items_done)->toBe(1);
});

it('marks batch partially completed when some items fail and some succeed', function () {
    [, $workspace, $site, $user] = makeBatchAuthContext();

    $batchService = app(BatchGenerationService::class);
    $batch = $batchService->createBatch(
        workspace: $workspace,
        user: $user,
        clientSite: $site,
        mainKeyword: 'Main keyword',
        subkeywords: [
            ['subkeyword' => 'topic a'],
            ['subkeyword' => 'topic b'],
        ],
        settings: ['language' => 'en']
    );

    $items = $batch->items()->orderBy('sort_order')->get();
    $items[0]->update(['status' => 'done']);
    $items[1]->update(['status' => 'failed', 'error_message' => 'boom']);

    $batchService->syncBatchProgress($batch->fresh());

    $batch->refresh();
    expect($batch->status)->toBe('partially_completed');
    expect($batch->items_done)->toBe(1);
});

it('returns ai-assisted subkeyword suggestions with max 10 unique rows', function () {
    [, $workspace, $site, $user] = makeBatchAuthContext();

    config(['llm.providers.openai.api_key' => 'test-key']);
    config(['llm.providers.openai.base_url' => 'https://api.openai.com']);

    $items = [];
    for ($i = 1; $i <= 12; $i++) {
        $items[] = [
            'subkeyword' => $i <= 2 ? 'duplicate keyword' : 'keyword ' . $i,
            'angle' => 'angle ' . $i,
            'intent' => 'informational',
            'differentiator' => 'focus ' . $i,
        ];
    }

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'output_text' => json_encode(['items' => $items]),
        ], 200),
    ]);

    $response = $this->actingAs($user)->postJson(route('app.content.batches.suggest'), [
        'main_keyword' => 'CRM migratie',
        'language' => 'nl',
        'subkeywords_text' => '',
    ]);

    $response->assertOk();
    $response->assertJsonPath('ok', true);
    $lines = $response->json('lines');
    expect(is_array($lines))->toBeTrue();
    expect(count($lines))->toBe(10);
});
