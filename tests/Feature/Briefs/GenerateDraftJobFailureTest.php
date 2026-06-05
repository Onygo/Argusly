<?php

use App\Jobs\GenerateDraftJob;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\Draft;
use App\Models\DraftComparison;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Brief\NormalizeContentBrief;
use App\Services\Content\ContentLifecycleService;
use App\Services\CreditWalletService;
use App\Services\DraftGenerationService;
use App\Services\PlanQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('marks draft failed and stores error when generation throws non-retryable exception', function () {
    $organization = Organization::query()->create([
        'name' => 'Failure Org',
        'slug' => 'failure-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Failure Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Failure Site',
        'site_url' => 'https://failure.example.com',
        'allowed_domains' => ['failure.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    User::query()->create([
        'name' => 'Failure User',
        'email' => 'failure+' . Str::random(5) . '@example.com',
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
        'title' => 'Failure brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Failure draft',
        'output_type' => 'kb_article',
        'meta' => ['language' => 'nl'],
    ]);

    $draftService = \Mockery::mock(DraftGenerationService::class);
        $draftService->shouldReceive('generateWithRepair')
        ->once()
        ->andThrow(new \RuntimeException('OpenAI request failed (401): invalid api key'));

    $quota = \Mockery::mock(PlanQuotaService::class);
    $quota->shouldNotReceive('assertCanGenerateArticle');
    $quota->shouldNotReceive('incrementUsage');

    $lifecycle = \Mockery::mock(ContentLifecycleService::class);
    $lifecycle->shouldNotReceive('ensureRevisionFromDraft');

    $wallets = \Mockery::mock(CreditWalletService::class);
    $wallets->shouldReceive('reserveForDraft')->once()->andReturn(CreditLedgerEntry::make([
        'id' => (string) Str::uuid(),
    ]));
    $wallets->shouldNotReceive('commitUsageForDraft');
    $wallets->shouldReceive('releaseReservationForDraft')->zeroOrMoreTimes();

    $normalizer = \Mockery::mock(NormalizeContentBrief::class);
    $normalizer->shouldReceive('getDiagnosticContext')->andReturn([]);
    $normalizer->shouldReceive('normalizeDraftMeta')->andReturn([
        'normalized' => false,
        'fields_added' => [],
        'meta' => $draft->meta,
    ]);
    $normalizer->shouldReceive('validateDraftForGeneration')->andReturn([
        'valid' => true,
        'errors' => [],
        'missing' => [],
    ]);

    $job = new GenerateDraftJob((string) $draft->id);
    try {
        $job->handle($draftService, $lifecycle, $wallets, $quota, $normalizer);
    } catch (\Throwable $e) {
        // Non-retryable errors are marked failed and then fail the job.
    }

    $draft->refresh();
    expect((string) $draft->status)->toBe('failed');
    expect((string) $draft->last_error)->toContain('invalid api key');
    expect((int) $draft->attempts)->toBe(1);
});

it('blocks generation before LLM call when credits are insufficient', function () {
    $organization = Organization::query()->create([
        'name' => 'No Credits Org',
        'slug' => 'no-credits-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'No Credits Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'No Credits Site',
        'site_url' => 'https://nocredits.example.com',
        'allowed_domains' => ['nocredits.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'No credits brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'No credits draft',
        'output_type' => 'kb_article',
        'credit_cost' => 4,
        'meta' => ['language' => 'nl'],
    ]);

    $draftService = \Mockery::mock(DraftGenerationService::class);
    $draftService->shouldNotReceive('generateWithRepair');

    $quota = \Mockery::mock(PlanQuotaService::class);
    $quota->shouldNotReceive('assertCanGenerateArticle');
    $quota->shouldNotReceive('incrementUsage');

    $lifecycle = \Mockery::mock(ContentLifecycleService::class);
    $lifecycle->shouldNotReceive('ensureRevisionFromDraft');

    $wallets = \Mockery::mock(CreditWalletService::class);
    $wallets->shouldReceive('reserveForDraft')
        ->once()
        ->andThrow(new InsufficientCreditsException(4, 0));
    $wallets->shouldNotReceive('commitUsageForDraft');
    $wallets->shouldNotReceive('releaseReservationForDraft');

    $normalizer = \Mockery::mock(NormalizeContentBrief::class);
    $normalizer->shouldReceive('getDiagnosticContext')->andReturn([]);
    $normalizer->shouldReceive('normalizeDraftMeta')->andReturn([
        'normalized' => false,
        'fields_added' => [],
        'meta' => $draft->meta,
    ]);
    $normalizer->shouldReceive('validateDraftForGeneration')->andReturn([
        'valid' => true,
        'errors' => [],
        'missing' => [],
    ]);

    $job = new GenerateDraftJob((string) $draft->id);
    try {
        $job->handle($draftService, $lifecycle, $wallets, $quota, $normalizer);
    } catch (\Throwable) {
        // Expected: job marks draft failed and rethrows.
    }

    $draft->refresh();
    expect((string) $draft->status)->toBe('failed');
    expect((string) $draft->last_error)->toContain('Insufficient credits. Required: 4, available: 0.');
});

it('marks hybrid comparison state as failed when draft job exhausts retries', function () {
    $organization = Organization::query()->create([
        'name' => 'Hybrid Retry Org',
        'slug' => 'hybrid-retry-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Hybrid Retry Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Hybrid Retry Site',
        'site_url' => 'https://hybrid-retry.example.com',
        'allowed_domains' => ['hybrid-retry.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Hybrid retry brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $comparison = DraftComparison::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'mode' => 'compare_two',
        'status' => DraftComparison::STATUS_COMPLETED,
        'hybrid_status' => 'queued',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'draft_comparison_id' => $comparison->id,
        'status' => 'ready',
        'title' => 'Hybrid retry draft',
        'output_type' => 'kb_article',
        'meta' => [
            'draft_compare' => [
                'comparison_id' => (string) $comparison->id,
                'is_hybrid' => true,
                'comparison_credit_managed' => true,
            ],
        ],
    ]);

    $job = new GenerateDraftJob((string) $draft->id);
    $job->failed(new RuntimeException('Provider timeout'));

    $draft->refresh();
    $comparison->refresh();

    expect((string) $draft->status)->toBe('failed')
        ->and((string) $draft->last_error)->toContain('Provider timeout')
        ->and((string) $comparison->hybrid_status)->toBe('failed')
        ->and((string) $comparison->hybrid_last_error)->toContain('Provider timeout');
});
