<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\Organization;
use App\Models\SignalDetection;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('features.agentic_marketing', true);
    Config::set('features.signal_intelligence', true);
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function opportunitiesWorkspaceContext(string $slug = 'main'): array
{
    $organization = Organization::query()->create([
        'name' => 'Opportunities '.$slug,
        'slug' => 'opportunities-'.$slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Opportunities Workspace '.$slug,
        'display_name' => 'Opportunities Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Opportunities Site '.$slug,
        'site_url' => 'https://'.$slug.'.opportunities.test',
        'base_url' => 'https://'.$slug.'.opportunities.test',
        'allowed_domains' => [$slug.'.opportunities.test'],
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

function opportunitiesWorkspaceOpportunity(Workspace $workspace, ClientSite $site, array $overrides = []): Opportunity
{
    return Opportunity::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => OpportunityCategory::CONTENT_GAP->value,
        'status' => OpportunityStatus::REVIEWING->value,
        'title' => 'Create comparison page for AI visibility buyers',
        'topic' => 'AI visibility',
        'summary' => 'Competitor content is winning buyer attention.',
        'priority_score' => 82,
        'confidence_score' => 77,
        'impact_score' => 80,
        'urgency_score' => 58,
        'effort_score' => 45,
        'score_breakdown' => [],
        'recommended_actions' => ['Approve this opportunity and prepare a comparison page.'],
        'evidence' => [],
        'source_signal_summary' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function opportunitiesWorkspaceCandidate(Workspace $workspace, ClientSite $site, array $overrides = []): SignalDetection
{
    return SignalDetection::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => SignalDetection::CATEGORY_OPPORTUNITY_DETECTION,
        'type' => 'opportunity_candidate',
        'status' => SignalStatus::DETECTED->value,
        'title' => 'AI answer gap needs a content response',
        'summary' => 'AI answers omit the brand for a high-intent buyer question.',
        'primary_topic' => 'AI answer visibility',
        'primary_entity' => 'Argusly',
        'severity' => SignalSeverity::MEDIUM->value,
        'priority_score' => 76,
        'confidence_score' => 72,
        'impact_score' => 70,
        'urgency_score' => 55,
        'risk_score' => 10,
        'opportunity_score' => 78,
        'score_breakdown' => [],
        'evidence_summary' => [],
        'recommended_actions' => ['Turn this into an active opportunity.'],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'metadata' => [],
    ], $overrides));
}

function opportunitiesWorkspacePlan(Workspace $workspace, ClientSite $site, Opportunity $opportunity, array $overrides = []): OpportunityExecutionPlan
{
    return OpportunityExecutionPlan::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'opportunity_id' => $opportunity->id,
        'status' => OpportunityExecutionPlan::STATUS_DRAFT,
        'title' => 'Publish AI visibility comparison page',
        'summary' => 'Turn the opportunity into a comparison article and distribution push.',
        'objective' => 'Win more high-intent AI visibility buyers.',
        'recommended_channel' => 'Website',
        'recommended_format' => 'Comparison page',
        'priority_score' => 80,
        'estimated_effort' => 40,
        'expected_impact' => 82,
        'planned_steps' => ['Create a brief', 'Draft the comparison page', 'Distribute to LinkedIn'],
        'source_evidence' => [],
        'metadata' => [],
        'created_by' => null,
    ], $overrides));
}

it('renders a unified opportunities workspace without exposing internal concepts', function (): void {
    $context = opportunitiesWorkspaceContext('inbox');
    $opportunity = opportunitiesWorkspaceOpportunity($context['workspace'], $context['site']);
    opportunitiesWorkspaceCandidate($context['workspace'], $context['site']);
    opportunitiesWorkspacePlan($context['workspace'], $context['site'], $opportunity);

    $this->actingAs($context['user'])
        ->get(route('app.opportunities.index', ['workspace' => $context['workspace']->id]))
        ->assertOk()
        ->assertSee('Opportunity Inbox')
        ->assertSee('Decision Queue')
        ->assertSee('Execution Recommendation')
        ->assertSee('Why it matters')
        ->assertSee('Recommended action')
        ->assertSee('Expected impact')
        ->assertSee('Next step')
        ->assertSee('Create comparison page for AI visibility buyers')
        ->assertSee('AI answer gap needs a content response')
        ->assertDontSee('Signal Intelligence')
        ->assertDontSee('Detections')
        ->assertDontSee('Clusters')
        ->assertDontSee('Runs');
});

it('renders opportunity, candidate, and execution recommendation details through compatibility routes', function (): void {
    $context = opportunitiesWorkspaceContext('details');
    $opportunity = opportunitiesWorkspaceOpportunity($context['workspace'], $context['site']);
    $candidate = opportunitiesWorkspaceCandidate($context['workspace'], $context['site']);

    $this->actingAs($context['user'])
        ->get(route('app.opportunities.show', $opportunity))
        ->assertOk()
        ->assertSee('Opportunity Detail')
        ->assertSee('Create execution recommendation')
        ->assertSee('Open advanced detail');

    $plan = opportunitiesWorkspacePlan($context['workspace'], $context['site'], $opportunity, [
        'status' => OpportunityExecutionPlan::STATUS_APPROVED,
    ]);

    $this->actingAs($context['user'])
        ->get(route('app.opportunities.candidates.show', $candidate))
        ->assertOk()
        ->assertSee('Opportunity Detail')
        ->assertSee('Create opportunity')
        ->assertSee('Expected impact');

    $this->actingAs($context['user'])
        ->get(route('app.opportunities.execution-recommendations.show', $plan))
        ->assertOk()
        ->assertSee('Execution Recommendation')
        ->assertSee('Create brief')
        ->assertSee('Related Opportunity');
});
