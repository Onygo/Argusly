<?php

use App\Actions\Briefs\CreateBriefFromResearchAction;
use App\Actions\Briefs\EnhanceBriefAction;
use App\Models\BrandVoice;
use App\Models\Brief;
use App\Models\BriefSuggestion;
use App\Models\ClientSite;
use App\Models\CompanyProfile;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\ResearchFinding;
use App\Models\ResearchProject;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\Briefs\BriefGapAnalyzer;
use App\Services\Briefs\BriefIntelligenceService;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates a brief from research through the app endpoint', function () {
    config(['features.brief_intelligence' => true]);

    [, $workspace, $site, $user] = makeBriefIntelligenceContext('brief-intel-from-research');
    setBriefIntelligenceEntitlement($workspace, 'brief_intelligence_enabled', 'bool', true);

    $project = ResearchProject::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Q2 Messaging Research',
        'status' => 'completed',
        'target_keywords' => ['ai governance', 'workflow automation'],
        'summary' => [
            'highlights' => [
                'insights' => ['Teams need clearer approval ownership.'],
                'statistics' => ['42% of teams report approval bottlenecks.'],
                'questions' => ['How to shorten approval cycles?'],
                'entities' => ['Marketing ops'],
            ],
            'brief_enrichment' => [
                'recommended_angles' => ['Speed to publish with governance'],
                'keyword_clusters' => ['ai governance', 'editorial workflow'],
            ],
            'model_summary' => [
                'executive_summary' => 'Governance and publishing speed are the core themes.',
                'key_insights' => ['Clear owners reduce publication delays.'],
            ],
        ],
        'human_summary' => 'Teams want clearer ownership and faster execution.',
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)->post(route('app.content.create.from-research'), [
        'research_project_id' => (string) $project->id,
        'language' => 'en',
        'content_type' => 'blog',
    ]);

    $brief = Brief::query()->latest('created_at')->first();

    expect($brief)->not->toBeNull()
        ->and((string) $brief->client_site_id)->toBe((string) $site->id)
        ->and((string) data_get($brief->client_refs, 'brief_intelligence.research_project_id'))->toBe((string) $project->id)
        ->and((array) ($brief->key_points ?? []))->not->toBeEmpty()
        ->and((string) $project->fresh()->brief_id)->toBe((string) $brief->id);

    $response->assertRedirect(route('app.content.workspace.show', $brief));
});

it('enhance action stores brief suggestions and completeness metadata', function () {
    [, $workspace, $site, $user] = makeBriefIntelligenceContext('brief-intel-enhance');
    setBriefIntelligenceEntitlement($workspace, 'brief_intelligence_enabled', 'bool', true);

    $brief = makeBrief($site, $user, [
        'title' => 'AI governance playbook',
        'primary_keyword' => 'ai governance',
        'target_audience' => 'Marketing directors',
    ]);

    CompanyProfile::query()->create([
        'workspace_id' => $workspace->id,
        'company_name' => 'Argusly',
        'industry' => 'SaaS',
        'value_propositions' => "Governance\nAutomation",
        'target_audience' => 'B2B marketers',
    ]);

    BrandVoice::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'name' => 'Clear Executive',
        'tone_of_voice' => 'Confident and practical',
        'writing_style' => 'Direct',
        'is_default' => true,
    ]);

    $project = ResearchProject::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'brief_id' => $brief->id,
        'name' => 'Linked research',
        'status' => 'completed',
        'summary' => ['brief_enrichment' => ['recommended_angles' => ['Operational control']]],
        'human_summary' => 'Focus on operational control.',
        'completed_at' => now(),
    ]);

    $brief->update([
        'client_refs' => array_merge((array) $brief->client_refs, [
            'brief_intelligence' => [
                'research_project_id' => (string) $project->id,
            ],
        ]),
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: briefIntelligenceJsonStub(),
        usage: new LlmUsage(150, 70, 220),
        modelUsed: 'gpt-4.1-mini',
        providerName: 'openai',
        requestId: 'req-brief-enhance-1',
    ));
    app()->instance(LlmManager::class, $llm);

    $result = app(EnhanceBriefAction::class)->execute($brief->fresh(), $user, false);

    $brief->refresh();
    $suggestions = BriefSuggestion::query()->where('brief_id', $brief->id)->get();

    expect((int) ($result['suggestions_created'] ?? 0))->toBeGreaterThan(0)
        ->and($suggestions->count())->toBeGreaterThan(0)
        ->and((int) data_get($brief->client_refs, 'brief_intelligence.completeness.score'))->toBeGreaterThanOrEqual(0)
        ->and((string) data_get($brief->client_refs, 'brief_intelligence.intelligence_summary'))->not->toBe('');
});

it('keeps enhance suggestions idempotent when rerun without force', function () {
    [, $workspace, $site, $user] = makeBriefIntelligenceContext('brief-intel-idempotent');
    setBriefIntelligenceEntitlement($workspace, 'brief_intelligence_enabled', 'bool', true);

    $brief = makeBrief($site, $user, [
        'title' => 'Idempotent brief',
        'primary_keyword' => 'content ops',
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: briefIntelligenceJsonStub(),
        usage: new LlmUsage(150, 70, 220),
        modelUsed: 'gpt-4.1-mini',
        providerName: 'openai',
        requestId: 'req-brief-enhance-idempotent',
    ));
    app()->instance(LlmManager::class, $llm);

    $action = app(EnhanceBriefAction::class);
    $first = $action->execute($brief->fresh(), $user, false);
    $second = $action->execute($brief->fresh(), $user, false);

    expect((int) ($first['suggestions_created'] ?? 0))->toBeGreaterThan(0)
        ->and((int) ($second['suggestions_created'] ?? 0))->toBe(0)
        ->and(BriefSuggestion::query()->where('brief_id', $brief->id)->count())->toBe((int) ($first['suggestions_created'] ?? 0));
});

it('denies brief enhancement when entitlement is disabled', function () {
    [, $workspace, $site, $user] = makeBriefIntelligenceContext('brief-intel-gate');
    setBriefIntelligenceEntitlement($workspace, 'brief_intelligence_enabled', 'bool', false);

    $brief = makeBrief($site, $user, [
        'title' => 'Blocked enhancement',
    ]);

    expect(fn () => app(EnhanceBriefAction::class)->execute($brief, $user, false))
        ->toThrow(AuthorizationException::class);
});

it('calculates completeness scores deterministically', function () {
    [, , $site, $user] = makeBriefIntelligenceContext('brief-intel-score');

    $brief = makeBrief($site, $user, [
        'title' => 'Short',
        'primary_keyword' => null,
        'target_audience' => null,
        'secondary_keywords' => [],
        'key_points' => [],
        'call_to_action' => null,
        'tone_of_voice' => null,
    ]);

    $analysis = app(BriefGapAnalyzer::class)->analyze($brief);

    expect((int) ($analysis['score'] ?? 100))->toBeLessThan(60)
        ->and((array) ($analysis['missing_inputs'] ?? []))->toContain('Primary keyword')
        ->and((string) ($analysis['recommendation'] ?? ''))->not->toBe('');
});

it('applies one pending suggestion to the brief safely', function () {
    [, $workspace, $site, $user] = makeBriefIntelligenceContext('brief-intel-apply');
    setBriefIntelligenceEntitlement($workspace, 'brief_intelligence_enabled', 'bool', true);

    $brief = makeBrief($site, $user, [
        'title' => 'Original title',
    ]);

    $suggestion = BriefSuggestion::query()->create([
        'brief_id' => $brief->id,
        'suggestion_type' => 'title',
        'original_value' => 'Original title',
        'suggested_value' => 'Improved title',
        'rationale' => 'Sharper and clearer.',
        'status' => BriefSuggestion::STATUS_PENDING,
        'meta' => ['value_format' => 'text'],
    ]);

    $noopLlm = \Mockery::mock(LlmManager::class);
    app()->instance(LlmManager::class, $noopLlm);

    app(BriefIntelligenceService::class)->applySuggestion($brief->fresh(), $suggestion->fresh(), (int) $user->id);

    $brief->refresh();
    $suggestion->refresh();

    expect((string) $brief->title)->toBe('Improved title')
        ->and((string) $suggestion->status)->toBe(BriefSuggestion::STATUS_APPLIED)
        ->and((array) data_get($brief->client_refs, 'brief_intelligence.applied_suggestion_history', []))->not->toBeEmpty();
});

it('rejects a pending suggestion while tracking reason', function () {
    [, $workspace, $site, $user] = makeBriefIntelligenceContext('brief-intel-reject');
    setBriefIntelligenceEntitlement($workspace, 'brief_intelligence_enabled', 'bool', true);

    $brief = makeBrief($site, $user, [
        'title' => 'Keep this title',
    ]);

    $suggestion = BriefSuggestion::query()->create([
        'brief_id' => $brief->id,
        'suggestion_type' => 'cta_direction',
        'original_value' => 'Book a demo',
        'suggested_value' => 'Start a guided trial',
        'status' => BriefSuggestion::STATUS_PENDING,
        'meta' => ['value_format' => 'text'],
    ]);

    $noopLlm = \Mockery::mock(LlmManager::class);
    app()->instance(LlmManager::class, $noopLlm);

    app(BriefIntelligenceService::class)->rejectSuggestion($brief, $suggestion, (int) $user->id, 'Not aligned with campaign');

    $suggestion->refresh();

    expect((string) $suggestion->status)->toBe(BriefSuggestion::STATUS_REJECTED)
        ->and((string) data_get($suggestion->meta, 'rejected_reason'))->toContain('Not aligned');
});

it('keeps workspace boundaries on suggestion apply endpoints', function () {
    config(['features.brief_intelligence' => true]);

    [, $workspaceA, $siteA, $userA] = makeBriefIntelligenceContext('brief-intel-isolation-a');
    [, $workspaceB, $siteB] = makeBriefIntelligenceContext('brief-intel-isolation-b');
    setBriefIntelligenceEntitlement($workspaceA, 'brief_intelligence_enabled', 'bool', true);
    setBriefIntelligenceEntitlement($workspaceB, 'brief_intelligence_enabled', 'bool', true);

    $briefB = makeBrief($siteB, null, ['title' => 'Foreign org brief']);
    $suggestionB = BriefSuggestion::query()->create([
        'brief_id' => $briefB->id,
        'suggestion_type' => 'title',
        'suggested_value' => 'Foreign suggestion',
        'status' => BriefSuggestion::STATUS_PENDING,
        'meta' => ['value_format' => 'text'],
    ]);

    $response = $this->actingAs($userA)->post(route('app.content.workspace.brief.suggestions.apply', [$briefB, $suggestionB->id]));

    $response->assertNotFound();
});

it('passes company profile, brand voice, and linked research context into llm prompt', function () {
    [, $workspace, $site, $user] = makeBriefIntelligenceContext('brief-intel-context');
    setBriefIntelligenceEntitlement($workspace, 'brief_intelligence_enabled', 'bool', true);

    $brief = makeBrief($site, $user, [
        'title' => 'Context brief',
        'primary_keyword' => 'ai operations',
    ]);

    CompanyProfile::query()->create([
        'workspace_id' => $workspace->id,
        'company_name' => 'Acme Analytics',
        'industry' => 'B2B SaaS',
        'value_propositions' => "Compliance first\nFast publishing",
        'target_audience' => 'Enterprise marketing teams',
    ]);

    BrandVoice::query()->create([
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'name' => 'Boardroom Crisp',
        'tone_of_voice' => 'Executive and precise',
        'writing_style' => 'Concise',
        'is_default' => true,
    ]);

    $project = ResearchProject::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'brief_id' => $brief->id,
        'name' => 'Enterprise AI research',
        'status' => 'completed',
        'summary' => ['brief_enrichment' => ['recommended_angles' => ['Governed scale']]],
        'human_summary' => 'Enterprise teams want governed scale.',
        'completed_at' => now(),
    ]);

    ResearchFinding::query()->create([
        'research_project_id' => $project->id,
        'finding_type' => 'insight',
        'finding_text' => 'Governance trust is required for enterprise adoption.',
        'confidence_score' => 0.92,
        'is_selected' => true,
    ]);

    $brief->update([
        'client_refs' => [
            'brief_intelligence' => [
                'research_project_id' => (string) $project->id,
            ],
        ],
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')
        ->once()
        ->withArgs(function (LlmRequest $request): bool {
            $content = (string) ($request->messages[1]->content ?? '');

            return str_contains($content, 'Acme Analytics')
                && str_contains($content, 'Boardroom Crisp')
                && str_contains($content, 'Enterprise AI research')
                && str_contains($content, 'Governance trust is required');
        })
        ->andReturn(new LlmResponse(
            text: '{}',
            json: briefIntelligenceJsonStub(),
            usage: new LlmUsage(120, 60, 180),
            modelUsed: 'gpt-4.1-mini',
            providerName: 'openai',
            requestId: 'req-brief-intel-context',
        ));
    app()->instance(LlmManager::class, $llm);

    $result = app(BriefIntelligenceService::class)->generateSuggestions($brief->fresh(), false);

    expect((int) ($result['suggestions_created'] ?? 0))->toBeGreaterThan(0);
});

function makeBriefIntelligenceContext(string $prefix = 'brief-intel'): array
{
    $organization = Organization::query()->create([
        'name' => 'Brief Intelligence Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Brief Intelligence BV',
        'billing_address_line1' => 'Teststraat 42',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Brief Intelligence Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Brief Intelligence Site',
        'site_url' => 'https://brief-intelligence.example.com',
        'allowed_domains' => ['brief-intelligence.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => $prefix . '-plan'],
        [
            'name' => 'Brief Intelligence Plan',
            'slug' => $prefix . '-plan',
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
        'name' => 'Brief Intelligence User',
        'email' => $prefix . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

function makeBrief(ClientSite $site, ?User $user, array $overrides = []): Brief
{
    return Brief::query()->create(array_merge([
        'client_site_id' => $site->id,
        'created_by_user_id' => $user?->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Brief intelligence article',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'secondary_keywords' => ['governance'],
        'key_points' => ['Point one'],
        'desired_length_min' => 900,
        'desired_length_max' => 1200,
    ], $overrides));
}

function setBriefIntelligenceEntitlement(Workspace $workspace, string $featureKey, string $valueType, mixed $value): void
{
    WorkspaceEntitlement::query()->updateOrCreate(
        [
            'workspace_id' => $workspace->id,
            'feature_key' => $featureKey,
        ],
        [
            'id' => (string) Str::uuid(),
            'organization_id' => $workspace->organization_id,
            'value_type' => $valueType,
            'value_bool' => $valueType === 'bool' ? (bool) $value : null,
            'value_int' => $valueType === 'int' ? (int) $value : null,
            'value_string' => $valueType === 'string' ? (string) $value : null,
            'value_json' => $valueType === 'json' ? (array) $value : null,
            'source' => 'manual',
            'effective_at' => now()->subMinute(),
            'expires_at' => null,
            'refreshed_at' => now(),
        ]
    );
}

/**
 * @return array<string,mixed>
 */
function briefIntelligenceJsonStub(): array
{
    return [
        'intelligence_summary' => 'Use a practical governance-first narrative with clear CTA.',
        'title' => ['value' => 'AI governance in practice for marketing teams', 'rationale' => 'Specific and outcome-focused'],
        'angle' => ['value' => 'Balance compliance and speed to publish', 'rationale' => 'Captures real trade-off'],
        'audience' => ['value' => 'B2B marketing leaders', 'rationale' => 'Matches the brief context'],
        'keyword_cluster' => ['values' => ['ai governance', 'content workflow'], 'rationale' => 'Core search themes'],
        'semantic_terms' => ['values' => ['approval SLAs', 'workflow orchestration'], 'rationale' => 'Supports semantic depth'],
        'search_intent' => ['value' => 'informational', 'rationale' => 'User seeks guidance and patterns'],
        'recommended_headings' => ['values' => ['Why governance stalls publishing', 'A practical rollout model'], 'rationale' => 'Clear article structure'],
        'cta_direction' => ['value' => 'Start with a governance readiness audit', 'rationale' => 'Low-friction next step'],
    ];
}
