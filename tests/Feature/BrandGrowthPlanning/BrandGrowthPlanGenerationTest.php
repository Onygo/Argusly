<?php

use App\Enums\BrandGrowthPlanReviewState;
use App\Enums\BrandGrowthPlanStatus;
use App\Enums\OpportunitySignalSource;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\BrandGrowthAudienceProposal;
use App\Models\BrandGrowthPlan;
use App\Models\BrandGrowthPlanFinding;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\Content;
use App\Models\Draft;
use App\Models\LlmTrackingQuery;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\Organization;
use App\Models\PageBrandMatch;
use App\Models\PageCompetitorMatch;
use App\Models\PageGeoObservation;
use App\Models\PageSerpObservation;
use App\Models\Persona;
use App\Models\SignalDetection;
use App\Models\SiteCompetitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BrandGrowthPlanning\BrandGrowthPlanGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('features.agentic_marketing', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function brandGrowthContext(string $slug, string $role = 'owner'): array
{
    $organization = Organization::query()->create([
        'name' => 'Brand Growth '.$slug,
        'slug' => 'brand-growth-'.$slug.'-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Brand Growth Workspace '.$slug,
        'display_name' => 'Brand Growth Workspace '.$slug,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => $role,
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Brand Growth Site '.$slug,
        'site_url' => 'https://'.$slug.'.test',
        'base_url' => 'https://'.$slug.'.test',
        'allowed_domains' => [$slug.'.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    CompanyIntelligenceProfile::factory()->default()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'brand_key' => 'primary-'.$slug,
        'company_name' => 'Argusly '.$slug,
        'market_category' => 'B2B SaaS',
        'icps' => ['B2B SaaS marketing teams', 'AI visibility teams'],
        'personas' => ['Head of Marketing', 'Content Lead'],
        'buyer_roles' => ['CMO', 'Technical evaluator'],
        'primary_topics' => ['agentic brand growth', 'AI visibility'],
        'authority_areas' => ['Brand strategy', 'Content operations'],
        'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
        'is_default' => true,
    ]);

    Content::factory()->forWorkspace($workspace)->create([
        'title' => 'Agentic marketing operations guide',
        'primary_keyword' => 'agentic marketing',
    ]);

    SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Visible Rival '.$slug,
        'domain' => $slug.'-rival.test',
        'is_active' => true,
    ]);

    SignalDetection::factory()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'AI visibility signal '.$slug,
        'primary_topic' => 'AI visibility',
        'primary_entity' => 'Argusly '.$slug,
        'priority_score' => 86,
        'confidence_score' => 82,
        'opportunity_score' => 80,
    ]);

    LlmTrackingQuery::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'AI visibility query '.$slug,
        'query_text' => 'Best agentic brand growth platform',
        'target_brand' => 'Argusly '.$slug,
        'target_domain' => $slug.'.test',
        'brand_terms' => ['Argusly '.$slug],
        'competitor_terms' => ['Visible Rival '.$slug],
        'target_urls' => ['https://'.$slug.'.test'],
        'tags' => ['AI visibility'],
        'locale' => 'en',
        'frequency' => 'weekly',
        'priority' => 80,
        'is_active' => true,
    ]);

    return compact('organization', 'workspace', 'user', 'site');
}

it('generates a versioned draft plan with findings, audience proposals and evidence context', function (): void {
    $context = brandGrowthContext('generate');

    $plan = app(BrandGrowthPlanGenerator::class)->generate($context['workspace'], $context['user'], [
        'business_objective' => 'Win more AI visibility programs',
        'client_site_id' => $context['site']->id,
    ]);

    expect($plan->version)->toBe(1)
        ->and($plan->status->value)->toBe('draft')
        ->and($plan->findings)->not->toBeEmpty()
        ->and($plan->audienceProposals)->not->toBeEmpty()
        ->and($plan->context_snapshot['available_sources']['brand_intelligence'])->toBeTrue()
        ->and($plan->missing_information)->toContain('No approved personas are available.');

    $this->assertDatabaseHas('brand_growth_plan_findings', [
        'brand_growth_plan_id' => $plan->id,
        'review_state' => 'pending',
    ]);
});

it('increments versions and supersedes older unapproved drafts', function (): void {
    $context = brandGrowthContext('versions');
    $generator = app(BrandGrowthPlanGenerator::class);

    $first = $generator->generate($context['workspace'], $context['user'], [
        'business_objective' => 'First strategic objective',
    ]);
    $second = $generator->generate($context['workspace'], $context['user'], [
        'business_objective' => 'Second strategic objective',
    ]);

    expect($second->version)->toBe(2)
        ->and($first->refresh()->status->value)->toBe('superseded')
        ->and($second->supersedes_plan_id)->toBe($first->id);
});

it('keeps one approved Brand Growth Plan baseline per workspace', function (): void {
    $context = brandGrowthContext('baseline');
    $generator = app(BrandGrowthPlanGenerator::class);

    $first = $generator->generate($context['workspace'], $context['user'], [
        'business_objective' => 'First approved baseline',
    ]);

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.approve', ['plan' => $first->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    $second = $generator->generate($context['workspace'], $context['user'], [
        'business_objective' => 'Second approved baseline',
    ]);

    expect($first->refresh()->status)->toBe(BrandGrowthPlanStatus::APPROVED)
        ->and($second->status)->toBe(BrandGrowthPlanStatus::DRAFT);

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.approve', ['plan' => $second->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($first->refresh()->status)->toBe(BrandGrowthPlanStatus::SUPERSEDED)
        ->and($second->refresh()->status)->toBe(BrandGrowthPlanStatus::APPROVED)
        ->and(BrandGrowthPlan::query()->where('workspace_id', $context['workspace']->id)->where('status', BrandGrowthPlanStatus::APPROVED->value)->count())->toBe(1);

    $this->actingAs($context['user'])
        ->get(route('app.agentic-marketing.brand-growth-plans.show', ['plan' => $first->id, 'workspace_id' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('superseded baseline');

    $this->actingAs($context['user'])
        ->get(route('app.agentic-marketing.brand-growth-plans.show', ['plan' => $second->id, 'workspace_id' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('current baseline');
});

it('shows only plans for the current user workspace', function (): void {
    $own = brandGrowthContext('own');
    $other = brandGrowthContext('other');

    app(BrandGrowthPlanGenerator::class)->generate($own['workspace'], $own['user'], [
        'business_objective' => 'Own strategic objective',
    ]);
    app(BrandGrowthPlanGenerator::class)->generate($other['workspace'], $other['user'], [
        'business_objective' => 'Other strategic objective',
    ]);

    $this->actingAs($own['user'])
        ->get(route('app.agentic-marketing.brand-growth-plans.index', ['workspace_id' => $own['workspace']->id]))
        ->assertOk()
        ->assertSee('Own strategic objective')
        ->assertDontSee('Other strategic objective');
});

it('approves and promotes an approved finding into existing opportunities without duplicates', function (): void {
    $context = brandGrowthContext('promote');
    $plan = app(BrandGrowthPlanGenerator::class)->generate($context['workspace'], $context['user'], [
        'business_objective' => 'Promote strategic findings',
    ]);
    $finding = $plan->findings()->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-findings.approve', ['finding' => $finding->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-findings.promote', ['finding' => $finding->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-findings.promote', ['finding' => $finding->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    expect(Opportunity::query()->where('workspace_id', $context['workspace']->id)->count())->toBe(1)
        ->and($finding->refresh()->review_state)->toBe(BrandGrowthPlanReviewState::APPROVED)
        ->and($finding->opportunity_id)->not->toBeNull();

    $this->assertDatabaseHas('opportunity_signals', [
        'workspace_id' => $context['workspace']->id,
        'source' => OpportunitySignalSource::BRAND_GROWTH_PLAN->value,
    ]);
});

it('promotes approved inferred audiences into canonical personas without duplicates', function (): void {
    $context = brandGrowthContext('audience-promote');
    $plan = app(BrandGrowthPlanGenerator::class)->generate($context['workspace'], $context['user'], [
        'business_objective' => 'Promote reviewed audience strategy',
    ]);
    $proposal = $plan->audienceProposals()->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-audiences.approve', ['proposal' => $proposal->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-audiences.promote', ['proposal' => $proposal->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-audiences.promote', ['proposal' => $proposal->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    expect(Persona::query()->where('organization_id', $context['organization']->id)->count())->toBe(1)
        ->and($proposal->refresh()->persona_id)->not->toBeNull()
        ->and($proposal->review_state)->toBe(BrandGrowthPlanReviewState::APPROVED);

    $persona = Persona::query()->whereKey($proposal->persona_id)->firstOrFail();

    expect($persona->status)->toBe(Persona::STATUS_APPROVED)
        ->and($persona->source_type)->toBe('brand_growth_plan')
        ->and(data_get($persona->profile_data, 'brand_growth.audience_proposal_id'))->toBe((string) $proposal->id);
});

it('promotes approved plan findings and audiences in bulk without duplicates', function (): void {
    $context = brandGrowthContext('bulk-promote');
    $plan = app(BrandGrowthPlanGenerator::class)->generate($context['workspace'], $context['user'], [
        'business_objective' => 'Bulk promote reviewed strategic plan items',
    ]);
    $findings = $plan->findings()->limit(2)->get();
    $proposal = $plan->audienceProposals()->firstOrFail();

    expect($findings)->toHaveCount(2);

    $findings->each(fn (BrandGrowthPlanFinding $finding) => $finding->forceFill([
        'review_state' => BrandGrowthPlanReviewState::APPROVED->value,
        'reviewed_by' => $context['user']->id,
        'reviewed_at' => now(),
    ])->save());
    $proposal->forceFill([
        'review_state' => BrandGrowthPlanReviewState::APPROVED->value,
        'reviewed_by' => $context['user']->id,
        'reviewed_at' => now(),
    ])->save();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.promote-approved', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    $findings->each(fn (BrandGrowthPlanFinding $finding) => expect($finding->refresh()->opportunity_id)->not->toBeNull());
    expect($proposal->refresh()->persona_id)->not->toBeNull();

    $opportunityCount = Opportunity::query()->where('workspace_id', $context['workspace']->id)->count();
    $personaCount = Persona::query()->where('organization_id', $context['organization']->id)->count();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.promote-approved', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(Opportunity::query()->where('workspace_id', $context['workspace']->id)->count())->toBe($opportunityCount)
        ->and(Persona::query()->where('organization_id', $context['organization']->id)->count())->toBe($personaCount);
});

it('creates execution recommendations for promoted brand growth opportunities without duplicates', function (): void {
    $context = brandGrowthContext('execution-recommendations');
    $plan = app(BrandGrowthPlanGenerator::class)->generate($context['workspace'], $context['user'], [
        'business_objective' => 'Turn approved Brand Growth findings into execution recommendations',
    ]);
    $finding = $plan->findings()->firstOrFail();

    $finding->forceFill([
        'review_state' => BrandGrowthPlanReviewState::APPROVED->value,
        'reviewed_by' => $context['user']->id,
        'reviewed_at' => now(),
    ])->save();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.promote-approved', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    $opportunity = Opportunity::query()->whereKey($finding->refresh()->opportunity_id)->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.execution-recommendations.create', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    $executionPlan = OpportunityExecutionPlan::query()
        ->where('workspace_id', $context['workspace']->id)
        ->where('opportunity_id', $opportunity->id)
        ->firstOrFail();

    expect($opportunity->refresh()->status->value)->toBe('reviewing')
        ->and($executionPlan->status)->toBe(OpportunityExecutionPlan::STATUS_DRAFT)
        ->and(data_get($executionPlan->metadata, 'brand_growth_planning.brand_growth_plan_id'))->toBe((string) $plan->id)
        ->and(data_get($executionPlan->metadata, 'brand_growth_planning.brand_growth_plan_finding_ids'))->toContain((string) $finding->id)
        ->and(data_get($executionPlan->source_evidence, 'brand_growth_plan.findings.0.id'))->toBe((string) $finding->id);

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.execution-recommendations.create', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(OpportunityExecutionPlan::query()
        ->where('workspace_id', $context['workspace']->id)
        ->where('opportunity_id', $opportunity->id)
        ->count())->toBe(1);
});

it('creates content briefs from approved brand growth execution recommendations without duplicates', function (): void {
    $context = brandGrowthContext('content-briefs');
    $plan = app(BrandGrowthPlanGenerator::class)->generate($context['workspace'], $context['user'], [
        'business_objective' => 'Turn Brand Growth execution recommendations into content briefs',
    ]);
    $finding = $plan->findings()->firstOrFail();

    $finding->forceFill([
        'review_state' => BrandGrowthPlanReviewState::APPROVED->value,
        'reviewed_by' => $context['user']->id,
        'reviewed_at' => now(),
    ])->save();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.promote-approved', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.execution-recommendations.create', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    $executionPlan = OpportunityExecutionPlan::query()
        ->where('workspace_id', $context['workspace']->id)
        ->where('opportunity_id', $finding->refresh()->opportunity_id)
        ->firstOrFail();

    $executionPlan->forceFill(['status' => OpportunityExecutionPlan::STATUS_APPROVED])->save();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.content-briefs.create', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    $brief = Brief::query()->where('source', 'opportunity_execution_plan')->firstOrFail();
    $executionPlan->refresh();

    expect($brief->client_refs['execution_plan_id'])->toBe((string) $executionPlan->id)
        ->and($brief->client_refs['brand_growth_plan_id'])->toBe((string) $plan->id)
        ->and($brief->client_refs['brand_growth_plan_finding_ids'])->toContain((string) $finding->id)
        ->and(data_get($brief->client_refs, 'brand_growth_plan.findings.0.id'))->toBe((string) $finding->id)
        ->and(data_get($executionPlan->metadata, 'brief_id'))->toBe((string) $brief->id);

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.content-briefs.create', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(Brief::query()->where('source', 'opportunity_execution_plan')->count())->toBe(1);

    $this->actingAs($context['user'])
        ->get(route('app.agentic-marketing.brand-growth-plans.show', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('Open brief');
});

it('creates first drafts from brand growth content briefs without duplicates', function (): void {
    $context = brandGrowthContext('first-drafts');
    $plan = app(BrandGrowthPlanGenerator::class)->generate($context['workspace'], $context['user'], [
        'business_objective' => 'Turn Brand Growth briefs into governed first drafts',
    ]);
    $finding = $plan->findings()->firstOrFail();

    $finding->forceFill([
        'review_state' => BrandGrowthPlanReviewState::APPROVED->value,
        'reviewed_by' => $context['user']->id,
        'reviewed_at' => now(),
    ])->save();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.promote-approved', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.execution-recommendations.create', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    $executionPlan = OpportunityExecutionPlan::query()
        ->where('workspace_id', $context['workspace']->id)
        ->where('opportunity_id', $finding->refresh()->opportunity_id)
        ->firstOrFail();

    $executionPlan->forceFill(['status' => OpportunityExecutionPlan::STATUS_APPROVED])->save();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.content-briefs.create', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    $brief = Brief::query()->where('source', 'opportunity_execution_plan')->firstOrFail();

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.drafts.create', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    $draft = Draft::query()->where('brief_id', $brief->id)->firstOrFail();
    $brief->refresh();

    expect($draft->status)->toBe('draft')
        ->and($draft->meta['source_context']['brief_id'])->toBe((string) $brief->id)
        ->and($draft->meta['source_context']['brand_growth_plan_id'])->toBe((string) $plan->id)
        ->and($draft->meta['source_context']['brand_growth_plan_finding_ids'])->toContain((string) $finding->id)
        ->and(data_get($draft->meta, 'source_context.brand_growth_plan.findings.0.id'))->toBe((string) $finding->id)
        ->and($brief->client_refs['draft_id'])->toBe((string) $draft->id);

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.drafts.create', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(Draft::query()->where('brief_id', $brief->id)->count())->toBe(1);

    $this->actingAs($context['user'])
        ->get(route('app.agentic-marketing.brand-growth-plans.show', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('Open draft');
});

it('shows version changes against the superseded plan', function (): void {
    $context = brandGrowthContext('version-diff');

    $baseline = BrandGrowthPlan::factory()->forWorkspace($context['workspace'])->create([
        'version' => 1,
        'business_objective' => 'Baseline objective',
        'messaging_priorities' => ['Keep proof assets'],
        'missing_information' => ['No CRM data'],
    ]);

    $current = BrandGrowthPlan::factory()->forWorkspace($context['workspace'])->create([
        'version' => 2,
        'supersedes_plan_id' => $baseline->id,
        'business_objective' => 'Updated objective',
        'messaging_priorities' => ['Keep proof assets', 'Add executive narrative'],
        'missing_information' => ['No AI overview samples'],
        'confidence_score' => 76,
    ]);

    $createFinding = function (BrandGrowthPlan $plan, array $attributes = []): BrandGrowthPlanFinding {
        return BrandGrowthPlanFinding::query()->create(array_merge([
            'organization_id' => $plan->organization_id,
            'workspace_id' => $plan->workspace_id,
            'brand_growth_plan_id' => $plan->id,
            'type' => 'content_gap',
            'status' => BrandGrowthPlanFinding::STATUS_ACTIVE,
            'review_state' => 'pending',
            'title' => 'Proof-led content is missing',
            'description' => 'Owned content does not show enough evidence-led assets.',
            'rationale' => 'Credibility improves when buyers can inspect proof.',
            'impact_score' => 80,
            'urgency_score' => 64,
            'confidence_score' => 72,
            'recommended_action' => 'Create one proof-led decision-stage asset.',
            'source_references' => [],
            'source_summary' => [],
            'metadata_json' => ['test' => true],
            'dedupe_hash' => hash('sha256', (string) Str::uuid()),
        ], $attributes));
    };

    $createAudience = function (BrandGrowthPlan $plan, array $attributes = []): BrandGrowthAudienceProposal {
        return BrandGrowthAudienceProposal::query()->create(array_merge([
            'organization_id' => $plan->organization_id,
            'workspace_id' => $plan->workspace_id,
            'brand_growth_plan_id' => $plan->id,
            'proposal_type' => 'audience',
            'source_type' => 'inferred',
            'review_state' => 'pending',
            'name' => 'B2B SaaS marketing leaders',
            'role' => 'Head of Marketing',
            'industry' => 'B2B SaaS',
            'goals' => ['Increase qualified demand'],
            'pain_points' => ['Weak AI visibility'],
            'kpis' => ['Pipeline influenced'],
            'buying_committee_role' => 'Economic buyer',
            'confidence_score' => 72,
            'source_references' => [],
            'metadata_json' => ['test' => true],
            'dedupe_hash' => hash('sha256', (string) Str::uuid()),
        ], $attributes));
    };

    $createFinding($baseline, [
        'title' => 'Legacy channel gap',
        'impact_score' => 58,
        'dedupe_hash' => 'removed-channel-gap',
    ]);
    $createFinding($baseline, [
        'title' => 'Proof-led content is missing',
        'impact_score' => 60,
        'dedupe_hash' => 'shared-proof-gap',
    ]);
    $createFinding($current, [
        'title' => 'Proof-led content is missing',
        'impact_score' => 90,
        'dedupe_hash' => 'shared-proof-gap',
    ]);
    $createFinding($current, [
        'title' => 'Executive narrative is missing',
        'impact_score' => 92,
        'dedupe_hash' => 'new-executive-narrative',
    ]);

    $createAudience($baseline, [
        'name' => 'Legacy newsletter audience',
        'dedupe_hash' => 'removed-newsletter-audience',
    ]);
    $createAudience($current, [
        'name' => 'Enterprise AI visibility buyers',
        'dedupe_hash' => 'new-ai-visibility-buyers',
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.agentic-marketing.brand-growth-plans.show', ['plan' => $current->id, 'workspace_id' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('Version Changes')
        ->assertSee('Compared with v1')
        ->assertSee('Business objective')
        ->assertSee('Executive narrative is missing')
        ->assertSee('Legacy channel gap')
        ->assertSee('Enterprise AI visibility buyers')
        ->assertSee('new in v2')
        ->assertSee('updated in v2');
});

it('uses Page Intelligence observations as evidence for strategic findings', function (): void {
    $context = brandGrowthContext('page-intelligence-evidence');
    $workspace = $context['workspace'];
    $site = $context['site'];
    $query = LlmTrackingQuery::query()->where('workspace_id', $workspace->id)->firstOrFail();
    $competitor = SiteCompetitor::query()->where('workspace_id', $workspace->id)->firstOrFail();

    $source = MonitoredSource::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'source_type' => 'serp',
        'name' => 'Strategic SERP source',
        'base_url' => $site->base_url,
        'domain' => parse_url($site->base_url, PHP_URL_HOST),
        'status' => MonitoredSource::STATUS_ACTIVE,
        'trust_level' => 3,
        'authority_score' => 60,
        'polling_frequency' => 'weekly',
        'crawl_policy_json' => [],
        'fetch_config_json' => [],
        'discovery_config_json' => [],
        'metadata_json' => ['test' => true],
        'failure_count' => 0,
    ]);
    $pageUrl = $site->base_url.'/ai-visibility-proof';
    $page = MonitoredPage::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'monitored_source_id' => $source->id,
        'canonical_url' => $pageUrl,
        'canonical_url_hash' => hash('sha256', $pageUrl),
        'first_seen_url' => $pageUrl,
        'first_seen_url_hash' => hash('sha256', $pageUrl),
        'final_url' => $pageUrl,
        'final_url_hash' => hash('sha256', $pageUrl),
        'domain' => parse_url($pageUrl, PHP_URL_HOST),
        'path' => '/ai-visibility-proof',
        'source_type' => 'serp',
        'page_type' => 'article',
        'content_type' => 'text/html',
        'publisher_name' => 'Argusly',
        'language_current' => 'en',
        'title_current' => 'AI visibility proof',
        'first_seen_at' => now()->subDays(2),
        'last_seen_at' => now()->subDay(),
        'last_fetched_at' => now()->subDay(),
        'last_changed_at' => now()->subDay(),
        'crawl_status' => MonitoredPage::CRAWL_STATUS_FETCHED,
        'indexability_status' => 'indexable',
        'dedupe_key' => hash('sha256', 'page-intelligence-evidence'),
        'metadata_json' => ['test' => true],
    ]);

    $serpObservation = PageSerpObservation::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'monitored_page_id' => $page->id,
        'query' => 'best ai visibility platform',
        'query_hash' => hash('sha256', 'best ai visibility platform'),
        'locale' => 'en_US',
        'country' => 'US',
        'device' => 'desktop',
        'search_engine' => 'google',
        'observed_at' => now(),
        'result_type' => 'organic',
        'position' => 18,
        'absolute_position' => 18,
        'page_url' => $pageUrl,
        'page_url_hash' => hash('sha256', $pageUrl),
        'domain' => parse_url($pageUrl, PHP_URL_HOST),
        'title' => 'AI visibility proof',
        'snippet' => 'Weak SERP visibility despite strategic relevance.',
        'serp_features_json' => [],
        'competitor_presence_json' => [['domain' => $competitor->domain]],
        'search_volume' => 900,
        'keyword_intent' => 'commercial',
        'click_potential' => 0.18,
        'visibility_score' => 22,
        'breakdown_json' => ['test' => true],
        'raw_payload_json' => ['test' => true],
        'provider_key' => 'test',
        'metadata_json' => ['test' => true],
    ]);
    $geoObservation = PageGeoObservation::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'monitored_page_id' => $page->id,
        'llm_tracking_query_id' => $query->id,
        'query' => 'Best agentic brand growth platform',
        'query_hash' => hash('sha256', 'best agentic brand growth platform'),
        'answer_engine' => 'chatgpt',
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'locale' => 'en',
        'observed_at' => now(),
        'cited_domain' => $competitor->domain,
        'citation_count' => 2,
        'mentioned_brands_json' => [],
        'mentioned_competitors_json' => [['domain' => $competitor->domain]],
        'client_cited' => false,
        'competitors_cited' => true,
        'brand_mentioned' => false,
        'sentiment' => 'neutral',
        'topic_ownership_score' => 0.22,
        'consistency_score' => 0.30,
        'geo_visibility_score' => 18,
        'breakdown_json' => ['test' => true],
        'answer_summary' => 'Competitor is cited, Argusly is absent.',
        'raw_payload_json' => ['test' => true],
        'retention_policy' => 'summary_only',
        'metadata_json' => ['test' => true],
    ]);
    $competitorMatch = PageCompetitorMatch::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'monitored_page_id' => $page->id,
        'site_competitor_id' => $competitor->id,
        'match_type' => 'topic_overlap',
        'match_score' => 0.91,
        'evidence_json' => ['topic' => 'AI visibility'],
        'observed_at' => now(),
    ]);
    $brandMatch = PageBrandMatch::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'monitored_page_id' => $page->id,
        'brand_key' => 'argusly',
        'brand_name' => 'Argusly',
        'match_type' => 'brand_alignment',
        'match_score' => 0.31,
        'evidence_json' => ['missing' => 'proof language'],
        'observed_at' => now(),
    ]);

    $plan = app(BrandGrowthPlanGenerator::class)->generate($workspace, $context['user'], [
        'business_objective' => 'Use page intelligence as strategic evidence',
    ]);

    $titles = $plan->findings->pluck('title')->all();

    expect($titles)->toContain('Observed SERP visibility is weak for priority page queries')
        ->and($titles)->toContain('AI answers cite competitors without citing the brand')
        ->and($titles)->toContain('Observed pages overlap strongly with competitor themes')
        ->and($titles)->toContain('Observed pages show weak brand-to-page alignment')
        ->and(data_get($plan->context_snapshot, 'available_sources.page_intelligence'))->toBeTrue()
        ->and(data_get($plan->context_snapshot, 'source_reference_index.page_serp_observation_ids'))->toContain((string) $serpObservation->id)
        ->and(data_get($plan->context_snapshot, 'source_reference_index.page_geo_observation_ids'))->toContain((string) $geoObservation->id)
        ->and(data_get($plan->context_snapshot, 'source_reference_index.page_competitor_match_ids'))->toContain((string) $competitorMatch->id)
        ->and(data_get($plan->context_snapshot, 'source_reference_index.page_brand_match_ids'))->toContain((string) $brandMatch->id);
});

it('regenerates a draft from an existing brand growth plan', function (): void {
    $context = brandGrowthContext('regenerate');
    $plan = app(BrandGrowthPlanGenerator::class)->generate($context['workspace'], $context['user'], [
        'business_objective' => 'Regenerate this strategic objective',
        'brand_objective' => 'Refresh the governed brand growth snapshot',
        'client_site_id' => $context['site']->id,
    ]);

    $this->actingAs($context['user'])
        ->post(route('app.agentic-marketing.brand-growth-plans.regenerate', ['plan' => $plan->id, 'workspace_id' => $context['workspace']->id]))
        ->assertRedirect();

    $regenerated = BrandGrowthPlan::query()
        ->where('workspace_id', $context['workspace']->id)
        ->orderByDesc('version')
        ->firstOrFail();

    expect($regenerated->version)->toBe(2)
        ->and($regenerated->supersedes_plan_id)->toBe($plan->id)
        ->and($regenerated->business_objective)->toBe('Regenerate this strategic objective')
        ->and($plan->refresh()->status->value)->toBe('superseded');
});

it('includes Brand Growth Planning rows in diagnostics', function (): void {
    $context = brandGrowthContext('diagnostics');
    app(BrandGrowthPlanGenerator::class)->generate($context['workspace'], $context['user']);

    $this->artisan('argusly:diagnostics', ['--workspace' => $context['workspace']->id])
        ->expectsOutputToContain('brand_growth_planning.plans.total')
        ->expectsOutputToContain('brand_growth_planning.plans.approved_conflicts')
        ->expectsOutputToContain('brand_growth_planning.findings.pending_review')
        ->expectsOutputToContain('brand_growth_planning.audiences.pending_review')
        ->assertExitCode(0);
});
