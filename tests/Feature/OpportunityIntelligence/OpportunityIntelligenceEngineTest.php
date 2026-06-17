<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Models\Brief;
use App\Models\Campaign;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\SignalDetection;
use App\Models\Workspace;
use App\Models\User;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;
use App\Services\OpportunityIntelligence\OpportunityExecutionPlanBuilder;
use App\Services\OpportunityIntelligence\OpportunitySignalIngestor;
use App\Services\OpportunityIntelligence\OpportunitySignalPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function promotedOpportunityContext(string $slug): array
{
    $organization = Organization::query()->create([
        'name' => 'Promoted Opportunity '.$slug,
        'slug' => 'promoted-opportunity-'.$slug.'-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Promoted Workspace '.$slug,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return compact('organization', 'workspace', 'user');
}

function promotedOpportunitySignal(Workspace $workspace, array $overrides = []): array
{
    $detection = SignalDetection::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'category' => $overrides['signal_detection_category'] ?? SignalDetection::CATEGORY_TREND_DETECTION,
        'type' => $overrides['signal_detection_type'] ?? 'topic_velocity',
        'status' => SignalStatus::PUBLISHED->value,
        'severity' => SignalSeverity::HIGH->value,
        'title' => $overrides['title'] ?? 'Promoted AI search signal',
        'summary' => $overrides['summary'] ?? 'Promoted detection evidence indicates an opportunity.',
        'primary_topic' => $overrides['topic'] ?? 'AI search',
        'primary_entity' => $overrides['entity'] ?? 'Argusly',
        'priority_score' => $overrides['priority_score'] ?? 88,
        'confidence_score' => $overrides['confidence'] ?? 84,
        'impact_score' => $overrides['impact_score'] ?? 72,
        'urgency_score' => $overrides['urgency_score'] ?? 68,
        'opportunity_score' => $overrides['signal_strength'] ?? 82,
    ]);

    $signal = OpportunitySignal::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $overrides['client_site_id'] ?? null,
        'source' => OpportunitySignalSource::SIGNAL_INTELLIGENCE->value,
        'category' => $overrides['category'] ?? OpportunityCategory::TREND_OPPORTUNITY->value,
        'topic' => $overrides['topic'] ?? 'AI search',
        'entity' => $overrides['entity'] ?? 'Argusly',
        'signal_strength' => $overrides['signal_strength'] ?? 82,
        'confidence' => $overrides['confidence'] ?? 84,
        'observed_at' => $overrides['observed_at'] ?? now()->subDay(),
        'metrics' => [
            'impact_score' => $overrides['impact_score'] ?? 72,
            'urgency_score' => $overrides['urgency_score'] ?? 68,
            'priority_score' => $overrides['priority_score'] ?? 88,
        ],
        'evidence' => [
            'summary' => $detection->summary,
            'evidence_summary' => ['signals' => 2, 'source' => 'signal_intelligence'],
        ],
        'metadata' => [
            'summary' => $detection->summary,
            'signal_detection_id' => (string) $detection->id,
            'signal_detection_category' => $detection->category,
            'signal_detection_type' => $detection->type,
            'signal_priority_score' => $detection->priority_score,
            'linked_signal_event_ids' => [(string) Str::uuid()],
        ],
        'dedupe_hash' => hash('sha256', $workspace->id.'|promoted|'.$detection->id),
    ]);

    return compact('detection', 'signal');
}

function promotedOpportunitySite(Workspace $workspace, string $slug = 'site'): ClientSite
{
    return ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Opportunity Site '.$slug,
        'site_url' => 'https://'.$slug.'-'.Str::random(6).'.example.com',
        'allowed_domains' => [$slug.'.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);
}

function promotedExecutionPlan(array $context, string $status = 'approved'): OpportunityExecutionPlan
{
    $site = promotedOpportunitySite($context['workspace'], 'execution-plan');
    promotedOpportunitySignal($context['workspace'], ['client_site_id' => $site->id]);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);

    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();
    $opportunity->forceFill([
        'status' => 'approved',
        'client_site_id' => $site->id,
    ])->save();

    $plan = app(OpportunityExecutionPlanBuilder::class)->build($opportunity, $context['user']);
    $plan->forceFill([
        'status' => $status,
        'client_site_id' => $site->id,
    ])->save();

    return $plan->refresh();
}

function promotedExecutionPlanBrief(array $context, string $planStatus = 'approved'): Brief
{
    $plan = promotedExecutionPlan($context, $planStatus);

    test()->actingAs($context['user'])
        ->post(route('app.opportunity-intelligence.execution-plans.create-brief', $plan));

    return Brief::query()->where('client_refs->execution_plan_id', (string) $plan->id)->firstOrFail();
}

function promotedExecutionPlanDraft(array $context): Draft
{
    $brief = promotedExecutionPlanBrief($context);

    test()->actingAs($context['user'])
        ->post(route('app.briefs.create-draft', $brief));

    return Draft::query()->where('brief_id', $brief->id)->firstOrFail();
}

it('ingests explainable signals and creates scored recommended opportunities', function (): void {
    $organization = Organization::query()->create([
        'name' => 'Opportunity Intelligence Org',
        'slug' => 'opportunity-intelligence-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Opportunity Intelligence Workspace',
    ]);

    $ingestor = app(OpportunitySignalIngestor::class);

    $ingestor->ingest($workspace, new OpportunitySignalPayload(
        source: OpportunitySignalSource::SEARCH_TRENDS,
        category: OpportunityCategory::TREND_OPPORTUNITY,
        topic: 'agentic marketing workflows',
        entity: 'Agentic Marketing',
        signalStrength: 84,
        confidence: 78,
        metrics: ['query_growth' => 0.42],
        evidence: [['source' => 'search_trends', 'summary' => 'Query growth increased 42%.']],
    ));

    $ingestor->ingest($workspace, new OpportunitySignalPayload(
        source: OpportunitySignalSource::ENGAGEMENT_ANALYTICS,
        category: OpportunityCategory::TREND_OPPORTUNITY,
        topic: 'agentic marketing workflows',
        entity: 'Agentic Marketing',
        signalStrength: 72,
        confidence: 74,
        metrics: ['linkedin_engagement_rate' => 0.08],
        evidence: [['source' => 'linkedin', 'summary' => 'Related social posts are gaining engagement.']],
    ));

    $result = app(OpportunityIntelligenceEngine::class)->run($workspace);

    $opportunity = Opportunity::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($result['created'])->toBe(1)
        ->and($opportunity->category)->toBe(OpportunityCategory::TREND_OPPORTUNITY)
        ->and($opportunity->priority_score)->toBeGreaterThan(65)
        ->and($opportunity->confidence_score)->toBeGreaterThan(70)
        ->and($opportunity->recommended_actions)->not->toBeEmpty()
        ->and($opportunity->signals()->count())->toBe(2);
});

it('picks up promoted signal intelligence signals and creates an opportunity', function (): void {
    $context = promotedOpportunityContext('pickup');
    $promoted = promotedOpportunitySignal($context['workspace']);

    $result = app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();

    expect($result['created'])->toBe(1)
        ->and($opportunity->metadata['has_signal_intelligence_input'])->toBeTrue()
        ->and($opportunity->metadata['signal_detection_ids'])->toContain((string) $promoted['detection']->id)
        ->and($opportunity->source_signal_summary['promoted_signal_intelligence_count'])->toBe(1)
        ->and($opportunity->signals()->count())->toBe(1);
});

it('clusters multiple promoted signals into one opportunity without duplicates', function (): void {
    $context = promotedOpportunityContext('cluster');
    promotedOpportunitySignal($context['workspace'], ['topic' => 'AI answer visibility', 'impact_score' => 72, 'urgency_score' => 68]);
    promotedOpportunitySignal($context['workspace'], ['topic' => 'AI answer visibility', 'impact_score' => 75, 'urgency_score' => 66]);

    $first = app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $second = app(OpportunityIntelligenceEngine::class)->run($context['workspace']);

    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();

    expect($first['created'])->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(1)
        ->and(Opportunity::query()->where('workspace_id', $context['workspace']->id)->count())->toBe(1)
        ->and($opportunity->signals()->count())->toBe(2);
});

it('keeps promoted signal clustering isolated per workspace', function (): void {
    $first = promotedOpportunityContext('workspace-a');
    $second = promotedOpportunityContext('workspace-b');
    promotedOpportunitySignal($first['workspace'], ['topic' => 'Shared topic']);
    promotedOpportunitySignal($second['workspace'], ['topic' => 'Shared topic']);

    app(OpportunityIntelligenceEngine::class)->run($first['workspace']);

    expect(Opportunity::query()->where('workspace_id', $first['workspace']->id)->count())->toBe(1)
        ->and(Opportunity::query()->where('workspace_id', $second['workspace']->id)->count())->toBe(0);
});

it('shows promoted signal intelligence evidence and detection link in the UI', function (): void {
    Config::set('features.agentic_marketing', true);
    Config::set('features.signal_intelligence', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('ui');
    $promoted = promotedOpportunitySignal($context['workspace'], ['topic' => 'AI discoverability']);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);

    $this->actingAs($context['user'])
        ->get(route('app.agentic-marketing.intelligence.index'))
        ->assertOk()
        ->assertSee('Signal Intelligence')
        ->assertSee('Signal Intelligence evidence')
        ->assertSee('Signal priority')
        ->assertSee(route('app.signal-intelligence.detections.show', $promoted['detection']->id), false);
});

it('shows active non-open opportunities in the default intelligence list', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('default-active-list');
    promotedOpportunitySignal($context['workspace']);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();
    $opportunity->forceFill([
        'status' => 'planned',
        'title' => 'Planned opportunity remains actionable',
    ])->save();

    $this->actingAs($context['user'])
        ->get(route('app.agentic-marketing.intelligence.index'))
        ->assertOk()
        ->assertSee('Planned opportunity remains actionable')
        ->assertSee('Open opportunities')
        ->assertDontSee('No opportunities yet');
});

it('shows the opportunity detail page with full signal lineage', function (): void {
    Config::set('features.agentic_marketing', true);
    Config::set('features.signal_intelligence', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('detail');
    $promoted = promotedOpportunitySignal($context['workspace'], ['topic' => 'AI governance']);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.opportunities.show', $opportunity))
        ->assertOk()
        ->assertSee($opportunity->title)
        ->assertSee('Signal Lineage')
        ->assertSee('OpportunitySignal')
        ->assertSee('SignalDetection')
        ->assertSee($promoted['detection']->title)
        ->assertSee(route('app.signal-intelligence.detections.show', $promoted['detection']->id), false);
});

it('blocks cross workspace opportunity detail access', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('detail-cross-a');
    $other = promotedOpportunityContext('detail-cross-b');
    promotedOpportunitySignal($other['workspace']);
    app(OpportunityIntelligenceEngine::class)->run($other['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $other['workspace']->id)->firstOrFail();

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.opportunities.show', $opportunity))
        ->assertNotFound();
});

it('supports opportunity governance workflow actions', function (string $routeAction, string $expectedStatus, string $metadataKey): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('workflow-'.$routeAction);
    promotedOpportunitySignal($context['workspace']);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('app.opportunity-intelligence.opportunities.'.$routeAction, $opportunity))
        ->assertRedirect(route('app.opportunity-intelligence.opportunities.show', $opportunity));

    expect($opportunity->refresh()->status->value)->toBe($expectedStatus)
        ->and($opportunity->metadata[$metadataKey])->toBe((string) $context['user']->id);
})->with([
    ['review', 'reviewing', 'reviewed_by'],
    ['approve', 'approved', 'approved_by'],
    ['dismiss', 'dismissed', 'dismissed_by'],
    ['resolve', 'resolved', 'resolved_by'],
    ['archive', 'archived', 'archived_by'],
]);

it('runs opportunity intelligence manually from the app route', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('manual-run');
    promotedOpportunitySignal($context['workspace']);

    $this->actingAs($context['user'])
        ->post(route('app.opportunity-intelligence.run'))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(Opportunity::query()->where('workspace_id', $context['workspace']->id)->count())->toBe(1);
});

it('keeps opportunity intelligence routes behind the feature flag', function (): void {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
    $context = promotedOpportunityContext('flag-off');
    promotedOpportunitySignal($context['workspace']);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();

    Config::set('features.agentic_marketing', false);

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.opportunities.show', $opportunity))
        ->assertNotFound();
});

it('creates an execution plan for an approved opportunity without creating execution records', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('plan-create');
    promotedOpportunitySignal($context['workspace'], ['category' => OpportunityCategory::AI_VISIBILITY_OPPORTUNITY->value]);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();
    $opportunity->forceFill(['status' => 'approved'])->save();

    $counts = [
        'briefs' => Brief::query()->count(),
        'campaigns' => Campaign::query()->count(),
        'content' => Content::query()->count(),
    ];

    $this->actingAs($context['user'])
        ->post(route('app.opportunity-intelligence.opportunities.execution-plans.store', $opportunity))
        ->assertRedirect();

    $plan = OpportunityExecutionPlan::query()->where('opportunity_id', $opportunity->id)->firstOrFail();

    expect($plan->status)->toBe('draft')
        ->and($plan->planned_steps)->toHaveCount(6)
        ->and($plan->source_evidence['signals'])->not->toBeEmpty()
        ->and(Brief::query()->count())->toBe($counts['briefs'])
        ->and(Campaign::query()->count())->toBe($counts['campaigns'])
        ->and(Content::query()->count())->toBe($counts['content']);
});

it('does not create a duplicate active execution plan', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('plan-duplicate');
    promotedOpportunitySignal($context['workspace']);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();
    $opportunity->forceFill(['status' => 'approved'])->save();

    $this->actingAs($context['user'])->post(route('app.opportunity-intelligence.opportunities.execution-plans.store', $opportunity));

    $this->actingAs($context['user'])
        ->from(route('app.opportunity-intelligence.opportunities.show', $opportunity))
        ->post(route('app.opportunity-intelligence.opportunities.execution-plans.store', $opportunity))
        ->assertRedirect(route('app.opportunity-intelligence.opportunities.show', $opportunity))
        ->assertSessionHasErrors('execution_plan');

    expect(OpportunityExecutionPlan::query()->where('opportunity_id', $opportunity->id)->count())->toBe(1);
});

it('shows create plan button only when allowed', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('plan-button');
    promotedOpportunitySignal($context['workspace']);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.opportunities.show', $opportunity))
        ->assertOk()
        ->assertDontSee('Create Execution Plan');

    $opportunity->forceFill(['status' => 'approved'])->save();

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.opportunities.show', $opportunity))
        ->assertOk()
        ->assertSee('Create Execution Plan');

    OpportunityExecutionPlan::query()->create([
        'organization_id' => $context['workspace']->organization_id,
        'workspace_id' => $context['workspace']->id,
        'opportunity_id' => $opportunity->id,
        'status' => 'draft',
        'title' => 'Existing plan',
        'created_by' => $context['user']->id,
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.opportunities.show', $opportunity))
        ->assertOk()
        ->assertDontSee('Create Execution Plan')
        ->assertSee('View Execution Plan');
});

it('shows execution plan detail page with evidence and prepared links', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('plan-detail');
    promotedOpportunitySignal($context['workspace']);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();
    $opportunity->forceFill(['status' => 'approved'])->save();
    $this->actingAs($context['user'])->post(route('app.opportunity-intelligence.opportunities.execution-plans.store', $opportunity));
    $plan = OpportunityExecutionPlan::query()->where('opportunity_id', $opportunity->id)->firstOrFail();
    $plan->forceFill(['status' => 'approved'])->save();

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.execution-plans.show', $plan))
        ->assertOk()
        ->assertSee($plan->title)
        ->assertSee('Planned Steps')
        ->assertSee('Source Evidence')
        ->assertSee('Create content brief')
        ->assertSee('Create campaign idea')
        ->assertSee('Create social draft');
});

it('blocks cross workspace execution plan access', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('plan-cross-a');
    $other = promotedOpportunityContext('plan-cross-b');
    promotedOpportunitySignal($other['workspace']);
    app(OpportunityIntelligenceEngine::class)->run($other['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $other['workspace']->id)->firstOrFail();
    $opportunity->forceFill(['status' => 'approved'])->save();
    $plan = OpportunityExecutionPlan::query()->create([
        'organization_id' => $other['workspace']->organization_id,
        'workspace_id' => $other['workspace']->id,
        'opportunity_id' => $opportunity->id,
        'status' => 'draft',
        'title' => 'Cross workspace plan',
        'created_by' => $other['user']->id,
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.execution-plans.show', $plan))
        ->assertNotFound();
});

it('supports execution plan review approve planned and archive workflow', function (string $routeAction, string $expectedStatus): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('plan-workflow-'.$routeAction);
    promotedOpportunitySignal($context['workspace']);
    app(OpportunityIntelligenceEngine::class)->run($context['workspace']);
    $opportunity = Opportunity::query()->where('workspace_id', $context['workspace']->id)->firstOrFail();
    $opportunity->forceFill(['status' => 'approved'])->save();
    $this->actingAs($context['user'])->post(route('app.opportunity-intelligence.opportunities.execution-plans.store', $opportunity));
    $plan = OpportunityExecutionPlan::query()->where('opportunity_id', $opportunity->id)->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('app.opportunity-intelligence.execution-plans.'.$routeAction, $plan))
        ->assertRedirect(route('app.opportunity-intelligence.execution-plans.show', $plan));

    expect($plan->refresh()->status)->toBe($expectedStatus);

    if ($routeAction === 'approve') {
        expect($plan->approved_by)->toBe($context['user']->id)
            ->and($plan->approved_at)->not->toBeNull();
    }
})->with([
    ['review', 'reviewing'],
    ['approve', 'approved'],
    ['planned', 'planned'],
    ['archive', 'archived'],
]);

it('creates a content brief from an approved execution plan with source context', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('brief-approved');
    $plan = promotedExecutionPlan($context, 'approved');

    $counts = [
        'campaigns' => Campaign::query()->count(),
        'content' => Content::query()->count(),
    ];

    $this->actingAs($context['user'])
        ->post(route('app.opportunity-intelligence.execution-plans.create-brief', $plan))
        ->assertRedirect();

    $brief = Brief::query()->firstOrFail();
    $plan->refresh();

    expect($brief->status)->toBe('draft')
        ->and($brief->source)->toBe('opportunity_execution_plan')
        ->and($brief->client_refs['execution_plan_id'])->toBe((string) $plan->id)
        ->and($brief->client_refs['opportunity_id'])->toBe((string) $plan->opportunity_id)
        ->and($brief->client_refs['signal_detection_ids'])->not->toBeEmpty()
        ->and($brief->notes)->toContain('Created from Opportunity Execution Plan')
        ->and($plan->metadata['brief_id'])->toBe((string) $brief->id)
        ->and($plan->metadata['brief_created_by'])->toBe((string) $context['user']->id)
        ->and(Campaign::query()->count())->toBe($counts['campaigns'])
        ->and(Content::query()->count())->toBe($counts['content']);
});

it('creates a content brief from a planned execution plan', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('brief-planned');
    $plan = promotedExecutionPlan($context, 'planned');

    $this->actingAs($context['user'])
        ->post(route('app.opportunity-intelligence.execution-plans.create-brief', $plan))
        ->assertRedirect();

    expect(Brief::query()->count())->toBe(1)
        ->and($plan->refresh()->metadata['brief_id'])->not->toBeEmpty();
});

it('blocks content brief creation for non-executable plan statuses', function (string $status): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('brief-blocked-'.$status);
    $plan = promotedExecutionPlan($context, $status);

    $this->actingAs($context['user'])
        ->from(route('app.opportunity-intelligence.execution-plans.show', $plan))
        ->post(route('app.opportunity-intelligence.execution-plans.create-brief', $plan))
        ->assertRedirect(route('app.opportunity-intelligence.execution-plans.show', $plan))
        ->assertSessionHasErrors('brief');

    expect(Brief::query()->count())->toBe(0)
        ->and($plan->refresh()->metadata['brief_id'] ?? null)->toBeNull();
})->with([
    'draft',
    'reviewing',
    'archived',
]);

it('returns the existing brief when creating from the same execution plan twice', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('brief-idempotent');
    $plan = promotedExecutionPlan($context, 'approved');

    $this->actingAs($context['user'])->post(route('app.opportunity-intelligence.execution-plans.create-brief', $plan));
    $firstBriefId = (string) Brief::query()->firstOrFail()->id;

    $this->actingAs($context['user'])
        ->post(route('app.opportunity-intelligence.execution-plans.create-brief', $plan->refresh()))
        ->assertRedirect();

    expect(Brief::query()->count())->toBe(1)
        ->and((string) Brief::query()->firstOrFail()->id)->toBe($firstBriefId)
        ->and($plan->refresh()->metadata['brief_id'])->toBe($firstBriefId);
});

it('blocks cross workspace content brief creation from execution plans', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('brief-cross-a');
    $other = promotedOpportunityContext('brief-cross-b');
    $plan = promotedExecutionPlan($other, 'approved');

    $this->actingAs($context['user'])
        ->post(route('app.opportunity-intelligence.execution-plans.create-brief', $plan))
        ->assertNotFound();

    expect(Brief::query()->count())->toBe(0);
});

it('shows create brief button only for allowed execution plans and links existing briefs', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('brief-ui');
    $plan = promotedExecutionPlan($context, 'draft');

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.execution-plans.show', $plan))
        ->assertOk()
        ->assertDontSee('Create content brief');

    $plan->forceFill(['status' => 'approved'])->save();

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.execution-plans.show', $plan))
        ->assertOk()
        ->assertSee('Create content brief');

    $this->actingAs($context['user'])->post(route('app.opportunity-intelligence.execution-plans.create-brief', $plan));
    $brief = Brief::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-intelligence.execution-plans.show', $plan->refresh()))
        ->assertOk()
        ->assertDontSee('Create content brief')
        ->assertSee('Open content brief')
        ->assertSee(route('app.content.workspace.show', $brief), false);
});

it('creates a first draft from an execution plan brief with source lineage', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('draft-create');
    $brief = promotedExecutionPlanBrief($context);

    $counts = [
        'campaigns' => Campaign::query()->count(),
        'content' => Content::query()->count(),
    ];

    $this->actingAs($context['user'])
        ->post(route('app.briefs.create-draft', $brief))
        ->assertRedirect();

    $draft = Draft::query()->firstOrFail();
    $brief->refresh();

    expect($draft->status)->toBe('draft')
        ->and($draft->brief_id)->toBe($brief->id)
        ->and($draft->client_site_id)->toBe($brief->client_site_id)
        ->and($draft->delivery_status)->toBe('pending')
        ->and($draft->delivered_at)->toBeNull()
        ->and($draft->meta['source_context']['brief_id'])->toBe((string) $brief->id)
        ->and($draft->meta['source_context']['execution_plan_id'])->toBe((string) $brief->client_refs['execution_plan_id'])
        ->and($draft->meta['source_context']['opportunity_id'])->toBe((string) $brief->client_refs['opportunity_id'])
        ->and($draft->meta['source_context']['signal_detection_ids'])->not->toBeEmpty()
        ->and($draft->content_html)->toContain('Draft outline')
        ->and($brief->client_refs['draft_id'])->toBe((string) $draft->id)
        ->and($brief->client_refs['draft_created_by'])->toBe((string) $context['user']->id)
        ->and(Campaign::query()->count())->toBe($counts['campaigns'])
        ->and(Content::query()->count())->toBe($counts['content']);
});

it('blocks the first draft flow for normal briefs', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('draft-normal');
    $site = promotedOpportunitySite($context['workspace'], 'normal-brief');
    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $context['user']->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Normal manual brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'blog',
        'client_refs' => ['client_type' => 'client_ui'],
    ]);

    $this->actingAs($context['user'])
        ->from(route('app.content.workspace.show', $brief))
        ->post(route('app.briefs.create-draft', $brief))
        ->assertRedirect(route('app.content.workspace.show', $brief))
        ->assertSessionHasErrors('brief');

    expect(Draft::query()->count())->toBe(0);
});

it('returns the existing first draft for the same execution plan brief', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('draft-idempotent');
    $brief = promotedExecutionPlanBrief($context);

    $this->actingAs($context['user'])->post(route('app.briefs.create-draft', $brief));
    $firstDraftId = (string) Draft::query()->firstOrFail()->id;

    $this->actingAs($context['user'])
        ->post(route('app.briefs.create-draft', $brief->refresh()))
        ->assertRedirect();

    expect(Draft::query()->count())->toBe(1)
        ->and((string) Draft::query()->firstOrFail()->id)->toBe($firstDraftId)
        ->and($brief->refresh()->client_refs['draft_id'])->toBe($firstDraftId);
});

it('blocks cross workspace first draft creation', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('draft-cross-a');
    $other = promotedOpportunityContext('draft-cross-b');
    $brief = promotedExecutionPlanBrief($other);

    $this->actingAs($context['user'])
        ->post(route('app.briefs.create-draft', $brief))
        ->assertNotFound();

    expect(Draft::query()->count())->toBe(0);
});

it('shows first draft button only for eligible execution plan briefs and links existing draft', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('draft-ui');
    $brief = promotedExecutionPlanBrief($context);

    $this->actingAs($context['user'])
        ->get(route('app.content.workspace.show', $brief))
        ->assertOk()
        ->assertSee('Create first draft');

    $this->actingAs($context['user'])->post(route('app.briefs.create-draft', $brief));
    $draft = Draft::query()->firstOrFail();

    $this->actingAs($context['user'])
        ->get(route('app.content.workspace.show', $brief->refresh()))
        ->assertOk()
        ->assertDontSee('Create first draft')
        ->assertSee('Open draft')
        ->assertSee(route('app.drafts.show', $draft), false);

    $site = promotedOpportunitySite($context['workspace'], 'normal-brief-ui');
    $normalBrief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $context['user']->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Normal UI brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'blog',
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.content.workspace.show', $normalBrief))
        ->assertOk()
        ->assertDontSee('Create first draft');
});

it('marks an opportunity execution draft as ready for review with audit metadata', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('governance-ready');
    $draft = promotedExecutionPlanDraft($context);
    $originalDeliveryStatus = (string) $draft->delivery_status;

    $this->actingAs($context['user'])
        ->post(route('app.drafts.ready-for-review', $draft))
        ->assertRedirect(route('app.drafts.show', $draft));

    $draft->refresh();

    expect($draft->status)->toBe('ready_for_review')
        ->and($draft->delivery_status)->toBe($originalDeliveryStatus)
        ->and($draft->meta['governance']['ready_for_review_by'])->toBe((string) $context['user']->id)
        ->and($draft->meta['governance']['ready_for_review_at'])->not->toBeEmpty();
});

it('blocks draft governance for normal drafts without source context', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('governance-normal');
    $site = promotedOpportunitySite($context['workspace'], 'governance-normal');
    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $context['user']->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Normal governance brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'blog',
    ]);
    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'draft',
        'title' => 'Normal draft',
        'output_type' => 'blog',
        'language' => 'nl',
        'content_html' => '<p>Normal draft.</p>',
        'meta' => ['source' => 'client_ui'],
        'delivery_status' => 'pending',
    ]);

    $this->actingAs($context['user'])
        ->from(route('app.drafts.show', $draft))
        ->post(route('app.drafts.ready-for-review', $draft))
        ->assertRedirect(route('app.drafts.show', $draft))
        ->assertSessionHasErrors('governance');

    expect($draft->refresh()->status)->toBe('draft');
});

it('requests changes with a note for a ready for review draft', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('governance-changes');
    $draft = promotedExecutionPlanDraft($context);
    $this->actingAs($context['user'])->post(route('app.drafts.ready-for-review', $draft));

    $this->actingAs($context['user'])
        ->post(route('app.drafts.request-changes', $draft->refresh()), ['note' => 'Tighten the intro and add proof.'])
        ->assertRedirect(route('app.drafts.show', $draft));

    $draft->refresh();

    expect($draft->status)->toBe('changes_requested')
        ->and($draft->meta['governance']['changes_requested_by'])->toBe((string) $context['user']->id)
        ->and($draft->meta['governance']['changes_requested_note'])->toBe('Tighten the intro and add proof.');
});

it('approves a ready for review draft for publishing without triggering publication', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('governance-approve');
    $draft = promotedExecutionPlanDraft($context);
    $this->actingAs($context['user'])->post(route('app.drafts.ready-for-review', $draft));
    Queue::fake();

    $counts = [
        'publications' => ContentPublication::query()->count(),
        'content' => Content::query()->count(),
        'campaigns' => Campaign::query()->count(),
    ];
    $originalDeliveryStatus = (string) $draft->refresh()->delivery_status;

    $this->actingAs($context['user'])
        ->post(route('app.drafts.approve-for-publishing', $draft))
        ->assertRedirect(route('app.drafts.show', $draft));

    $draft->refresh();

    expect($draft->status)->toBe('approved_for_publishing')
        ->and($draft->delivery_status)->toBe($originalDeliveryStatus)
        ->and($draft->meta['governance']['approved_for_publishing_by'])->toBe((string) $context['user']->id)
        ->and(ContentPublication::query()->count())->toBe($counts['publications'])
        ->and(Content::query()->count())->toBe($counts['content'])
        ->and(Campaign::query()->count())->toBe($counts['campaigns']);

    Queue::assertNothingPushed();
});

it('archives opportunity execution draft governance without touching delivery status', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('governance-archive');
    $draft = promotedExecutionPlanDraft($context);
    $originalDeliveryStatus = (string) $draft->delivery_status;

    $this->actingAs($context['user'])
        ->post(route('app.drafts.archive-governance', $draft))
        ->assertRedirect(route('app.drafts.show', $draft));

    $draft->refresh();

    expect($draft->status)->toBe('archived')
        ->and($draft->delivery_status)->toBe($originalDeliveryStatus)
        ->and($draft->meta['governance']['archived_by'])->toBe((string) $context['user']->id);
});

it('blocks invalid draft governance transitions', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('governance-invalid');
    $draft = promotedExecutionPlanDraft($context);

    $this->actingAs($context['user'])
        ->from(route('app.drafts.show', $draft))
        ->post(route('app.drafts.approve-for-publishing', $draft))
        ->assertRedirect(route('app.drafts.show', $draft))
        ->assertSessionHasErrors('governance');

    expect($draft->refresh()->status)->toBe('draft');
});

it('blocks cross workspace draft governance access', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('governance-cross-a');
    $other = promotedOpportunityContext('governance-cross-b');
    $draft = promotedExecutionPlanDraft($other);

    $this->actingAs($context['user'])
        ->post(route('app.drafts.ready-for-review', $draft))
        ->assertNotFound();

    expect($draft->refresh()->status)->toBe('draft');
});

it('shows draft governance panel only for opportunity execution drafts', function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);

    $context = promotedOpportunityContext('governance-ui');
    $draft = promotedExecutionPlanDraft($context);

    $this->actingAs($context['user'])
        ->get(route('app.drafts.show', $draft))
        ->assertOk()
        ->assertSee('Draft Governance')
        ->assertSee('Mark ready for review')
        ->assertSee('Execution plan')
        ->assertSee('Opportunity');

    $site = promotedOpportunitySite($context['workspace'], 'governance-normal-ui');
    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $context['user']->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => 'Normal governance UI brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'blog',
    ]);
    $normalDraft = Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => 'draft',
        'title' => 'Normal UI draft',
        'output_type' => 'blog',
        'language' => 'nl',
        'content_html' => '<p>Normal draft.</p>',
        'meta' => ['source' => 'client_ui'],
        'delivery_status' => 'pending',
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.drafts.show', $normalDraft))
        ->assertOk()
        ->assertDontSee('Draft Governance');
});
