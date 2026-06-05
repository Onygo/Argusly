<?php

use App\Enums\DraftImprovementAction;
use App\Jobs\AnalyzeDraftJob;
use App\Jobs\ImproveDraftSectionJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CreditReservation;
use App\Models\Draft;
use App\Models\DraftAnalysis;
use App\Models\DraftImprovementResult;
use App\Models\DraftIntelligenceDelta;
use App\Models\DraftRecommendation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use App\Services\Drafts\DraftIntelligenceBillingService;
use App\Services\Drafts\Exceptions\DraftImprovementException;
use App\Services\Drafts\DraftIntelligenceService;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\Exceptions\LlmException;
use App\Services\Llm\LlmManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeDraftIntelligenceContext(string $prefix = 'draft-intelligence'): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft Intelligence Org',
        'slug' => $prefix . '-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Draft Intelligence BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Intelligence Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Draft Intelligence Site',
        'site_url' => 'https://draft-intelligence.example.com',
        'allowed_domains' => ['draft-intelligence.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'draft-intelligence-plan'],
        [
            'name' => 'Draft Intelligence Plan',
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
        'name' => 'Draft Intelligence User',
        'email' => $prefix . '+' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Draft intelligence brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'draft intelligence',
        'call_to_action' => 'Book a demo',
        'progress' => 0,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Draft intelligence article',
        'output_type' => 'kb_article',
        'seo_title' => 'Draft intelligence article',
        'seo_meta_description' => 'Draft intelligence summary.',
        'seo_h1' => 'Draft intelligence article',
        'content_html' => '<h1>Draft intelligence</h1><p>This article explains SEO, readability, and CTA improvements.</p>',
    ]);

    return [$user, $draft];
}

function addDraftImprovementCredits(Draft $draft, int $amount = 10): void
{
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $draft->client_site_id,
        amount: $amount,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );
}

function configureOpenAiDraftImprovementTest(): void
{
    config([
        'llm.default_provider' => 'openai',
        'llm.fallback.default_enabled' => false,
        'llm.providers.openai.api_key' => 'test-openai-key',
        'llm.providers.openai.base_url' => 'https://api.openai.com',
        'draft_intelligence.improvement_model' => 'gpt-5.1-2025-11-13',
    ]);
}

it('renders intelligence tabs and latest draft analysis details', function () {
    Queue::fake();

    [$user, $draft] = makeDraftIntelligenceContext('draft-intelligence-view');

    DraftAnalysis::query()->create([
        'id' => (string) Str::uuid(),
        'draft_id' => $draft->id,
        'seo_score' => 84,
        'readability_score' => 79,
        'cta_score' => 61,
        'keyword_coverage' => 76,
        'entity_coverage' => 72,
        'analysis_model' => 'gpt-4.1-mini',
        'tokens_used' => 923,
        'internal_link_opportunities' => [
            [
                'target_title' => 'Content strategy guide',
                'reason' => 'Strong topical overlap with editorial workflow.',
                'anchor_text' => 'content strategy guide',
                'placement' => 'body',
            ],
        ],
        'suggestions' => [
            'summary' => [
                'headline' => 'Strong draft with CTA gaps',
                'overall_explanation' => 'The draft is discoverable but needs a clearer conversion step.',
            ],
            'sections' => [
                'seo' => ['score' => 84, 'explanation' => 'Primary keyword coverage is solid.', 'improvements' => ['Tighten the meta description.']],
                'readability' => ['score' => 79, 'explanation' => 'Sentence length is mostly controlled.', 'improvements' => ['Split one dense paragraph.']],
                'cta' => ['score' => 61, 'explanation' => 'CTA intent is implied rather than explicit.', 'improvements' => ['Add one explicit CTA near the close.']],
                'structure' => ['score' => 74, 'explanation' => 'Heading flow is mostly clear.', 'improvements' => ['Make one subheading more specific.']],
                'entities' => ['score' => 72, 'explanation' => 'Entity breadth is acceptable.', 'improvements' => ['Mention one supporting entity from the brief.']],
            ],
            'top_improvements' => ['Add one direct CTA.'],
        ],
    ]);

    $response = $this->actingAs($user)->get(route('app.drafts.show', ['draft' => $draft, 'tab' => 'intelligence']));

    $response->assertOk()
        ->assertSee('Draft')
        ->assertSee('Intelligence')
        ->assertSee('Improve')
        ->assertSee('History')
        ->assertSee('Strong draft with CTA gaps')
        ->assertSee('Primary keyword coverage is solid.')
        ->assertSee('Content strategy guide');
});

it('queues manual analyze action from the draft workspace', function () {
    Queue::fake();

    [$user, $draft] = makeDraftIntelligenceContext('draft-intelligence-actions');

    $this->actingAs($user)
        ->post(route('app.drafts.analyze', $draft))
        ->assertRedirect(route('app.drafts.show', ['draft' => $draft, 'tab' => 'intelligence']));

    Queue::assertPushed(AnalyzeDraftJob::class, function (AnalyzeDraftJob $job) use ($draft): bool {
        return $job->draftId === (string) $draft->id && $job->force === true;
    });

});

it('queues each supported draft improvement action from the draft workspace', function (string $action) {
    Queue::fake();

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-' . $action);

    $this->actingAs($user)
        ->post(route('app.drafts.improve', $draft), ['action' => $action])
        ->assertRedirect(route('app.drafts.show', ['draft' => $draft, 'tab' => 'improve']))
        ->assertSessionHas('status', DraftImprovementAction::from($action)->queuedMessage());

    Queue::assertPushed(ImproveDraftSectionJob::class, function (ImproveDraftSectionJob $job) use ($draft, $action): bool {
        return $job->draftId === (string) $draft->id && $job->section === $action;
    });
})->with([
    'improve_full_draft' => 'improve_full_draft',
    'seo' => 'seo',
    'readability' => 'readability',
    'cta' => 'cta',
    'headings' => 'headings',
]);

it('rejects invalid draft improvement actions', function () {
    Queue::fake();

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-invalid');

    $this->from(route('app.drafts.show', ['draft' => $draft, 'tab' => 'improve']))
        ->actingAs($user)
        ->post(route('app.drafts.improve', $draft), ['action' => 'unknown'])
        ->assertRedirect(route('app.drafts.show', ['draft' => $draft, 'tab' => 'improve']))
        ->assertSessionHasErrors('action');

    Queue::assertNotPushed(ImproveDraftSectionJob::class);
});

it('uses a bounded idempotency key when reserving credits for draft analysis', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-intelligence-billing');

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $draft->client_site_id,
        amount: 10,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $reservation = app(DraftIntelligenceBillingService::class)->reserveForAnalysis(
        draft: $draft,
        userId: (string) $user->id,
        suffix: 'manual:' . (string) Str::uuid(),
    );

    expect($reservation->idempotency_key)->toStartWith('draft_intelligence:' . $draft->id . ':draft_analysis:')
        ->and(strlen('reservation:' . $reservation->idempotency_key))->toBeLessThanOrEqual(120);
});

it('renders partial analysis state instead of treating empty completed data as usable', function () {
    Queue::fake();

    [$user, $draft] = makeDraftIntelligenceContext('draft-intelligence-partial');

    DraftAnalysis::query()->create([
        'id' => (string) Str::uuid(),
        'draft_id' => $draft->id,
        'status' => DraftAnalysis::STATUS_COMPLETED,
        'analysis_model' => 'gpt-5.1',
        'tokens_used' => 2200,
        'suggestions' => [
            'summary' => ['headline' => null, 'overall_explanation' => null],
            'sections' => [
                'seo' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'readability' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'cta' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'structure' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'entities' => ['score' => null, 'explanation' => null, 'improvements' => []],
            ],
            'top_improvements' => [],
            'internal_link_summary' => 'The model response was incomplete before internal link recommendations finished.',
        ],
        'normalized_payload' => [
            'summary' => ['headline' => null, 'overall_explanation' => null],
            'sections' => [
                'seo' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'readability' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'cta' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'structure' => ['score' => null, 'explanation' => null, 'improvements' => []],
                'entities' => ['score' => null, 'explanation' => null, 'improvements' => []],
            ],
            'top_improvements' => [],
            'internal_link_summary' => 'The model response was incomplete before internal link recommendations finished.',
        ],
        'validation_errors' => ['Only 0 of 3 required section scores present.'],
    ]);

    $response = $this->actingAs($user)->get(route('app.drafts.show', ['draft' => $draft, 'tab' => 'intelligence']));

    $response->assertOk()
        ->assertSee('Analysis Failed')
        ->assertSee('The model response was incomplete before internal link recommendations finished.');
});

it('builds an openai-compatible schema for draft improvements', function () {
    [, $draft] = makeDraftIntelligenceContext('draft-improve-schema');

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')
        ->once()
        ->withArgs(function (LlmRequest $request, array $schema) use ($draft): bool {
            expect(data_get($request->metadata, 'feature'))->toBe('draft_intelligence_improvement')
                ->and(data_get($request->metadata, 'action'))->toBe('seo')
                ->and(data_get($request->metadata, 'promptVersion'))->toBe(DraftIntelligenceService::IMPROVEMENT_PROMPT_VERSION)
                ->and(data_get($schema, 'required'))->toBe(['title', 'content_html', 'change_summary', 'change_notes', 'seo'])
                ->and(data_get($schema, 'properties.seo.required'))->toBe(['seo_title', 'seo_meta_description', 'seo_h1']);

            return true;
        })
        ->andReturn(new LlmResponse(
            text: '{}',
            json: [
                'title' => 'Improved draft intelligence article',
                'content_html' => '<h1>Draft intelligence article</h1><p>Improved SEO support copy.</p>',
                'change_summary' => 'Improved SEO metadata and supporting copy.',
                'change_notes' => ['Improved SEO metadata and supporting copy.'],
                'seo' => [
                    'seo_title' => 'Improved SEO title',
                    'seo_meta_description' => 'Improved meta description.',
                    'seo_h1' => 'Improved H1',
                ],
            ],
            usage: new LlmUsage(120, 60, 180),
            modelUsed: 'gpt-5.1',
            providerName: 'openai',
            requestId: 'req-draft-improve-schema',
        ));
    app()->instance(LlmManager::class, $llm);

    $result = app(DraftIntelligenceService::class)->improveSection($draft->fresh(), 'seo');

    expect($result['action'])->toBe('seo')
        ->and($result['prompt_version'])->toBe(DraftIntelligenceService::IMPROVEMENT_PROMPT_VERSION);
});

it('persists full draft improvements, stores change notes, and queues an explicit fresh analysis for the same operation', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-full-draft');
    addDraftImprovementCredits($draft);

    Queue::fake([AnalyzeDraftJob::class]);
    $operationKey = (string) Str::uuid();

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')
        ->once()
        ->withArgs(function (LlmRequest $request, array $schema): bool {
            expect(data_get($request->metadata, 'feature'))->toBe('draft_intelligence_improvement')
                ->and(data_get($request->metadata, 'action'))->toBe('improve_full_draft')
                ->and(data_get($request->metadata, 'promptVersion'))->toBe(DraftIntelligenceService::FULL_IMPROVEMENT_PROMPT_VERSION)
                ->and(data_get($schema, 'required'))->toContain('change_notes')
                ->and(data_get($schema, 'required'))->toContain('change_summary');

            return true;
        })
        ->andReturn(new LlmResponse(
            text: '{}',
            json: [
                'title' => 'Smarter telecom automation in 30 days',
                'content_html' => '<h1>Smarter telecom automation in 30 days</h1><h2>Where to start</h2><p>This version improves flow, keyword usage, and structure.</p><p>Wil je verkennen hoe jij in 30 dagen een eerste kernproces kunt automatiseren? Plan dan een kort gesprek met je CTO of operations team en bepaal welke workflow zich het beste leent voor een eerste pilot.</p>',
                'change_summary' => 'Improved the full draft holistically.',
                'change_notes' => [
                    'Improved heading clarity',
                    'Added a stronger closing CTA',
                    'Reduced sentence complexity in the introduction',
                ],
                'seo' => [
                    'seo_title' => 'Telecom automation in 30 days',
                    'seo_meta_description' => 'Improve telecom automation with a clearer structure, stronger SEO, and a practical CTA.',
                    'seo_h1' => 'Smarter telecom automation in 30 days',
                ],
            ],
            usage: new LlmUsage(210, 120, 330),
            modelUsed: 'gpt-5.1',
            providerName: 'openai',
            requestId: 'req-draft-improve-full-draft',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'improve_full_draft', (string) $user->id, $operationKey);
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->title)->toBe('Smarter telecom automation in 30 days')
        ->and((string) $draft->seo_title)->toBe('Telecom automation in 30 days')
        ->and((string) $draft->seo_meta_description)->toContain('practical CTA')
        ->and((string) $draft->seo_h1)->toBe('Smarter telecom automation in 30 days')
        ->and((string) $draft->content_html)->toContain('Plan dan een kort gesprek')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.action'))->toBe('improve_full_draft')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.prompt_version'))->toBe(DraftIntelligenceService::FULL_IMPROVEMENT_PROMPT_VERSION)
        ->and(data_get($draft->meta, 'draft_intelligence.latest_improvement.change_notes'))->toBe([
            'Improved heading clarity',
            'Added a stronger closing CTA',
            'Reduced sentence complexity in the introduction',
        ]);

    Queue::assertPushed(AnalyzeDraftJob::class, function (AnalyzeDraftJob $job) use ($draft, $operationKey): bool {
        return $job->draftId === (string) $draft->id
            && $job->force === true
            && $job->operationKey === $operationKey;
    });

    $response = $this->actingAs($user)->get(route('app.drafts.show', ['draft' => $draft, 'tab' => 'improve']));

    $response->assertOk()
        ->assertSee('Improve entire draft')
        ->assertSee('Optimize SEO, readability, headings and CTA together.')
        ->assertSee('Improved heading clarity')
        ->assertSee('Added a stronger closing CTA');
});

it('handles missing draft content gracefully for draft improvements', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-missing-content');
    $draft->update(['content_html' => '']);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldNotReceive('generateJson');
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'seo', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->last_error)->toContain('requires existing draft content')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('failed')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.action'))->toBe('seo');
});

it('does not corrupt the draft when the improvement response is malformed', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-malformed');
    addDraftImprovementCredits($draft);

    $originalTitle = (string) $draft->title;
    $originalSeoTitle = (string) $draft->seo_title;
    $originalContent = (string) $draft->content_html;

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: [
            'title' => 'Should not persist',
            'content_html' => '',
            'change_summary' => '',
            'seo' => [
                'seo_title' => 'Should not persist',
                'seo_meta_description' => 'Should not persist',
                'seo_h1' => 'Should not persist',
            ],
        ],
        usage: new LlmUsage(50, 20, 70),
        modelUsed: 'gpt-5.1',
        providerName: 'openai',
        requestId: 'req-draft-improve-malformed',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->title)->toBe($originalTitle)
        ->and((string) $draft->seo_title)->toBe($originalSeoTitle)
        ->and((string) $draft->content_html)->toBe($originalContent)
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('failed')
        ->and((string) $draft->last_error)->toBe('The AI returned an empty draft improvement. Please try again.');
});

it('updates only the intended area for non-seo draft improvements', function () {
    Queue::fake([AnalyzeDraftJob::class]);

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-persist');
    addDraftImprovementCredits($draft);

    $originalTitle = (string) $draft->title;
    $originalSeoTitle = (string) $draft->seo_title;
    $originalSeoMeta = (string) $draft->seo_meta_description;
    $originalSeoH1 = (string) $draft->seo_h1;

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: [
            'title' => 'Should not replace title',
            'content_html' => '<h1>Draft intelligence</h1><p>This article now ends with a stronger CTA.</p><p><a href="/demo">Book a demo</a></p>',
            'change_summary' => 'Added a direct CTA near the conclusion.',
            'seo' => [
                'seo_title' => 'Should not replace seo title',
                'seo_meta_description' => 'Should not replace meta description',
                'seo_h1' => 'Should not replace H1',
            ],
        ],
        usage: new LlmUsage(110, 45, 155),
        modelUsed: 'gpt-5.1',
        providerName: 'openai',
        requestId: 'req-draft-improve-success',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->title)->toBe($originalTitle)
        ->and((string) $draft->seo_title)->toBe($originalSeoTitle)
        ->and((string) $draft->seo_meta_description)->toBe($originalSeoMeta)
        ->and((string) $draft->seo_h1)->toBe($originalSeoH1)
        ->and((string) $draft->content_html)->toContain('Book a demo')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('completed')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.action'))->toBe('cta')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.change_summary'))->toContain('CTA')
        ->and((string) data_get($draft->meta, 'draft_intelligence.improvements.0.action'))->toBe('cta');
});

it('persists cta improvements from a clean json payload', function () {
    configureOpenAiDraftImprovementTest();

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-clean-json');
    addDraftImprovementCredits($draft);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-draft-improve-cta-clean-json',
            'model' => 'gpt-5.1-2025-11-13',
            'status' => 'completed',
            'output' => [
                [
                    'content' => [
                        ['text' => '{"title":null,"content_html":"<h1>Draft intelligence</h1><p>This article now ends with a stronger CTA.</p><p><a href=\"/demo\">Book a demo</a></p>","change_summary":"Added a direct CTA near the conclusion.","seo":{"seo_title":null,"seo_meta_description":null,"seo_h1":null}}'],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ],
        ], 200, ['x-request-id' => 'req-draft-improve-cta-clean-json']),
    ]);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->content_html)->toContain('Book a demo')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('completed')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.request_id'))->toBe('req-draft-improve-cta-clean-json');
});

it('shows a completed cta status in the improve tab after a successful run', function () {
    Queue::fake([AnalyzeDraftJob::class]);

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-ui-success');
    addDraftImprovementCredits($draft);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: [
            'title' => null,
            'content_html' => '<h1>Draft intelligence</h1><p>CTA added.</p><p><a href="/demo">Book a demo</a></p>',
            'change_summary' => 'Added CTA',
            'seo' => [
                'seo_title' => null,
                'seo_meta_description' => null,
                'seo_h1' => null,
            ],
        ],
        usage: new LlmUsage(50, 20, 70),
        modelUsed: 'gpt-5.1',
        providerName: 'openai',
        requestId: 'req-draft-improve-cta-ui-success',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $response = $this->actingAs($user)->get(route('app.drafts.show', ['draft' => $draft, 'tab' => 'improve']));

    $response->assertOk()
        ->assertSee('Add CTA')
        ->assertSee('Completed')
        ->assertDontSee('Failed');
});

it('persists cta improvements from markdown fenced json payloads', function () {
    configureOpenAiDraftImprovementTest();

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-fenced-json');
    addDraftImprovementCredits($draft);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-draft-improve-cta-fenced-json',
            'model' => 'gpt-5.1-2025-11-13',
            'status' => 'completed',
            'output' => [
                [
                    'content' => [
                        ['text' => "```json\n{\"title\":null,\"content_html\":\"<h1>Draft intelligence</h1><p>CTA added.</p><p><a href=\\\"/demo\\\">Book a demo</a></p>\",\"change_summary\":\"Added a CTA in the closing section.\",\"seo\":{\"seo_title\":null,\"seo_meta_description\":null,\"seo_h1\":null}}\n```"],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ],
        ], 200, ['x-request-id' => 'req-draft-improve-cta-fenced-json']),
    ]);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->content_html)->toContain('Book a demo')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('completed');
});

it('persists cta improvements when prose surrounds one json object', function () {
    configureOpenAiDraftImprovementTest();

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-prose-json');
    addDraftImprovementCredits($draft);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-draft-improve-cta-prose-json',
            'model' => 'gpt-5.1-2025-11-13',
            'status' => 'completed',
            'output' => [
                [
                    'content' => [
                        ['text' => "Here is the improved draft:\n{\"title\":null,\"content_html\":\"<h1>Draft intelligence</h1><p>CTA added.</p><p><a href=\\\"/demo\\\">Book a demo</a></p>\",\"change_summary\":\"Added a CTA in the closing section.\",\"seo\":{\"seo_title\":null,\"seo_meta_description\":null,\"seo_h1\":null}}\nThanks."],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ],
        ], 200, ['x-request-id' => 'req-draft-improve-cta-prose-json']),
    ]);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->content_html)->toContain('Book a demo')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('completed');
});

it('persists cta improvements when malformed html json can be repaired', function () {
    config([
        'llm.default_provider' => 'openai',
        'llm.fallback.default_enabled' => false,
        'llm.providers.openai.api_key' => 'test-openai-key',
        'llm.providers.openai.base_url' => 'https://api.openai.com',
        'draft_intelligence.improvement_model' => 'gpt-5.1-2025-11-13',
    ]);

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-repaired');
    addDraftImprovementCredits($draft);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-draft-improve-cta-repaired',
            'model' => 'gpt-5.1-2025-11-13',
            'output' => [
                [
                    'content' => [
                        ['text' => "{\"title\":null,\"content_html\":\"<h1>Draft intelligence</h1><p>This article now ends with a stronger CTA.</p>\n<p><a href=\\\"/demo\\\">Book a demo</a></p>\",\"change_summary\":\"Added a direct CTA near the conclusion.\",\"seo\":{\"seo_title\":null,\"seo_meta_description\":null,\"seo_h1\":null}}"],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ],
        ], 200, ['x-request-id' => 'req-draft-improve-cta-repaired']),
    ]);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();
    $reservation = CreditReservation::query()->latest('created_at')->firstOrFail();

    expect((string) $draft->content_html)->toContain('Book a demo')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('completed')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.request_id'))->toBe('req-draft-improve-cta-repaired')
        ->and((string) $reservation->status)->toBe(CreditReservation::STATUS_CAPTURED);
});

it('retries one json-fix pass when a draft improvement response is undecodable', function () {
    config([
        'llm.default_provider' => 'openai',
        'llm.fallback.default_enabled' => false,
        'llm.providers.openai.api_key' => 'test-openai-key',
        'llm.providers.openai.base_url' => 'https://api.openai.com',
        'draft_intelligence.improvement_model' => 'gpt-5.1-2025-11-13',
    ]);

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-retry');
    addDraftImprovementCredits($draft);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push([
                'id' => 'resp-draft-improve-cta-retry-1',
                'model' => 'gpt-5.1-2025-11-13',
                'output' => [
                    [
                        'content' => [
                            ['text' => 'not valid json'],
                        ],
                    ],
                ],
            ], 200, ['x-request-id' => 'req-draft-improve-cta-retry-1'])
            ->push([
                'id' => 'resp-draft-improve-cta-retry-2',
                'model' => 'gpt-5.1-2025-11-13',
                'output' => [
                    [
                        'content' => [
                            ['text' => '{"title":null,"content_html":"<h1>Draft intelligence</h1><p>This article now ends with a stronger CTA.</p><p><a href=\"/demo\">Book a demo</a></p>","change_summary":"Added a direct CTA near the conclusion.","seo":{"seo_title":null,"seo_meta_description":null,"seo_h1":null}}'],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 60,
                    'total_tokens' => 180,
                ],
            ], 200, ['x-request-id' => 'req-draft-improve-cta-retry-2']),
    ]);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    Http::assertSentCount(2);

    expect((string) $draft->content_html)->toContain('Book a demo')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.request_id'))->toBe('req-draft-improve-cta-retry-2')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('completed');
});

it('retries cta improvements with a larger token budget when the provider cuts off the response', function () {
    configureOpenAiDraftImprovementTest();
    config([
        'draft_intelligence.improvement_max_tokens' => 2200,
        'draft_intelligence.improvement_retry_max_tokens' => 5200,
    ]);

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-token-retry');
    addDraftImprovementCredits($draft);

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push([
                'id' => 'resp-draft-improve-cta-token-retry-1',
                'model' => 'gpt-5.1-2025-11-13',
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'max_output_tokens' => 2200,
                'output' => [
                    [
                        'content' => [
                            ['text' => '{"title":null,"content_html":"<h1>Draft intelligence</h1><p>This JSON is truncated before the CTA finishes'],
                        ],
                    ],
                ],
            ], 200, ['x-request-id' => 'req-draft-improve-cta-token-retry-1'])
            ->push([
                'id' => 'resp-draft-improve-cta-token-retry-2',
                'model' => 'gpt-5.1-2025-11-13',
                'status' => 'completed',
                'output' => [
                    [
                        'content' => [
                            ['text' => '{"title":null,"content_html":"<h1>Draft intelligence</h1><p>This article now ends with a stronger CTA.</p><p><a href=\"/demo\">Book a demo</a></p>","change_summary":"Added a direct CTA near the conclusion.","seo":{"seo_title":null,"seo_meta_description":null,"seo_h1":null}}'],
                        ],
                    ],
                ],
                'usage' => [
                    'input_tokens' => 120,
                    'output_tokens' => 60,
                    'total_tokens' => 180,
                ],
            ], 200, ['x-request-id' => 'req-draft-improve-cta-token-retry-2']),
    ]);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    Http::assertSent(function ($request) {
        return ($request->data()['max_output_tokens'] ?? null) === 2200;
    });

    Http::assertSent(function ($request) {
        return ($request->data()['max_output_tokens'] ?? null) === 5200;
    });

    expect((string) $draft->content_html)->toContain('Book a demo')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.request_id'))->toBe('req-draft-improve-cta-token-retry-2')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('completed');
});

it('logs useful context when a draft improvement fails', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-log-failure');
    addDraftImprovementCredits($draft);

    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($draft): bool {
            expect($message)->toBe('Draft improvement job failed')
                ->and($context['draft_id'] ?? null)->toBe((string) $draft->id)
                ->and($context['action'] ?? null)->toBe('seo')
                ->and($context['provider'] ?? null)->toBe('openai')
                ->and($context['request_id'] ?? null)->toBe('req-draft-improve-failure')
                ->and($context['prompt_version'] ?? null)->toBe(DraftIntelligenceService::IMPROVEMENT_PROMPT_VERSION)
                ->and($context['exception_class'] ?? null)->toBe(LlmException::class);

            return true;
        });

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andThrow(new LlmException(
        message: 'OpenAI request failed (400): Invalid schema.',
        statusCode: 400,
        provider: 'openai',
        requestId: 'req-draft-improve-failure',
        userMessage: 'The AI request was invalid. Please review the input.',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'seo', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->last_error)->toBe('The AI request was invalid. Please review the input.')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('failed');
});

it('fails cta improvements with an explicit validation reason when the payload shape is invalid', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-invalid-shape');
    addDraftImprovementCredits($draft);

    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('warning')
        ->atLeast()->once()
        ->withArgs(function (string $message, array $context): bool {
            if ($message !== 'Draft improvement payload failed schema validation') {
                return true;
            }

            expect($context['validation_errors'] ?? [])->toContain('content_html must be a string');

            return true;
        });
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('Draft improvement job failed')
                ->and($context['failure_stage'] ?? null)->toBe('schema_validation')
                ->and((string) ($context['internal_reason'] ?? ''))->toContain('content_html must be a string')
                ->and($context['provider'] ?? null)->toBe('openai')
                ->and($context['request_id'] ?? null)->toBe('req-draft-improve-cta-invalid-shape');

            return true;
        });

    // Payload has content_html as an array instead of a string - truly invalid shape
    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{"title":null,"content_html":["<p>CTA</p>"],"change_summary":"Added CTA","seo":{}}',
        json: [
            'title' => null,
            'content_html' => ['<p>CTA</p>'], // Array instead of string - invalid!
            'change_summary' => 'Added CTA',
            'seo' => [],
        ],
        usage: new LlmUsage(50, 20, 70),
        modelUsed: 'gpt-5.1-2025-11-13',
        providerName: 'openai',
        requestId: 'req-draft-improve-cta-invalid-shape',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->last_error)->toBe('The AI returned an incomplete draft improvement. Please try again.')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('failed')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.failure_stage'))->toBe('schema_validation');
});

it('releases reserved credits and keeps provider context when json stays undecodable', function () {
    config([
        'llm.default_provider' => 'openai',
        'llm.fallback.default_enabled' => false,
        'llm.providers.openai.api_key' => 'test-openai-key',
        'llm.providers.openai.base_url' => 'https://api.openai.com',
        'draft_intelligence.improvement_model' => 'gpt-5.1-2025-11-13',
    ]);

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-hard-fail');
    addDraftImprovementCredits($draft);

    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('warning')->atLeast()->once();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('Draft improvement job failed')
                ->and($context['provider'] ?? null)->toBe('openai')
                ->and($context['request_id'] ?? null)->toBe('req-draft-improve-cta-hard-fail-2')
                ->and($context['model_used'] ?? null)->toBe('gpt-5.1-2025-11-13')
                ->and($context['exception_class'] ?? null)->toBe(DraftImprovementException::class);

            return true;
        });

    Http::fake([
        'https://api.openai.com/v1/responses' => Http::sequence()
            ->push([
                'id' => 'resp-draft-improve-cta-hard-fail-1',
                'model' => 'gpt-5.1-2025-11-13',
                'output' => [
                    [
                        'content' => [
                            ['text' => 'still not json'],
                        ],
                    ],
                ],
            ], 200, ['x-request-id' => 'req-draft-improve-cta-hard-fail-1'])
            ->push([
                'id' => 'resp-draft-improve-cta-hard-fail-2',
                'model' => 'gpt-5.1-2025-11-13',
                'output' => [
                    [
                        'content' => [
                            ['text' => 'still not json after retry'],
                        ],
                    ],
                ],
            ], 200, ['x-request-id' => 'req-draft-improve-cta-hard-fail-2']),
    ]);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();
    $reservation = CreditReservation::query()->latest('created_at')->firstOrFail();

    Http::assertSentCount(2);

    expect((string) $reservation->status)->toBe(CreditReservation::STATUS_RELEASED)
        ->and((string) $reservation->reason)->toBe('draft_improvement_failed')
        ->and((string) $draft->last_error)->toContain('AI returned')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('failed');
});

it('recovers cta improvement from truncated json using partial extraction fallback', function () {
    configureOpenAiDraftImprovementTest();

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-truncated-recovery');
    addDraftImprovementCredits($draft);

    // Response is truncated JSON that contains valid content_html
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'id' => 'resp-draft-improve-cta-truncated-recovery',
            'model' => 'gpt-5.1-2025-11-13',
            'status' => 'completed',
            'output' => [
                [
                    'content' => [
                        ['text' => '{"title":null,"content_html":"<h1>Draft intelligence</h1><p>This article now ends with a stronger CTA.</p><p><a href=\"/demo\">Book a demo</a></p>","change_summary":"Added a direct CTA near the conclusion.","seo":{"seo_title":null,"seo_meta_description":null'],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'total_tokens' => 150,
            ],
        ], 200, ['x-request-id' => 'req-draft-improve-cta-truncated-recovery']),
    ]);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->content_html)->toContain('Book a demo')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('completed');
});

it('recovers cta improvement when json is malformed but content_html is extractable', function () {
    Queue::fake([AnalyzeDraftJob::class]);

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-malformed-recovery');
    addDraftImprovementCredits($draft);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{"title":null,"content_html":"<h1>Draft intelligence</h1><p>CTA improved.</p><p><a href=\"/demo\">Book a demo</a></p>","change_summary":"Added CTA","seo":{"seo_title":null,broken',
        json: null, // JSON parsing failed but text has valid content
        usage: new LlmUsage(100, 50, 150),
        modelUsed: 'gpt-5.1',
        providerName: 'openai',
        requestId: 'req-draft-improve-cta-malformed-recovery',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->content_html)->toContain('Book a demo')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('completed');
});

it('provides specific error message when json is truncated without content_html', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-truncated-no-content');
    addDraftImprovementCredits($draft);

    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('warning')->atLeast()->once();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('Draft improvement job failed')
                ->and($context['failure_stage'] ?? null)->toBe('parse')
                ->and((string) ($context['internal_reason'] ?? ''))->toContain('json');

            return true;
        });

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{"title":null,"content_html":"<p>This content is truncat',
        json: null,
        usage: new LlmUsage(50, 20, 70),
        modelUsed: 'gpt-5.1',
        providerName: 'openai',
        requestId: 'req-draft-improve-cta-truncated-no-content',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('failed')
        ->and((string) $draft->last_error)->toContain('cut off');
});

it('provides specific error message for empty response', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-empty-response');
    addDraftImprovementCredits($draft);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '',
        json: null,
        usage: new LlmUsage(50, 0, 50),
        modelUsed: 'gpt-5.1',
        providerName: 'openai',
        requestId: 'req-draft-improve-cta-empty-response',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('failed')
        ->and((string) $draft->last_error)->toContain('empty');
});

it('logs partial json extraction fallback usage', function () {
    Queue::fake([AnalyzeDraftJob::class]);

    [$user, $draft] = makeDraftIntelligenceContext('draft-improve-cta-partial-json-log');
    addDraftImprovementCredits($draft);

    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            if ($message !== 'Draft improvement used partial JSON extraction fallback') {
                return true;
            }

            expect($context['extraction_type'] ?? null)->not->toBeNull()
                ->and($context['action'] ?? null)->toBe('cta');

            return true;
        });

    // Content must be at least 30 chars after stripping tags for partial extraction to succeed
    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{"title":null,"content_html":"<h1>Draft Intelligence Guide</h1><p>This is a comprehensive article about draft improvements. <a href=\"/demo\">Book a demo now</a> to learn more.</p>","change_summary":"Added CTA","seo":{"seo_title":null',
        json: null,
        usage: new LlmUsage(100, 50, 150),
        modelUsed: 'gpt-5.1',
        providerName: 'openai',
        requestId: 'req-draft-improve-cta-partial-json-log',
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new ImproveDraftSectionJob((string) $draft->id, 'cta', (string) $user->id, (string) Str::uuid());
    $job->handle(app(DraftIntelligenceService::class), app(DraftIntelligenceBillingService::class));

    $draft->refresh();

    expect((string) $draft->content_html)->toContain('Book a demo now')
        ->and((string) data_get($draft->meta, 'draft_intelligence.latest_improvement.status'))->toBe('completed');
});

it('creates improvement deltas and recommendation records after an improvement followed by a fresh rescan', function () {
    Queue::fake();

    [$user, $draft] = makeDraftIntelligenceContext('draft-phase-two-deltas');
    addDraftImprovementCredits($draft, 20);

    $beforeAnalysis = DraftAnalysis::query()->create([
        'draft_id' => (string) $draft->id,
        'status' => DraftAnalysis::STATUS_COMPLETED,
        'seo_score' => 68,
        'readability_score' => 80,
        'cta_score' => 40,
        'headings_score' => 59,
        'suggestions' => [
            'summary' => ['headline' => 'Before', 'overall_explanation' => 'Before improvement.'],
            'sections' => [
                'seo' => ['score' => 68, 'explanation' => 'SEO needs work.', 'improvements' => ['Improve keyword placement.']],
                'readability' => ['score' => 80, 'explanation' => 'Readable.', 'improvements' => ['Keep it concise.']],
                'cta' => ['score' => 40, 'explanation' => 'Weak CTA.', 'improvements' => ['Add a clearer CTA.']],
                'structure' => ['score' => 59, 'explanation' => 'Generic headings.', 'improvements' => ['Rewrite headings.']],
                'entities' => ['score' => 70, 'explanation' => 'Enough entities.', 'improvements' => ['Add one example.']],
            ],
            'top_improvements' => ['Add CTA', 'Improve headings', 'Tighten SEO'],
        ],
        'normalized_payload' => [
            'summary' => ['headline' => 'Before', 'overall_explanation' => 'Before improvement.'],
            'sections' => [
                'seo' => ['score' => 68, 'explanation' => 'SEO needs work.', 'improvements' => ['Improve keyword placement.']],
                'readability' => ['score' => 80, 'explanation' => 'Readable.', 'improvements' => ['Keep it concise.']],
                'cta' => ['score' => 40, 'explanation' => 'Weak CTA.', 'improvements' => ['Add a clearer CTA.']],
                'structure' => ['score' => 59, 'explanation' => 'Generic headings.', 'improvements' => ['Rewrite headings.']],
                'entities' => ['score' => 70, 'explanation' => 'Enough entities.', 'improvements' => ['Add one example.']],
            ],
            'top_improvements' => ['Add CTA', 'Improve headings', 'Tighten SEO'],
            'context' => ['snapshot_signature' => 'before-analysis'],
        ],
        'signals_payload' => [
            'cta' => ['cta_present' => false, 'cta_near_end' => false, 'weak_generic_cta' => true],
            'seo' => ['title_has_primary_keyword' => false, 'intro_has_primary_keyword' => false, 'meta_title_present' => true, 'meta_description_present' => true, 'keyword_stuffing_detected' => false, 'related_terms_present' => 0, 'related_term_total' => 1, 'headings_with_primary_keyword' => 0, 'internal_link_present' => false],
            'readability' => ['dense_block_count' => 2, 'average_sentence_words' => 22, 'scanability' => true],
            'headings' => ['h1_present' => true, 'generic_heading_count' => 2, 'hierarchy_consistent' => true],
        ],
        'snapshot_signature' => 'before-analysis',
    ]);

    $this->actingAs($user)
        ->post(route('app.drafts.improve', $draft), ['action' => 'improve_full_draft'])
        ->assertRedirect(route('app.drafts.show', ['draft' => $draft, 'tab' => 'improve']));

    $draft->refresh();
    $operationKey = (string) data_get($draft->meta, 'draft_intelligence.latest_improvement.operation_key');

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: [
            'title' => 'Draft intelligence article',
            'content_html' => '<h1>Draft intelligence article</h1><h2>What changed</h2><p>This draft now opens with stronger keyword alignment.</p><p>Plan a short review with your team, choose one pilot workflow, and use this checklist to launch your first 30-day rollout.</p>',
            'change_notes' => [
                'Added a clear closing CTA',
                'Rewrote generic headings',
                'Improved keyword placement in the opening section',
            ],
            'seo' => [
                'seo_title' => 'Draft intelligence article',
                'seo_meta_description' => 'Draft intelligence summary.',
                'seo_h1' => 'Draft intelligence article',
            ],
        ],
        usage: new LlmUsage(150, 80, 230),
        modelUsed: 'gpt-5.1',
        providerName: 'openai',
        requestId: 'req-phase-two-improve',
    ));
    app()->instance(LlmManager::class, $llm);

    $improveJob = new ImproveDraftSectionJob((string) $draft->id, 'improve_full_draft', (string) $user->id, $operationKey);
    $improveJob->handle(
        app(DraftIntelligenceService::class),
        app(DraftIntelligenceBillingService::class),
        app(\App\Services\Drafts\Intelligence\DraftImprovementHistoryBuilder::class),
    );

    $analysisLlm = \Mockery::mock(LlmManager::class);
    $analysisLlm->shouldReceive('generateJson')->once()->andReturn(fakeDraftAnalysisResponse(61, 'The CTA now gives the reader a clear next step.'));
    app()->instance(LlmManager::class, $analysisLlm);

    $analyzeJob = new AnalyzeDraftJob((string) $draft->id, true, (string) $user->id, $operationKey);
    $analyzeJob->handle(
        app(DraftIntelligenceService::class),
        app(DraftIntelligenceBillingService::class),
        app(\App\Services\Drafts\Intelligence\DraftRecommendationEngine::class),
        app(\App\Services\Drafts\Intelligence\DraftImprovementHistoryBuilder::class),
        app(\App\Services\Drafts\Intelligence\DraftIntelligenceDeltaService::class),
    );

    $improvementResult = DraftImprovementResult::query()
        ->where('draft_id', (string) $draft->id)
        ->where('operation_key', $operationKey)
        ->firstOrFail();

    expect($improvementResult->status)->toBe('completed')
        ->and($improvementResult->summary)->toContain('Added a clear closing CTA')
        ->and($improvementResult->change_notes)->toContain('Rewrote generic headings')
        ->and($improvementResult->after_analysis_id)->not->toBeNull()
        ->and($improvementResult->score_delta_snapshot)->toHaveKey('cta');

    $ctaDelta = DraftIntelligenceDelta::query()
        ->where('draft_improvement_result_id', (string) $improvementResult->id)
        ->where('metric_key', 'cta')
        ->firstOrFail();

    expect($ctaDelta->score_before)->toBe(40)
        ->and($ctaDelta->score_after)->toBeGreaterThan(40)
        ->and($ctaDelta->delta)->toBeGreaterThan(0)
        ->and($ctaDelta->explanation)->toContain('CTA improved');

    $latestAnalysis = DraftAnalysis::query()->where('draft_id', (string) $draft->id)->latest('created_at')->firstOrFail();

    expect(DraftRecommendation::query()->where('draft_analysis_id', (string) $latestAnalysis->id)->count())->toBeGreaterThan(0)
        ->and(data_get($latestAnalysis->canonicalPayload(), 'top_priorities.0.title'))->not->toBeEmpty();
});

it('renders top priorities and recent improvement deltas in the draft detail ui', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-phase-two-ui');

    $analysis = DraftAnalysis::query()->create([
        'draft_id' => (string) $draft->id,
        'status' => DraftAnalysis::STATUS_COMPLETED,
        'seo_score' => 62,
        'readability_score' => 77,
        'cta_score' => 45,
        'headings_score' => 58,
        'suggestions' => [
            'summary' => ['headline' => 'Needs CTA work', 'overall_explanation' => 'CTA and headings need improvement.'],
            'sections' => [
                'seo' => ['score' => 62, 'explanation' => 'SEO is acceptable.', 'improvements' => ['Tighten title.']],
                'readability' => ['score' => 77, 'explanation' => 'Readable.', 'improvements' => ['Keep blocks short.']],
                'cta' => ['score' => 45, 'explanation' => 'CTA is weak.', 'improvements' => ['Add CTA.']],
                'structure' => ['score' => 58, 'explanation' => 'Headings are generic.', 'improvements' => ['Rewrite headings.']],
                'entities' => ['score' => 70, 'explanation' => 'Entity coverage is acceptable.', 'improvements' => ['Add one example.']],
            ],
            'top_improvements' => ['Add CTA', 'Rewrite headings', 'Tighten title'],
            'top_priorities' => [
                ['title' => 'Add a clear CTA to the conclusion', 'summary' => 'The article has no clear next step.', 'why_it_matters' => 'Readers need direction.', 'suggested_action' => 'Add a CTA.', 'impact_level' => 'high', 'effort_level' => 'medium', 'confidence_level' => 'high', 'metric_key' => 'cta'],
            ],
        ],
        'normalized_payload' => [
            'summary' => ['headline' => 'Needs CTA work', 'overall_explanation' => 'CTA and headings need improvement.'],
            'sections' => [
                'seo' => ['score' => 62, 'explanation' => 'SEO is acceptable.', 'improvements' => ['Tighten title.']],
                'readability' => ['score' => 77, 'explanation' => 'Readable.', 'improvements' => ['Keep blocks short.']],
                'cta' => ['score' => 45, 'explanation' => 'CTA is weak.', 'improvements' => ['Add CTA.']],
                'structure' => ['score' => 58, 'explanation' => 'Headings are generic.', 'improvements' => ['Rewrite headings.']],
                'entities' => ['score' => 70, 'explanation' => 'Entity coverage is acceptable.', 'improvements' => ['Add one example.']],
            ],
            'top_improvements' => ['Add CTA', 'Rewrite headings', 'Tighten title'],
            'top_priorities' => [
                ['title' => 'Add a clear CTA to the conclusion', 'summary' => 'The article has no clear next step.', 'why_it_matters' => 'Readers need direction.', 'suggested_action' => 'Add a CTA.', 'impact_level' => 'high', 'effort_level' => 'medium', 'confidence_level' => 'high', 'metric_key' => 'cta'],
            ],
        ],
    ]);

    DraftRecommendation::query()->create([
        'draft_id' => (string) $draft->id,
        'draft_analysis_id' => (string) $analysis->id,
        'metric_key' => 'cta',
        'title' => 'Add a clear CTA to the conclusion',
        'summary' => 'The article has no clear next step.',
        'why_it_matters' => 'Readers need direction.',
        'suggested_action' => 'Add a CTA.',
        'impact_level' => 'high',
        'effort_level' => 'medium',
        'confidence_level' => 'high',
        'priority_score' => 330,
        'sort_order' => 1,
    ]);

    $improvementResult = DraftImprovementResult::query()->create([
        'draft_id' => (string) $draft->id,
        'action' => 'improve_full_draft',
        'status' => 'completed',
        'operation_key' => (string) Str::uuid(),
        'summary' => 'Added a stronger CTA and better headings.',
        'change_notes' => ['Added a stronger CTA', 'Rewrote two headings'],
        'fully_applied' => true,
        'completed_at' => now(),
    ]);

    DraftIntelligenceDelta::query()->create([
        'draft_id' => (string) $draft->id,
        'draft_improvement_result_id' => (string) $improvementResult->id,
        'after_analysis_id' => (string) $analysis->id,
        'metric_key' => 'cta',
        'score_before' => 45,
        'score_after' => 63,
        'delta' => 18,
        'explanation' => 'CTA improved from 45 to 63 (+18).',
        'confidence_level' => 'high',
    ]);

    $response = $this->actingAs($user)->get(route('app.drafts.show', ['draft' => $draft, 'tab' => 'intelligence']));

    $response->assertOk()
        ->assertSee('Top priorities')
        ->assertSee('Add a clear CTA to the conclusion')
        ->assertSee('Latest improvement deltas')
        ->assertSee('45 → 63 (+18)');
});

it('renders null metric deltas as n/a instead of zero mirrors', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-phase-two-null-delta-ui');

    $analysis = DraftAnalysis::query()->create([
        'draft_id' => (string) $draft->id,
        'status' => DraftAnalysis::STATUS_COMPLETED,
        'seo_score' => 62,
        'readability_score' => 78,
        'cta_score' => 35,
        'headings_score' => 64,
        'normalized_payload' => [
            'sections' => [
                'seo' => ['score' => 62, 'explanation' => 'SEO is acceptable.', 'improvements' => []],
                'readability' => ['score' => 78, 'explanation' => 'Readable.', 'improvements' => []],
                'cta' => ['score' => 35, 'explanation' => 'CTA is weak.', 'improvements' => []],
                'structure' => ['score' => 64, 'explanation' => 'Headings are acceptable.', 'improvements' => []],
            ],
        ],
        'suggestions' => [
            'sections' => [
                'seo' => ['score' => 62, 'explanation' => 'SEO is acceptable.', 'improvements' => []],
                'readability' => ['score' => 78, 'explanation' => 'Readable.', 'improvements' => []],
                'cta' => ['score' => 35, 'explanation' => 'CTA is weak.', 'improvements' => []],
                'structure' => ['score' => 64, 'explanation' => 'Headings are acceptable.', 'improvements' => []],
            ],
        ],
    ]);

    $result = DraftImprovementResult::query()->create([
        'draft_id' => (string) $draft->id,
        'after_analysis_id' => (string) $analysis->id,
        'action' => 'improve_full_draft',
        'status' => 'completed',
        'operation_key' => (string) Str::uuid(),
        'summary' => 'Improvement completed but one metric was not scored yet.',
        'change_notes' => ['Improved the conclusion.'],
        'completed_at' => now(),
    ]);

    DraftIntelligenceDelta::query()->create([
        'draft_id' => (string) $draft->id,
        'draft_improvement_result_id' => (string) $result->id,
        'after_analysis_id' => (string) $analysis->id,
        'metric_key' => 'cta',
        'score_before' => 35,
        'score_after' => null,
        'delta' => null,
        'explanation' => 'CTA was 35 before the improvement, but the latest rescan did not produce a new score yet.',
        'confidence_level' => 'low',
    ]);

    $response = $this->actingAs($user)->get(route('app.drafts.show', ['draft' => $draft, 'tab' => 'intelligence']));

    $response->assertOk()
        ->assertSee('35 → n/a')
        ->assertDontSee('(-35)')
        ->assertDontSee('to (0)');
});

it('renders recent improvements with a status and timestamp for completed runs', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-phase-two-history-ui');

    $completedAt = now()->setSecond(0);

    DraftImprovementResult::query()->create([
        'draft_id' => (string) $draft->id,
        'action' => 'improve_full_draft',
        'status' => 'completed',
        'operation_key' => (string) Str::uuid(),
        'summary' => 'Added a stronger CTA and summary block.',
        'change_notes' => ['Added a stronger CTA and summary block.'],
        'completed_at' => $completedAt,
    ]);

    $response = $this->actingAs($user)->get(route('app.drafts.show', ['draft' => $draft, 'tab' => 'improve']));

    $response->assertOk()
        ->assertSee('Completed')
        ->assertSee($completedAt->format('Y-m-d H:i'));
});

it('persists llm visibility scores and grounded recommendations for weak answer structure', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-llm-visibility-analysis');
    addDraftImprovementCredits($draft);

    $draft->update([
        'content_html' => '<h1>Automation ideas</h1><p>This is useful for many teams and it can help in many situations.</p><p>There are different things to consider depending on what works.</p>',
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andThrow(new \RuntimeException('Force deterministic fallback.'));
    app()->instance(LlmManager::class, $llm);

    $job = new AnalyzeDraftJob((string) $draft->id, true, (string) $user->id, (string) Str::uuid());
    $job->handle(
        app(DraftIntelligenceService::class),
        app(DraftIntelligenceBillingService::class),
        app(\App\Services\Drafts\Intelligence\DraftRecommendationEngine::class),
        app(\App\Services\Drafts\Intelligence\DraftImprovementHistoryBuilder::class),
        app(\App\Services\Drafts\Intelligence\DraftIntelligenceDeltaService::class),
    );

    $analysis = DraftAnalysis::query()->where('draft_id', (string) $draft->id)->latest('created_at')->firstOrFail();

    expect($analysis->llm_visibility_score)->not->toBeNull()
        ->and($analysis->llm_visibility_score)->toBeLessThan(60)
        ->and((string) data_get($analysis->canonicalPayload(), 'sections.llm_visibility.explanation'))->toContain('AI systems');

    $recommendation = DraftRecommendation::query()
        ->where('draft_analysis_id', (string) $analysis->id)
        ->where('metric_key', 'llm_visibility')
        ->orderBy('sort_order')
        ->firstOrFail();

    expect($recommendation->title)->toContain('summary')
        ->and((string) $recommendation->why_it_matters)->toContain('AI');
});

it('improve_full_draft can improve llm visibility and attach a delta after rescan', function () {
    [$user, $draft] = makeDraftIntelligenceContext('draft-llm-visibility-improve');
    addDraftImprovementCredits($draft, 20);
    Queue::fake([AnalyzeDraftJob::class]);

    $draft->update([
        'content_html' => '<h1>Automation ideas</h1><p>This is useful for many teams and it can help in many situations.</p><p>There are different things to consider depending on what works.</p>',
    ]);

    $before = DraftAnalysis::query()->create([
        'draft_id' => (string) $draft->id,
        'status' => DraftAnalysis::STATUS_COMPLETED,
        'seo_score' => 52,
        'readability_score' => 58,
        'cta_score' => 32,
        'headings_score' => 44,
        'llm_visibility_score' => 28,
        'trust_evidence_score' => 34,
        'publish_readiness_score' => 26,
        'publish_readiness_status' => 'Not ready',
        'publish_readiness_blocking_issues' => ['Add a clear CTA to the end of the draft.'],
        'publish_readiness_next_actions' => ['Make the core answer explicit.', 'Add a concrete next step.'],
        'suggestions' => [
            'summary' => ['headline' => 'Weak framing', 'overall_explanation' => 'The draft is vague and hard to extract.'],
            'sections' => [
                'seo' => ['score' => 52, 'explanation' => 'SEO is basic.', 'improvements' => ['Tighten title.']],
                'readability' => ['score' => 58, 'explanation' => 'Readability is uneven.', 'improvements' => ['Break up one paragraph.']],
                'cta' => ['score' => 32, 'explanation' => 'CTA is weak.', 'improvements' => ['Add a CTA.']],
                'structure' => ['score' => 44, 'explanation' => 'Headings are generic.', 'improvements' => ['Improve headings.']],
                'llm_visibility' => ['score' => 28, 'explanation' => 'The core answer is hard for AI systems to extract.', 'improvements' => ['State the answer explicitly.']],
                'trust_evidence' => ['score' => 34, 'explanation' => 'The draft relies on generic claims.', 'improvements' => ['Add a concrete example.']],
                'publish_readiness' => ['score' => 26, 'explanation' => 'The draft is not ready to publish.', 'improvements' => ['Fix the CTA and support the recommendation.'], 'status_label' => 'Not ready', 'blocking_issues' => ['Add a clear CTA to the end of the draft.'], 'recommended_next_actions' => ['Make the core answer explicit.', 'Add a concrete next step.']],
                'entities' => ['score' => 60, 'explanation' => 'Entity coverage is acceptable.', 'improvements' => ['Name one example.']],
            ],
            'top_improvements' => ['State the answer explicitly', 'Add a summary block', 'Add a CTA'],
        ],
        'normalized_payload' => [
            'summary' => ['headline' => 'Weak framing', 'overall_explanation' => 'The draft is vague and hard to extract.'],
            'sections' => [
                'seo' => ['score' => 52, 'explanation' => 'SEO is basic.', 'improvements' => ['Tighten title.']],
                'readability' => ['score' => 58, 'explanation' => 'Readability is uneven.', 'improvements' => ['Break up one paragraph.']],
                'cta' => ['score' => 32, 'explanation' => 'CTA is weak.', 'improvements' => ['Add a CTA.']],
                'structure' => ['score' => 44, 'explanation' => 'Headings are generic.', 'improvements' => ['Improve headings.']],
                'llm_visibility' => ['score' => 28, 'explanation' => 'The core answer is hard for AI systems to extract.', 'improvements' => ['State the answer explicitly.']],
                'trust_evidence' => ['score' => 34, 'explanation' => 'The draft relies on generic claims.', 'improvements' => ['Add a concrete example.']],
                'publish_readiness' => ['score' => 26, 'explanation' => 'The draft is not ready to publish.', 'improvements' => ['Fix the CTA and support the recommendation.'], 'status_label' => 'Not ready', 'blocking_issues' => ['Add a clear CTA to the end of the draft.'], 'recommended_next_actions' => ['Make the core answer explicit.', 'Add a concrete next step.']],
                'entities' => ['score' => 60, 'explanation' => 'Entity coverage is acceptable.', 'improvements' => ['Name one example.']],
            ],
            'top_improvements' => ['State the answer explicitly', 'Add a summary block', 'Add a CTA'],
        ],
    ]);

    $operationKey = (string) Str::uuid();
    app(\App\Services\Drafts\Intelligence\DraftImprovementHistoryBuilder::class)
        ->queue($draft->fresh(['analysis']), DraftImprovementAction::FULL_DRAFT, (string) $user->id, $operationKey);

    $improvementLlm = \Mockery::mock(LlmManager::class);
    $improvementLlm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: [
            'title' => 'What telecom automation actually means',
            'content_html' => '<h1>What telecom automation actually means</h1><p>Telecom automation is the practice of replacing repetitive telecom workflows with rule-based or AI-assisted steps, and one team can validate the first workflow in 30 days.</p><h2>Summary</h2><p>In short, choose one workflow, assign an owner, and measure one pilot outcome in 30 days.</p><h2>3 practical steps</h2><ul><li>Map the workflow</li><li>Assign the owner</li><li>Measure the pilot</li></ul><p>For example, start with ticket routing and review the time saved after two weeks.</p><p>Plan a short workshop with your operations team and choose one pilot workflow this week.</p>',
            'change_notes' => [
                'Made the core answer explicit in the introduction',
                'Added a concise summary block',
                'Added a step-based section, one concrete example, and a clearer CTA',
            ],
            'seo' => [
                'seo_title' => 'What telecom automation actually means',
                'seo_meta_description' => 'A concise telecom automation explainer with a summary, steps, and clear next step.',
                'seo_h1' => 'What telecom automation actually means',
            ],
        ],
        usage: new LlmUsage(180, 120, 300),
        modelUsed: 'gpt-5.1',
        providerName: 'openai',
        requestId: 'req-llm-visibility-improve',
    ));
    app()->instance(LlmManager::class, $improvementLlm);

    $improveJob = new ImproveDraftSectionJob((string) $draft->id, 'improve_full_draft', (string) $user->id, $operationKey);
    $improveJob->handle(
        app(DraftIntelligenceService::class),
        app(DraftIntelligenceBillingService::class),
        app(\App\Services\Drafts\Intelligence\DraftImprovementHistoryBuilder::class),
    );

    $analysisLlm = \Mockery::mock(LlmManager::class);
    $analysisLlm->shouldReceive('generateJson')->once()->andThrow(new \RuntimeException('Force deterministic fallback.'));
    app()->instance(LlmManager::class, $analysisLlm);

    $analyzeJob = new AnalyzeDraftJob((string) $draft->id, true, (string) $user->id, $operationKey);
    $analyzeJob->handle(
        app(DraftIntelligenceService::class),
        app(DraftIntelligenceBillingService::class),
        app(\App\Services\Drafts\Intelligence\DraftRecommendationEngine::class),
        app(\App\Services\Drafts\Intelligence\DraftImprovementHistoryBuilder::class),
        app(\App\Services\Drafts\Intelligence\DraftIntelligenceDeltaService::class),
    );

    $result = DraftImprovementResult::query()
        ->where('draft_id', (string) $draft->id)
        ->where('operation_key', $operationKey)
        ->firstOrFail();
    $after = DraftAnalysis::query()->whereKey($result->after_analysis_id)->firstOrFail();

    expect($after->llm_visibility_score)->toBeGreaterThan(28)
        ->and($after->trust_evidence_score)->toBeGreaterThan(34)
        ->and($after->publish_readiness_score)->toBeGreaterThan(26)
        ->and($after->seo_score)->not->toBeNull()
        ->and($after->readability_score)->not->toBeNull()
        ->and($after->cta_score)->not->toBeNull()
        ->and($after->headings_score)->not->toBeNull()
        ->and(data_get($result->score_delta_snapshot, 'llm_visibility.delta'))->toBeGreaterThan(0)
        ->and(data_get($result->score_delta_snapshot, 'trust_evidence.delta'))->toBeGreaterThan(0)
        ->and(data_get($result->score_delta_snapshot, 'publish_readiness.delta'))->toBeGreaterThan(0)
        ->and((string) data_get($result->score_delta_snapshot, 'llm_visibility.explanation'))->toContain('LLM Visibility improved');
});
