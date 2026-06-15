<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\BrandContext;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\Organization;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\SiteCompetitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Journey\WorkspaceJourneyService;
use App\Services\Onboarding\FirstValueActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('features.agentic_marketing', true);
    Config::set('features.signal_intelligence', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function journeyContext(string $slug = 'journey'): array
{
    $organization = Organization::query()->create([
        'name' => 'Journey Org '.$slug,
        'slug' => 'journey-org-'.$slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Journey Workspace '.$slug,
        'display_name' => 'Journey Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Journey Site '.$slug,
        'site_url' => 'https://'.$slug.'.example.com',
        'base_url' => 'https://'.$slug.'.example.com',
        'allowed_domains' => [$slug.'.example.com'],
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    return compact('organization', 'workspace', 'site', 'user');
}

function seedJourneySetup(Workspace $workspace, ClientSite $site): void
{
    BrandContext::query()->create([
        'workspace_id' => $workspace->id,
        'raw_input' => 'Argusly helps teams track AI visibility.',
        'source_type' => 'manual',
        'structured_json' => ['primary_topics' => ['AI visibility']],
    ]);

    SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Rival Signals',
        'domain' => 'rival.example',
        'is_active' => true,
    ]);
}

function journeyQuery(Workspace $workspace, ClientSite $site): LlmTrackingQuery
{
    return LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Best AI visibility tools',
        'query_text' => 'best AI visibility tools',
        'locale' => 'en',
        'frequency' => 'daily',
        'is_active' => true,
    ]);
}

function journeyRun(LlmTrackingQuery $query): LlmTrackingQueryRun
{
    return LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now(),
        'provider' => 'openai',
        'model' => 'gpt-test',
        'status' => 'succeeded',
        'raw_response' => 'Argusly appears in the answer.',
        'brand_mentioned' => true,
    ]);
}

function journeySignalEvent(Workspace $workspace, ClientSite $site): SignalEvent
{
    return SignalEvent::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => SignalCategory::BRAND_VISIBILITY->value,
        'type' => SignalType::BRAND_MENTIONED->value,
        'severity' => SignalSeverity::INFO->value,
        'status' => SignalStatus::DETECTED->value,
        'topic' => 'AI visibility',
        'entity_name' => 'Argusly',
        'entity_key' => 'argusly',
        'signal_strength' => 74,
        'confidence_score' => 82,
        'impact_score' => 61,
        'urgency_score' => 44,
        'observed_at' => now(),
        'evidence' => [],
        'metrics' => [],
        'metadata' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
    ]);
}

function journeyDetection(Workspace $workspace, ClientSite $site): SignalDetection
{
    return SignalDetection::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => SignalDetection::CATEGORY_OPPORTUNITY_DETECTION,
        'type' => 'opportunity_candidate',
        'status' => SignalStatus::DETECTED->value,
        'title' => 'AI visibility opportunity',
        'summary' => 'Evidence suggests a content opportunity.',
        'primary_topic' => 'AI visibility',
        'primary_entity' => 'Argusly',
        'severity' => SignalSeverity::MEDIUM->value,
        'priority_score' => 70,
        'confidence_score' => 80,
        'impact_score' => 75,
        'urgency_score' => 60,
        'risk_score' => 10,
        'opportunity_score' => 82,
        'score_breakdown' => [],
        'evidence_summary' => [],
        'recommended_actions' => [],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'metadata' => [],
    ]);
}

function journeyApprovedOpportunity(Workspace $workspace, ClientSite $site): Opportunity
{
    return Opportunity::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => OpportunityCategory::CONTENT_GAP->value,
        'status' => OpportunityStatus::APPROVED->value,
        'title' => 'Create AI visibility guide',
        'topic' => 'AI visibility',
        'summary' => 'Approved opportunity.',
        'priority_score' => 82,
        'confidence_score' => 80,
        'impact_score' => 75,
        'urgency_score' => 60,
        'effort_score' => 40,
        'score_breakdown' => [],
        'recommended_actions' => [],
        'evidence' => [],
        'source_signal_summary' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function journeyExecutionPlan(Workspace $workspace, ClientSite $site, Opportunity $opportunity): OpportunityExecutionPlan
{
    return OpportunityExecutionPlan::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'opportunity_id' => $opportunity->id,
        'status' => OpportunityExecutionPlan::STATUS_DRAFT,
        'title' => 'AI visibility plan',
        'summary' => 'Plan the content response.',
        'planned_steps' => [],
        'source_evidence' => [],
        'metadata' => [],
    ]);
}

function journeyBrief(ClientSite $site): Brief
{
    return Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'AI visibility brief',
        'language' => 'en',
        'intent' => 'educate',
        'primary_keyword' => 'AI visibility',
        'audience' => 'Marketing leaders',
        'output_type' => 'article',
        'notes' => 'Brief from opportunity plan.',
    ]);
}

function journeyDraft(ClientSite $site, Brief $brief, string $status = Draft::STATUS_DRAFT): Draft
{
    return Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => $status,
        'title' => 'AI visibility draft',
        'output_type' => 'article',
        'content_html' => '<p>Draft content.</p>',
        'meta' => ['source_context' => ['execution_plan_id' => 'plan']],
        'links' => [],
    ]);
}

it('calculates locked active and completed journey states', function (): void {
    $context = journeyContext('states');
    seedJourneySetup($context['workspace'], $context['site']);

    $service = app(WorkspaceJourneyService::class);
    $initial = $service->forWorkspace($context['workspace'])['steps']->keyBy('key');

    expect($initial['setup']->status)->toBe('completed')
        ->and($initial['ai_visibility']->status)->toBe('available')
        ->and($initial['signal_intelligence']->status)->toBe('locked')
        ->and($service->getRecommendedAction($context['workspace'])->title)->toBe('Generate Starter Queries');

    $query = journeyQuery($context['workspace'], $context['site']);
    $withQuery = $service->forWorkspace($context['workspace'])['steps']->keyBy('key');

    expect($withQuery['ai_visibility']->status)->toBe('active')
        ->and($service->getRecommendedAction($context['workspace'])->title)->toBe('Run First Visibility Check');

    journeyRun($query);
    journeySignalEvent($context['workspace'], $context['site']);
    $withEvent = $service->forWorkspace($context['workspace'])['steps']->keyBy('key');

    expect($withEvent['ai_visibility']->status)->toBe('completed')
        ->and($withEvent['signal_intelligence']->status)->toBe('active');

    journeyDetection($context['workspace'], $context['site']);
    $opportunityActive = $service->forWorkspace($context['workspace'])['steps']->keyBy('key');

    expect($opportunityActive['signal_intelligence']->status)->toBe('completed')
        ->and($opportunityActive['opportunity_review']->status)->toBe('active')
        ->and($opportunityActive['opportunity_intelligence']->status)->toBe('locked')
        ->and($service->getRecommendedAction($context['workspace'])->title)->toBe('Review Opportunity');
});

it('moves content stages from opportunity review through drafting', function (): void {
    $context = journeyContext('content');
    seedJourneySetup($context['workspace'], $context['site']);
    $query = journeyQuery($context['workspace'], $context['site']);
    journeyRun($query);
    journeySignalEvent($context['workspace'], $context['site']);
    journeyDetection($context['workspace'], $context['site']);

    $opportunity = journeyApprovedOpportunity($context['workspace'], $context['site']);
    $service = app(WorkspaceJourneyService::class);

    $withOpportunity = $service->forWorkspace($context['workspace'])['steps']->keyBy('key');

    expect($withOpportunity['opportunity_review']->status)->toBe('completed')
        ->and($withOpportunity['opportunity_intelligence']->status)->toBe('active')
        ->and($withOpportunity['execution_planning']->status)->toBe('active')
        ->and($service->getRecommendedAction($context['workspace'])->title)->toBe('Create Execution Plan');

    journeyExecutionPlan($context['workspace'], $context['site'], $opportunity);
    expect($service->forWorkspace($context['workspace'])['steps']->keyBy('key')['briefing']->status)->toBe('active')
        ->and($service->getRecommendedAction($context['workspace'])->title)->toBe('Create Content Brief');

    $brief = journeyBrief($context['site']);
    expect($service->forWorkspace($context['workspace'])['steps']->keyBy('key')['drafting']->status)->toBe('active')
        ->and($service->getRecommendedAction($context['workspace'])->title)->toBe('Create First Draft');

    journeyDraft($context['site'], $brief);
    expect($service->forWorkspace($context['workspace'])['steps']->keyBy('key')['drafting']->status)->toBe('active')
        ->and($service->getRecommendedAction($context['workspace'])->title)->toBe('Review Draft');

    journeyDraft($context['site'], $brief, Draft::STATUS_APPROVED_FOR_PUBLISHING);
    expect($service->forWorkspace($context['workspace'])['steps']->keyBy('key')['drafting']->status)->toBe('completed')
        ->and($service->forWorkspace($context['workspace'])['counts']['approved_drafts'])->toBe(1);
});

it('keeps journey calculations isolated per workspace', function (): void {
    $own = journeyContext('own');
    $other = journeyContext('other');
    seedJourneySetup($own['workspace'], $own['site']);

    $otherQuery = journeyQuery($other['workspace'], $other['site']);
    journeyRun($otherQuery);
    journeySignalEvent($other['workspace'], $other['site']);
    journeyDetection($other['workspace'], $other['site']);
    journeyApprovedOpportunity($other['workspace'], $other['site']);

    $steps = app(WorkspaceJourneyService::class)->forWorkspace($own['workspace'])['steps']->keyBy('key');

    expect($steps['ai_visibility']->status)->toBe('available')
        ->and($steps['signal_intelligence']->status)->toBe('locked')
        ->and($steps['opportunity_review']->status)->toBe('locked')
        ->and($steps['opportunity_intelligence']->status)->toBe('locked');
});

it('exposes blocking messages for locked stages', function (): void {
    $context = journeyContext('blocked');
    $steps = app(WorkspaceJourneyService::class)->forWorkspace($context['workspace'])['steps']->keyBy('key');

    expect($steps['opportunity_review']->blockingMessage)->toBe('Detect the first opportunity candidate in Signal Intelligence before review unlocks.')
        ->and($steps['opportunity_intelligence']->blockingMessage)->toBe('Complete Opportunity Review before Opportunity Intelligence unlocks.')
        ->and($steps['execution_planning']->blockingMessage)->toBe('Approve an Opportunity before planning becomes available.')
        ->and($steps['briefing']->blockingMessage)->toBe('Create an Execution Plan before generating a content brief.')
        ->and($steps['drafting']->blockingMessage)->toBe('Create a content brief before drafting content.');
});

it('renders journey on activation and hides it on settings', function (): void {
    $context = journeyContext('visibility');
    seedJourneySetup($context['workspace'], $context['site']);

    $this->actingAs($context['user'])
        ->get(route('app.activation.index', ['workspace' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('Unified Intelligence Journey')
        ->assertSee('Generate Starter Queries');

    $this->actingAs($context['user'])
        ->get(route('app.settings'))
        ->assertOk()
        ->assertDontSee('Unified Intelligence Journey');
});

it('unlocks opportunity review when the first candidate exists', function (): void {
    $context = journeyContext('opportunity-review');
    seedJourneySetup($context['workspace'], $context['site']);
    $query = journeyQuery($context['workspace'], $context['site']);
    journeyRun($query);
    journeySignalEvent($context['workspace'], $context['site']);
    journeyDetection($context['workspace'], $context['site']);

    $this->actingAs($context['user'])
        ->get(route('app.activation.index', ['workspace' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('First Opportunity Candidate')
        ->assertSee('Opportunity Review unlocked')
        ->assertSee('Review Opportunity');

    $this->actingAs($context['user'])
        ->get(route('app.opportunity-review.index', ['workspace' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('Opportunity Review')
        ->assertSee('First Opportunity Candidate Detected')
        ->assertSee('Argusly found a potential growth opportunity.')
        ->assertSee('AI visibility opportunity')
        ->assertSee('Review Opportunity');
});

it('does not ask users to review processed opportunity candidates', function (): void {
    $context = journeyContext('processed-candidate');
    seedJourneySetup($context['workspace'], $context['site']);
    $query = journeyQuery($context['workspace'], $context['site']);
    journeyRun($query);
    journeySignalEvent($context['workspace'], $context['site']);
    $detection = journeyDetection($context['workspace'], $context['site']);
    $detection->markResolved();

    $activation = app(FirstValueActivationService::class)->forWorkspace($context['workspace']);
    $candidateStep = $activation['steps']->firstWhere('key', 'first_opportunity_candidate');

    expect($activation['counts']['opportunity_candidates'])->toBe(0)
        ->and($candidateStep['completed'])->toBeFalse()
        ->and($candidateStep['action_label'])->toBe('Find Opportunity Candidate')
        ->and(app(WorkspaceJourneyService::class)->getRecommendedAction($context['workspace'])->title)->toBe('Find Opportunity Candidate')
        ->and(app(WorkspaceJourneyService::class)->getRecommendedAction($context['workspace'])->route)->toEndWith('#priority');
});

it('does not send users back to find a candidate after an opportunity already exists', function (): void {
    $context = journeyContext('processed-candidate-with-opportunity');
    seedJourneySetup($context['workspace'], $context['site']);
    $query = journeyQuery($context['workspace'], $context['site']);
    journeyRun($query);
    journeySignalEvent($context['workspace'], $context['site']);
    $detection = journeyDetection($context['workspace'], $context['site']);
    $detection->markResolved();
    journeyApprovedOpportunity($context['workspace'], $context['site']);

    $service = app(WorkspaceJourneyService::class);
    $steps = $service->forWorkspace($context['workspace'])['steps']->keyBy('key');
    $activation = app(FirstValueActivationService::class)->forWorkspace($context['workspace']);
    $candidateStep = $activation['steps']->firstWhere('key', 'first_opportunity_candidate');

    expect($steps['signal_intelligence']->status)->toBe('completed')
        ->and($steps['opportunity_review']->status)->toBe('completed')
        ->and($candidateStep['completed'])->toBeTrue()
        ->and($service->getRecommendedAction($context['workspace'])->title)->toBe('Create Execution Plan');
});
