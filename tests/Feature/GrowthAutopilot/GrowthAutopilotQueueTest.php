<?php

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\AssistantFeedItem;
use App\Models\ClientSite;
use App\Models\GrowthAutopilotQueueItem;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\RecommendedAction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Assistant\AssistantFeedService;
use App\Services\GrowthAutopilot\GrowthAutopilotQueueBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function autopilotQueueContext(string $slug = 'main'): array
{
    $organization = Organization::query()->create([
        'name' => 'Growth Autopilot '.$slug,
        'slug' => 'growth-autopilot-'.$slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Growth Autopilot Workspace '.$slug,
        'display_name' => 'Growth Autopilot Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Growth Autopilot Site '.$slug,
        'site_url' => 'https://'.$slug.'.growth-autopilot.test',
        'base_url' => 'https://'.$slug.'.growth-autopilot.test',
        'allowed_domains' => [$slug.'.growth-autopilot.test'],
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

function autopilotQueueOpportunity(Workspace $workspace, ClientSite $site, array $overrides = []): Opportunity
{
    return Opportunity::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => OpportunityCategory::AI_VISIBILITY_OPPORTUNITY->value,
        'status' => OpportunityStatus::OPEN->value,
        'title' => 'Create AI visibility buyer guide',
        'topic' => 'AI visibility',
        'summary' => 'AI answers are missing the connected site for buyer-intent prompts.',
        'priority_score' => 92,
        'confidence_score' => 84,
        'impact_score' => 91,
        'urgency_score' => 72,
        'effort_score' => 34,
        'score_breakdown' => [],
        'recommended_actions' => [
            ['title' => 'Prepare the buyer guide brief and execution path.'],
        ],
        'evidence' => [['type' => 'prompt_gap']],
        'source_signal_summary' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

it('builds queue items from the highest value recommended actions', function (): void {
    $context = autopilotQueueContext('build');
    autopilotQueueOpportunity($context['workspace'], $context['site']);

    $items = app(GrowthAutopilotQueueBuilder::class)->build($context['workspace']);

    expect($items)->toHaveCount(1);

    $item = $items->first();
    expect($item)
        ->toBeInstanceOf(GrowthAutopilotQueueItem::class)
        ->status->toBe(GrowthAutopilotQueueItem::STATUS_NEEDS_APPROVAL)
        ->opportunity->toBe('Create AI visibility buyer guide')
        ->recommended_action->toContain('buyer guide brief')
        ->expected_impact->toContain('AI visibility')
        ->approval_required->toBeTrue()
        ->confidence_score->toBeGreaterThan(80)
        ->priority_label->toBe('critical');

    expect($item->prepared_assets)->toBeArray()->not->toBeEmpty();

    $this->assertDatabaseHas('growth_autopilot_queue_items', [
        'workspace_id' => $context['workspace']->id,
        'source_type' => Opportunity::class,
        'status' => GrowthAutopilotQueueItem::STATUS_NEEDS_APPROVAL,
    ]);
});

it('approval integration updates queue and recommended action state', function (): void {
    $context = autopilotQueueContext('approval');
    autopilotQueueOpportunity($context['workspace'], $context['site']);
    $item = app(GrowthAutopilotQueueBuilder::class)->build($context['workspace'])->first();

    $response = $this->actingAs($context['user'])
        ->post(route('app.growth-autopilot-queue.approve', $item));

    $response->assertRedirect();

    $item->refresh();
    expect($item->status)->toBe(GrowthAutopilotQueueItem::STATUS_APPROVED);
    expect($item->recommendedAction->status)->toBe(RecommendedAction::STATUS_APPROVED);
});

it('uses the growth autopilot queue as the default assistant source', function (): void {
    $context = autopilotQueueContext('assistant');
    autopilotQueueOpportunity($context['workspace'], $context['site']);

    $items = app(AssistantFeedService::class)->hydrateWorkspace($context['workspace'], 5, false);

    expect($items)->not->toBeEmpty();

    $assistantItem = $items->first();
    expect($assistantItem)
        ->toBeInstanceOf(AssistantFeedItem::class)
        ->source_type->toBe(GrowthAutopilotQueueItem::class)
        ->i_found->toContain('AI answers')
        ->i_recommend->toContain('buyer guide brief')
        ->i_prepared->toContain('asset')
        ->i_need_your_input->toContain('approve');
});
