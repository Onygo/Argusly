<?php

declare(strict_types=1);

use App\Jobs\RunOrganizationEnrichmentJob;
use App\Jobs\RunTeamMemberEnrichmentJob;
use App\Models\EnrichmentRun;
use App\Models\Organization;
use App\Models\OrganizationProfile;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use App\Services\WorkspaceIntelligence\WorkspaceIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeWorkspaceIntelligenceContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Workspace Intelligence Org',
        'slug' => 'wi-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Workspace Intelligence BV',
        'billing_address_line1' => 'Intelligence Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Workspace Intelligence Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'workspace-intelligence-test'],
        [
            'name' => 'Workspace Intelligence Test',
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
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $owner = User::query()->create([
        'name' => 'Workspace Intelligence Owner',
        'email' => 'wi-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $owner];
}

it('creates enrichment runs from the workspace intelligence UI', function () {
    Queue::fake();

    [$organization, $workspace, $owner] = makeWorkspaceIntelligenceContext();

    $this->actingAs($owner)
        ->post(route('app.workspace-intelligence.organization.store'), [
            'source_type' => 'website_url',
            'website_url' => 'https://example.com',
        ])
        ->assertRedirect();

    $run = EnrichmentRun::query()->where('organization_id', $organization->id)->latest()->first();

    expect($run)->not->toBeNull()
        ->and($run?->enrichment_type)->toBe(EnrichmentRun::TYPE_ORGANIZATION)
        ->and($run?->source_type)->toBe('website_url')
        ->and($run?->status)->toBe(EnrichmentRun::STATUS_QUEUED);

    Queue::assertPushed(RunOrganizationEnrichmentJob::class);
});

it('shows the workspace intelligence overview page', function () {
    [$organization, $workspace, $owner] = makeWorkspaceIntelligenceContext();

    $this->actingAs($owner)
        ->get(route('app.workspace-intelligence.index'))
        ->assertOk()
        ->assertSee('Workspace Intelligence')
        ->assertSee('Brand profile completion')
        ->assertSee('Brand Profile')
        ->assertSee('Personas')
        ->assertSee('Team')
        ->assertSee('Insights / Runs')
        ->assertSee('This profile is used to generate');
});

it('translates the workspace intelligence overview when Dutch is selected', function () {
    [$organization, $workspace, $owner] = makeWorkspaceIntelligenceContext();

    $this->actingAs($owner)
        ->get(route('app.workspace-intelligence.index', ['lang' => 'nl']))
        ->assertOk()
        ->assertSee('Zet enrichment-output om naar herbruikbare operationele context')
        ->assertSee('Enrichment uitvoeren')
        ->assertSee('Wijzigingen goedkeuren')
        ->assertSee('Merkprofiel')
        ->assertSee('Persona')
        ->assertSee('Insights / runs')
        ->assertSee('Dit profiel wordt gebruikt voor generatie')
        ->assertSee('Merkcontext verfijnen')
        ->assertSee('Brontype')
        ->assertSee('Handmatige notities')
        ->assertDontSee('This profile is used to generate');
});

it('approves selected organization sections into the approved profile', function () {
    [$organization, $workspace, $owner] = makeWorkspaceIntelligenceContext();

    $run = EnrichmentRun::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'enrichable_type' => EnrichmentRun::ENRICHABLE_ORGANIZATION,
        'enrichment_type' => EnrichmentRun::TYPE_ORGANIZATION,
        'source_type' => 'manual_text',
        'source_payload' => ['manual_text' => 'B2B automation platform'],
        'ai_payload' => [
            'brand_summary' => 'PublishLayer helps teams govern content operations.',
            'seo_topics' => ['content governance', 'editorial workflow automation'],
        ],
        'status' => EnrichmentRun::STATUS_DRAFT,
        'progress' => 1,
    ]);

    $this->actingAs($owner)
        ->post(route('app.workspace-intelligence.runs.approve', $run), [
            'sections' => ['brand_summary', 'seo_topics'],
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Proposal approved.');

    $profile = OrganizationProfile::query()->where('organization_id', $organization->id)->first();
    $run->refresh();

    expect($profile)->not->toBeNull()
        ->and($profile?->brand_summary)->toBe('PublishLayer helps teams govern content operations.')
        ->and($profile?->seo_topics)->toBe(['content governance', 'editorial workflow automation'])
        ->and($run->status)->toBe(EnrichmentRun::STATUS_APPROVED);
});

it('preserves approved organization data until replacement is explicitly confirmed', function () {
    [$organization, $workspace, $owner] = makeWorkspaceIntelligenceContext();

    OrganizationProfile::query()->create([
        'organization_id' => $organization->id,
        'brand_summary' => 'Existing approved summary',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]);

    $run = EnrichmentRun::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'enrichable_type' => EnrichmentRun::ENRICHABLE_ORGANIZATION,
        'enrichment_type' => EnrichmentRun::TYPE_ORGANIZATION,
        'source_type' => 'manual_text',
        'source_payload' => ['manual_text' => 'Replacement summary'],
        'ai_payload' => [
            'brand_summary' => 'Replacement summary',
        ],
        'status' => EnrichmentRun::STATUS_DRAFT,
        'progress' => 1,
    ]);

    $this->actingAs($owner)
        ->post(route('app.workspace-intelligence.runs.approve', $run), [
            'sections' => ['brand_summary'],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('workspace_intelligence');

    expect(OrganizationProfile::query()->where('organization_id', $organization->id)->first()?->brand_summary)
        ->toBe('Existing approved summary');
});

it('generates a team member persona from pasted profile text', function () {
    [$organization, $workspace, $owner] = makeWorkspaceIntelligenceContext();

    $teamMember = TeamMember::factory()->forOrganization($organization)->create([
        'name' => 'Jane Expert',
        'title' => 'Head of Editorial Operations',
        'role' => 'Head of Editorial Operations',
        'bio_source_text' => 'Jane leads editorial workflow design for regulated B2B teams.',
    ]);

    $run = EnrichmentRun::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'enrichable_type' => EnrichmentRun::ENRICHABLE_TEAM_MEMBER,
        'enrichable_id' => $teamMember->id,
        'enrichment_type' => EnrichmentRun::TYPE_TEAM_MEMBER_PERSONA,
        'source_type' => 'pasted_profile_text',
        'source_payload' => [
            'pasted_profile_text' => 'Jane has spent 12 years building editorial systems for B2B SaaS and compliance-heavy teams.',
        ],
        'status' => EnrichmentRun::STATUS_QUEUED,
        'progress' => 0,
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: [
            'profile_data' => [
                'expert_summary' => 'Operational editorial leader for regulated B2B teams.',
                'expertise_areas' => ['editorial operations', 'regulated content systems'],
                'tone_traits' => ['Pragmatic', 'Structured'],
                'point_of_view' => 'Operator perspective grounded in workflow design.',
                'credibility_markers' => ['12 years in B2B SaaS'],
                'content_angles' => ['workflow governance'],
                'author_bio' => 'Jane writes from hands-on experience building editorial systems.',
            ],
        ],
        usage: new LlmUsage(100, 50, 150),
        modelUsed: 'gpt-4.1-mini',
        providerName: 'openai',
        requestId: 'req-team-member-persona'
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new RunTeamMemberEnrichmentJob((string) $run->id);
    $job->handle(app(WorkspaceIntelligenceService::class));

    $run->refresh();

    expect($run->status)->toBe(EnrichmentRun::STATUS_DRAFT)
        ->and(data_get($run->ai_payload, 'profile_data.expert_summary'))->toBe('Operational editorial leader for regulated B2B teams.')
        ->and(data_get($run->extracted_payload, 'source_text'))->toContain('12 years');
});

it('generates an organization proposal from a website url', function () {
    [$organization, $workspace, $owner] = makeWorkspaceIntelligenceContext();

    Http::fake([
        'https://example.com*' => Http::response(<<<'HTML'
            <!doctype html>
            <html>
            <head>
                <title>PublishLayer for regulated B2B content teams</title>
                <meta name="description" content="Govern editorial workflows and brand consistency.">
            </head>
            <body>
                <main>
                    <h1>Governed content operations for B2B teams</h1>
                    <p>PublishLayer helps marketing and editorial teams manage approval flows, brand consistency, and SEO planning.</p>
                </main>
            </body>
            </html>
        HTML, 200, ['Content-Type' => 'text/html']),
    ]);

    $run = EnrichmentRun::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'enrichable_type' => EnrichmentRun::ENRICHABLE_ORGANIZATION,
        'enrichment_type' => EnrichmentRun::TYPE_ORGANIZATION,
        'source_type' => 'website_url',
        'source_payload' => [
            'website_url' => 'https://example.com',
            'input_text' => 'https://example.com',
        ],
        'status' => EnrichmentRun::STATUS_QUEUED,
        'progress' => 0,
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '{}',
        json: [
            'brand_summary' => 'Platform for governed B2B content operations.',
            'tone_of_voice' => 'Clear and operational.',
            'audience_profiles' => [
                ['name' => 'Editorial leaders', 'summary' => 'Need control and throughput.', 'goals' => ['Govern workflows'], 'pain_points' => ['Approval bottlenecks']],
            ],
            'offerings' => ['Editorial workflow governance'],
            'differentiators' => ['Structured approvals'],
            'strategic_topics' => ['content operations'],
            'seo_topics' => ['editorial workflow automation'],
            'visual_direction' => ['style_summary' => 'Structured SaaS', 'colors' => ['#0f172a'], 'design_cues' => ['Dashboards']],
        ],
        usage: new LlmUsage(120, 70, 190),
        modelUsed: 'gpt-4.1-mini',
        providerName: 'openai',
        requestId: 'req-org-proposal'
    ));
    app()->instance(LlmManager::class, $llm);

    $job = new RunOrganizationEnrichmentJob((string) $run->id);
    $job->handle(app(WorkspaceIntelligenceService::class));

    $run->refresh();

    expect($run->status)->toBe(EnrichmentRun::STATUS_DRAFT)
        ->and(data_get($run->ai_payload, 'brand_summary'))->toBe('Platform for governed B2B content operations.')
        ->and((string) data_get($run->extracted_payload, 'combined_text'))->toContain('Governed content operations for B2B teams');
});
