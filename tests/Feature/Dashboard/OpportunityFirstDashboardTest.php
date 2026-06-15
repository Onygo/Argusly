<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\BrandContext;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\SignalDetection;
use App\Models\SiteCompetitor;
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

function ux17DashboardContext(string $slug = 'main'): array
{
    $organization = Organization::query()->create([
        'name' => 'UX17 '.$slug,
        'slug' => 'ux17-'.$slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'UX17 Workspace '.$slug,
        'display_name' => 'UX17 Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'UX17 Site '.$slug,
        'site_url' => 'https://'.$slug.'.ux17.test',
        'base_url' => 'https://'.$slug.'.ux17.test',
        'allowed_domains' => [$slug.'.ux17.test'],
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    BrandContext::query()->create([
        'workspace_id' => $workspace->id,
        'raw_input' => 'Argusly tracks market intelligence.',
        'source_type' => 'manual',
        'structured_json' => ['primary_topics' => ['AI visibility']],
    ]);

    SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'UX17 Competitor',
        'domain' => 'competitor.ux17.test',
        'is_active' => true,
    ]);

    return compact('organization', 'workspace', 'site', 'user');
}

function ux17Detection(Workspace $workspace, ClientSite $site, array $overrides = []): SignalDetection
{
    return SignalDetection::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => SignalDetection::CATEGORY_OPPORTUNITY_DETECTION,
        'type' => 'opportunity_candidate',
        'status' => SignalStatus::DETECTED->value,
        'title' => 'AI answer gap growth opportunity',
        'summary' => 'AI answers show a gap that could affect visibility.',
        'primary_topic' => 'AI answer visibility',
        'primary_entity' => 'Argusly',
        'severity' => SignalSeverity::MEDIUM->value,
        'priority_score' => 82,
        'confidence_score' => 78,
        'impact_score' => 74,
        'urgency_score' => 55,
        'risk_score' => 12,
        'opportunity_score' => 84,
        'score_breakdown' => [],
        'evidence_summary' => [],
        'recommended_actions' => [],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'metadata' => [],
    ], $overrides));
}

function ux17Opportunity(Workspace $workspace, ClientSite $site, array $overrides = []): Opportunity
{
    return Opportunity::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => OpportunityCategory::CONTENT_GAP->value,
        'status' => OpportunityStatus::OPEN->value,
        'title' => 'Create AI visibility comparison page',
        'topic' => 'AI visibility',
        'summary' => 'A reviewed growth opportunity is ready for a business decision.',
        'priority_score' => 80,
        'confidence_score' => 76,
        'impact_score' => 78,
        'urgency_score' => 58,
        'effort_score' => 42,
        'score_breakdown' => [],
        'recommended_actions' => [],
        'evidence' => [],
        'source_signal_summary' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

it('renders the dashboard as opportunity first when growth opportunities exist', function (): void {
    $context = ux17DashboardContext('opportunity-first');
    ux17Detection($context['workspace'], $context['site']);
    ux17Detection($context['workspace'], $context['site'], [
        'title' => 'Competitor citation growth opportunity',
        'opportunity_score' => 76,
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
    ]);
    ux17Detection($context['workspace'], $context['site'], [
        'category' => SignalDetection::CATEGORY_RISK_DETECTION,
        'type' => 'visibility_risk',
        'title' => 'Competitor visibility risk',
        'summary' => 'Competitor mentions are rising in AI answers.',
        'risk_score' => 88,
        'opportunity_score' => 20,
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
    ]);
    ux17Opportunity($context['workspace'], $context['site']);

    $this->actingAs($context['user'])
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Command Center')
        ->assertSee('Growth Health')
        ->assertSee('Next Best Action')
        ->assertSee('Review growth opportunities')
        ->assertSee('We identified 2 growth opportunities.')
        ->assertSee('What happened?')
        ->assertSee('What matters?')
        ->assertSee('What can Argusly do?')
        ->assertSee('Review Opportunities')
        ->assertSee('Recommended Actions')
        ->assertSee('Urgent Decisions')
        ->assertSee('Weekly Mission')
        ->assertSee('Recent Results')
        ->assertSee('Supporting Detail')
        ->assertDontSee('Signals')
        ->assertDontSee('Detections')
        ->assertDontSee('Clusters')
        ->assertDontSee('Runs')
        ->assertSeeInOrder(['Growth Health', 'Next Best Action', 'Recommended Actions', 'Urgent Decisions', 'Weekly Mission', 'Recent Results', 'Supporting Detail']);
});

it('shows continue monitoring when there are no open risks or opportunities', function (): void {
    $context = ux17DashboardContext('monitoring');

    $this->actingAs($context['user'])
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Continue Monitoring')
        ->assertSee('No urgent decisions or opportunities need review right now.')
        ->assertSee('No open opportunities need action right now.')
        ->assertSee('No urgent decisions need action right now.');
});

it('keeps dashboard actions scoped to the first organization workspace', function (): void {
    $context = ux17DashboardContext('own');
    $otherWorkspace = Workspace::query()->create([
        'organization_id' => $context['organization']->id,
        'name' => 'UX17 Later Workspace',
        'display_name' => 'UX17 Later Workspace',
    ]);
    $otherSite = ClientSite::query()->create([
        'workspace_id' => $otherWorkspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'UX17 Later Site',
        'site_url' => 'https://later.ux17.test',
        'base_url' => 'https://later.ux17.test',
        'allowed_domains' => ['later.ux17.test'],
        'is_active' => true,
    ]);

    ux17Detection($otherWorkspace, $otherSite, ['title' => 'Hidden later workspace opportunity']);

    $this->actingAs($context['user'])
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Continue Monitoring')
        ->assertDontSee('Hidden later workspace opportunity')
        ->assertDontSee('Review growth opportunities');
});
