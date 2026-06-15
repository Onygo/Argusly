<?php

use App\Enums\LearningRecommendationStatus;
use App\Enums\LearningRecommendationType;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\AssistantFeedItem;
use App\Models\BrandContext;
use App\Models\ClientSite;
use App\Models\LearningRecommendation;
use App\Models\Notification;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Assistant\AssistantFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function assistantLayerContext(string $slug = 'main'): array
{
    $organization = Organization::query()->create([
        'name' => 'Assistant '.$slug,
        'slug' => 'assistant-'.$slug.'-'.Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Assistant Workspace '.$slug,
        'display_name' => 'Assistant Workspace '.$slug,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Assistant Site '.$slug,
        'site_url' => 'https://'.$slug.'.assistant.test',
        'base_url' => 'https://'.$slug.'.assistant.test',
        'allowed_domains' => [$slug.'.assistant.test'],
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
        'raw_input' => 'Argusly helps teams find and act on growth opportunities.',
        'source_type' => 'manual',
        'structured_json' => ['primary_topics' => ['growth opportunities']],
    ]);

    return compact('organization', 'workspace', 'site', 'user');
}

function assistantLayerOpportunity(Workspace $workspace, ClientSite $site, array $overrides = []): Opportunity
{
    return Opportunity::query()->create(array_merge([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'category' => OpportunityCategory::AI_VISIBILITY_OPPORTUNITY->value,
        'status' => OpportunityStatus::OPEN->value,
        'title' => 'Improve AI visibility for comparison searches',
        'topic' => 'AI visibility',
        'summary' => 'AI answers are not citing the connected site for high-intent comparison searches.',
        'priority_score' => 88,
        'confidence_score' => 82,
        'impact_score' => 91,
        'urgency_score' => 70,
        'effort_score' => 38,
        'score_breakdown' => [],
        'recommended_actions' => [
            ['title' => 'Prepare a comparison page brief'],
        ],
        'evidence' => [],
        'source_signal_summary' => [],
        'dedupe_hash' => hash('sha256', Str::uuid()->toString()),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

it('converts opportunities into structured assistant feed items and notifications', function (): void {
    $context = assistantLayerContext('opportunity');
    $opportunity = assistantLayerOpportunity($context['workspace'], $context['site']);

    $item = app(AssistantFeedService::class)->upsertFromSource($opportunity);

    expect($item)
        ->toBeInstanceOf(AssistantFeedItem::class)
        ->assistant_state->toBe(AssistantFeedItem::STATE_NEEDS_INPUT)
        ->category->toBe(AssistantFeedItem::CATEGORY_OPPORTUNITY)
        ->priority_label->toBe('critical')
        ->i_found->toContain('AI answers')
        ->i_recommend->toContain('Prepare a comparison page brief')
        ->i_prepared->toContain('opportunity context')
        ->i_need_your_input->toContain('Approve');

    $this->assertDatabaseHas('assistant_feed_items', [
        'source_type' => Opportunity::class,
        'source_id' => (string) $opportunity->id,
        'workspace_id' => $context['workspace']->id,
        'status' => AssistantFeedItem::STATUS_ACTIVE,
    ]);

    $notification = Notification::query()->first();

    expect($notification)
        ->not->toBeNull()
        ->type->toBe(Notification::TYPE_ACTION_REQUIRED)
        ->cta_url->toBe($item->primary_cta_url);
    expect($notification->meta['assistant_feed_item_id'])->toBe((string) $item->id);
});

it('converts learning recommendations into assistant messages', function (): void {
    $context = assistantLayerContext('learning');

    $recommendation = LearningRecommendation::query()->create([
        'workspace_id' => $context['workspace']->id,
        'type' => LearningRecommendationType::AI_VISIBILITY->value,
        'status' => LearningRecommendationStatus::PROPOSED->value,
        'priority_score' => 72,
        'confidence_score' => 81,
        'title' => 'Refresh content with stronger answer blocks',
        'summary' => 'Recent results show answer-block pages outperform long-form pages.',
        'recommended_actions' => [
            ['title' => 'Apply the winning answer-block structure to the next content refresh.'],
        ],
        'recommended_at' => now(),
    ]);

    $item = app(AssistantFeedService::class)->upsertFromSource($recommendation, false);

    expect($item->category)->toBe(AssistantFeedItem::CATEGORY_LEARNING);
    expect($item->assistant_state)->toBe(AssistantFeedItem::STATE_RECOMMEND);
    expect($item->i_found)->toContain('answer-block pages');
    expect($item->i_recommend)->toContain('winning answer-block structure');
    expect($item->i_prepared)->toContain('evidence');
    expect($item->i_need_your_input)->toContain('Choose');
});

it('surfaces the assistant timeline on the dashboard', function (): void {
    $context = assistantLayerContext('dashboard');
    assistantLayerOpportunity($context['workspace'], $context['site']);

    $this->actingAs($context['user'])
        ->get(route('app.dashboard'))
        ->assertOk()
        ->assertSee('What Argusly is doing for you')
        ->assertSee('Improve AI visibility for comparison searches')
        ->assertSee('I found')
        ->assertSee('I recommend')
        ->assertSee('I prepared')
        ->assertSee('I need your input');
});
