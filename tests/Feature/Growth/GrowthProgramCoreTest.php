<?php

use App\Enums\GrowthProgramStatus;
use App\Enums\GrowthAssetType;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Enums\OpportunityStatus;
use App\Enums\ProgrammaticPatternType;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Brief;
use App\Models\CampaignCluster;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentOpportunity;
use App\Models\ContentPublication;
use App\Models\CompetitorContentOpportunity;
use App\Models\Draft;
use App\Models\GrowthAsset;
use App\Models\GrowthProgram;
use App\Models\GrowthProgramBetaEvent;
use App\Models\GrowthRun;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\OpportunitySignal;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticClusterItem;
use App\Models\ProgrammaticDraftRequest;
use App\Models\ProgrammaticDraftReview;
use App\Models\ProgrammaticOpportunity;
use App\Models\ProgrammaticPublicationPlan;
use App\Models\ProgrammaticPublicationPlanItem;
use App\Models\ProgrammaticPublicationReadiness;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Jobs\GenerateDraftJob;
use App\Services\Growth\GrowthProgramOrchestrator;
use App\Services\Growth\GrowthAssetTypeResolver;
use App\Services\Growth\GrowthProgramNextActionResolver;
use App\Services\Growth\GrowthProgramBetaMetrics;
use App\Services\Growth\ProgrammaticBriefBlueprintBuilder;
use App\Services\Growth\ProgrammaticBriefConverter;
use App\Services\Growth\ProgrammaticContentConverter;
use App\Services\Growth\ProgrammaticDraftRequestBuilder;
use App\Services\Growth\ProgrammaticDraftGenerator;
use App\Services\Growth\ProgrammaticDraftReviewService;
use App\Services\Growth\ProgrammaticPublicationPlanBuilder;
use App\Services\Growth\ProgrammaticPublicationScheduler;
use App\Services\Growth\ProgrammaticPublicationReadinessService;
use App\Services\Growth\ProgrammaticClusterBuilder;
use App\Services\Growth\ProgrammaticOpportunityDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates a growth program from an opportunity and links existing execution assets', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'title' => 'AI visibility landing page',
        'status' => 'review',
        'publish_status' => 'draft',
    ]);

    $opportunity = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'content_id' => $content->id,
        'category' => OpportunityCategory::CONTENT_GAP,
        'status' => OpportunityStatus::APPROVED,
        'title' => 'Build AI visibility comparison pages',
        'topic' => 'AI visibility',
        'priority_score' => 82,
        'impact_score' => 91,
    ]);

    $plan = OpportunityExecutionPlan::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'opportunity_id' => $opportunity->id,
        'status' => OpportunityExecutionPlan::STATUS_APPROVED,
        'title' => 'Comparison page rollout',
        'summary' => 'Plan the rollout.',
    ]);

    $program = app(GrowthProgramOrchestrator::class)->createFromOpportunity($opportunity, $user);

    expect($program->status)->toBe(GrowthProgramStatus::PLANNED)
        ->and($program->owner_user_id)->toBe($user->id)
        ->and($program->metrics['opportunities_count'])->toBe(1)
        ->and($program->metrics['assets_count'])->toBe(3)
        ->and($program->estimated_reach)->toBe(8200.0);

    $this->assertDatabaseHas('growth_assets', [
        'growth_program_id' => $program->id,
        'assetable_type' => $opportunity->getMorphClass(),
        'assetable_id' => $opportunity->id,
        'role' => GrowthAsset::ROLE_OPPORTUNITY,
    ]);
    $this->assertDatabaseHas('growth_assets', [
        'growth_program_id' => $program->id,
        'assetable_type' => $plan->getMorphClass(),
        'assetable_id' => $plan->id,
        'role' => GrowthAsset::ROLE_EXECUTION_PLAN,
    ]);
    $this->assertDatabaseHas('growth_assets', [
        'growth_program_id' => $program->id,
        'assetable_type' => $content->getMorphClass(),
        'assetable_id' => $content->id,
        'role' => GrowthAsset::ROLE_CONTENT,
    ]);
});

it('tracks growth run lifecycle results and metric snapshots', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $orchestrator = app(GrowthProgramOrchestrator::class);
    $program = $orchestrator->create($workspace, ['name' => 'FAQ Authority Program'], $user);

    $run = $orchestrator->startRun($program, GrowthProgramStatus::BRIEFED, 'briefing_started', [
        'batch' => 'faq-authority',
    ], $user);

    $completed = $orchestrator->completeRun($run, ['briefs_created' => 3]);

    expect($completed->status)->toBe(GrowthRun::STATUS_COMPLETED)
        ->and($completed->stage)->toBe(GrowthProgramStatus::BRIEFED->value)
        ->and($completed->result['briefs_created'])->toBe(3)
        ->and($completed->metrics_snapshot)->toHaveKey('opportunities_count')
        ->and($completed->finished_at)->not->toBeNull();

    $failed = $orchestrator->failRun(
        $orchestrator->startRun($program, GrowthProgramStatus::DRAFTING, 'drafting_started', [], $user),
        str_repeat('x', 2100),
    );

    expect($failed->status)->toBe(GrowthRun::STATUS_FAILED)
        ->and(strlen((string) $failed->failure_reason))->toBe(2000);
});

it('links polymorphic assets without duplicating source data and aggregates metrics', function (): void {
    [$organization, $workspace, $user, $site] = growthProgramFixture();
    $orchestrator = app(GrowthProgramOrchestrator::class);
    $program = $orchestrator->create($workspace, [
        'name' => 'Industry Cluster Growth Program',
        'score' => 50,
    ], $user);

    $opportunity = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'priority_score' => 90,
    ]);
    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Industry cluster brief',
        'primary_keyword' => 'industry automation',
    ]);
    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'client_site_id' => $site->id,
        'status' => Draft::STATUS_READY_FOR_REVIEW,
        'title' => 'Industry cluster draft',
    ]);
    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Industry cluster article',
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    $publication = ContentPublication::query()->create([
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
    ]);
    $cluster = CampaignCluster::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'status' => CampaignCluster::STATUS_PLANNED,
        'name' => 'Industry cluster',
        'primary_topic' => 'Industry automation',
        'primary_entity' => 'Argusly',
        'authority_strategy' => 'Build supporting comparison and FAQ coverage.',
        'completeness_score' => 72,
        'ai_visibility_score' => 88,
        'dedupe_hash' => Str::uuid()->toString(),
    ]);

    foreach ([$opportunity, $brief, $draft, $content, $publication, $cluster] as $asset) {
        $orchestrator->linkAsset($program, $asset);
    }

    $program->refresh();

    expect($program->assets)->toHaveCount(6)
        ->and($program->metrics['opportunities_count'])->toBe(1)
        ->and($program->metrics['briefs_count'])->toBe(1)
        ->and($program->metrics['drafts_count'])->toBe(1)
        ->and($program->metrics['publications_count'])->toBe(1)
        ->and($program->metrics['published_count'])->toBe(1)
        ->and($program->metrics['clusters_count'])->toBe(1)
        ->and((float) $program->metrics['estimated_reach'])->toBe(9000.0)
        ->and((float) $program->metrics['estimated_traffic'])->toBe(1620.0)
        ->and((float) $program->metrics['estimated_ai_visibility'])->toBe(90.0);
});

it('enforces the canonical state machine', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $orchestrator = app(GrowthProgramOrchestrator::class);
    $program = $orchestrator->create($workspace, ['name' => 'Comparison Page Expansion'], $user);

    $program = $orchestrator->transition($program, GrowthProgramStatus::PLANNED);

    expect($program->status)->toBe(GrowthProgramStatus::PLANNED)
        ->and($program->planned_at)->not->toBeNull();

    $orchestrator->transition($program, GrowthProgramStatus::QUALIFIED);
})->throws(InvalidArgumentException::class, 'Growth programs cannot transition backwards.');

it('renders growth program pages and creates programs from opportunity intelligence', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $opportunity = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'FAQ authority gap',
        'topic' => 'FAQ authority',
        'priority_score' => 77,
    ]);

    $response = $this->actingAs($user)->post(route('app.growth-programs.from-opportunity', $opportunity));

    $response->assertRedirect();

    $program = GrowthProgram::query()->firstOrFail();
    $response->assertRedirect(route('app.growth-programs.show', $program));

    $this->actingAs($user)
        ->get(route('app.growth-programs.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Growth Programs')
        ->assertSee($program->name);

    $this->actingAs($user)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertSee('Timeline')
        ->assertSee('Opportunities')
        ->assertSee('Command Center')
        ->assertSee('Health')
        ->assertSee('Programmatic Opportunity')
        ->assertSee('Draft Request')
        ->assertSee('Publication Readiness');
});

it('resolves command center next action for an empty growth program', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Empty command center'], $user);

    $state = resolveGrowthProgramCommandCenter($program);

    expect($state['primary_action']['label'])->toBe('Detect Programmatic Opportunities')
        ->and($state['primary_action']['blocked'])->toBeTrue()
        ->and($state['primary_action']['missing'][0])->toBe('Attach an opportunity or signal first.');
});

it('resolves command center next action when programmatic opportunities are present', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Opportunity command center'], $user);
    $opportunity = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Best AI visibility tools',
    ]);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticOpportunity($program, makeProgrammaticOpportunity($organization, $workspace, $opportunity, ProgrammaticPatternType::COMPARISON_PAGE));

    $state = resolveGrowthProgramCommandCenter($program);

    expect($state['primary_action']['label'])->toBe('Build Cluster Preview');
});

it('resolves command center next action when clusters have no blueprints', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Cluster command center'], $user);
    $cluster = createCommandCenterCluster($organization, $workspace, $program);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticCluster($program, $cluster);

    $state = resolveGrowthProgramCommandCenter($program);

    expect($state['primary_action']['label'])->toBe('Build Brief Blueprints');
});

it('resolves command center next action for approved blueprints', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Blueprint command center'], $user);
    $blueprint = createCommandCenterBlueprint($workspace, $program, ProgrammaticBriefBlueprint::STATUS_APPROVED);
    app(GrowthProgramOrchestrator::class)->attachBriefBlueprint($program, $blueprint);

    $state = resolveGrowthProgramCommandCenter($program);

    expect($state['primary_action']['label'])->toBe('Convert Approved Blueprints to Briefs');
});

it('resolves command center next action for converted briefs', function (): void {
    [, $workspace, $user, $site] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Brief command center'], $user);
    $blueprint = createCommandCenterBlueprint($workspace, $program, ProgrammaticBriefBlueprint::STATUS_CONVERTED);
    app(GrowthProgramOrchestrator::class)->attachBriefBlueprint($program, $blueprint);
    app(GrowthProgramOrchestrator::class)->attachBrief($program, Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Converted command center brief',
        'primary_keyword' => 'command center',
    ]));

    $state = resolveGrowthProgramCommandCenter($program);

    expect($state['primary_action']['label'])->toBe('Prepare Draft Requests');
});

it('resolves command center next action for approved draft requests', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Draft request command center'], $user);
    app(GrowthProgramOrchestrator::class)->attachDraftRequest($program, createCommandCenterDraftRequest($workspace, $program, ProgrammaticDraftRequest::STATUS_APPROVED));

    $state = resolveGrowthProgramCommandCenter($program);

    expect($state['primary_action']['label'])->toBe('Create Approved Drafts');
});

it('resolves command center next action for generated drafts', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Generated draft command center'], $user);
    app(GrowthProgramOrchestrator::class)->attachDraftRequest($program, createCommandCenterDraftRequest($workspace, $program, ProgrammaticDraftRequest::STATUS_GENERATED));

    $state = resolveGrowthProgramCommandCenter($program);

    expect($state['primary_action']['label'])->toBe('Run Draft Quality Checks');
});

it('resolves command center next action for approved reviews', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Review command center'], $user);
    app(GrowthProgramOrchestrator::class)->attachDraftReview($program, createCommandCenterDraftReview($workspace, $program, ProgrammaticDraftReview::STATUS_APPROVED));

    $state = resolveGrowthProgramCommandCenter($program);

    expect($state['primary_action']['label'])->toBe('Convert Approved Reviews to Content');
});

it('resolves command center next action for approved readiness', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Readiness command center'], $user);
    app(GrowthProgramOrchestrator::class)->attachPublicationReadiness($program, createCommandCenterReadiness($workspace, $program, ProgrammaticPublicationReadiness::STATUS_APPROVED));

    $state = resolveGrowthProgramCommandCenter($program);

    expect($state['primary_action']['label'])->toBe('Create Publication Plan');
});

it('resolves command center next action for approved plans', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Plan command center'], $user);
    $readiness = createCommandCenterReadiness($workspace, $program, ProgrammaticPublicationReadiness::STATUS_APPROVED);
    app(GrowthProgramOrchestrator::class)->attachPublicationReadiness($program, $readiness);
    $plan = createCommandCenterPlan($workspace, $program, $readiness, ProgrammaticPublicationPlan::STATUS_APPROVED);
    app(GrowthProgramOrchestrator::class)->attachPublicationPlan($program, $plan);

    $state = resolveGrowthProgramCommandCenter($program);

    expect($state['primary_action']['label'])->toBe('Prepare Scheduled Publications');
});

it('shows command center blocked state for missing destinations', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Missing destination command center'], $user);
    $readiness = createCommandCenterReadiness($workspace, $program, ProgrammaticPublicationReadiness::STATUS_APPROVED);
    $plan = createCommandCenterPlan($workspace, $program, $readiness, ProgrammaticPublicationPlan::STATUS_SCHEDULING, 'missing_destination');
    app(GrowthProgramOrchestrator::class)->attachPublicationPlan($program, $plan);

    $state = resolveGrowthProgramCommandCenter($program);

    expect(collect($state['steps'])->firstWhere('label', 'Plan')['blocked_reason'])->toBe('Choose a destination before preparing scheduled publications.')
        ->and(collect($state['health'])->firstWhere('label', 'Destination status')['status'])->toBe('blocked');
});

it('shows command center blocked state for publication conflicts', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Publication conflict command center'], $user);
    $readiness = createCommandCenterReadiness($workspace, $program, ProgrammaticPublicationReadiness::STATUS_APPROVED);
    $plan = createCommandCenterPlan($workspace, $program, $readiness, ProgrammaticPublicationPlan::STATUS_SCHEDULING, 'existing_publication_terminal');
    app(GrowthProgramOrchestrator::class)->attachPublicationPlan($program, $plan);

    $state = resolveGrowthProgramCommandCenter($program);

    expect(collect($state['steps'])->firstWhere('label', 'Plan')['blocked_reason'])->toBe('A terminal publication already exists and will not be changed.')
        ->and(collect($state['health'])->firstWhere('label', 'Duplicate/conflict status')['status'])->toBe('blocked');
});

it('shows programmatic growth navigation links to read-enabled workspace users', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $this->actingAs($user)
        ->get(route('app.growth-programs.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Programmatic Growth')
        ->assertSee('Growth Programs')
        ->assertSee('Programmatic Opportunities')
        ->assertSee('Programmatic Clusters')
        ->assertSee('Brief Blueprints')
        ->assertSee('Draft Requests')
        ->assertSee('Draft Reviews')
        ->assertSee('Publication Readiness')
        ->assertSee('Publication Plans');
});

it('shows the controlled beta banner on programmatic growth screens', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $this->actingAs($user)
        ->get(route('app.programmatic-opportunities.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Programmatic Growth is in controlled beta')
        ->assertSee('It does not publish live content automatically');
});

it('loads the dashboard programmatic growth entry card', function (): void {
    [, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Dashboard beta program'], $user);

    $this->actingAs($user)
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Automated growth')
        ->assertSee('Open growth actions')
        ->assertSee('scheduled actions')
        ->assertSee('need decisions');
});

it('seeds a safe programmatic growth demo flow without live publishing', function (): void {
    [, $workspace, $user] = growthProgramFixture();

    $program = app(\App\Services\Growth\ProgrammaticGrowthDemoSeeder::class)->seed($workspace, $user);

    expect($program->name)->toBe('Demo Programmatic Growth Flow')
        ->and(ProgrammaticOpportunity::query()->where('growth_program_id', $program->id)->exists())->toBeTrue()
        ->and(ProgrammaticCluster::query()->where('growth_program_id', $program->id)->exists())->toBeTrue()
        ->and(ProgrammaticBriefBlueprint::query()->where('growth_program_id', $program->id)->exists())->toBeTrue()
        ->and(ProgrammaticDraftRequest::query()->where('growth_program_id', $program->id)->exists())->toBeTrue()
        ->and(ProgrammaticDraftReview::query()->where('growth_program_id', $program->id)->exists())->toBeTrue()
        ->and(ProgrammaticPublicationReadiness::query()->where('growth_program_id', $program->id)->exists())->toBeTrue()
        ->and(ProgrammaticPublicationPlan::query()->where('growth_program_id', $program->id)->exists())->toBeTrue()
        ->and(ContentPublication::query()->where('delivery_status', ContentPublication::STATUS_DELIVERED)->count())->toBe(0)
        ->and(ContentPublication::query()->where('remote_status', ContentPublication::REMOTE_PUBLISHED)->count())->toBe(0);
});

it('hides programmatic mutation actions from viewers', function (): void {
    [$organization, $workspace, ,] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $viewer = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'viewer',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Viewer command center'], $viewer);
    $blueprint = createCommandCenterBlueprint($workspace, $program, ProgrammaticBriefBlueprint::STATUS_CONVERTED);
    app(GrowthProgramOrchestrator::class)->attachBriefBlueprint($program, $blueprint);

    $this->actingAs($viewer)
        ->get(route('app.programmatic-brief-blueprints.show', $blueprint))
        ->assertOk()
        ->assertDontSee('Approve')
        ->assertDontSee('Convert to Brief')
        ->assertDontSee('Prepare Draft Request');
});

it('runs the programmatic growth smoke command without mutations by default', function (): void {
    growthProgramFixture();

    $before = [
        GrowthProgram::class => GrowthProgram::query()->count(),
        ProgrammaticOpportunity::class => ProgrammaticOpportunity::query()->count(),
        ContentPublication::class => ContentPublication::query()->count(),
    ];

    $exitCode = Artisan::call('argusly:programmatic-growth-smoke-test');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Programmatic Growth smoke checks passed')
        ->and(GrowthProgram::query()->count())->toBe($before[GrowthProgram::class])
        ->and(ProgrammaticOpportunity::query()->count())->toBe($before[ProgrammaticOpportunity::class])
        ->and(ContentPublication::query()->count())->toBe($before[ContentPublication::class]);
});

it('creates a safe demo flow from the programmatic growth smoke command when requested', function (): void {
    [, $workspace] = growthProgramFixture();

    $exitCode = Artisan::call('argusly:programmatic-growth-smoke-test', [
        '--create-demo' => true,
        '--workspace-id' => $workspace->id,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Safe demo flow is ready')
        ->and(GrowthProgram::query()->where('name', 'Demo Programmatic Growth Flow')->exists())->toBeTrue()
        ->and(ContentPublication::query()->where('delivery_status', ContentPublication::STATUS_DELIVERED)->count())->toBe(0)
        ->and(ContentPublication::query()->where('remote_status', ContentPublication::REMOTE_PUBLISHED)->count())->toBe(0);
});

it('attaches opportunity variants and signals without duplicates', function (): void {
    [$organization, $workspace, $user, $site] = growthProgramFixture();
    $orchestrator = app(GrowthProgramOrchestrator::class);
    $program = $orchestrator->create($workspace, ['name' => 'Opportunity mapping'], $user);

    $contentOpportunity = ContentOpportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'query_gap',
        'title' => 'Query gap opportunity',
        'priority_score' => 66,
        'dedupe_hash' => hash('sha256', 'content-opportunity'),
    ]);
    $competitorGap = CompetitorContentOpportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'competitor_gap',
        'title' => 'Competitor comparison gap',
        'priority_score' => 72,
        'dedupe_hash' => hash('sha256', 'competitor-gap'),
    ]);
    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Agentic source content',
    ]);
    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'AI visibility objective',
        'goal' => 'Grow AI visibility',
    ]);
    $agenticOpportunity = AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'content_id' => $content->id,
        'title' => 'Agentic AI visibility gap',
        'type' => 'ai_visibility',
        'priority_score' => 81,
    ]);
    $signal = OpportunitySignal::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'source' => OpportunitySignalSource::SIGNAL_INTELLIGENCE,
        'category' => OpportunityCategory::CONTENT_GAP,
        'topic' => 'AI visibility',
        'signal_strength' => 88,
        'confidence' => 79,
        'observed_at' => now(),
        'dedupe_hash' => hash('sha256', 'signal'),
    ]);

    $orchestrator->attachContentOpportunity($program, $contentOpportunity);
    $orchestrator->attachContentOpportunity($program, $contentOpportunity);
    $orchestrator->attachCompetitorGap($program, $competitorGap);
    $orchestrator->attachAgenticOpportunity($program, $agenticOpportunity);
    $orchestrator->attachSignal($program, $signal);

    $program->refresh();

    expect($program->assets()->where('role', GrowthAsset::ROLE_CONTENT_OPPORTUNITY)->count())->toBe(1)
        ->and($program->metrics['content_opportunities_count'])->toBe(1)
        ->and($program->metrics['competitor_gaps_count'])->toBe(1)
        ->and($program->metrics['agentic_opportunities_count'])->toBe(1)
        ->and($program->metrics['signals_count'])->toBe(1)
        ->and($program->metrics['assets_count'])->toBe(5)
        ->and($program->status)->toBe(GrowthProgramStatus::QUALIFIED);
});

it('syncs execution plans through briefs drafts and publications with status progression', function (): void {
    [$organization, $workspace, $user, $site] = growthProgramFixture();
    $orchestrator = app(GrowthProgramOrchestrator::class);
    $program = $orchestrator->create($workspace, ['name' => 'Execution mapping'], $user);

    $opportunity = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'priority_score' => 75,
    ]);
    $signal = OpportunitySignal::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'source' => OpportunitySignalSource::SIGNAL_INTELLIGENCE,
        'category' => OpportunityCategory::CONTENT_GAP,
        'topic' => 'Execution sync',
        'signal_strength' => 82,
        'confidence' => 70,
        'observed_at' => now(),
        'dedupe_hash' => hash('sha256', 'execution-signal'),
    ]);
    $opportunity->signals()->attach($signal->id, [
        'id' => (string) Str::uuid(),
        'weight' => 1,
        'contribution' => 1,
    ]);
    $plan = OpportunityExecutionPlan::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'opportunity_id' => $opportunity->id,
        'status' => OpportunityExecutionPlan::STATUS_PLANNED,
        'title' => 'Execution plan sync',
        'summary' => 'Sync mapped assets.',
        'priority_score' => 75,
    ]);
    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Execution content',
        'publish_status' => 'draft',
    ]);
    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'ready',
        'source' => 'opportunity_execution_plan',
        'title' => 'Execution brief',
        'client_refs' => [
            'execution_plan_id' => (string) $plan->id,
            'opportunity_id' => (string) $opportunity->id,
        ],
    ]);
    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => Draft::STATUS_READY_FOR_REVIEW,
        'title' => 'Execution draft',
        'meta' => ['source_context' => ['execution_plan_id' => (string) $plan->id]],
    ]);
    $publication = ContentPublication::query()->create([
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
    ]);

    $orchestrator->attachExecutionPlan($program, $plan);
    $orchestrator->syncDraftsFromBriefs($program);
    $orchestrator->syncPublicationsFromDrafts($program);
    $orchestrator->syncPublicationsFromDrafts($program);

    $program->refresh();

    expect($program->metrics['signals_count'])->toBe(1)
        ->and($program->metrics['execution_plans_count'])->toBe(1)
        ->and($program->metrics['briefs_count'])->toBe(1)
        ->and($program->metrics['drafts_count'])->toBe(1)
        ->and($program->metrics['publications_count'])->toBe(1)
        ->and($program->metrics['published_count'])->toBe(1)
        ->and($program->metrics['next_recommended_action'])->toBe('Measure performance and update outcomes.')
        ->and($program->assets()->where('assetable_id', $publication->id)->count())->toBe(1)
        ->and($program->status)->toBe(GrowthProgramStatus::PUBLISHED);
});

it('detects programmatic opportunities from supported source types', function (): void {
    [$organization, $workspace, , $site] = growthProgramFixture();
    $detector = app(ProgrammaticOpportunityDetector::class);

    $opportunity = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Argusly vs legacy content operations platform',
        'topic' => 'Argusly vs legacy platform',
        'priority_score' => 84,
    ]);
    $contentOpportunity = ContentOpportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'use_case',
        'title' => 'Best automation tool for support teams',
        'priority_score' => 73,
        'dedupe_hash' => hash('sha256', 'programmatic-content'),
    ]);
    $competitorGap = CompetitorContentOpportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'type' => 'gap',
        'title' => 'Competitor comparison opportunity',
        'topic' => 'Argusly competitors',
        'priority_score' => 68,
        'dedupe_hash' => hash('sha256', 'programmatic-competitor'),
    ]);
    $objective = AgenticMarketingObjective::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Answer visibility',
        'goal' => 'Build AI answer library',
    ]);
    $agenticOpportunity = AgenticMarketingOpportunity::query()->create([
        'objective_id' => $objective->id,
        'title' => 'AI answer library for content governance',
        'type' => 'ai_visibility',
        'priority_score' => 78,
    ]);
    $signal = OpportunitySignal::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'source' => OpportunitySignalSource::SIGNAL_INTELLIGENCE,
        'category' => OpportunityCategory::CONTENT_GAP,
        'topic' => 'Frequently asked questions about AI visibility',
        'signal_strength' => 76,
        'confidence' => 81,
        'observed_at' => now(),
        'dedupe_hash' => hash('sha256', 'programmatic-signal'),
    ]);

    $detected = [
        $detector->detect($opportunity),
        $detector->detect($contentOpportunity),
        $detector->detect($competitorGap),
        $detector->detect($agenticOpportunity),
        $detector->detect($signal),
    ];

    expect($detected[0]?->pattern_type)->toBe(ProgrammaticPatternType::COMPARISON_PAGE)
        ->and($detected[1]?->pattern_type)->toBe(ProgrammaticPatternType::USE_CASE_PAGE)
        ->and($detected[2]?->pattern_type)->toBe(ProgrammaticPatternType::COMPARISON_PAGE)
        ->and($detected[3]?->pattern_type)->toBe(ProgrammaticPatternType::AI_ANSWER_LIBRARY)
        ->and($detected[4]?->pattern_type)->toBe(ProgrammaticPatternType::FAQ_LIBRARY);

    foreach ($detected as $item) {
        expect($item)->not->toBeNull()
            ->and($item->scale_score)->toBeGreaterThanOrEqual(0)
            ->and($item->scale_score)->toBeLessThanOrEqual(100)
            ->and($item->confidence_score)->toBeGreaterThanOrEqual(0)
            ->and($item->confidence_score)->toBeLessThanOrEqual(100);
    }
});

it('prevents duplicate programmatic detections and links them to growth programs', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $detector = app(ProgrammaticOpportunityDetector::class);
    $orchestrator = app(GrowthProgramOrchestrator::class);

    $opportunity = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Best AI content tool for healthcare',
        'topic' => 'AI content tool for healthcare',
        'priority_score' => 88,
    ]);

    $first = $detector->detect($opportunity);
    $second = $detector->detect($opportunity);
    $program = $orchestrator->create($workspace, ['name' => 'Programmatic healthcare'], $user);
    $orchestrator->attachOpportunity($program, $opportunity);
    $synced = $orchestrator->syncProgrammaticOpportunitiesFromAssets($program);
    $orchestrator->attachProgrammaticOpportunity($program, $first);
    $orchestrator->attachProgrammaticOpportunity($program, $first);

    $program->refresh();

    expect($first?->id)->toBe($second?->id)
        ->and($synced)->toBe(1)
        ->and(ProgrammaticOpportunity::query()->where('source_id', $opportunity->id)->count())->toBe(1)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_PROGRAMMATIC_OPPORTUNITY)->count())->toBe(1)
        ->and($program->metrics['programmatic_opportunities_count'])->toBe(1);
});

it('supports programmatic validate reject lifecycle and app routes', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $opportunity = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'FAQ about AI answer visibility',
        'topic' => 'AI answer visibility FAQ',
        'priority_score' => 79,
    ]);

    $response = $this->actingAs($user)->post(route('app.programmatic-opportunities.detect.opportunity', $opportunity));
    $programmatic = ProgrammaticOpportunity::query()->firstOrFail();
    $response->assertRedirect(route('app.programmatic-opportunities.show', $programmatic));

    $this->actingAs($user)
        ->get(route('app.programmatic-opportunities.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Programmatic Opportunities')
        ->assertSee($programmatic->base_topic);

    $this->actingAs($user)
        ->get(route('app.programmatic-opportunities.show', $programmatic))
        ->assertOk()
        ->assertSee('Detected Pattern')
        ->assertSee('Recommended Next Action');

    $this->actingAs($user)
        ->post(route('app.programmatic-opportunities.validate', $programmatic))
        ->assertRedirect();
    expect($programmatic->refresh()->status)->toBe(ProgrammaticOpportunity::STATUS_VALIDATED);

    $this->actingAs($user)
        ->post(route('app.programmatic-opportunities.reject', $programmatic))
        ->assertRedirect();
    expect($programmatic->refresh()->status)->toBe(ProgrammaticOpportunity::STATUS_REJECTED);
});

it('builds programmatic cluster previews for key pattern types', function (ProgrammaticPatternType $pattern, string $expectedTitlePart): void {
    [$organization, $workspace] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Source for '.$pattern->value,
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, $pattern);

    $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);

    expect($cluster->status)->toBe(ProgrammaticCluster::STATUS_PREVIEW)
        ->and($cluster->items)->not->toBeEmpty()
        ->and($cluster->estimated_assets_count)->toBe($cluster->items->count())
        ->and($cluster->items->first()->title)->toContain($expectedTitlePart);

    foreach ($cluster->items as $item) {
        expect($item->priority_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
            ->and($item->seo_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
            ->and($item->ai_visibility_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
            ->and($item->business_value_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
            ->and($item->duplicate_risk_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
    }
})->with([
    'industry' => [ProgrammaticPatternType::INDUSTRY_PAGE, 'voor'],
    'location' => [ProgrammaticPatternType::LOCATION_PAGE, 'in'],
    'alternative' => [ProgrammaticPatternType::ALTERNATIVE_PAGE, 'Alternatief voor'],
    'comparison' => [ProgrammaticPatternType::COMPARISON_PAGE, 'vs'],
    'faq' => [ProgrammaticPatternType::FAQ_LIBRARY, 'Veelgestelde vragen over'],
]);

it('resolves growth asset types from programmatic pattern types', function (): void {
    $resolver = app(GrowthAssetTypeResolver::class);

    expect($resolver->fromPattern(ProgrammaticPatternType::INDUSTRY_PAGE))->toBe(GrowthAssetType::INDUSTRY_PAGE)
        ->and($resolver->fromPattern(ProgrammaticPatternType::LOCATION_PAGE))->toBe(GrowthAssetType::LOCATION_PAGE)
        ->and($resolver->fromPattern(ProgrammaticPatternType::ALTERNATIVE_PAGE))->toBe(GrowthAssetType::ALTERNATIVE_PAGE)
        ->and($resolver->fromPattern(ProgrammaticPatternType::COMPARISON_PAGE))->toBe(GrowthAssetType::COMPARISON_PAGE)
        ->and($resolver->fromPattern(ProgrammaticPatternType::FAQ_LIBRARY))->toBe(GrowthAssetType::FAQ_PAGE)
        ->and($resolver->fromPattern(ProgrammaticPatternType::AI_ANSWER_LIBRARY))->toBe(GrowthAssetType::AI_ANSWER_PAGE)
        ->and($resolver->fromPattern(ProgrammaticPatternType::FEATURE_PAGE))->toBe(GrowthAssetType::FEATURE_PAGE)
        ->and($resolver->fromPattern(ProgrammaticPatternType::INTEGRATION_PAGE))->toBe(GrowthAssetType::INTEGRATION_PAGE);
});

it('enriches cluster items with specialized asset type requirements', function (): void {
    [$organization, $workspace] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Comparison page source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::COMPARISON_PAGE);

    $item = app(ProgrammaticClusterBuilder::class)->build($opportunity)->items->first();

    expect($item->growth_asset_type)->toBe(GrowthAssetType::COMPARISON_PAGE)
        ->and($item->asset_type)->toBe(GrowthAssetType::COMPARISON_PAGE->value)
        ->and($item->intent)->toBe('commercial_investigation')
        ->and($item->recommended_word_count_min)->toBe(1200)
        ->and($item->recommended_word_count_max)->toBe(2200)
        ->and($item->recommended_schema_types)->toContain('WebPage', 'BreadcrumbList', 'FAQPage')
        ->and($item->recommended_cta)->toBe('decision')
        ->and($item->internal_linking_role)->toBe('conversion')
        ->and($item->briefing_requirements)->toContain('comparison table', 'criteria', 'best fit')
        ->and($item->seo_requirements)->toContain('search intent', 'metadata', 'internal links')
        ->and($item->ai_visibility_requirements)->toContain('clear criteria', 'answerable headings');
});

it('keeps existing cluster items backwards compatible when enrichment is missing', function (): void {
    [$organization, $workspace] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Legacy programmatic source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::FAQ_LIBRARY);
    $cluster = ProgrammaticCluster::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'programmatic_opportunity_id' => $opportunity->id,
        'name' => 'Legacy FAQ cluster',
        'pattern_type' => ProgrammaticPatternType::FAQ_LIBRARY->value,
        'base_topic' => 'AI visibility',
        'status' => ProgrammaticCluster::STATUS_PREVIEW,
        'estimated_assets_count' => 1,
    ]);
    $legacyItem = ProgrammaticClusterItem::query()->create([
        'workspace_id' => $workspace->id,
        'programmatic_cluster_id' => $cluster->id,
        'variable_value' => 'wat is ai visibility',
        'title' => 'Legacy FAQ item',
        'slug' => 'legacy-faq-item',
        'asset_type' => 'answer_page',
        'intent' => 'informational',
        'priority_score' => 50,
        'seo_score' => 50,
        'ai_visibility_score' => 50,
        'business_value_score' => 50,
        'duplicate_risk_score' => 10,
        'status' => ProgrammaticClusterItem::STATUS_PREVIEW,
        'metadata' => ['pattern_type' => ProgrammaticPatternType::FAQ_LIBRARY->value],
    ]);
    $unclassifiedItem = ProgrammaticClusterItem::query()->create([
        'workspace_id' => $workspace->id,
        'programmatic_cluster_id' => $cluster->id,
        'variable_value' => 'legacy',
        'title' => 'Unclassified legacy item',
        'slug' => 'unclassified-legacy-item',
        'asset_type' => 'programmatic_page',
        'intent' => 'solution_evaluation',
        'priority_score' => 40,
        'seo_score' => 40,
        'ai_visibility_score' => 40,
        'business_value_score' => 40,
        'duplicate_risk_score' => 10,
        'status' => ProgrammaticClusterItem::STATUS_PREVIEW,
        'metadata' => [],
    ]);

    $resolver = app(GrowthAssetTypeResolver::class);

    expect($resolver->fromClusterItem($legacyItem->refresh()))->toBe(GrowthAssetType::FAQ_PAGE)
        ->and($resolver->fromClusterItem($unclassifiedItem->refresh()))->toBe(GrowthAssetType::SUPPORTING_PAGE);
});

it('prevents duplicate cluster preview items and links clusters to growth programs', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Best software for industries',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::INDUSTRY_PAGE);
    $builder = app(ProgrammaticClusterBuilder::class);
    $orchestrator = app(GrowthProgramOrchestrator::class);

    $first = $builder->build($opportunity);
    $firstCount = $first->items()->count();
    $second = $builder->build($opportunity);
    $program = $orchestrator->create($workspace, ['name' => 'Industry program'], $user);
    $orchestrator->attachProgrammaticOpportunity($program, $opportunity);
    $orchestrator->attachProgrammaticCluster($program, $second);
    $orchestrator->syncProgrammaticClustersForProgram($program);

    $program->refresh();

    expect($second->id)->toBe($first->id)
        ->and($second->items()->count())->toBe($firstCount)
        ->and(ProgrammaticCluster::query()->where('programmatic_opportunity_id', $opportunity->id)->count())->toBe(1)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER)->count())->toBe(1)
        ->and($program->metrics['programmatic_clusters_count'])->toBe(1)
        ->and($program->metrics['programmatic_cluster_items_count'])->toBe($firstCount)
        ->and($program->metrics['estimated_programmatic_reach'])->toBeGreaterThan(0);
});

it('shows growth program asset type summaries for programmatic clusters', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Industry page source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::INDUSTRY_PAGE);
    $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Industry asset type program'], $user);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticCluster($program, $cluster);

    $this->actingAs($user)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertSee('Industry pages')
        ->assertSee((string) $cluster->items()->count());
});

it('builds brief blueprints from specialized cluster item asset types', function (
    ProgrammaticPatternType $pattern,
    GrowthAssetType $expectedType,
    string $expectedOutlineHeading,
): void {
    [$organization, $workspace] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Blueprint source '.$pattern->value,
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, $pattern);
    $item = app(ProgrammaticClusterBuilder::class)->build($opportunity)->items->first();

    $blueprint = app(ProgrammaticBriefBlueprintBuilder::class)->build($item);

    expect($blueprint->growth_asset_type)->toBe($expectedType)
        ->and($blueprint->status)->toBe(ProgrammaticBriefBlueprint::STATUS_DRAFT)
        ->and($blueprint->programmatic_cluster_item_id)->toBe($item->id)
        ->and($blueprint->primary_keyword)->toBe(Str::lower($item->title))
        ->and(collect($blueprint->outline)->pluck('heading'))->toContain($expectedOutlineHeading)
        ->and($blueprint->faq_questions)->not->toBeEmpty()
        ->and($blueprint->schema_recommendations)->not->toBeEmpty()
        ->and($blueprint->seo_requirements)->toContain('metadata')
        ->and($blueprint->ai_visibility_requirements)->not->toBeEmpty()
        ->and($blueprint->readinessPercentage())->toBeGreaterThanOrEqual(90);

    expect(Brief::query()->count())->toBe(0)
        ->and(Draft::query()->count())->toBe(0)
        ->and(Content::query()->count())->toBe(0)
        ->and(ContentPublication::query()->count())->toBe(0);
})->with([
    'industry' => [ProgrammaticPatternType::INDUSTRY_PAGE, GrowthAssetType::INDUSTRY_PAGE, 'Industry problem'],
    'comparison' => [ProgrammaticPatternType::COMPARISON_PAGE, GrowthAssetType::COMPARISON_PAGE, 'Comparison table'],
    'alternative' => [ProgrammaticPatternType::ALTERNATIVE_PAGE, GrowthAssetType::ALTERNATIVE_PAGE, 'Why look for an alternative'],
    'faq' => [ProgrammaticPatternType::FAQ_LIBRARY, GrowthAssetType::FAQ_PAGE, 'FAQ list'],
    'ai answer' => [ProgrammaticPatternType::AI_ANSWER_LIBRARY, GrowthAssetType::AI_ANSWER_PAGE, 'Direct answer'],
]);

it('prevents duplicate brief blueprints and links them to growth programs', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Blueprint duplicate source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::COMPARISON_PAGE);
    $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Blueprint program'], $user);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticCluster($program, $cluster);
    $item = $cluster->items->first();

    $first = app(GrowthProgramOrchestrator::class)->buildBriefBlueprintForClusterItem($program, $item);
    $second = app(GrowthProgramOrchestrator::class)->buildBriefBlueprintForClusterItem($program, $item);

    $program->refresh();

    expect($second->id)->toBe($first->id)
        ->and(ProgrammaticBriefBlueprint::query()->where('programmatic_cluster_item_id', $item->id)->count())->toBe(1)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_BRIEF_BLUEPRINT)->count())->toBe(1)
        ->and($program->metrics['brief_blueprints_count'])->toBe(1)
        ->and($program->metrics['blueprint_readiness_percentage'])->toBeGreaterThanOrEqual(90);
});

it('supports brief blueprint review approve reject lifecycle and metrics aggregation', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Blueprint lifecycle source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::FAQ_LIBRARY);
    $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Blueprint lifecycle'], $user);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticCluster($program, $cluster);
    app(GrowthProgramOrchestrator::class)->buildBriefBlueprintsForCluster($program, $cluster);

    $blueprints = ProgrammaticBriefBlueprint::query()->where('growth_program_id', $program->id)->get();
    $blueprints->first()->approve();
    $blueprints->skip(1)->first()?->reject();
    app(GrowthProgramOrchestrator::class)->refreshMetrics($program);

    $program->refresh();

    expect($blueprints->first()->refresh()->status)->toBe(ProgrammaticBriefBlueprint::STATUS_APPROVED)
        ->and($program->metrics['brief_blueprints_count'])->toBe($cluster->items()->count())
        ->and($program->metrics['approved_brief_blueprints_count'])->toBe(1)
        ->and($program->metrics['rejected_brief_blueprints_count'])->toBe(1)
        ->and($program->metrics['next_recommended_action'])->toBe('Create or attach a content brief.');
});

it('loads brief blueprint app routes and cluster integration', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Blueprint route source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::AI_ANSWER_LIBRARY);
    $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Blueprint routes'], $user);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticCluster($program, $cluster);

    $this->actingAs($user)
        ->post(route('app.programmatic-brief-blueprints.build.item', $cluster->items->first()))
        ->assertRedirect();

    $blueprint = ProgrammaticBriefBlueprint::query()->firstOrFail();

    $this->actingAs($user)
        ->get(route('app.programmatic-brief-blueprints.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Programmatic Brief Blueprints')
        ->assertSee($blueprint->title);

    $this->actingAs($user)
        ->get(route('app.programmatic-brief-blueprints.show', $blueprint))
        ->assertOk()
        ->assertSee('Blueprint Summary')
        ->assertSee('AI Visibility Requirements');

    $this->actingAs($user)
        ->get(route('app.programmatic-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Blueprint')
        ->assertSee('Draft');

    $this->actingAs($user)
        ->post(route('app.programmatic-brief-blueprints.approve', $blueprint))
        ->assertRedirect();
    expect($blueprint->refresh()->status)->toBe(ProgrammaticBriefBlueprint::STATUS_APPROVED);
});

it('converts an approved programmatic brief blueprint to a brief', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Convert blueprint source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::INDUSTRY_PAGE);
    $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Convert blueprint program'], $user);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticCluster($program, $cluster);
    $blueprint = app(GrowthProgramOrchestrator::class)->buildBriefBlueprintForClusterItem($program, $cluster->items->first());
    $blueprint->approve();

    $brief = app(ProgrammaticBriefConverter::class)->convertBlueprint($blueprint->refresh());
    app(GrowthProgramOrchestrator::class)->attachConvertedBrief($program, $blueprint->refresh(), $brief);

    $program->refresh();

    expect($brief->title)->toBe($blueprint->title)
        ->and($brief->source)->toBe('programmatic_brief_blueprint')
        ->and($brief->primary_keyword)->toBe($blueprint->primary_keyword)
        ->and($brief->secondary_keywords)->toBe($blueprint->secondary_keywords)
        ->and($brief->call_to_action)->toBe($blueprint->cta_recommendation)
        ->and(data_get($brief->client_refs, 'programmatic_brief_blueprint_id'))->toBe((string) $blueprint->id)
        ->and(data_get($brief->client_refs, 'growth_asset_type'))->toBe(GrowthAssetType::INDUSTRY_PAGE->value)
        ->and($blueprint->refresh()->status)->toBe(ProgrammaticBriefBlueprint::STATUS_CONVERTED)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_BRIEF)->count())->toBe(1)
        ->and($program->status)->toBe(GrowthProgramStatus::BRIEFED)
        ->and($program->metrics['converted_blueprints_count'])->toBe(1)
        ->and($program->metrics['programmatic_briefs_count'])->toBe(1);

    expect(Draft::query()->count())->toBe(0)
        ->and(Content::query()->count())->toBe(0)
        ->and(ContentPublication::query()->count())->toBe(0);
});

it('refuses to convert a non approved brief blueprint', function (): void {
    [$organization, $workspace] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Refuse conversion source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::FAQ_LIBRARY);
    $blueprint = app(ProgrammaticBriefBlueprintBuilder::class)
        ->build(app(ProgrammaticClusterBuilder::class)->build($opportunity)->items->first());

    app(ProgrammaticBriefConverter::class)->convertBlueprint($blueprint);
})->throws(InvalidArgumentException::class, 'Only approved programmatic brief blueprints can be converted.');

it('keeps brief conversion idempotent and restores growth asset links', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Idempotent conversion source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::COMPARISON_PAGE);
    $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Idempotent conversion'], $user);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticCluster($program, $cluster);
    $blueprint = app(GrowthProgramOrchestrator::class)->buildBriefBlueprintForClusterItem($program, $cluster->items->first());
    $blueprint->approve();

    $first = app(ProgrammaticBriefConverter::class)->convertBlueprint($blueprint->refresh());
    $second = app(ProgrammaticBriefConverter::class)->convertBlueprint($blueprint->refresh());
    app(GrowthProgramOrchestrator::class)->syncConvertedBriefsFromBlueprints($program);

    expect($second->id)->toBe($first->id)
        ->and(Brief::query()->where('client_refs->programmatic_brief_blueprint_id', $blueprint->id)->count())->toBe(1)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_BRIEF)->count())->toBe(1);
});

it('converts approved blueprints for clusters and growth programs in batches', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Batch conversion source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::ALTERNATIVE_PAGE);
    $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Batch conversion'], $user);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticCluster($program, $cluster);
    app(GrowthProgramOrchestrator::class)->buildBriefBlueprintsForCluster($program, $cluster);
    ProgrammaticBriefBlueprint::query()->where('growth_program_id', $program->id)->update(['status' => ProgrammaticBriefBlueprint::STATUS_APPROVED]);

    $clusterCount = app(GrowthProgramOrchestrator::class)->convertApprovedBlueprintsForCluster($program, $cluster);
    $programCount = app(GrowthProgramOrchestrator::class)->convertApprovedBlueprintsForProgram($program);

    $program->refresh();

    expect($clusterCount)->toBe($cluster->items()->count())
        ->and($programCount)->toBe($cluster->items()->count())
        ->and(Brief::query()->count())->toBe($cluster->items()->count())
        ->and($program->metrics['converted_blueprints_count'])->toBe($cluster->items()->count())
        ->and($program->metrics['programmatic_briefs_count'])->toBe($cluster->items()->count());
});

it('loads conversion UI actions and shows linked briefs on blueprint detail', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Conversion route source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::FAQ_LIBRARY);
    $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Conversion UI'], $user);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticCluster($program, $cluster);
    $blueprint = app(GrowthProgramOrchestrator::class)->buildBriefBlueprintForClusterItem($program, $cluster->items->first());
    $blueprint->approve();

    $this->actingAs($user)
        ->get(route('app.programmatic-brief-blueprints.show', $blueprint))
        ->assertOk()
        ->assertSee('Convert to Brief');

    $this->actingAs($user)
        ->post(route('app.programmatic-brief-blueprints.convert', $blueprint))
        ->assertRedirect();

    $brief = Brief::query()->firstOrFail();

    $this->actingAs($user)
        ->get(route('app.programmatic-brief-blueprints.show', $blueprint->refresh()))
        ->assertOk()
        ->assertSee('Linked Brief')
        ->assertSee($brief->title);

    $this->actingAs($user)
        ->get(route('app.programmatic-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Convert Approved')
        ->assertSee('Brief');

    $this->actingAs($user)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertSee('Convert Approved Blueprints to Briefs')
        ->assertSee('Programmatic briefs');
});

it('prepares a draft request from a converted programmatic blueprint', function (): void {
    [$program, $cluster, $blueprint] = convertedBlueprintFixture(ProgrammaticPatternType::INDUSTRY_PAGE);

    $draftRequest = app(GrowthProgramOrchestrator::class)->prepareDraftRequestForBlueprint($program, $blueprint);
    $program->refresh();

    expect($draftRequest->brief_id)->toBe($blueprint->linkedBrief()?->id)
        ->and($draftRequest->programmatic_brief_blueprint_id)->toBe($blueprint->id)
        ->and($draftRequest->growth_asset_type)->toBe(GrowthAssetType::INDUSTRY_PAGE)
        ->and($draftRequest->status)->toBe(ProgrammaticDraftRequest::STATUS_PENDING)
        ->and($draftRequest->generation_mode)->toBe(ProgrammaticDraftRequest::MODE_MANUAL)
        ->and($draftRequest->estimated_tokens)->toBeGreaterThan(0)
        ->and($draftRequest->estimated_cost)->toBeGreaterThan(0)
        ->and(data_get($draftRequest->metadata, 'requires_manual_approval'))->toBeTrue()
        ->and($program->assets()->where('role', GrowthAsset::ROLE_DRAFT_REQUEST)->count())->toBe(1)
        ->and($program->metrics['draft_requests_count'])->toBe(1)
        ->and($program->metrics['estimated_generation_tokens'])->toBe($draftRequest->estimated_tokens);

    expect(Draft::query()->count())->toBe(0)
        ->and(Content::query()->count())->toBe(0)
        ->and(ContentPublication::query()->count())->toBe(0);
});

it('refuses draft request preparation for non converted blueprints', function (): void {
    [$organization, $workspace] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Non converted draft request source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::FAQ_LIBRARY);
    $blueprint = app(ProgrammaticBriefBlueprintBuilder::class)
        ->build(app(ProgrammaticClusterBuilder::class)->build($opportunity)->items->first());

    app(ProgrammaticDraftRequestBuilder::class)->buildForBlueprint($blueprint);
})->throws(InvalidArgumentException::class, 'Only converted programmatic brief blueprints can be prepared for draft generation.');

it('prevents duplicate draft requests for the same brief', function (): void {
    [$program, , $blueprint] = convertedBlueprintFixture(ProgrammaticPatternType::COMPARISON_PAGE);

    $first = app(GrowthProgramOrchestrator::class)->prepareDraftRequestForBlueprint($program, $blueprint);
    $second = app(GrowthProgramOrchestrator::class)->prepareDraftRequestForBlueprint($program, $blueprint);

    expect($second->id)->toBe($first->id)
        ->and(ProgrammaticDraftRequest::query()->where('brief_id', $first->brief_id)->count())->toBe(1)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_DRAFT_REQUEST)->count())->toBe(1);
});

it('respects cluster and growth program draft request preparation limits', function (): void {
    config([
        'argusly_programmatic.max_requests_per_cluster' => 2,
        'argusly_programmatic.max_requests_per_growth_program' => 3,
    ]);

    [$program, $cluster] = convertedBlueprintFixture(ProgrammaticPatternType::FAQ_LIBRARY, convertAll: true);

    $clusterCount = app(GrowthProgramOrchestrator::class)->prepareDraftRequestsForCluster($program, $cluster);
    ProgrammaticDraftRequest::query()->delete();
    $program->assets()->where('role', GrowthAsset::ROLE_DRAFT_REQUEST)->delete();
    $programCount = app(GrowthProgramOrchestrator::class)->prepareDraftRequestsForProgram($program);

    expect($clusterCount)->toBe(2)
        ->and($programCount)->toBe(3)
        ->and(ProgrammaticDraftRequest::query()->count())->toBe(3);
});

it('supports draft request lifecycle metrics and app routes', function (): void {
    [$program, $cluster, $blueprint, $user, $workspace] = convertedBlueprintFixture(ProgrammaticPatternType::AI_ANSWER_LIBRARY);
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);
    $draftRequest = app(GrowthProgramOrchestrator::class)->prepareDraftRequestForBlueprint($program, $blueprint);

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-requests.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Programmatic Draft Requests')
        ->assertSee($draftRequest->title);

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-requests.show', $draftRequest))
        ->assertOk()
        ->assertSee('Request Summary')
        ->assertSee('Safety Metadata');

    $this->actingAs($user)
        ->post(route('app.programmatic-draft-requests.approve', $draftRequest))
        ->assertRedirect();

    $program->refresh();

    expect($draftRequest->refresh()->status)->toBe(ProgrammaticDraftRequest::STATUS_APPROVED)
        ->and($program->metrics['approved_draft_requests_count'])->toBe(1);

    $this->actingAs($user)
        ->get(route('app.programmatic-brief-blueprints.show', $blueprint))
        ->assertOk()
        ->assertSee('Draft Request')
        ->assertSee('Prepare Draft Request');

    $this->actingAs($user)
        ->get(route('app.programmatic-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Prepare Draft Requests')
        ->assertSee('Draft request');

    $this->actingAs($user)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertSee('Prepare Draft Requests')
        ->assertSee('Estimated tokens');
});

it('generates a draft from an approved programmatic draft request', function (): void {
    Queue::fake();
    [$program, , , , , $draftRequest] = approvedDraftRequestFixture(ProgrammaticPatternType::INDUSTRY_PAGE);

    $draft = app(GrowthProgramOrchestrator::class)->generateDraftForRequest($program, $draftRequest);
    $program->refresh();

    expect($draft->brief_id)->toBe($draftRequest->brief_id)
        ->and($draft->status)->toBe(Draft::STATUS_DRAFT)
        ->and(data_get($draft->meta, 'source'))->toBe('programmatic_draft_request')
        ->and(data_get($draft->meta, 'programmatic_draft_request_id'))->toBe((string) $draftRequest->id)
        ->and(data_get($draft->meta, 'growth_asset_type'))->toBe(GrowthAssetType::INDUSTRY_PAGE->value)
        ->and(data_get($draft->meta, 'outline'))->not->toBeEmpty()
        ->and(data_get($draft->meta, 'faq_questions'))->not->toBeEmpty()
        ->and($draftRequest->refresh()->status)->toBe(ProgrammaticDraftRequest::STATUS_GENERATED)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_DRAFT)->count())->toBe(1)
        ->and($program->metrics['generated_programmatic_drafts_count'])->toBe(1);

    Queue::assertNotPushed(GenerateDraftJob::class);
    expect(ContentPublication::query()->count())->toBe(0);
});

it('refuses draft generation for pending and rejected requests', function (): void {
    [, , , , , $pending] = approvedDraftRequestFixture(ProgrammaticPatternType::FAQ_LIBRARY);
    $pending->forceFill(['status' => ProgrammaticDraftRequest::STATUS_PENDING])->save();

    app(ProgrammaticDraftGenerator::class)->generate($pending);
})->throws(InvalidArgumentException::class, 'Only approved programmatic draft requests can generate drafts.');

it('refuses draft generation for rejected requests', function (): void {
    [, , , , , $rejected] = approvedDraftRequestFixture(ProgrammaticPatternType::FAQ_LIBRARY);
    $rejected->forceFill(['status' => ProgrammaticDraftRequest::STATUS_REJECTED])->save();

    app(ProgrammaticDraftGenerator::class)->generate($rejected);
})->throws(InvalidArgumentException::class, 'Only approved programmatic draft requests can generate drafts.');

it('keeps draft generation idempotent and restores growth asset links', function (): void {
    [$program, , , , , $draftRequest] = approvedDraftRequestFixture(ProgrammaticPatternType::COMPARISON_PAGE);

    $first = app(GrowthProgramOrchestrator::class)->generateDraftForRequest($program, $draftRequest);
    $program->assets()->where('role', GrowthAsset::ROLE_DRAFT)->delete();
    $second = app(GrowthProgramOrchestrator::class)->generateDraftForRequest($program, $draftRequest->refresh());

    expect($second->id)->toBe($first->id)
        ->and(Draft::query()->where('brief_id', $draftRequest->brief_id)->count())->toBe(1)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_DRAFT)->count())->toBe(1);
});

it('respects generation limits and blocks batch generation when disabled', function (): void {
    config([
        'argusly_programmatic.allow_batch_generation' => false,
        'argusly_programmatic.max_requests_per_cluster' => 2,
        'argusly_programmatic.max_requests_per_growth_program' => 3,
    ]);
    [$program, $cluster] = approvedDraftRequestFixture(ProgrammaticPatternType::ALTERNATIVE_PAGE, prepareAll: true);

    app(GrowthProgramOrchestrator::class)->generateApprovedDraftsForCluster($program, $cluster);
})->throws(InvalidArgumentException::class, 'Batch programmatic draft generation is disabled.');

it('generates approved cluster and program requests within configured limits', function (): void {
    config([
        'argusly_programmatic.allow_batch_generation' => true,
        'argusly_programmatic.max_requests_per_cluster' => 2,
        'argusly_programmatic.max_requests_per_growth_program' => 3,
    ]);
    [$program, $cluster] = approvedDraftRequestFixture(ProgrammaticPatternType::FAQ_LIBRARY, prepareAll: true);

    $clusterCount = app(GrowthProgramOrchestrator::class)->generateApprovedDraftsForCluster($program, $cluster);
    Draft::query()->delete();
    ProgrammaticDraftRequest::query()->update(['status' => ProgrammaticDraftRequest::STATUS_APPROVED, 'metadata' => []]);
    $program->assets()->where('role', GrowthAsset::ROLE_DRAFT)->delete();
    $programCount = app(GrowthProgramOrchestrator::class)->generateApprovedDraftsForProgram($program);

    expect($clusterCount)->toBe(2)
        ->and($programCount)->toBe(3)
        ->and(Draft::query()->count())->toBe(3);
});

it('marks failed programmatic draft generation requests with error metadata', function (): void {
    [, , , , , $draftRequest] = approvedDraftRequestFixture(ProgrammaticPatternType::AI_ANSWER_LIBRARY);
    config(['argusly_programmatic.allow_batch_generation' => false]);
    $draftRequest->forceFill(['generation_mode' => ProgrammaticDraftRequest::MODE_BATCH])->save();

    try {
        app(ProgrammaticDraftGenerator::class)->generate($draftRequest->refresh());
    } catch (Throwable $exception) {
        // Expected: the generator records failure metadata before bubbling the error.
    }

    expect($draftRequest->refresh()->status)->toBe(ProgrammaticDraftRequest::STATUS_FAILED)
        ->and(data_get($draftRequest->metadata, 'failure_reason'))->not->toBeEmpty();
});

it('loads controlled generation UI and linked draft state', function (): void {
    [$program, $cluster, , $user, $workspace, $draftRequest] = approvedDraftRequestFixture(ProgrammaticPatternType::FAQ_LIBRARY);
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-requests.show', $draftRequest))
        ->assertOk()
        ->assertSee('Generate Draft');

    $this->actingAs($user)
        ->post(route('app.programmatic-draft-requests.generate', $draftRequest))
        ->assertRedirect();

    $draft = Draft::query()->firstOrFail();

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-requests.show', $draftRequest->refresh()))
        ->assertOk()
        ->assertSee('Linked Draft')
        ->assertSee($draft->title);

    $this->actingAs($user)
        ->get(route('app.programmatic-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Generate Approved Requests');

    $this->actingAs($user)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertSee('Create Approved Drafts')
        ->assertSee('Generation safety');
});

it('creates a deterministic draft review from a generated request', function (): void {
    [$program, , , , , $draftRequest] = generatedDraftRequestFixture(ProgrammaticPatternType::INDUSTRY_PAGE);

    $review = app(GrowthProgramOrchestrator::class)->reviewDraftRequest($program, $draftRequest);
    $program->refresh();

    expect($review->programmatic_draft_request_id)->toBe($draftRequest->id)
        ->and($review->draft_id)->toBe($draftRequest->linkedDraft()?->id)
        ->and($review->overall_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($review->seo_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($review->ai_visibility_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($review->risk_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($review->checks)->toHaveKey('completeness')
        ->and($program->assets()->where('role', GrowthAsset::ROLE_DRAFT_REVIEW)->count())->toBe(1)
        ->and($program->metrics['draft_reviews_count'])->toBe(1);

    expect(Content::query()->count())->toBe(0)
        ->and(ContentPublication::query()->count())->toBe(0);
});

it('refuses draft review for non generated requests', function (): void {
    [, , , , , $draftRequest] = approvedDraftRequestFixture(ProgrammaticPatternType::FAQ_LIBRARY);

    app(ProgrammaticDraftReviewService::class)->reviewRequest($draftRequest);
})->throws(InvalidArgumentException::class, 'Only generated programmatic draft requests can be reviewed.');

it('prevents duplicate draft reviews for the same request', function (): void {
    [$program, , , , , $draftRequest] = generatedDraftRequestFixture(ProgrammaticPatternType::COMPARISON_PAGE);

    $first = app(GrowthProgramOrchestrator::class)->reviewDraftRequest($program, $draftRequest);
    $second = app(GrowthProgramOrchestrator::class)->reviewDraftRequest($program, $draftRequest->refresh());

    expect($second->id)->toBe($first->id)
        ->and(ProgrammaticDraftReview::query()->where('programmatic_draft_request_id', $draftRequest->id)->count())->toBe(1);
});

it('creates blocking issues for missing content and high duplicate risk', function (): void {
    [$program, , , , , $draftRequest] = generatedDraftRequestFixture(ProgrammaticPatternType::AI_ANSWER_LIBRARY);
    $draftRequest->item?->forceFill(['duplicate_risk_score' => 95])->save();

    $review = app(GrowthProgramOrchestrator::class)->reviewDraftRequest($program, $draftRequest->refresh());

    expect($review->status)->toBe(ProgrammaticDraftReview::STATUS_BLOCKED)
        ->and($review->blocking_issues)->toContain('Draft body is missing.')
        ->and($review->blocking_issues)->toContain('Duplicate risk is high for this cluster item.');
});

it('supports draft review approval lifecycle and blocks normal approval for blocked reviews', function (): void {
    [$program, , , $user, , $draftRequest] = generatedDraftRequestFixture(ProgrammaticPatternType::FAQ_LIBRARY);
    $draft = $draftRequest->linkedDraft();
    $draft->forceFill(['content_html' => '<h1>FAQ</h1><p>Direct answer with helpful context.</p>'])->save();
    $review = app(GrowthProgramOrchestrator::class)->reviewDraftRequest($program, $draftRequest->refresh());
    $review->needsWork($user);
    $review->approve($user);

    expect($review->refresh()->status)->toBe(ProgrammaticDraftReview::STATUS_APPROVED);

    $review->forceFill(['status' => ProgrammaticDraftReview::STATUS_BLOCKED])->save();
    $review->approve($user);
})->throws(InvalidArgumentException::class, 'Blocked reviews require an explicit override before approval.');

it('loads draft review routes and review integrations', function (): void {
    [$program, $cluster, , $user, $workspace, $draftRequest] = generatedDraftRequestFixture(ProgrammaticPatternType::FAQ_LIBRARY);
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $this->actingAs($user)
        ->post(route('app.programmatic-draft-reviews.run.request', $draftRequest))
        ->assertRedirect();

    $review = ProgrammaticDraftReview::query()->firstOrFail();

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-reviews.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Programmatic Draft Reviews');

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-reviews.show', $review))
        ->assertOk()
        ->assertSee('Scores')
        ->assertSee('Blocking Issues');

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-requests.show', $draftRequest))
        ->assertOk()
        ->assertSee('Review status');

    $this->actingAs($user)
        ->get(route('app.programmatic-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Review Generated Drafts');

    $this->actingAs($user)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertSee('Draft Quality Checks')
        ->assertSee('Run Draft Quality Checks');
});

it('converts an approved programmatic draft review to content', function (): void {
    [$program, , , $user, , , $review] = approvedDraftReviewFixture(ProgrammaticPatternType::INDUSTRY_PAGE);

    $content = app(GrowthProgramOrchestrator::class)->convertReviewToContent($program, $review, createdByUserId: $user->id);
    $program->refresh();

    expect($content->workspace_id)->toBe($program->workspace_id)
        ->and($content->status)->toBe('draft')
        ->and($content->publish_status)->toBe('draft')
        ->and($content->external_key)->toBe('programmatic-draft-review-'.$review->id)
        ->and($content->primary_keyword)->not->toBeNull()
        ->and($content->current_revision_id)->not->toBeNull()
        ->and($content->current_version_id)->not->toBeNull()
        ->and($review->refresh()->metadata['converted_content_id'])->toBe($content->id)
        ->and($review->linkedContent()?->id)->toBe($content->id)
        ->and($review->draft->refresh()->content_id)->toBe($content->id)
        ->and($review->brief->refresh()->content_id)->toBe($content->id)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_CONTENT)->count())->toBe(1)
        ->and($program->metrics['programmatic_content_count'])->toBe(1)
        ->and($program->metrics['converted_content_count'])->toBe(1)
        ->and($program->metrics['content_ready_for_publication_count'])->toBe(1);

    expect(ContentPublication::query()->count())->toBe(0);
});

it('refuses to convert a non approved programmatic draft review to content', function (): void {
    [, , , , , , $review] = draftReviewFixture(ProgrammaticPatternType::FAQ_LIBRARY);

    app(ProgrammaticContentConverter::class)->convertReview($review);
})->throws(InvalidArgumentException::class, 'Only approved programmatic draft reviews can be converted to content.');

it('keeps content conversion idempotent and restores growth asset links', function (): void {
    [$program, , , $user, , , $review] = approvedDraftReviewFixture(ProgrammaticPatternType::COMPARISON_PAGE);
    $orchestrator = app(GrowthProgramOrchestrator::class);

    $first = $orchestrator->convertReviewToContent($program, $review, createdByUserId: $user->id);
    GrowthAsset::query()
        ->where('growth_program_id', $program->id)
        ->where('role', GrowthAsset::ROLE_CONTENT)
        ->delete();
    $second = $orchestrator->convertReviewToContent($program->refresh(), $review->refresh(), createdByUserId: $user->id);

    expect($second->id)->toBe($first->id)
        ->and(Content::query()->where('external_key', 'programmatic-draft-review-'.$review->id)->count())->toBe(1)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_CONTENT)->count())->toBe(1);
});

it('converts approved draft reviews for clusters and growth programs without publications', function (): void {
    [$program, $cluster] = approvedDraftReviewFixture(ProgrammaticPatternType::FAQ_LIBRARY, reviewAll: true);
    $expected = ProgrammaticDraftReview::query()->where('growth_program_id', $program->id)->where('status', ProgrammaticDraftReview::STATUS_APPROVED)->count();

    $clusterCount = app(GrowthProgramOrchestrator::class)->convertApprovedReviewsForCluster($program, $cluster);
    $programCount = app(GrowthProgramOrchestrator::class)->convertApprovedReviewsForProgram($program->refresh());

    expect($clusterCount)->toBe($expected)
        ->and($programCount)->toBe($expected)
        ->and(Content::query()->count())->toBe($expected)
        ->and(ContentPublication::query()->count())->toBe(0)
        ->and($program->refresh()->metrics['converted_content_count'])->toBe($expected);
});

it('loads content conversion UI and linked content state', function (): void {
    [$program, $cluster, , $user, $workspace, , $review] = approvedDraftReviewFixture(ProgrammaticPatternType::ALTERNATIVE_PAGE);
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-reviews.show', $review))
        ->assertOk()
        ->assertSee('Convert to Content');

    $this->actingAs($user)
        ->post(route('app.programmatic-draft-reviews.convert-to-content', $review))
        ->assertRedirect();

    $content = $review->refresh()->linkedContent();

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-reviews.show', $review))
        ->assertOk()
        ->assertSee('Linked Content')
        ->assertSee($content->title);

    $this->actingAs($user)
        ->get(route('app.programmatic-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Convert Approved Reviews')
        ->assertSee('not converted');

    $this->actingAs($user)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertSee('Convert Approved Reviews to Content')
        ->assertSee('Converted content');

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-reviews.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Programmatic Draft Reviews');
});

it('creates publication readiness for converted programmatic content', function (): void {
    [$program, , , $user, , , $review] = approvedDraftReviewFixture(ProgrammaticPatternType::INDUSTRY_PAGE);
    $content = app(GrowthProgramOrchestrator::class)->convertReviewToContent($program, $review, createdByUserId: $user->id);

    $readiness = app(GrowthProgramOrchestrator::class)->runPublicationReadinessForContent($program->refresh(), $content);
    $program->refresh();

    expect($readiness->content_id)->toBe($content->id)
        ->and($readiness->programmatic_draft_review_id)->toBe($review->id)
        ->and($readiness->readiness_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($readiness->seo_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($readiness->schema_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($readiness->internal_linking_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($readiness->destination_readiness_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($readiness->publication_risk_score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100)
        ->and($readiness->checks)->toHaveKey('seo')
        ->and($program->assets()->where('role', GrowthAsset::ROLE_PUBLICATION_READINESS)->count())->toBe(1)
        ->and($program->metrics['publication_readiness_count'])->toBe(1);

    expect(ContentPublication::query()->count())->toBe(0);
});

it('blocks or marks content without approved review as not publication ready', function (): void {
    [$organization, $workspace] = growthProgramFixture();
    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Unreviewed programmatic content',
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);

    $readiness = app(ProgrammaticPublicationReadinessService::class)->checkContent($content);

    expect($readiness->status)->toBe(ProgrammaticPublicationReadiness::STATUS_BLOCKED)
        ->and($readiness->missing_requirements)->not->toBeEmpty();
});

it('keeps publication readiness checks idempotent', function (): void {
    [$program, , , $user, , , $review] = approvedDraftReviewFixture(ProgrammaticPatternType::COMPARISON_PAGE);
    $content = app(GrowthProgramOrchestrator::class)->convertReviewToContent($program, $review, createdByUserId: $user->id);
    $orchestrator = app(GrowthProgramOrchestrator::class);

    $first = $orchestrator->runPublicationReadinessForContent($program, $content);
    GrowthAsset::query()
        ->where('growth_program_id', $program->id)
        ->where('role', GrowthAsset::ROLE_PUBLICATION_READINESS)
        ->delete();
    $second = $orchestrator->runPublicationReadinessForContent($program->refresh(), $content->refresh());

    expect($second->id)->toBe($first->id)
        ->and(ProgrammaticPublicationReadiness::query()->where('content_id', $content->id)->count())->toBe(1)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_PUBLICATION_READINESS)->count())->toBe(1);
});

it('supports publication readiness approval lifecycle and blocks normal approval for blocked gates', function (): void {
    [$program, , , $user, , , $review] = approvedDraftReviewFixture(ProgrammaticPatternType::FAQ_LIBRARY);
    $content = app(GrowthProgramOrchestrator::class)->convertReviewToContent($program, $review, createdByUserId: $user->id);

    $readiness = app(GrowthProgramOrchestrator::class)->runPublicationReadinessForContent($program, $content);
    $readiness->needsWork();
    $readiness->approve($user);

    expect($readiness->refresh()->status)->toBe(ProgrammaticPublicationReadiness::STATUS_APPROVED);

    $blocked = Content::query()->create([
        'workspace_id' => $program->workspace_id,
        'title' => 'Blocked content',
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);
    $blockedReadiness = app(ProgrammaticPublicationReadinessService::class)->checkContent($blocked);
    $blockedReadiness->approve($user);
})->throws(InvalidArgumentException::class, 'Blocked publication readiness requires an explicit override before approval.');

it('requires an explicit form override to approve blocked publication readiness', function (): void {
    [$program, , , $user] = approvedDraftReviewFixture(ProgrammaticPatternType::FAQ_LIBRARY);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $blocked = Content::query()->create([
        'workspace_id' => $program->workspace_id,
        'title' => 'Blocked approval route content',
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);
    $readiness = app(ProgrammaticPublicationReadinessService::class)->checkContent($blocked);

    $this->actingAs($user)
        ->get(route('app.programmatic-publication-readiness.show', $readiness))
        ->assertOk()
        ->assertSee('Override blocked checks');

    $this->actingAs($user)
        ->post(route('app.programmatic-publication-readiness.approve', $readiness))
        ->assertSessionHasErrors('publication_readiness');

    $this->actingAs($user)
        ->post(route('app.programmatic-publication-readiness.approve', $readiness), [
            'override' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Publication readiness approved.');

    expect($readiness->fresh()->status)->toBe(ProgrammaticPublicationReadiness::STATUS_APPROVED);
});

it('runs publication readiness for clusters and growth programs without publications', function (): void {
    [$program, $cluster] = approvedDraftReviewFixture(ProgrammaticPatternType::FAQ_LIBRARY, reviewAll: true);
    app(GrowthProgramOrchestrator::class)->convertApprovedReviewsForProgram($program);
    $expected = Content::query()->count();

    $clusterCount = app(GrowthProgramOrchestrator::class)->runPublicationReadinessForCluster($program->refresh(), $cluster);
    ProgrammaticPublicationReadiness::query()->delete();
    $program->assets()->where('role', GrowthAsset::ROLE_PUBLICATION_READINESS)->delete();
    $programCount = app(GrowthProgramOrchestrator::class)->runPublicationReadinessForProgram($program->refresh());

    expect($clusterCount)->toBe($expected)
        ->and($programCount)->toBe($expected)
        ->and(ProgrammaticPublicationReadiness::query()->count())->toBe($expected)
        ->and(ContentPublication::query()->count())->toBe(0)
        ->and($program->refresh()->metrics['publication_readiness_count'])->toBe($expected);
});

it('loads publication readiness routes and integrations', function (): void {
    [$program, $cluster, , $user, $workspace, , $review] = approvedDraftReviewFixture(ProgrammaticPatternType::ALTERNATIVE_PAGE);
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);
    $content = app(GrowthProgramOrchestrator::class)->convertReviewToContent($program, $review, createdByUserId: $user->id);

    $this->actingAs($user)
        ->post(route('app.programmatic-publication-readiness.run.content', $content))
        ->assertRedirect();

    $readiness = ProgrammaticPublicationReadiness::query()->firstOrFail();

    $this->actingAs($user)
        ->get(route('app.programmatic-publication-readiness.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Programmatic Publication Readiness');

    $this->actingAs($user)
        ->get(route('app.programmatic-publication-readiness.show', $readiness))
        ->assertOk()
        ->assertSee('Scores')
        ->assertSee('Missing Requirements');

    $this->actingAs($user)
        ->get(route('app.programmatic-draft-reviews.show', $review))
        ->assertOk()
        ->assertSee('Publication Readiness');

    $this->actingAs($user)
        ->get(route('app.programmatic-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Run Publication Readiness');

    $this->actingAs($user)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertSee('Publication Readiness')
        ->assertSee('Run Publication Readiness');

    $this->actingAs($user)
        ->get(route('app.content.show', $content))
        ->assertOk()
        ->assertSee('Run Readiness Check');

    expect(ContentPublication::query()->count())->toBe(0);
});

it('creates a publication plan from approved readiness', function (): void {
    [$program, , , , , , , $readiness] = approvedPublicationReadinessFixture(ProgrammaticPatternType::INDUSTRY_PAGE);

    $plan = app(GrowthProgramOrchestrator::class)->createPublicationPlanFromReadiness($program, $readiness, [
        'planned_start_at' => '2026-07-01 09:00:00',
        'cadence' => ProgrammaticPublicationPlan::CADENCE_DAILY,
    ]);
    $program->refresh();

    expect($plan->growth_program_id)->toBe($program->id)
        ->and($plan->items()->count())->toBe(1)
        ->and($plan->items()->first()->publication_readiness_id)->toBe($readiness->id)
        ->and($plan->items()->first()->planned_publish_at?->format('Y-m-d H:i:s'))->toBe('2026-07-01 09:00:00')
        ->and($program->assets()->where('role', GrowthAsset::ROLE_PUBLICATION_PLAN)->count())->toBe(1)
        ->and($program->metrics['publication_plans_count'])->toBe(1)
        ->and($program->metrics['publication_plan_items_count'])->toBe(1);

    expect(ContentPublication::query()->count())->toBe(0);
});

it('refuses publication plan creation for non approved readiness', function (): void {
    [$program, , , $user, , , $review] = approvedDraftReviewFixture(ProgrammaticPatternType::FAQ_LIBRARY);
    $content = app(GrowthProgramOrchestrator::class)->convertReviewToContent($program, $review, createdByUserId: $user->id);
    $readiness = app(GrowthProgramOrchestrator::class)->runPublicationReadinessForContent($program, $content);

    app(ProgrammaticPublicationPlanBuilder::class)->createFromReadiness($readiness);
})->throws(InvalidArgumentException::class, 'Only approved publication readiness records can be planned.');

it('prevents duplicate publication plan items for the same readiness', function (): void {
    [$program, , , , , , , $readiness] = approvedPublicationReadinessFixture(ProgrammaticPatternType::COMPARISON_PAGE);
    $builder = app(ProgrammaticPublicationPlanBuilder::class);
    $plan = $builder->createFromReadiness($readiness);

    $first = $plan->items()->firstOrFail();
    $second = $builder->addReadinessToPlan($plan->refresh(), $readiness->refresh());

    expect($second->id)->toBe($first->id)
        ->and($plan->items()->count())->toBe(1);

    app(GrowthProgramOrchestrator::class)->attachPublicationPlan($program, $plan->refresh());
    expect($program->refresh()->assets()->where('role', GrowthAsset::ROLE_PUBLICATION_PLAN)->count())->toBe(1);
});

it('respects cluster and growth program publication plan limits', function (): void {
    [$program, $cluster] = approvedPublicationReadinessFixture(ProgrammaticPatternType::FAQ_LIBRARY, readinessAll: true);
    config([
        'argusly_programmatic.max_plan_items_per_cluster' => 2,
        'argusly_programmatic.max_plan_items_per_growth_program' => 3,
    ]);

    $clusterPlan = app(GrowthProgramOrchestrator::class)->createPublicationPlanForCluster($program, $cluster);
    $clusterPlanItemsCount = $clusterPlan->items()->count();
    ProgrammaticPublicationPlan::query()->delete();
    GrowthAsset::query()->where('growth_program_id', $program->id)->where('role', GrowthAsset::ROLE_PUBLICATION_PLAN)->delete();
    $programPlan = app(GrowthProgramOrchestrator::class)->createPublicationPlanForProgram($program->refresh());

    expect($clusterPlanItemsCount)->toBe(2)
        ->and($programPlan->items()->count())->toBe(3)
        ->and(ContentPublication::query()->count())->toBe(0);
});

it('calculates publication plan cadence previews', function (string $cadence, int $expectedDays): void {
    [$program, $cluster] = approvedPublicationReadinessFixture(ProgrammaticPatternType::FAQ_LIBRARY, readinessAll: true);

    $plan = app(GrowthProgramOrchestrator::class)->createPublicationPlanForCluster($program, $cluster, [
        'planned_start_at' => '2026-07-01 09:00:00',
        'cadence' => $cadence,
    ]);
    $dates = $plan->items()->orderBy('planned_publish_at')->pluck('planned_publish_at')->map(fn ($date) => $date?->format('Y-m-d'))->take(2)->values();

    $expectedSecondDate = match ($expectedDays) {
        1 => '2026-07-02',
        2 => '2026-07-03',
        7 => '2026-07-08',
        default => '2026-07-01',
    };

    expect($dates[0])->toBe('2026-07-01')
        ->and($dates[1])->toBe($expectedSecondDate);
})->with([
    'daily' => [ProgrammaticPublicationPlan::CADENCE_DAILY, 1],
    'every two days' => [ProgrammaticPublicationPlan::CADENCE_EVERY_2_DAYS, 2],
    'weekly' => [ProgrammaticPublicationPlan::CADENCE_WEEKLY, 7],
]);

it('supports publication plan approve cancel lifecycle and metrics', function (): void {
    [$program, , , , , , , $readiness] = approvedPublicationReadinessFixture(ProgrammaticPatternType::ALTERNATIVE_PAGE);
    $plan = app(GrowthProgramOrchestrator::class)->createPublicationPlanFromReadiness($program, $readiness);

    $plan->approve();
    expect($plan->refresh()->status)->toBe(ProgrammaticPublicationPlan::STATUS_APPROVED)
        ->and($plan->items()->first()->status)->toBe(ProgrammaticPublicationPlanItem::STATUS_APPROVED);

    app(GrowthProgramOrchestrator::class)->refreshMetrics($program);
    expect($program->refresh()->metrics['approved_publication_plan_items_count'])->toBe(1);

    $plan->cancel();
    expect($plan->refresh()->status)->toBe(ProgrammaticPublicationPlan::STATUS_CANCELLED)
        ->and($plan->items()->first()->status)->toBe(ProgrammaticPublicationPlanItem::STATUS_CANCELLED)
        ->and(ContentPublication::query()->count())->toBe(0);
});

it('loads publication plan routes and integrations', function (): void {
    [$program, $cluster, , $user, $workspace, , , $readiness] = approvedPublicationReadinessFixture(ProgrammaticPatternType::ALTERNATIVE_PAGE);
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $this->actingAs($user)
        ->post(route('app.programmatic-publication-plans.create.readiness', $readiness))
        ->assertRedirect();

    $plan = ProgrammaticPublicationPlan::query()->firstOrFail();

    $this->actingAs($user)
        ->get(route('app.programmatic-publication-plans.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Programmatic Publication Plans');

    $this->actingAs($user)
        ->get(route('app.programmatic-publication-plans.show', $plan))
        ->assertOk()
        ->assertSee('Plan Summary')
        ->assertSee('Approve Plan');

    $this->actingAs($user)
        ->get(route('app.programmatic-publication-readiness.show', $readiness))
        ->assertOk()
        ->assertSee('Publication Plans');

    $this->actingAs($user)
        ->get(route('app.programmatic-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Create Publication Plan');

    $this->actingAs($user)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertSee('Publication Plans')
        ->assertSee('Create Publication Plan');

    expect(ContentPublication::query()->count())->toBe(0);
});

it('enforces publication plan post action authorization by role', function (): void {
    [$program, , , $owner, , , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::ALTERNATIVE_PAGE, approvePlan: false);
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $viewer = User::factory()->create([
        'organization_id' => $owner->organization_id,
        'role' => 'viewer',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);
    $member = User::factory()->create([
        'organization_id' => $owner->organization_id,
        'role' => 'member',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);
    $admin = User::factory()->create([
        'organization_id' => $owner->organization_id,
        'role' => 'admin',
        'is_admin' => true,
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    foreach ([$viewer, $member] as $blockedUser) {
        $this->actingAs($blockedUser)
            ->post(route('app.programmatic-publication-plans.approve', $plan))
            ->assertForbidden();

        $this->actingAs($blockedUser)
            ->post(route('app.programmatic-publication-plans.schedule', $plan))
            ->assertForbidden();

        $this->actingAs($blockedUser)
            ->post(route('app.programmatic-publication-plans.cancel', $plan))
            ->assertForbidden();
    }

    $this->actingAs($admin)
        ->post(route('app.programmatic-publication-plans.approve', $plan))
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('app.programmatic-publication-plans.schedule', $plan->refresh()))
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('app.programmatic-publication-plans.cancel', $plan->refresh()))
        ->assertRedirect();

    expect($plan->refresh()->status)->toBe(ProgrammaticPublicationPlan::STATUS_CANCELLED);
});

it('schedules an approved publication plan into content publications without dispatching publish jobs', function (): void {
    Queue::fake();
    [$program, , , , , , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::INDUSTRY_PAGE);

    $count = app(GrowthProgramOrchestrator::class)->schedulePublicationPlan($program, $plan);
    $publication = ContentPublication::query()->firstOrFail();
    $item = $plan->items()->firstOrFail();

    expect($count)->toBe(1)
        ->and($publication->content_id)->toBe($item->content_id)
        ->and($publication->delivery_status)->toBe(ContentPublication::STATUS_PENDING)
        ->and($publication->remote_status)->toBe(ContentPublication::REMOTE_SCHEDULED)
        ->and($publication->scheduled_publish_at?->format('Y-m-d H:i:s'))->toBe($item->planned_publish_at?->format('Y-m-d H:i:s'))
        ->and(data_get($publication->meta, 'programmatic_publication_plan_id'))->toBe($plan->id)
        ->and(data_get($publication->meta, 'programmatic_publication_plan_item_id'))->toBe($item->id)
        ->and($item->refresh()->content_publication_id)->toBe($publication->id)
        ->and($item->refresh()->status)->toBe(ProgrammaticPublicationPlanItem::STATUS_SCHEDULED)
        ->and($plan->refresh()->status)->toBe(ProgrammaticPublicationPlan::STATUS_SCHEDULED)
        ->and($program->refresh()->status->value ?? $program->refresh()->status)->toBe(GrowthProgramStatus::SCHEDULED->value)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_PUBLICATION)->count())->toBe(1);

    Queue::assertNotPushed(PublishContentJob::class);
});

it('keeps scheduled publish dates queryable on content publications', function (): void {
    [$program, , , , , , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::INDUSTRY_PAGE);
    $item = $plan->items()->firstOrFail();

    app(GrowthProgramOrchestrator::class)->schedulePublicationPlan($program, $plan);

    expect(ContentPublication::query()
        ->scheduledForPublication()
        ->where('scheduled_publish_at', $item->planned_publish_at)
        ->count())->toBe(1);
});

it('does not downgrade delivered publications when scheduling finds an existing terminal record', function (): void {
    [$program, , , , $workspace, , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::COMPARISON_PAGE);
    $item = $plan->items()->firstOrFail();
    $destination = ContentDestination::query()->where('workspace_id', $workspace->id)->firstOrFail();
    $publication = ContentPublication::query()->create([
        'content_id' => $item->content_id,
        'destination_id' => $destination->id,
        'client_site_id' => null,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_id' => 'terminal-remote-id',
        'remote_type' => 'post',
        'remote_url' => 'https://growth.example.test/terminal',
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => now(),
        'meta' => [],
    ]);

    $count = app(GrowthProgramOrchestrator::class)->schedulePublicationPlan($program, $plan);
    $item->refresh();

    expect($count)->toBe(0)
        ->and($publication->fresh()->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED)
        ->and($publication->fresh()->remote_status)->toBe(ContentPublication::REMOTE_PUBLISHED)
        ->and($publication->fresh()->scheduled_publish_at)->toBeNull()
        ->and($item->status)->toBe(ProgrammaticPublicationPlanItem::STATUS_CONFLICT)
        ->and(data_get($item->metadata, 'conflict.reason'))->toBe('existing_publication_terminal');
});

it('blocks scheduling without an explicit destination in multi destination workspaces', function (): void {
    [$program, , , , $workspace, , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::FAQ_LIBRARY);
    $plan->forceFill(['destination_id' => null])->save();
    $plan->items()->update(['destination_id' => null]);

    ContentDestination::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Second Destination',
        'type' => 'api',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
    ]);

    $count = app(GrowthProgramOrchestrator::class)->schedulePublicationPlan($program, $plan);
    $item = $plan->items()->firstOrFail()->refresh();

    expect($count)->toBe(0)
        ->and(ContentPublication::query()->count())->toBe(0)
        ->and($item->status)->toBe(ProgrammaticPublicationPlanItem::STATUS_NEEDS_ATTENTION)
        ->and(data_get($item->metadata, 'conflict.reason'))->toBe('missing_destination')
        ->and(data_get($item->metadata, 'conflict.message'))->toBe('Choose a destination before scheduling this plan.');
});

it('allows exact one active destination as a safe fallback', function (): void {
    [$program, , , , , , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::ALTERNATIVE_PAGE);

    $count = app(GrowthProgramOrchestrator::class)->schedulePublicationPlan($program, $plan);

    expect($count)->toBe(1)
        ->and(ContentPublication::query()->whereNotNull('destination_id')->count())->toBe(1);
});

it('restores metadata only publication links onto the plan item foreign key', function (): void {
    [$program, , , , , , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::INDUSTRY_PAGE);
    $orchestrator = app(GrowthProgramOrchestrator::class);

    $orchestrator->schedulePublicationPlan($program, $plan);
    $item = $plan->items()->firstOrFail();
    $publicationId = $item->content_publication_id;
    $item->forceFill(['content_publication_id' => null])->save();

    $orchestrator->schedulePublicationPlan($program->refresh(), $plan->refresh());

    expect($item->fresh()->content_publication_id)->toBe($publicationId)
        ->and(data_get($item->fresh()->metadata, 'link_restored_at'))->not->toBeNull();
});

it('prevents the same content from being scheduled in another active plan for the same destination', function (): void {
    [$program, , , , , , , $readiness, $firstPlan] = approvedPublicationPlanFixture(ProgrammaticPatternType::COMPARISON_PAGE);
    $orchestrator = app(GrowthProgramOrchestrator::class);
    $orchestrator->schedulePublicationPlan($program, $firstPlan);

    $secondPlan = $orchestrator->createPublicationPlanFromReadiness($program->refresh(), $readiness->refresh(), [
        'planned_start_at' => '2026-07-02 09:00:00',
        'cadence' => ProgrammaticPublicationPlan::CADENCE_DAILY,
    ]);
    $secondPlan->approve();
    $orchestrator->attachPublicationPlan($program->refresh(), $secondPlan->refresh());

    $count = $orchestrator->schedulePublicationPlan($program->refresh(), $secondPlan->refresh());
    $conflicted = $secondPlan->items()->firstOrFail()->refresh();

    expect($count)->toBe(0)
        ->and($conflicted->status)->toBe(ProgrammaticPublicationPlanItem::STATUS_CONFLICT)
        ->and(data_get($conflicted->metadata, 'conflict.reason'))->toBe('content_already_scheduled_in_active_plan')
        ->and(data_get($conflicted->metadata, 'conflict.conflicting_plan_id'))->toBe($firstPlan->id);
});

it('cancels pending scheduled publications without touching delivered publications', function (): void {
    [$program, , , $user, $workspace, , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::ALTERNATIVE_PAGE);
    app(GrowthProgramOrchestrator::class)->schedulePublicationPlan($program, $plan);
    $pending = ContentPublication::query()->firstOrFail();

    $deliveredContent = Content::query()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Delivered content',
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    $delivered = ContentPublication::query()->create([
        'content_id' => $deliveredContent->id,
        'destination_id' => ContentDestination::query()->where('workspace_id', $workspace->id)->firstOrFail()->id,
        'client_site_id' => null,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_id' => 'delivered-remote-id',
        'remote_type' => 'post',
        'remote_url' => 'https://growth.example.test/delivered',
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => now(),
        'meta' => [],
    ]);

    $plan->cancel($user);

    expect($plan->fresh()->status)->toBe(ProgrammaticPublicationPlan::STATUS_CANCELLED)
        ->and(data_get($plan->fresh()->metadata, 'cancelled_by'))->toBe((string) $user->id)
        ->and($plan->items()->firstOrFail()->status)->toBe(ProgrammaticPublicationPlanItem::STATUS_CANCELLED)
        ->and($pending->fresh()->delivery_status)->toBe(ContentPublication::STATUS_CANCELLED)
        ->and($delivered->fresh()->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED)
        ->and($delivered->fresh()->remote_status)->toBe(ContentPublication::REMOTE_PUBLISHED);
});

it('refuses scheduling for non approved publication plans', function (): void {
    [, , , , , , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::FAQ_LIBRARY, approvePlan: false);

    app(ProgrammaticPublicationScheduler::class)->schedulePlan($plan);
})->throws(InvalidArgumentException::class, 'Only approved publication plans can be scheduled.');

it('refuses scheduling when publication readiness is not approved', function (): void {
    [$program, , , , , , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::COMPARISON_PAGE);
    $item = $plan->items()->firstOrFail();
    $item->readiness->forceFill(['status' => ProgrammaticPublicationReadiness::STATUS_NEEDS_WORK])->save();

    app(GrowthProgramOrchestrator::class)->schedulePublicationPlan($program, $plan->refresh());
})->throws(InvalidArgumentException::class, 'Publication plan item readiness must be approved before scheduling.');

it('keeps publication scheduling idempotent and restores growth asset links', function (): void {
    [$program, , , , , , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::ALTERNATIVE_PAGE);
    $orchestrator = app(GrowthProgramOrchestrator::class);

    $orchestrator->schedulePublicationPlan($program, $plan);
    $first = ContentPublication::query()->firstOrFail();
    GrowthAsset::query()->where('growth_program_id', $program->id)->where('role', GrowthAsset::ROLE_PUBLICATION)->delete();
    $orchestrator->schedulePublicationPlan($program->refresh(), $plan->refresh());

    expect(ContentPublication::query()->count())->toBe(1)
        ->and(ContentPublication::query()->first()->id)->toBe($first->id)
        ->and($program->assets()->where('role', GrowthAsset::ROLE_PUBLICATION)->count())->toBe(1);
});

it('schedules approved publication plans for growth programs and updates metrics', function (): void {
    [$program, , , , , , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::FAQ_LIBRARY, all: true);
    $expected = $plan->items()->count();

    $count = app(GrowthProgramOrchestrator::class)->scheduleApprovedPlansForProgram($program);

    expect($count)->toBe($expected)
        ->and(ContentPublication::query()->count())->toBe($expected)
        ->and($program->refresh()->metrics['scheduled_programmatic_publications_count'])->toBe($expected)
        ->and($program->metrics['pending_programmatic_publications_count'])->toBe($expected)
        ->and($program->metrics['scheduled_publication_window_start'])->not->toBeNull()
        ->and($program->metrics['scheduled_publication_window_end'])->not->toBeNull();
});

it('loads scheduled publication UI routes and linked publication state', function (): void {
    [$program, $cluster, , $user, $workspace, , , , $plan] = approvedPublicationPlanFixture(ProgrammaticPatternType::ALTERNATIVE_PAGE);
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $this->actingAs($user)
        ->post(route('app.programmatic-publication-plans.schedule', $plan))
        ->assertRedirect();

    $content = $plan->items()->firstOrFail()->content;

    $this->actingAs($user)
        ->get(route('app.programmatic-publication-plans.show', $plan->refresh()))
        ->assertOk()
        ->assertSee('scheduled')
        ->assertSee('pending');

    $this->actingAs($user)
        ->get(route('app.growth-programs.show', $program->refresh()))
        ->assertOk()
        ->assertSee('Prepare Scheduled Publications')
        ->assertSee('Scheduled assets');

    $this->actingAs($user)
        ->get(route('app.content.show', $content))
        ->assertOk()
        ->assertSee('Programmatic Publication');

    $this->actingAs($user)
        ->post(route('app.growth-programs.publication-plans.schedule', $program))
        ->assertRedirect();

    expect(ContentPublication::query()->count())->toBe(1);
});

it('supports programmatic cluster validate reject lifecycle and app routes', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'FAQ library source',
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, ProgrammaticPatternType::FAQ_LIBRARY);

    $this->actingAs($user)
        ->post(route('app.programmatic-clusters.build', $opportunity))
        ->assertRedirect();

    $cluster = ProgrammaticCluster::query()->firstOrFail();

    $this->actingAs($user)
        ->get(route('app.programmatic-clusters.index', ['workspace_id' => $workspace->id]))
        ->assertOk()
        ->assertSee('Programmatic Clusters')
        ->assertSee($cluster->name);

    $this->actingAs($user)
        ->get(route('app.programmatic-clusters.show', $cluster))
        ->assertOk()
        ->assertSee('Cluster Summary')
        ->assertSee('Item Preview');

    $this->actingAs($user)
        ->post(route('app.programmatic-clusters.validate', $cluster))
        ->assertRedirect();
    expect($cluster->refresh()->status)->toBe(ProgrammaticCluster::STATUS_VALIDATED);

    $this->actingAs($user)
        ->post(route('app.programmatic-clusters.reject', $cluster))
        ->assertRedirect();
    expect($cluster->refresh()->status)->toBe(ProgrammaticCluster::STATUS_REJECTED);

    expect(Brief::query()->count())->toBe(0)
        ->and(Draft::query()->count())->toBe(0)
        ->and(ContentPublication::query()->count())->toBe(0);
});

it('calculates programmatic growth time to value metrics', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Time to value'], $user);
    $program->forceFill(['created_at' => now()->subHours(3)])->save();

    foreach ([
        GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER => now()->subMinutes(150),
        GrowthAsset::ROLE_BRIEF_BLUEPRINT => now()->subMinutes(120),
        GrowthAsset::ROLE_BRIEF => now()->subMinutes(90),
        GrowthAsset::ROLE_DRAFT => now()->subMinutes(60),
        GrowthAsset::ROLE_CONTENT => now()->subMinutes(30),
        GrowthAsset::ROLE_PUBLICATION => now()->subMinutes(15),
    ] as $role => $createdAt) {
        $asset = GrowthAsset::query()->create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'growth_program_id' => $program->id,
            'role' => $role,
            'assetable_type' => GrowthProgram::class,
            'assetable_id' => $program->id,
            'status_at_link' => 'linked',
            'source_type' => 'beta_test',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $asset->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
    }

    $metrics = app(GrowthProgramBetaMetrics::class)->forProgram($program->refresh());

    expect($metrics['time_to_value']['first_cluster_minutes'])->toBe(30)
        ->and($metrics['time_to_value']['first_blueprint_minutes'])->toBe(60)
        ->and($metrics['time_to_value']['first_brief_minutes'])->toBe(90)
        ->and($metrics['time_to_value']['first_draft_minutes'])->toBe(120)
        ->and($metrics['time_to_value']['first_content_asset_minutes'])->toBe(150)
        ->and($metrics['time_to_value']['first_scheduled_publication_record_minutes'])->toBe(165);
});

it('calculates a growth program success score without live publishing', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Success score'], $user);
    $program->forceFill([
        'metrics' => [
            'programmatic_opportunities_count' => 1,
            'programmatic_clusters_count' => 1,
            'brief_blueprints_count' => 5,
            'programmatic_briefs_count' => 1,
            'generated_programmatic_drafts_count' => 1,
            'converted_content_count' => 1,
            'publication_readiness_count' => 1,
            'approved_publication_readiness_count' => 1,
            'publication_plans_count' => 1,
            'approved_publication_plan_items_count' => 1,
            'scheduled_programmatic_publications_count' => 1,
        ],
    ])->save();

    GrowthAsset::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'role' => GrowthAsset::ROLE_CONTENT,
        'assetable_type' => GrowthProgram::class,
        'assetable_id' => $program->id,
        'status_at_link' => 'linked',
        'source_type' => 'beta_test',
    ]);

    $metrics = app(GrowthProgramBetaMetrics::class)->forProgram($program->refresh());

    expect($metrics['success_score'])->toBe(100)
        ->and(ContentPublication::query()->count())->toBe(0);
});

it('stores growth program beta feedback', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Feedback program'], $user);

    $this->actingAs($user)
        ->post(route('app.growth-programs.feedback', $program), [
            'clarity' => 'somewhat',
            'step' => 'Blueprint',
            'message' => 'The next step needs clearer context.',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('growth_program_beta_events', [
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'user_id' => $user->id,
        'event_type' => GrowthProgramBetaEvent::TYPE_FEEDBACK,
        'clarity' => 'somewhat',
        'step' => 'Blueprint',
    ]);
});

it('shows internal beta tester mode only to admin roles', function (): void {
    [$organization, $workspace, $owner] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $viewer = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'viewer',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Beta mode program'], $owner);

    $this->actingAs($owner)
        ->post(route('app.programmatic-growth.internal-beta-mode'), ['enabled' => true])
        ->assertRedirect();

    $this->actingAs($owner)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertSee('Internal Beta Tester Mode')
        ->assertSee('docs/programmatic-growth-beta-test-checklist.md');

    $this->actingAs($viewer)
        ->get(route('app.growth-programs.show', $program))
        ->assertOk()
        ->assertDontSee('Internal Beta Tester Mode');

    $this->actingAs($viewer)
        ->post(route('app.programmatic-growth.internal-beta-mode'), ['enabled' => true])
        ->assertForbidden();
});

it('loads the programmatic growth beta report', function (): void {
    [$organization, $workspace, $user] = growthProgramFixture();
    config(['features.agentic_marketing' => true]);
    $this->withoutMiddleware([EnsureBillingOnboardingCompleted::class]);

    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Reportable program'], $user);
    $program->forceFill([
        'metrics' => [
            'programmatic_opportunities_count' => 1,
            'programmatic_clusters_count' => 1,
            'brief_blueprints_count' => 1,
        ],
    ])->save();

    GrowthProgramBetaEvent::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'user_id' => $user->id,
        'event_type' => GrowthProgramBetaEvent::TYPE_BLOCKED,
        'step' => 'Readiness',
        'message' => 'missing_destination',
        'metadata' => ['reason' => 'missing_destination'],
    ]);
    GrowthProgramBetaEvent::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'user_id' => $user->id,
        'event_type' => GrowthProgramBetaEvent::TYPE_CONFLICT,
        'step' => 'Plan',
        'message' => 'active_plan_conflict',
        'metadata' => ['reason' => 'active_plan_conflict'],
    ]);
    GrowthProgramBetaEvent::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'user_id' => $user->id,
        'event_type' => GrowthProgramBetaEvent::TYPE_FEEDBACK,
        'step' => 'Cluster',
        'clarity' => 'yes',
    ]);

    $this->actingAs($user)
        ->get(route('app.programmatic-growth.beta-report'))
        ->assertOk()
        ->assertSee('Programmatic Growth Beta Report')
        ->assertSee('Average Time To Value')
        ->assertSee('Missing Destination')
        ->assertSee('Active Plan Conflict')
        ->assertSee('Reportable program');
});

function growthProgramFixture(): array
{
    $organization = Organization::query()->create([
        'name' => 'Growth Program Test Organization',
        'slug' => 'growth-program-test-'.Str::random(8),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Growth Workspace',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Growth Site',
        'site_url' => 'https://growth.example.test',
        'base_url' => 'https://growth.example.test',
        'allowed_domains' => ['growth.example.test'],
        'is_active' => true,
    ]);

    ContentDestination::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Growth Laravel Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
    ]);

    return [$organization, $workspace, $user, $site];
}

function resolveGrowthProgramCommandCenter(GrowthProgram $program): array
{
    $program = app(GrowthProgramOrchestrator::class)->refreshMetrics($program->refresh());
    $program->load(['assets.assetable']);
    $program->assets
        ->where('role', GrowthAsset::ROLE_PUBLICATION_PLAN)
        ->each(fn ($asset) => $asset->assetable instanceof ProgrammaticPublicationPlan ? $asset->assetable->loadMissing('items.contentPublication') : null);

    return app(GrowthProgramNextActionResolver::class)->resolve($program);
}

function createCommandCenterCluster(Organization $organization, Workspace $workspace, GrowthProgram $program): ProgrammaticCluster
{
    $opportunity = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Command center opportunity',
    ]);
    $programmatic = makeProgrammaticOpportunity($organization, $workspace, $opportunity, ProgrammaticPatternType::COMPARISON_PAGE);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticOpportunity($program, $programmatic);

    $cluster = ProgrammaticCluster::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'programmatic_opportunity_id' => $programmatic->id,
        'name' => 'Command center cluster',
        'pattern_type' => ProgrammaticPatternType::COMPARISON_PAGE->value,
        'base_topic' => 'Command center',
        'variable_axis' => 'competitor',
        'status' => ProgrammaticCluster::STATUS_PREVIEW,
        'estimated_assets_count' => 1,
        'estimated_reach' => 1200,
        'estimated_ai_visibility' => 70,
        'estimated_business_impact' => 65,
    ]);

    ProgrammaticClusterItem::query()->create([
        'workspace_id' => $workspace->id,
        'programmatic_cluster_id' => $cluster->id,
        'variable_value' => 'Alternative',
        'title' => 'Command center alternative',
        'slug' => 'command-center-alternative',
        'asset_type' => 'comparison_page',
        'growth_asset_type' => GrowthAssetType::COMPARISON_PAGE->value,
        'intent' => 'comparison',
        'priority_score' => 80,
        'status' => ProgrammaticClusterItem::STATUS_ACCEPTED,
    ]);

    return $cluster->refresh();
}

function createCommandCenterBlueprint(Workspace $workspace, GrowthProgram $program, string $status): ProgrammaticBriefBlueprint
{
    $organization = Organization::query()->findOrFail($workspace->organization_id);
    $cluster = createCommandCenterCluster($organization, $workspace, $program);
    $item = $cluster->items()->firstOrFail();

    return ProgrammaticBriefBlueprint::query()->create([
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'programmatic_cluster_id' => $cluster->id,
        'programmatic_cluster_item_id' => $item->id,
        'growth_asset_type' => GrowthAssetType::COMPARISON_PAGE->value,
        'title' => 'Command center blueprint',
        'slug' => 'command-center-blueprint',
        'intent' => 'comparison',
        'primary_keyword' => 'command center blueprint',
        'outline' => ['Intro', 'Comparison'],
        'required_sections' => ['Overview'],
        'schema_recommendations' => ['FAQPage'],
        'seo_requirements' => ['title'],
        'ai_visibility_requirements' => ['answer block'],
        'quality_requirements' => ['specificity'],
        'status' => $status,
    ]);
}

function createCommandCenterBrief(Workspace $workspace, string $title = 'Command center brief'): Brief
{
    $site = ClientSite::query()->where('workspace_id', $workspace->id)->firstOrFail();

    return Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => $title,
        'primary_keyword' => Str::slug($title),
    ]);
}

function createCommandCenterDraftRequest(Workspace $workspace, GrowthProgram $program, string $status): ProgrammaticDraftRequest
{
    $brief = createCommandCenterBrief($workspace, 'Command center draft request brief '.Str::random(6));

    return ProgrammaticDraftRequest::query()->create([
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'brief_id' => $brief->id,
        'growth_asset_type' => GrowthAssetType::COMPARISON_PAGE->value,
        'title' => 'Command center draft request '.Str::random(6),
        'slug' => 'command-center-draft-request-'.Str::random(6),
        'priority_score' => 80,
        'estimated_cost' => 0.25,
        'estimated_tokens' => 2500,
        'status' => $status,
        'generation_mode' => ProgrammaticDraftRequest::MODE_SUPERVISED,
    ]);
}

function createCommandCenterDraftReview(Workspace $workspace, GrowthProgram $program, string $status): ProgrammaticDraftReview
{
    $site = ClientSite::query()->where('workspace_id', $workspace->id)->firstOrFail();
    $request = createCommandCenterDraftRequest($workspace, $program, ProgrammaticDraftRequest::STATUS_GENERATED);
    $draft = Draft::query()->create([
        'brief_id' => $request->brief_id,
        'client_site_id' => $site->id,
        'status' => Draft::STATUS_READY_FOR_REVIEW,
        'title' => 'Command center draft',
    ]);

    return ProgrammaticDraftReview::query()->create([
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'programmatic_draft_request_id' => $request->id,
        'draft_id' => $draft->id,
        'brief_id' => $request->brief_id,
        'growth_asset_type' => GrowthAssetType::COMPARISON_PAGE->value,
        'status' => $status,
        'overall_score' => 82,
        'risk_score' => $status === ProgrammaticDraftReview::STATUS_BLOCKED ? 90 : 20,
        'blocking_issues' => $status === ProgrammaticDraftReview::STATUS_BLOCKED ? ['Missing source evidence.'] : [],
    ]);
}

function createCommandCenterReadiness(Workspace $workspace, GrowthProgram $program, string $status): ProgrammaticPublicationReadiness
{
    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Command center content '.Str::random(6),
        'status' => 'review',
        'publish_status' => 'draft',
    ]);

    return ProgrammaticPublicationReadiness::query()->create([
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'content_id' => $content->id,
        'growth_asset_type' => GrowthAssetType::COMPARISON_PAGE->value,
        'status' => $status,
        'readiness_score' => $status === ProgrammaticPublicationReadiness::STATUS_BLOCKED ? 35 : 88,
        'publication_risk_score' => $status === ProgrammaticPublicationReadiness::STATUS_BLOCKED ? 80 : 15,
        'missing_requirements' => $status === ProgrammaticPublicationReadiness::STATUS_BLOCKED ? ['Destination is missing.'] : [],
    ]);
}

function createCommandCenterPlan(Workspace $workspace, GrowthProgram $program, ProgrammaticPublicationReadiness $readiness, string $status, ?string $conflictReason = null): ProgrammaticPublicationPlan
{
    $destination = ContentDestination::query()->where('workspace_id', $workspace->id)->first();
    $plan = ProgrammaticPublicationPlan::query()->create([
        'workspace_id' => $workspace->id,
        'growth_program_id' => $program->id,
        'name' => 'Command center publication plan',
        'status' => $status,
        'planned_start_at' => '2026-07-01 09:00:00',
        'planned_end_at' => '2026-07-01 09:00:00',
        'cadence' => ProgrammaticPublicationPlan::CADENCE_DAILY,
        'destination_id' => $destination?->id,
        'metadata' => [],
    ]);

    ProgrammaticPublicationPlanItem::query()->create([
        'workspace_id' => $workspace->id,
        'programmatic_publication_plan_id' => $plan->id,
        'content_id' => $readiness->content_id,
        'publication_readiness_id' => $readiness->id,
        'growth_asset_type' => GrowthAssetType::COMPARISON_PAGE->value,
        'title' => 'Command center plan item',
        'slug' => 'command-center-plan-item',
        'destination_id' => $destination?->id,
        'planned_publish_at' => '2026-07-01 09:00:00',
        'status' => $conflictReason ? ProgrammaticPublicationPlanItem::STATUS_CONFLICT : ProgrammaticPublicationPlanItem::STATUS_APPROVED,
        'metadata' => $conflictReason ? ['conflict' => ['reason' => $conflictReason]] : [],
    ]);

    return $plan->refreshCounters();
}

function makeProgrammaticOpportunity(
    Organization $organization,
    Workspace $workspace,
    Opportunity $source,
    ProgrammaticPatternType $pattern,
): ProgrammaticOpportunity {
    return ProgrammaticOpportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'source_type' => $source->getMorphClass(),
        'source_id' => $source->id,
        'pattern_type' => $pattern->value,
        'base_topic' => match ($pattern) {
            ProgrammaticPatternType::ALTERNATIVE_PAGE => 'Argusly',
            ProgrammaticPatternType::COMPARISON_PAGE => 'Argusly',
            ProgrammaticPatternType::FAQ_LIBRARY => 'AI visibility',
            default => 'Content operations software',
        },
        'variable_axis' => match ($pattern) {
            ProgrammaticPatternType::INDUSTRY_PAGE => 'industry',
            ProgrammaticPatternType::LOCATION_PAGE => 'location',
            ProgrammaticPatternType::ALTERNATIVE_PAGE => 'alternative',
            ProgrammaticPatternType::COMPARISON_PAGE => 'competitor_or_product',
            ProgrammaticPatternType::FAQ_LIBRARY => 'question',
            default => 'variable',
        },
        'example_variables' => match ($pattern) {
            ProgrammaticPatternType::LOCATION_PAGE => ['Amsterdam', 'Rotterdam'],
            ProgrammaticPatternType::ALTERNATIVE_PAGE => ['Contentful', 'Webflow'],
            ProgrammaticPatternType::COMPARISON_PAGE => ['Contentful', 'Webflow'],
            ProgrammaticPatternType::FAQ_LIBRARY => ['wat is het', 'hoe werkt het'],
            default => ['healthcare', 'finance'],
        },
        'estimated_variants_count' => 10,
        'scale_score' => 70,
        'business_value_score' => 65,
        'seo_opportunity_score' => 68,
        'ai_visibility_score' => 72,
        'competition_score' => 40,
        'confidence_score' => 80,
        'status' => ProgrammaticOpportunity::STATUS_VALIDATED,
        'detected_at' => now(),
    ]);
}

function convertedBlueprintFixture(ProgrammaticPatternType $pattern, bool $convertAll = false): array
{
    [$organization, $workspace, $user] = growthProgramFixture();
    $source = Opportunity::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'title' => 'Converted blueprint fixture '.$pattern->value,
    ]);
    $opportunity = makeProgrammaticOpportunity($organization, $workspace, $source, $pattern);
    $cluster = app(ProgrammaticClusterBuilder::class)->build($opportunity);
    $program = app(GrowthProgramOrchestrator::class)->create($workspace, ['name' => 'Draft request fixture'], $user);
    app(GrowthProgramOrchestrator::class)->attachProgrammaticCluster($program, $cluster);
    app(GrowthProgramOrchestrator::class)->buildBriefBlueprintsForCluster($program, $cluster);
    ProgrammaticBriefBlueprint::query()->where('growth_program_id', $program->id)->update(['status' => ProgrammaticBriefBlueprint::STATUS_APPROVED]);

    if ($convertAll) {
        app(GrowthProgramOrchestrator::class)->convertApprovedBlueprintsForProgram($program);
    } else {
        $blueprint = ProgrammaticBriefBlueprint::query()->where('growth_program_id', $program->id)->firstOrFail();
        app(ProgrammaticBriefConverter::class)->convertBlueprint($blueprint);
        app(GrowthProgramOrchestrator::class)->attachConvertedBrief($program, $blueprint->refresh(), $blueprint->linkedBrief());
    }

    $blueprint = ProgrammaticBriefBlueprint::query()->where('growth_program_id', $program->id)->firstOrFail();

    return [$program->refresh(), $cluster->refresh(), $blueprint->refresh(), $user, $workspace];
}

function approvedDraftRequestFixture(ProgrammaticPatternType $pattern, bool $prepareAll = false): array
{
    [$program, $cluster, $blueprint, $user, $workspace] = convertedBlueprintFixture($pattern, convertAll: true);

    if ($prepareAll) {
        app(GrowthProgramOrchestrator::class)->prepareDraftRequestsForProgram($program);
        ProgrammaticDraftRequest::query()->where('growth_program_id', $program->id)->update(['status' => ProgrammaticDraftRequest::STATUS_APPROVED]);
    } else {
        $draftRequest = app(GrowthProgramOrchestrator::class)->prepareDraftRequestForBlueprint($program, $blueprint);
        $draftRequest->approve();
    }

    $draftRequest = ProgrammaticDraftRequest::query()->where('growth_program_id', $program->id)->firstOrFail();

    return [$program->refresh(), $cluster->refresh(), $blueprint->refresh(), $user, $workspace, $draftRequest->refresh()];
}

function generatedDraftRequestFixture(ProgrammaticPatternType $pattern): array
{
    [$program, $cluster, $blueprint, $user, $workspace, $draftRequest] = approvedDraftRequestFixture($pattern);
    app(GrowthProgramOrchestrator::class)->generateDraftForRequest($program, $draftRequest);

    return [$program->refresh(), $cluster->refresh(), $blueprint->refresh(), $user, $workspace, $draftRequest->refresh()];
}

function draftReviewFixture(ProgrammaticPatternType $pattern): array
{
    [$program, $cluster, $blueprint, $user, $workspace, $draftRequest] = generatedDraftRequestFixture($pattern);
    $draft = $draftRequest->linkedDraft();
    $draft->forceFill([
        'content_html' => '<h1>'.$draft->title.'</h1><p>Programmatic draft body with enough context for editorial review and conversion.</p><h2>FAQ</h2><p>Helpful answer.</p>',
        'seo_meta_description' => 'Programmatic draft meta description for conversion.',
    ])->save();

    $review = app(GrowthProgramOrchestrator::class)->reviewDraftRequest($program, $draftRequest->refresh());

    return [$program->refresh(), $cluster->refresh(), $blueprint->refresh(), $user, $workspace, $draftRequest->refresh(), $review->refresh()];
}

function approvedDraftReviewFixture(ProgrammaticPatternType $pattern, bool $reviewAll = false): array
{
    if (! $reviewAll) {
        [$program, $cluster, $blueprint, $user, $workspace, $draftRequest, $review] = draftReviewFixture($pattern);
        $review->approve($user, override: true);

        return [$program->refresh(), $cluster->refresh(), $blueprint->refresh(), $user, $workspace, $draftRequest->refresh(), $review->refresh()];
    }

    [$program, $cluster, $blueprint, $user, $workspace] = approvedDraftRequestFixture($pattern, prepareAll: true);

    ProgrammaticDraftRequest::query()
        ->where('growth_program_id', $program->id)
        ->get()
        ->each(function (ProgrammaticDraftRequest $draftRequest) use ($program, $user): void {
            app(GrowthProgramOrchestrator::class)->generateDraftForRequest($program, $draftRequest);
            $draft = $draftRequest->refresh()->linkedDraft();
            $draft?->forceFill([
                'content_html' => '<h1>'.$draft->title.'</h1><p>Programmatic draft body with enough context for editorial review and conversion.</p><h2>FAQ</h2><p>Helpful answer.</p>',
                'seo_meta_description' => 'Programmatic draft meta description for conversion.',
            ])->save();
            $review = app(GrowthProgramOrchestrator::class)->reviewDraftRequest($program, $draftRequest->refresh());
            $review->approve($user, override: true);
        });

    $review = ProgrammaticDraftReview::query()->where('growth_program_id', $program->id)->firstOrFail();
    $draftRequest = ProgrammaticDraftRequest::query()->where('growth_program_id', $program->id)->firstOrFail();

    return [$program->refresh(), $cluster->refresh(), $blueprint->refresh(), $user, $workspace, $draftRequest->refresh(), $review->refresh()];
}

function approvedPublicationReadinessFixture(ProgrammaticPatternType $pattern, bool $readinessAll = false): array
{
    if (! $readinessAll) {
        [$program, $cluster, $blueprint, $user, $workspace, $draftRequest, $review] = approvedDraftReviewFixture($pattern);
        $content = app(GrowthProgramOrchestrator::class)->convertReviewToContent($program, $review, createdByUserId: $user->id);
        $readiness = app(GrowthProgramOrchestrator::class)->runPublicationReadinessForContent($program->refresh(), $content);
        $readiness->approve($user, override: true);
        app(GrowthProgramOrchestrator::class)->attachPublicationReadiness($program->refresh(), $readiness->refresh());

        return [$program->refresh(), $cluster->refresh(), $blueprint->refresh(), $user, $workspace, $draftRequest->refresh(), $review->refresh(), $readiness->refresh()];
    }

    [$program, $cluster, $blueprint, $user, $workspace, $draftRequest, $review] = approvedDraftReviewFixture($pattern, reviewAll: true);
    app(GrowthProgramOrchestrator::class)->convertApprovedReviewsForProgram($program, createdByUserId: $user->id);
    app(GrowthProgramOrchestrator::class)->runPublicationReadinessForProgram($program->refresh());

    ProgrammaticPublicationReadiness::query()
        ->where('growth_program_id', $program->id)
        ->get()
        ->each(function (ProgrammaticPublicationReadiness $readiness) use ($program, $user): void {
            $readiness->approve($user, override: true);
            app(GrowthProgramOrchestrator::class)->attachPublicationReadiness($program->refresh(), $readiness->refresh());
        });

    $readiness = ProgrammaticPublicationReadiness::query()->where('growth_program_id', $program->id)->firstOrFail();

    return [$program->refresh(), $cluster->refresh(), $blueprint->refresh(), $user, $workspace, $draftRequest->refresh(), $review->refresh(), $readiness->refresh()];
}

function approvedPublicationPlanFixture(ProgrammaticPatternType $pattern, bool $approvePlan = true, bool $all = false): array
{
    if (! $all) {
        [$program, $cluster, $blueprint, $user, $workspace, $draftRequest, $review, $readiness] = approvedPublicationReadinessFixture($pattern);
        $plan = app(GrowthProgramOrchestrator::class)->createPublicationPlanFromReadiness($program, $readiness, [
            'planned_start_at' => '2026-07-01 09:00:00',
            'cadence' => ProgrammaticPublicationPlan::CADENCE_DAILY,
        ]);
        if ($approvePlan) {
            $plan->approve();
        }
        app(GrowthProgramOrchestrator::class)->attachPublicationPlan($program->refresh(), $plan->refresh());

        return [$program->refresh(), $cluster->refresh(), $blueprint->refresh(), $user, $workspace, $draftRequest->refresh(), $review->refresh(), $readiness->refresh(), $plan->refresh()];
    }

    [$program, $cluster, $blueprint, $user, $workspace, $draftRequest, $review, $readiness] = approvedPublicationReadinessFixture($pattern, readinessAll: true);
    $plan = app(GrowthProgramOrchestrator::class)->createPublicationPlanForProgram($program, [
        'planned_start_at' => '2026-07-01 09:00:00',
        'cadence' => ProgrammaticPublicationPlan::CADENCE_DAILY,
    ]);
    if ($approvePlan) {
        $plan->approve();
    }
    app(GrowthProgramOrchestrator::class)->attachPublicationPlan($program->refresh(), $plan->refresh());

    return [$program->refresh(), $cluster->refresh(), $blueprint->refresh(), $user, $workspace, $draftRequest->refresh(), $review->refresh(), $readiness->refresh(), $plan->refresh()];
}
