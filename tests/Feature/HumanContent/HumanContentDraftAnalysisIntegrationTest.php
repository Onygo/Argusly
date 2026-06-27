<?php

use App\Jobs\AnalyzeDraftJob;
use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CreditLedgerEntry;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Brief\NormalizeContentBrief;
use App\Services\Content\ContentLifecycleService;
use App\Services\CreditWalletService;
use App\Services\DraftGenerationService;
use App\Services\Drafts\DraftIntelligenceService;
use App\Services\HumanContent\HumanContentScoreService;
use App\Services\HumanContent\HumanizationService;
use App\Services\Llm\LlmManager;
use App\Services\PlanQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function strongHumanContentIntegrationHtml(): string
{
    return <<<'HTML'
<h1>Why approval speed changes content quality</h1>
<p>The central problem is not that teams lack ideas. It is that every useful idea loses context while it waits for approval, which changes what the article can realistically prove.</p>
<h2>The signal appears in the handoff</h2>
<p>In practice, teams that review briefs within 24 hours preserve the original customer language. One publishing team we observed kept the same thesis but replaced three broad claims with campaign examples after reviewing search notes and sales objections.</p>
<p>That evidence matters because the operational constraint is visible: when review cycles stretch, writers use safer phrases and fewer precise nouns. The result is content that sounds correct but teaches less.</p>
<h2>What the editor should decide</h2>
<p>The practical recommendation is to treat approval speed as a quality control metric. Measure where examples disappear, ask which claim needs proof, and decide before drafting which counterargument deserves space.</p>
<ul><li>Keep the thesis close to the reader tension.</li><li>Add one field observation to each major claim.</li><li>Remove generic summary language when the takeaway is already clear.</li></ul>
HTML;
}

function makeHumanContentAnalysisContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Human Content Org',
        'slug' => 'human-content-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Human Content BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Human Content Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Human Content Site',
        'site_url' => 'https://human-content.example.com',
        'allowed_domains' => ['human-content.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'human-content-plan'],
        [
            'name' => 'Human Content Plan',
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
        'name' => 'Human Content User',
        'email' => 'human-content+' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Approval speed and content quality',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'approval speed',
        'search_intent' => 'informational',
        'funnel_stage' => 'consideration',
        'key_points' => ['approval speed', 'field context', 'quality control'],
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Approval speed and content quality',
        'output_type' => 'kb_article',
        'seo_title' => 'Approval speed and content quality',
        'seo_meta_description' => 'How approval speed affects editorial quality.',
        'seo_h1' => 'Approval speed and content quality',
        'content_html' => strongHumanContentIntegrationHtml(),
        'meta' => [
            'editorial_plan' => [
                'central_thesis' => 'Approval speed changes content quality by preserving field context.',
                'unique_angle' => 'Review latency is an editorial quality signal.',
                'reader_misconception' => 'Content quality is only a writer skill problem.',
                'expected_reader_takeaway' => 'Measure approval speed as part of editorial governance.',
                'primary_pattern' => [
                    'name' => 'Problem to Discovery',
                    'article_movement' => 'Move from the operational problem to the editorial discovery and practical decision.',
                ],
            ],
        ],
    ]);

    return [$user, $draft];
}

function humanContentScorePayload(int $humanScore, int $editorialScore, int $fingerprintScore, bool $passed): array
{
    return [
        'status' => $passed ? 'pass' : 'fail',
        'passed' => $passed,
        'human_content_score' => $humanScore,
        'editorial_quality_score' => $editorialScore,
        'originality_score' => max(0, min(100, $editorialScore)),
        'ai_fingerprint_score' => $fingerprintScore,
        'findings' => ['Human content finding.'],
        'ai_fingerprint' => [
            'severity' => $fingerprintScore > 45 ? 'high' : 'low',
            'findings' => [
                ['type' => 'generic_headings', 'humanization_action' => 'Rewrite generic headings.'],
            ],
        ],
    ];
}

function runGenerateDraftJobWithHumanizationMocks(
    Draft $draft,
    array $generationResult,
    HumanContentScoreService $humanContentScoreService,
    HumanizationService $humanizationService,
): void {
    $draftService = Mockery::mock(DraftGenerationService::class);
    $draftService->shouldReceive('generateWithRepair')->once()->andReturn($generationResult);

    $lifecycle = Mockery::mock(ContentLifecycleService::class);
    $lifecycle->shouldNotReceive('ensureRevisionFromDraft');

    $wallets = Mockery::mock(CreditWalletService::class);
    $wallets->shouldReceive('reserveForDraft')->once()->andReturn(CreditLedgerEntry::make([
        'id' => (string) Str::uuid(),
    ]));
    $wallets->shouldReceive('commitUsageForDraft')->once();
    $wallets->shouldNotReceive('releaseReservationForDraft');

    $quota = Mockery::mock(PlanQuotaService::class);
    $quota->shouldReceive('incrementUsage')->once();

    $normalizer = Mockery::mock(NormalizeContentBrief::class);
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

    (new GenerateDraftJob((string) $draft->id))->handle(
        $draftService,
        $lifecycle,
        $wallets,
        $quota,
        $normalizer,
        null,
        null,
        null,
        $humanContentScoreService,
        $humanizationService,
    );
}

it('stores human content score in draft analysis payload and surfaces it in the UI', function (): void {
    [$user, $draft] = makeHumanContentAnalysisContext();

    $llm = Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(fakeDraftAnalysisResponse(72, 'The CTA gives the reader a clear next step.'));
    $this->app->instance(LlmManager::class, $llm);

    $analysis = app(DraftIntelligenceService::class)->analyzeAndStore($draft->fresh(), true);
    $payload = $analysis->canonicalPayload();

    expect(data_get($payload, 'human_content.human_content_score'))->toBeInt()
        ->and(data_get($payload, 'sections.human_content.score'))->toBe(data_get($payload, 'human_content.human_content_score'))
        ->and(data_get($payload, 'sections.human_content.findings'))->not->toBeEmpty()
        ->and(data_get($payload, 'context.human_content_score_version'))->toBe('human-content-score.v1');

    $this->actingAs($user)
        ->get(route('app.drafts.show', ['draft' => $draft, 'tab' => 'intelligence']))
        ->assertOk()
        ->assertSee('Human Content')
        ->assertSee('Humanization actions');
});

it('caps publish readiness when human content quality fails', function (): void {
    [$user, $draft] = makeHumanContentAnalysisContext();
    $draft->update([
        'content_html' => '<h1>Introduction</h1><p>In today\'s digital landscape, content is a game changer. It is important to note that businesses need robust solutions.</p><h2>Main Section</h2><p>Overall, teams should unlock the power of better workflows.</p><h2>Conclusion</h2><p>In conclusion, content is important for success.</p>',
    ]);

    $llm = Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(fakeDraftAnalysisResponse(72, 'The CTA gives the reader a clear next step.'));
    $this->app->instance(LlmManager::class, $llm);

    $analysis = app(DraftIntelligenceService::class)->analyzeAndStore($draft->fresh(), true);
    $payload = $analysis->canonicalPayload();

    expect(data_get($payload, 'human_content.passed'))->toBeFalse()
        ->and(data_get($payload, 'sections.publish_readiness.score'))->toBeLessThanOrEqual(64)
        ->and(data_get($payload, 'sections.publish_readiness.blocking_issues'))->toContain('Human content score did not pass editorial quality thresholds.');
});

it('queues draft analysis after successful draft generation', function (): void {
    Queue::fake([AnalyzeDraftJob::class]);

    [, $draft] = makeHumanContentAnalysisContext();
    $draft->update([
        'status' => 'ready',
        'credit_cost' => 4,
        'content_html' => null,
    ]);

    $draftService = Mockery::mock(DraftGenerationService::class);
    $draftService->shouldReceive('generateWithRepair')
        ->once()
        ->andReturn([
            'title' => 'Approval speed and content quality',
            'content_html' => strongHumanContentIntegrationHtml(),
            'provider' => 'openai',
            'model_used' => 'gpt-test',
            'usage' => ['total_tokens' => 300, 'input_tokens' => 100, 'output_tokens' => 200],
            'meta' => ['description' => 'How approval speed affects editorial quality.'],
        ]);

    $lifecycle = Mockery::mock(ContentLifecycleService::class);
    $lifecycle->shouldNotReceive('ensureRevisionFromDraft');

    $wallets = Mockery::mock(CreditWalletService::class);
    $wallets->shouldReceive('reserveForDraft')->once()->andReturn(CreditLedgerEntry::make([
        'id' => (string) Str::uuid(),
    ]));
    $wallets->shouldReceive('commitUsageForDraft')->once();
    $wallets->shouldNotReceive('releaseReservationForDraft');

    $quota = Mockery::mock(PlanQuotaService::class);
    $quota->shouldReceive('incrementUsage')->once();

    $normalizer = Mockery::mock(NormalizeContentBrief::class);
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

    (new GenerateDraftJob((string) $draft->id))->handle($draftService, $lifecycle, $wallets, $quota, $normalizer);

    $draft->refresh();

    expect((string) $draft->status)->toBe('generated')
        ->and(data_get($draft->meta, 'editorial_plan.central_thesis'))->not->toBeEmpty()
        ->and(data_get($draft->meta, 'human_content_score_before'))->toBeInt()
        ->and(data_get($draft->meta, 'human_content_score_after'))->toBeInt()
        ->and(data_get($draft->meta, 'ai_fingerprint_score_before'))->toBeInt()
        ->and(data_get($draft->meta, 'ai_fingerprint_score_after'))->toBeInt()
        ->and(data_get($draft->meta, 'publish_gate_status'))->not->toBeEmpty();

    Queue::assertPushed(AnalyzeDraftJob::class, function (AnalyzeDraftJob $job) use ($draft): bool {
        return $job->draftId === (string) $draft->id && $job->force === false;
    });
});

it('skips generation humanization when the human score passes threshold', function (): void {
    Queue::fake([AnalyzeDraftJob::class]);

    [, $draft] = makeHumanContentAnalysisContext();
    $draft->update([
        'status' => 'ready',
        'credit_cost' => 4,
        'content_html' => null,
    ]);

    $score = humanContentScorePayload(82, 78, 22, true);
    $scorer = Mockery::mock(HumanContentScoreService::class);
    $scorer->shouldReceive('scoreForDraftHtml')->once()->andReturn($score);

    $humanizer = Mockery::mock(HumanizationService::class);
    $humanizer->shouldReceive('shouldHumanize')->once()->with($score)->andReturnFalse();
    $humanizer->shouldNotReceive('humanize');

    runGenerateDraftJobWithHumanizationMocks($draft, [
        'title' => 'Approval speed and content quality',
        'content_html' => strongHumanContentIntegrationHtml(),
        'provider' => 'openai',
        'model_used' => 'gpt-test',
        'usage' => ['total_tokens' => 300],
        'meta' => ['description' => 'How approval speed affects editorial quality.'],
    ], $scorer, $humanizer);

    $draft->refresh();

    expect(data_get($draft->meta, 'humanization.status'))->toBe('skipped')
        ->and(data_get($draft->meta, 'human_content.before.human_content_score'))->toBe(82)
        ->and(data_get($draft->meta, 'human_content_score_before'))->toBe(82)
        ->and(data_get($draft->meta, 'human_content_score_after'))->toBe(82)
        ->and(data_get($draft->meta, 'ai_fingerprint_score_before'))->toBe(22)
        ->and(data_get($draft->meta, 'ai_fingerprint_score_after'))->toBe(22)
        ->and(data_get($draft->meta, 'humanization_status'))->toBe('skipped')
        ->and(data_get($draft->meta, 'publish_gate_status'))->toBe('passed')
        ->and($draft->content_html)->toContain('Why approval speed changes content quality');
});

it('persists targeted humanization and re-scores generated content', function (): void {
    Queue::fake([AnalyzeDraftJob::class]);

    [, $draft] = makeHumanContentAnalysisContext();
    $draft->update([
        'status' => 'ready',
        'credit_cost' => 4,
        'content_html' => null,
    ]);

    $generatedHtml = '<h1>Introduction</h1><p>In today\'s digital landscape, Argusly reviewed 42 briefs. Read <a href="/en/blog/editorial-workflows">the workflow guide</a>.</p><h2>Conclusion</h2><p>In conclusion, book a demo.</p>';
    $humanizedHtml = '<h1>Approval speed changes content quality</h1><p>Argusly reviewed 42 briefs. Read <a href="/en/blog/editorial-workflows">the workflow guide</a>.</p><h2>What readers should do next with approval speed</h2><p>Book a demo.</p>';
    $before = humanContentScorePayload(48, 52, 72, false);
    $after = humanContentScorePayload(74, 71, 31, true);

    $scorer = Mockery::mock(HumanContentScoreService::class);
    $scorer->shouldReceive('scoreForDraftHtml')->twice()->andReturn($before, $after);

    $humanizer = Mockery::mock(HumanizationService::class);
    $humanizer->shouldReceive('shouldHumanize')->once()->with($before)->andReturnTrue();
    $humanizer->shouldReceive('humanize')->once()->andReturn([
        'version' => HumanizationService::VERSION,
        'changed' => true,
        'improved_html' => $humanizedHtml,
        'change_summary' => 'Rewrote generic headings and stock phrases.',
        'before_after_notes' => ['Rewrote generic structural headings into topic-specific editorial headings.'],
        'preserved_validation' => [
            'passed' => true,
            'links_preserved' => true,
            'schema_preserved' => true,
            'facts_preserved' => true,
        ],
    ]);

    runGenerateDraftJobWithHumanizationMocks($draft, [
        'title' => 'Approval speed and content quality',
        'content_html' => $generatedHtml,
        'provider' => 'openai',
        'model_used' => 'gpt-test',
        'usage' => ['total_tokens' => 300],
        'meta' => ['description' => 'How approval speed affects editorial quality.'],
    ], $scorer, $humanizer);

    $draft->refresh();

    expect($draft->content_html)->toBe($humanizedHtml)
        ->and($draft->content_html)->toContain('/en/blog/editorial-workflows')
        ->and($draft->content_html)->toContain('42')
        ->and(data_get($draft->meta, 'humanization.status'))->toBe('applied')
        ->and(data_get($draft->meta, 'humanization_status'))->toBe('applied')
        ->and(data_get($draft->meta, 'human_content_score_before'))->toBe(48)
        ->and(data_get($draft->meta, 'human_content_score_after'))->toBe(74)
        ->and(data_get($draft->meta, 'ai_fingerprint_score_before'))->toBe(72)
        ->and(data_get($draft->meta, 'ai_fingerprint_score_after'))->toBe(31)
        ->and(data_get($draft->meta, 'fingerprint_findings'))->toBeArray()
        ->and(data_get($draft->meta, 'humanization_changes.change_summary'))->toBe('Rewrote generic headings and stock phrases.')
        ->and(data_get($draft->meta, 'publish_gate_status'))->toBe('passed')
        ->and(data_get($draft->meta, 'humanization.score_delta.human_content_score'))->toBe(26)
        ->and(data_get($draft->meta, 'humanization.score_delta.ai_fingerprint_score'))->toBe(-41)
        ->and(data_get($draft->meta, 'human_content.after.human_content_score'))->toBe(74);
});

it('automation draft generation uses the same human scoring gate', function (): void {
    Queue::fake([AnalyzeDraftJob::class]);

    [, $draft] = makeHumanContentAnalysisContext();
    $meta = (array) $draft->meta;
    $meta['content_automation'] = ['automation_id' => (string) Str::uuid(), 'sequence' => 1];
    $draft->update([
        'status' => 'ready',
        'credit_cost' => 4,
        'content_html' => null,
        'meta' => $meta,
    ]);

    $score = humanContentScorePayload(81, 77, 24, true);
    $scorer = Mockery::mock(HumanContentScoreService::class);
    $scorer->shouldReceive('scoreForDraftHtml')->once()->andReturn($score);

    $humanizer = Mockery::mock(HumanizationService::class);
    $humanizer->shouldReceive('shouldHumanize')->once()->with($score)->andReturnFalse();
    $humanizer->shouldNotReceive('humanize');

    runGenerateDraftJobWithHumanizationMocks($draft, [
        'title' => 'Approval speed and content quality',
        'content_html' => strongHumanContentIntegrationHtml(),
        'provider' => 'openai',
        'model_used' => 'gpt-test',
        'usage' => ['total_tokens' => 300],
        'meta' => ['description' => 'How approval speed affects editorial quality.'],
    ], $scorer, $humanizer);

    $draft->refresh();

    expect(data_get($draft->meta, 'content_automation.automation_id'))->not->toBeEmpty()
        ->and(data_get($draft->meta, 'human_content_score_before'))->toBe(81)
        ->and(data_get($draft->meta, 'human_content_score_after'))->toBe(81)
        ->and(data_get($draft->meta, 'publish_gate_status'))->toBe('passed');
});

it('failed humanization preserves the original generated draft and blocks publishing', function (): void {
    Queue::fake([AnalyzeDraftJob::class]);

    [, $draft] = makeHumanContentAnalysisContext();
    $draft->update([
        'status' => 'ready',
        'credit_cost' => 4,
        'content_html' => null,
    ]);

    $generatedHtml = '<h1>Introduction</h1><p>Argusly reviewed 42 briefs. Read <a href="/en/blog/editorial-workflows">the workflow guide</a>.</p>';
    $before = humanContentScorePayload(45, 49, 80, false);
    $after = humanContentScorePayload(45, 49, 80, false);

    $scorer = Mockery::mock(HumanContentScoreService::class);
    $scorer->shouldReceive('scoreForDraftHtml')->twice()->andReturn($before, $after);

    $humanizer = Mockery::mock(HumanizationService::class);
    $humanizer->shouldReceive('shouldHumanize')->once()->with($before)->andReturnTrue();
    $humanizer->shouldReceive('humanize')->once()->andThrow(new RuntimeException('Humanization provider unavailable'));

    runGenerateDraftJobWithHumanizationMocks($draft, [
        'title' => 'Approval speed and content quality',
        'content_html' => $generatedHtml,
        'provider' => 'openai',
        'model_used' => 'gpt-test',
        'usage' => ['total_tokens' => 300],
        'meta' => ['description' => 'How approval speed affects editorial quality.'],
    ], $scorer, $humanizer);

    $draft->refresh();

    expect($draft->content_html)->toBe($generatedHtml)
        ->and(data_get($draft->meta, 'humanization_status'))->toBe('failed')
        ->and(data_get($draft->meta, 'publish_gate_status'))->toBe('needs_editorial_review')
        ->and(data_get($draft->meta, 'human_content_gate.reasons'))->toContain('Humanization failed; editorial review is required before auto-publication.')
        ->and(data_get($draft->meta, 'humanization_changes.change_summary'))->toBe('Humanization failed; original generated draft was preserved.')
        ->and(data_get($draft->meta, 'human_content_score_after'))->toBe(45)
        ->and(data_get($draft->meta, 'ai_fingerprint_score_after'))->toBe(80);
});
