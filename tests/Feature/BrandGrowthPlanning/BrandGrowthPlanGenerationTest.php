<?php

use App\Enums\BrandGrowthPlanReviewState;
use App\Enums\OpportunitySignalSource;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\BrandGrowthPlan;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\Content;
use App\Models\LlmTrackingQuery;
use App\Models\Opportunity;
use App\Models\Organization;
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

it('includes Brand Growth Planning rows in diagnostics', function (): void {
    $context = brandGrowthContext('diagnostics');
    app(BrandGrowthPlanGenerator::class)->generate($context['workspace'], $context['user']);

    $this->artisan('argusly:diagnostics', ['--workspace' => $context['workspace']->id])
        ->expectsOutputToContain('brand_growth_planning.plans.total')
        ->expectsOutputToContain('brand_growth_planning.findings.pending_review')
        ->expectsOutputToContain('brand_growth_planning.audiences.pending_review')
        ->assertExitCode(0);
});
