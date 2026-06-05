<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\DraftAnalysis;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Drafts\DraftIntelligenceService;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('uses the latest saved closing CTA content in the analysis prompt and calibrates the CTA score upward', function () {
    [, $draft] = makeDraftIntelligenceCtaContext('draft-intelligence-cta-tail');

    $draft->brief->update([
        'funnel_stage' => 'consideration',
        'call_to_action' => 'Plan a pilot workshop',
    ]);

    $tailCta = 'Wil je verkennen hoe jij in 30 dagen een eerste kernproces kunt automatiseren? Plan dan een kort gesprek met je CTO of operations team en bepaal welke workflow zich het beste leent voor een eerste pilot. Gebruik dit artikel als checklist voor je eigen 30 dagen plan en zet vandaag de eerste stap richting slimmere telecom processen.';
    $longIntro = str_repeat('Telecom automation requires alignment on workflow selection, ownership, and pilot scope. ', 180);
    $draft->update([
        'content_html' => '<p>' . e($longIntro) . '</p><p>' . e($tailCta) . '</p>',
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')
        ->once()
        ->withArgs(function (LlmRequest $request, array $schema): bool {
            $payload = json_decode((string) ($request->messages[1]->content ?? ''), true);

            expect($request->temperature)->toBe(0.0)
                ->and(data_get($payload, 'draft.closing_plain_text'))->toContain('30 dagen een eerste kernproces kunt automatiseren')
                ->and(data_get($payload, 'draft.cta_focus_excerpt'))->toContain('kort gesprek met je CTO of operations team')
                ->and(data_get($payload, 'brief.funnel_stage'))->toBe('consideration');

            return true;
        })
        ->andReturn(fakeDraftAnalysisResponse(35, 'The CTA is still fairly weak.'));
    app()->instance(LlmManager::class, $llm);

    $analysis = app(DraftIntelligenceService::class)->analyzeAndStore($draft->fresh(), true);

    expect($analysis->cta_score)->toBeGreaterThan(35)
        ->and($analysis->cta_score)->toBeGreaterThanOrEqual(61)
        ->and((string) data_get($analysis->canonicalPayload(), 'sections.cta.explanation'))->toContain('consideration-stage')
        ->and((string) data_get($analysis->canonicalPayload(), 'context.cta_score_band'))->toBeIn([
            '61-80: clear, relevant, actionable CTA',
            '81-100: highly compelling, specific, well-matched CTA',
        ]);
});

it('keeps CTA scores stable across repeated forced rescans even when llm responses vary', function () {
    [, $draft] = makeDraftIntelligenceCtaContext('draft-intelligence-cta-stable');

    $draft->brief->update([
        'funnel_stage' => 'consideration',
    ]);

    $draft->update([
        'content_html' => '<p>Automation teams need a realistic first workflow.</p><p>Plan a short review with your operations team, choose one pilot workflow, and use this checklist to launch your first 30-day rollout.</p>',
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->times(3)->andReturn(
        fakeDraftAnalysisResponse(35, 'CTA is weak.'),
        fakeDraftAnalysisResponse(52, 'CTA is okay.'),
        fakeDraftAnalysisResponse(28, 'CTA needs work.'),
    );
    app()->instance(LlmManager::class, $llm);

    $service = app(DraftIntelligenceService::class);

    $scores = [
        $service->analyze($draft->fresh(), true)->ctaScore,
        $service->analyze($draft->fresh(), true)->ctaScore,
        $service->analyze($draft->fresh(), true)->ctaScore,
    ];

    expect(collect($scores)->filter()->unique()->values()->all())->toHaveCount(1);
});

it('recalculates from fresh saved draft content instead of reusing the old analysis snapshot', function () {
    [, $draft] = makeDraftIntelligenceCtaContext('draft-intelligence-cta-refresh');

    $draft->brief->update([
        'funnel_stage' => 'consideration',
    ]);

    DraftAnalysis::query()->create([
        'draft_id' => (string) $draft->id,
        'cta_score' => 18,
        'status' => DraftAnalysis::STATUS_COMPLETED,
        'prompt_version' => DraftIntelligenceService::PROMPT_VERSION,
        'suggestions' => [
            'summary' => ['headline' => 'No CTA', 'overall_explanation' => 'The draft ends without a clear next step.'],
            'sections' => [
                'seo' => ['score' => 60, 'explanation' => 'ok', 'improvements' => ['tighten metadata']],
                'readability' => ['score' => 60, 'explanation' => 'ok', 'improvements' => ['shorten one paragraph']],
                'cta' => ['score' => 18, 'explanation' => 'No real CTA.', 'improvements' => ['Add one CTA']],
                'structure' => ['score' => 60, 'explanation' => 'ok', 'improvements' => ['improve one heading']],
                'entities' => ['score' => 60, 'explanation' => 'ok', 'improvements' => ['add one supporting entity']],
            ],
            'keyword_coverage' => ['score' => 60, 'covered_terms' => [], 'missing_terms' => [], 'explanation' => 'ok'],
            'entity_coverage' => ['score' => 60, 'detected_entities' => [], 'missing_entities' => [], 'explanation' => 'ok'],
            'internal_link_summary' => 'None.',
            'internal_link_opportunities' => [],
            'top_improvements' => ['Add CTA', 'Tighten SEO', 'Improve structure'],
            'context' => [
                'content_hash' => sha1(implode('|', [
                    (string) $draft->title,
                    (string) $draft->seo_title,
                    (string) $draft->seo_meta_description,
                    (string) $draft->content_html,
                ])),
                'analysis_signature' => 'stale-signature',
                'prompt_version' => DraftIntelligenceService::PROMPT_VERSION,
            ],
        ],
        'normalized_payload' => null,
        'analysis_model' => 'gpt-5.1',
        'tokens_used' => 100,
    ]);

    $draft->forceFill([
        'content_html' => '<p>Automation strategy starts with one constrained workflow.</p><p>Plan a short discussion with your CTO or operations team, choose a first pilot, and use this checklist to shape the next 30 days.</p>',
    ])->saveQuietly();

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(fakeDraftAnalysisResponse(30, 'CTA still feels limited.'));
    app()->instance(LlmManager::class, $llm);

    $analysis = app(DraftIntelligenceService::class)->analyze($draft->fresh(), false);

    expect($analysis->ctaScore)->toBeGreaterThan(18)
        ->and((string) data_get($analysis->normalizedPayload, 'sections.cta.explanation'))->toContain('consideration-stage');
});

function makeDraftIntelligenceCtaContext(string $prefix): array
{
    $organization = Organization::query()->create([
        'name' => 'Draft CTA Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Draft CTA BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft CTA Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Draft CTA Site',
        'site_url' => 'https://draft-cta.example.com',
        'allowed_domains' => ['draft-cta.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'draft-cta-test-plan'],
        [
            'name' => 'Draft CTA Test Plan',
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
        'name' => 'Draft CTA User',
        'email' => $prefix . '+' . Str::lower(Str::random(6)) . '@example.com',
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
        'title' => 'CTA test brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'telecom processen automatiseren',
        'secondary_keywords' => ['workflow pilot', '30 dagen plan'],
        'target_audience' => 'CTO and operations teams',
        'call_to_action' => 'Plan a pilot workshop',
        'funnel_stage' => 'consideration',
        'progress' => 0,
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'CTA test draft',
        'output_type' => 'kb_article',
        'seo_title' => 'CTA test draft',
        'seo_meta_description' => 'CTA test summary.',
        'seo_h1' => 'CTA test draft',
        'content_html' => '<p>Baseline content without a real CTA.</p>',
    ]);

    return [$user, $draft];
}
