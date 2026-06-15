<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\AssistantFeedItem;
use App\Models\ClientSite;
use App\Models\ContentDestination;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\RecommendedAction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Api\ApiScopes;
use App\Services\Assistant\AssistantFeedService;
use App\Services\Integrations\ApiKeyService;
use App\Services\RecommendedActions\RecommendedActionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function recommendedActionsContext(string $slug = 'main'): array
{
    $organization = Organization::query()->create([
        'name' => 'Recommended Actions '.$slug,
        'slug' => 'recommended-actions-'.$slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Recommended Actions Workspace '.$slug,
        'display_name' => 'Recommended Actions Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Recommended Actions Site '.$slug,
        'site_url' => 'https://'.$slug.'.recommended-actions.test',
        'base_url' => 'https://'.$slug.'.recommended-actions.test',
        'allowed_domains' => [$slug.'.recommended-actions.test'],
        'is_active' => true,
        'status' => 'connected',
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

function recommendedActionsOpportunity(Workspace $workspace, ClientSite $site, array $overrides = []): Opportunity
{
    return Opportunity::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => OpportunityCategory::AI_VISIBILITY_OPPORTUNITY->value,
        'status' => OpportunityStatus::OPEN->value,
        'title' => 'Create AI visibility comparison page',
        'topic' => 'AI visibility',
        'summary' => 'AI answers are missing the connected site for comparison queries.',
        'priority_score' => 86,
        'confidence_score' => 78,
        'impact_score' => 90,
        'urgency_score' => 66,
        'effort_score' => 40,
        'score_breakdown' => [],
        'recommended_actions' => [
            ['title' => 'Prepare the comparison page and supporting distribution plan.'],
        ],
        'evidence' => [['type' => 'ai_visibility_gap']],
        'source_signal_summary' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function recommendedActionsApiHeaders(Workspace $workspace): array
{
    $destination = ContentDestination::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Recommended Actions API',
        'type' => 'api',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
    ]);

    $created = app(ApiKeyService::class)->create(
        workspace: $workspace,
        name: 'Recommended Actions Test Key',
        scopes: [ApiScopes::CONTENT_READ],
        contentDestinationId: (string) $destination->id,
    );

    return ['Authorization' => 'Bearer '.$created['plain_text_key']];
}

it('projects opportunity recommendations into normalized recommended actions', function (): void {
    $context = recommendedActionsContext('engine');
    $opportunity = recommendedActionsOpportunity($context['workspace'], $context['site']);

    $action = app(RecommendedActionEngine::class)->upsertFromSource($opportunity);

    expect($action)
        ->toBeInstanceOf(RecommendedAction::class)
        ->source_group->toBe(RecommendedAction::SOURCE_OPPORTUNITY)
        ->action_type->toBe('review_opportunity')
        ->priority_label->toBe('critical')
        ->estimated_effort->toBe(RecommendedAction::EFFORT_LOW)
        ->why_this_matters->toContain('AI answers')
        ->expected_outcome->toContain('AI visibility')
        ->what_argusly_will_do->toContain('execution recommendation')
        ->what_requires_approval->toContain('approve');

    $this->assertDatabaseHas('recommended_actions', [
        'source_type' => Opportunity::class,
        'source_id' => (string) $opportunity->id,
        'workspace_id' => $context['workspace']->id,
    ]);
});

it('renders the recommended actions inbox and dashboard widget', function (): void {
    $context = recommendedActionsContext('ui');
    recommendedActionsOpportunity($context['workspace'], $context['site']);

    $this->actingAs($context['user'])
        ->get(route('app.recommended-actions.index'))
        ->assertOk()
        ->assertSee('Recommended Actions Inbox')
        ->assertSee('Why this matters')
        ->assertSee('Expected outcome')
        ->assertSee('What Argusly will do')
        ->assertSee('What requires approval');

    $this->actingAs($context['user'])
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('Recommended Actions')
        ->assertSee('Open actions inbox');
});

it('exposes recommended actions through the api', function (): void {
    $context = recommendedActionsContext('api');
    recommendedActionsOpportunity($context['workspace'], $context['site']);

    $this->withHeaders(recommendedActionsApiHeaders($context['workspace']))
        ->getJson('/api/v1/recommended-actions')
        ->assertOk()
        ->assertJsonPath('data.0.source_group', RecommendedAction::SOURCE_OPPORTUNITY)
        ->assertJsonPath('data.0.title', 'Create AI visibility comparison page')
        ->assertJsonPath('data.0.scores.priority_label', 'critical');
});

it('converts recommended actions into assistant feed messages', function (): void {
    $context = recommendedActionsContext('assistant');
    $opportunity = recommendedActionsOpportunity($context['workspace'], $context['site']);
    $action = app(RecommendedActionEngine::class)->upsertFromSource($opportunity);

    $item = app(AssistantFeedService::class)->upsertFromSource($action, false);

    expect($item)
        ->toBeInstanceOf(AssistantFeedItem::class)
        ->assistant_state->toBe(AssistantFeedItem::STATE_NEEDS_INPUT)
        ->i_found->toContain('AI answers')
        ->i_recommend->toContain('AI visibility')
        ->i_prepared->toContain('execution recommendation')
        ->i_need_your_input->toContain('approve');
});
