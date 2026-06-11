<?php

use App\Enums\OpportunitySignalSource;
use App\Enums\OpportunityStatus;
use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalSourceType;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\BrandContext;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\Opportunity;
use App\Models\OpportunitySignal;
use App\Models\Organization;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\SignalSource;
use App\Models\SiteCompetitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Onboarding\WorkspaceReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('features.signal_intelligence', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function readinessContext(string $slug = 'main'): array
{
    $organization = Organization::query()->create([
        'name' => 'Readiness '.$slug,
        'slug' => 'readiness-'.$slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Readiness Workspace '.$slug,
        'display_name' => 'Readiness Workspace '.$slug,
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

function readinessSite(Workspace $workspace, string $slug = 'site'): ClientSite
{
    return ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Readiness Site '.$slug,
        'site_url' => 'https://'.$slug.'.test',
        'base_url' => 'https://'.$slug.'.test',
        'allowed_domains' => [$slug.'.test'],
        'is_active' => true,
    ]);
}

function seedSignalReadinessBasics(Workspace $workspace, ClientSite $site): void
{
    BrandContext::query()->create([
        'workspace_id' => $workspace->id,
        'raw_input' => 'Argusly tracks AI visibility and content opportunities.',
        'source_type' => 'manual',
        'structured_json' => [
            'primary_topics' => ['AI visibility', 'content operations'],
            'authority_areas' => ['opportunity intelligence'],
        ],
    ]);

    SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Competitor One',
        'domain' => 'competitor.test',
        'is_active' => true,
    ]);

    SignalSource::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'type' => SignalSourceType::MANUAL->value,
        'name' => 'Manual monitor',
    ]);
}

function readinessSignalEvent(Workspace $workspace, ?ClientSite $site = null): SignalEvent
{
    $source = SignalSource::query()
        ->where('workspace_id', $workspace->id)
        ->first()
        ?: SignalSource::factory()->create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'type' => SignalSourceType::MANUAL->value,
        ]);

    return SignalEvent::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site?->id,
        'signal_source_id' => $source->id,
        'category' => SignalCategory::BRAND_VISIBILITY->value,
        'type' => SignalType::BRAND_MENTIONED->value,
        'severity' => SignalSeverity::INFO->value,
        'status' => SignalStatus::DETECTED->value,
        'topic' => 'AI visibility',
        'entity_name' => 'Argusly',
    ]);
}

it('calculates module readiness and platform score from workspace setup', function (): void {
    $context = readinessContext('score');
    $site = readinessSite($context['workspace'], 'score');
    seedSignalReadinessBasics($context['workspace'], $site);
    readinessSignalEvent($context['workspace'], $site);

    $readiness = app(WorkspaceReadinessService::class)->getWorkspaceReadiness($context['workspace']);
    $signal = $readiness['modules']->firstWhere('key', 'signal_intelligence');
    $opportunity = $readiness['modules']->firstWhere('key', 'opportunity_intelligence');

    expect($readiness['score'])->toBeGreaterThan(0)
        ->and($readiness['quick_actions'])->not->toBeEmpty()
        ->and($signal->status)->toBe('active')
        ->and($signal->missing_requirements)->toBeEmpty()
        ->and($opportunity->status)->toBeIn(['not_ready', 'partially_ready']);
});

it('reports every onboarding module', function (): void {
    $context = readinessContext('modules');

    $modules = app(WorkspaceReadinessService::class)
        ->getWorkspaceReadiness($context['workspace'])['modules']
        ->pluck('key')
        ->all();

    expect($modules)->toContain(
        'signal_intelligence',
        'ai_visibility',
        'opportunity_intelligence',
        'execution_planning',
        'content_operations',
    );
});

it('reports missing requirements for an empty workspace', function (): void {
    $context = readinessContext('missing');

    $signal = app(WorkspaceReadinessService::class)
        ->getModuleReadiness($context['workspace'], 'signal_intelligence');

    expect($signal->status)->toBe('not_ready')
        ->and(collect($signal->missing_requirements)->pluck('key')->all())->toContain('brand_profile', 'website', 'competitors', 'topics', 'signal_sources');
});

it('links Signal Intelligence setup requirements to configurable screens', function (): void {
    $context = readinessContext('signal-links');
    readinessSite($context['workspace'], 'signal-links');

    $signal = app(WorkspaceReadinessService::class)
        ->getModuleReadiness($context['workspace'], 'signal_intelligence');

    $requirements = collect($signal->missing_requirements)->keyBy('key');

    expect($requirements->get('topics')->action_label)->toBe('Edit company intelligence')
        ->and($requirements->get('topics')->action_route)->toBe(route('app.brand.company-intelligence'))
        ->and($requirements->get('signal_sources')->action_label)->toBe('Create AI visibility query')
        ->and($requirements->get('signal_sources')->action_route)->toBe(route('app.sites.llm-tracking.index', ClientSite::query()->where('workspace_id', $context['workspace']->id)->first()));
});

it('counts Company Intelligence topics and active competitors as Signal Intelligence setup input', function (): void {
    $context = readinessContext('company-intelligence-topics');
    $site = readinessSite($context['workspace'], 'company-intelligence-topics');

    BrandContext::query()->create([
        'workspace_id' => $context['workspace']->id,
        'raw_input' => 'Argusly tracks AI visibility.',
        'source_type' => 'manual',
        'structured_json' => [],
    ]);

    CompanyIntelligenceProfile::query()->create([
        'organization_id' => $context['workspace']->organization_id,
        'workspace_id' => $context['workspace']->id,
        'brand_key' => 'primary',
        'company_name' => 'Argusly',
        'primary_topics' => ['AI visibility'],
        'authority_areas' => ['content operations'],
        'strategic_keywords' => ['opportunity intelligence'],
        'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
        'is_default' => true,
    ]);

    SiteCompetitor::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $site->id,
        'name' => 'Competitor One',
        'domain' => 'competitor.test',
        'is_active' => true,
    ]);

    $signal = app(WorkspaceReadinessService::class)
        ->getModuleReadiness($context['workspace'], 'signal_intelligence');

    $missing = collect($signal->missing_requirements)->pluck('key')->all();

    expect($missing)->not->toContain('topics')
        ->and($missing)->not->toContain('signal_sources');
});

it('respects the Signal Intelligence feature flag in readiness providers', function (): void {
    $context = readinessContext('flag');
    Config::set('features.signal_intelligence', false);

    $signal = app(WorkspaceReadinessService::class)
        ->getModuleReadiness($context['workspace'], 'signal_intelligence');

    expect($signal->status)->toBe('not_ready')
        ->and($signal->blocking_message)->toContain('not enabled');
});

it('keeps readiness scoped to the requested workspace', function (): void {
    $first = readinessContext('tenant-a');
    $second = readinessContext('tenant-b');
    $site = readinessSite($second['workspace'], 'tenant-b');
    seedSignalReadinessBasics($second['workspace'], $site);
    readinessSignalEvent($second['workspace'], $site);

    $firstSignal = app(WorkspaceReadinessService::class)
        ->getModuleReadiness($first['workspace'], 'signal_intelligence');
    $secondSignal = app(WorkspaceReadinessService::class)
        ->getModuleReadiness($second['workspace'], 'signal_intelligence');

    expect($firstSignal->status)->toBe('not_ready')
        ->and($secondSignal->status)->toBe('active');
});

it('renders the setup dashboard with module cards and quick actions', function (): void {
    $context = readinessContext('dashboard');

    $this->actingAs($context['user'])
        ->get(route('app.setup.index'))
        ->assertOk()
        ->assertSee('Platform readiness')
        ->assertSee('Signal Intelligence')
        ->assertSee('Opportunity Intelligence')
        ->assertSee('Recommended next actions');
});

it('renders the activation page with first value checklist and next action', function (): void {
    $context = readinessContext('activation');

    $this->actingAs($context['user'])
        ->get(route('app.activation.index'))
        ->assertOk()
        ->assertSee('First Value Activation')
        ->assertSee('First Value Score')
        ->assertSee('Brand profile')
        ->assertSee('Website')
        ->assertSee('AI Visibility queries')
        ->assertSee('Volgende beste actie');
});

it('shows a first value activation banner on an empty dashboard', function (): void {
    $context = readinessContext('dashboard-activation');

    $this->actingAs($context['user'])
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Nog 5 stappen tot je eerste signal')
        ->assertSee('Open Activation')
        ->assertSee('Brand profile')
        ->assertSee('First AI Visibility run');
});

it('redirects completed workspace onboarding to activation instead of the empty dashboard', function (): void {
    $context = readinessContext('onboarding-activation');

    \App\Models\OnboardingState::query()->create([
        'user_id' => $context['user']->id,
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'phase' => \App\Models\OnboardingState::PHASE_FIRST_LOGIN,
        'registered_at' => now(),
        'completed_steps_json' => ['intent', 'company_profile', 'connect_site'],
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.onboarding.show'))
        ->assertRedirect(route('app.activation.index'));
});

it('marks first value activation active after query run signal detection and opportunity candidate exist', function (): void {
    $context = readinessContext('activation-active');
    $site = readinessSite($context['workspace'], 'activation-active');
    seedSignalReadinessBasics($context['workspace'], $site);

    $query = LlmTrackingQuery::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $site->id,
        'name' => 'Buyer category query',
        'query_text' => 'best AI visibility tools',
        'target_brand' => 'Argusly',
        'target_domain' => 'activation-active.test',
        'brand_terms' => ['Argusly'],
        'competitor_terms' => ['Competitor One'],
        'target_urls' => ['https://activation-active.test'],
        'tags' => ['activation'],
        'locale' => 'en',
        'frequency' => 'daily',
        'priority' => 80,
        'is_active' => true,
    ]);

    LlmTrackingQueryRun::query()->create([
        'llm_tracking_query_id' => $query->id,
        'run_at' => now(),
        'provider' => 'openai',
        'model' => 'test-model',
        'status' => 'succeeded',
        'answer_text' => 'Argusly and Competitor One are mentioned.',
    ]);

    $event = readinessSignalEvent($context['workspace'], $site);

    $detection = SignalDetection::factory()->create([
        'organization_id' => $context['workspace']->organization_id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $site->id,
        'category' => SignalDetection::CATEGORY_OPPORTUNITY_DETECTION,
        'status' => SignalStatus::DETECTED->value,
        'opportunity_score' => 82,
    ]);

    $detection->events()->attach($event->id, [
        'id' => (string) Str::uuid(),
        'weight' => 1,
        'contribution' => [],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.activation.index'))
        ->assertOk()
        ->assertSee('First value is active')
        ->assertSee('100%');
});

it('does not allow setup dashboard access to another organization workspace', function (): void {
    $context = readinessContext('own-setup');
    $other = readinessContext('other-setup');

    $this->actingAs($context['user'])
        ->get(route('app.setup.index', ['workspace' => $other['workspace']->id]))
        ->assertNotFound();
});

it('shows a guided empty state on Signal Intelligence when no signal events exist', function (): void {
    $context = readinessContext('signal-empty');
    readinessSite($context['workspace'], 'signal-empty');

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.index'))
        ->assertOk()
        ->assertSee('First Value Activation')
        ->assertSee('Open Activation')
        ->assertSee('Signal Intelligence needs setup')
        ->assertSee('Edit company intelligence')
        ->assertSee('Create AI visibility query');
});

it('shows Signal Intelligence as ready when setup is complete but no signal events exist yet', function (): void {
    $context = readinessContext('signal-ready-no-events');
    $site = readinessSite($context['workspace'], 'signal-ready-no-events');
    seedSignalReadinessBasics($context['workspace'], $site);

    $this->actingAs($context['user'])
        ->get(route('app.signal-intelligence.index'))
        ->assertOk()
        ->assertSee('Signal Intelligence is ready')
        ->assertSee('Signal Intelligence is configured, but no signal events exist yet.')
        ->assertSee('Open AI Visibility')
        ->assertDontSee('Open Setup')
        ->assertDontSee('Signal Intelligence needs setup');
});

it('shows a guided empty state on Opportunity Intelligence when opportunities are missing', function (): void {
    $context = readinessContext('opportunity-empty');

    $this->actingAs($context['user'])
        ->get(route('app.agentic-marketing.intelligence.index'))
        ->assertOk()
        ->assertSee('First Value Activation')
        ->assertSee('Open Activation')
        ->assertSee('Opportunity Intelligence needs setup')
        ->assertSee('Create signal detections')
        ->assertSee('Open Signal Intelligence')
        ->assertDontSee('Open Setup')
        ->assertDontSee('Open Opportunity Intelligence');
});

it('marks Opportunity Intelligence ready after promoted Signal Intelligence input exists', function (): void {
    $context = readinessContext('promoted');
    $site = readinessSite($context['workspace'], 'promoted');
    seedSignalReadinessBasics($context['workspace'], $site);
    $event = readinessSignalEvent($context['workspace'], $site);

    $detection = SignalDetection::factory()->create([
        'organization_id' => $context['workspace']->organization_id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $site->id,
        'status' => SignalStatus::DETECTED->value,
    ]);

    $detection->events()->attach($event->id, [
        'id' => (string) Str::uuid(),
        'weight' => 1,
        'contribution' => [],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    OpportunitySignal::factory()->create([
        'organization_id' => $context['workspace']->organization_id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $site->id,
        'source' => OpportunitySignalSource::SIGNAL_INTELLIGENCE->value,
        'metadata' => ['signal_detection_id' => (string) $detection->id],
    ]);

    Opportunity::factory()->create([
        'organization_id' => $context['workspace']->organization_id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $site->id,
        'status' => OpportunityStatus::OPEN->value,
    ]);

    $result = app(WorkspaceReadinessService::class)
        ->getModuleReadiness($context['workspace'], 'opportunity_intelligence');

    expect($result->status)->toBe('active')
        ->and($result->progress)->toBe(100);
});
