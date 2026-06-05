<?php

use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticActionRunLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['features.agentic_marketing' => true]);

    [$this->organization, $this->workspace, $this->user, $this->site] = makeAgenticLearningTenant('agentic-learning');

    $this->withoutMiddleware([
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ]);
});

it('stores measurable learning signals after a successful action completes', function () {
    $content = makeAgenticLearningContent($this->workspace, $this->site, [
        'ai_visibility_score' => 58,
        'content_health_score' => 72,
        'aeo_score' => 67,
        'internal_links_meta' => ['applied_count' => 3],
        'answer_block_generation_persisted_count' => 2,
    ]);
    $objective = makeAgenticLearningObjective($this->organization, $this->workspace, $this->site);
    $opportunity = makeAgenticLearningOpportunity($objective, $content, [
        'title' => 'Improve AI visibility for pricing pages',
    ]);
    $action = makeAgenticLearningAction($objective, $opportunity, $content, [
        'action_type' => 'refresh_article',
        'status' => AgenticMarketingAction::STATUS_RUNNING,
        'payload' => [
            'topic' => 'Pricing pages',
            'metrics_before' => [
                'ai_visibility_score' => 41,
                'lifecycle_score' => 61,
                'seo_quality_score' => 62,
            ],
        ],
        'result' => [
            'summary' => 'Refresh draft created for editorial review.',
            'created_content_id' => (string) $content->id,
            'created_draft_id' => (string) Str::uuid(),
            'internal_links_added' => 3,
            'answer_blocks_added' => 2,
            'scores' => [
                'after' => [
                    'ai_visibility' => 58,
                    'lifecycle' => 72,
                    'seo_quality' => 67,
                ],
            ],
        ],
        'estimated_credits' => 12,
        'credits_captured' => 10,
        'started_at' => now()->subSeconds(45),
        'completed_at' => now(),
    ]);
    $action->forceFill(['status' => AgenticMarketingAction::STATUS_COMPLETED])->save();

    $run = app(AgenticActionRunLogger::class)->markCompleted($action->fresh(['objective', 'opportunity', 'content']));

    $actionSignal = data_get($action->fresh()->result, 'learning_signal');
    $opportunityPayload = $opportunity->fresh()->payload;
    $runSignal = data_get($run->fresh()->output_snapshot, 'learning_signal');

    expect($actionSignal)->toBeArray()
        ->and(data_get($actionSignal, 'measurements.content_refreshed'))->toBeTrue()
        ->and(data_get($actionSignal, 'measurements.internal_links_added'))->toBe(3)
        ->and(data_get($actionSignal, 'measurements.answer_blocks_added'))->toBe(2)
        ->and(data_get($actionSignal, 'measurements.credits_used'))->toBe(10)
        ->and(data_get($actionSignal, 'scores.delta.ai_visibility'))->toBe(17)
        ->and(data_get($actionSignal, 'scores.delta.lifecycle'))->toBe(11)
        ->and(data_get($actionSignal, 'scores.delta.seo_quality'))->toBe(5)
        ->and(data_get($actionSignal, 'classifiers.page_improved_after_refresh'))->toBeTrue()
        ->and(data_get($opportunityPayload, 'learning_signals.latest.impact_score'))->toBe(33)
        ->and(data_get($opportunityPayload, 'learning_signals.aggregates.action_types.refresh_article.completed'))->toBe(1)
        ->and(data_get($opportunityPayload, 'learning_signals.aggregates.refresh_improved_pages'))->toBe(1)
        ->and($runSignal)->toBeArray();
});

it('classifies failed high cost low impact actions for future recommendations', function () {
    $content = makeAgenticLearningContent($this->workspace, $this->site);
    $objective = makeAgenticLearningObjective($this->organization, $this->workspace, $this->site);
    $opportunity = makeAgenticLearningOpportunity($objective, $content, [
        'title' => 'Repeated product page opportunity',
    ]);
    $action = makeAgenticLearningAction($objective, $opportunity, $content, [
        'action_type' => 'add_answer_block',
        'status' => AgenticMarketingAction::STATUS_FAILED,
        'estimated_credits' => 40,
        'credits_captured' => 40,
        'result' => [
            'summary' => 'Action failed before making changes.',
            'scores' => [
                'before' => ['ai_visibility' => 50, 'lifecycle' => 50, 'seo_quality' => 50],
                'after' => ['ai_visibility' => 50, 'lifecycle' => 50, 'seo_quality' => 50],
            ],
        ],
        'started_at' => now()->subSeconds(12),
        'failed_at' => now(),
    ]);

    $run = app(AgenticActionRunLogger::class)->markFailed($action->fresh(['objective', 'opportunity', 'content']), 'Provider timed out');
    $signal = data_get($run->fresh()->output_snapshot, 'learning_signal');
    $opportunityPayload = $opportunity->fresh()->payload;

    expect(data_get($signal, 'failed'))->toBeTrue()
        ->and(data_get($signal, 'classifiers.failed_action_type'))->toBeTrue()
        ->and(data_get($signal, 'classifiers.high_cost_low_impact'))->toBeTrue()
        ->and(data_get($opportunityPayload, 'learning_signals.aggregates.action_types.add_answer_block.failed'))->toBe(1)
        ->and(data_get($opportunityPayload, 'learning_signals.aggregates.high_cost_low_impact'))->toBe(1);
});

it('shows learning signals in the customer action run ledger', function () {
    $objective = makeAgenticLearningObjective($this->organization, $this->workspace, $this->site);
    $opportunity = makeAgenticLearningOpportunity($objective);
    $run = AgenticActionRun::query()->create([
        'workspace_id' => (string) $this->workspace->id,
        'goal_id' => (string) $objective->id,
        'opportunity_id' => (string) $opportunity->id,
        'action_type' => 'refresh_article',
        'execution_mode_snapshot' => 'guided',
        'status' => AgenticActionRun::STATUS_COMPLETED,
        'reason' => 'Action completed.',
        'output_snapshot' => [
            'learning_signal' => [
                'summary' => 'Content refreshed, impact +12.',
                'impact_score' => 12,
                'measurements' => ['job_duration_seconds' => 8],
                'classifiers' => ['high_cost_low_impact' => false],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->get(route('app.agentic-marketing.index'))
        ->assertOk()
        ->assertSee('Content refreshed, impact +12.')
        ->assertSee('Impact +12')
        ->assertSee('8s');
});

function makeAgenticLearningTenant(string $slug): array
{
    $organization = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug.'-'.Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => Str::headline($slug).' Workspace',
        'display_name' => Str::headline($slug).' Workspace',
        'organization_id' => $organization->id,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Learning Site',
        'site_url' => 'https://'.$slug.'.example.test',
        'base_url' => 'https://'.$slug.'.example.test',
        'allowed_domains' => [$slug.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$organization, $workspace, $user, $site];
}

function makeAgenticLearningObjective(Organization $organization, Workspace $workspace, ClientSite $site): AgenticMarketingObjective
{
    return AgenticMarketingObjective::query()->create([
        'organization_id' => (int) $organization->id,
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'name' => 'Learning objective',
        'goal' => 'Learn from Agentic Marketing outcomes.',
        'locale' => 'en',
        'kpi_type' => 'ai_visibility',
        'approval_mode' => 'manual',
        'status' => 'active',
    ]);
}

function makeAgenticLearningOpportunity(?AgenticMarketingObjective $objective = null, ?Content $content = null, array $attributes = []): AgenticMarketingOpportunity
{
    $objective ??= makeAgenticLearningObjective(test()->organization, test()->workspace, test()->site);

    return AgenticMarketingOpportunity::query()->create(array_merge([
        'objective_id' => (string) $objective->id,
        'content_id' => $content ? (string) $content->id : null,
        'title' => 'Learning opportunity',
        'type' => 'refresh',
        'priority_score' => 70,
        'status' => 'open',
        'payload' => ['topic' => 'learning opportunity'],
    ], $attributes));
}

function makeAgenticLearningAction(AgenticMarketingObjective $objective, AgenticMarketingOpportunity $opportunity, ?Content $content = null, array $attributes = []): AgenticMarketingAction
{
    return AgenticMarketingAction::query()->create(array_merge([
        'objective_id' => (string) $objective->id,
        'opportunity_id' => (string) $opportunity->id,
        'content_id' => $content ? (string) $content->id : null,
        'action_type' => 'refresh_article',
        'status' => AgenticMarketingAction::STATUS_PROPOSED,
        'estimated_credits' => 5,
        'payload' => ['topic' => 'learning opportunity'],
    ], $attributes));
}

function makeAgenticLearningContent(Workspace $workspace, ClientSite $site, array $attributes = []): Content
{
    return Content::query()->create(array_merge([
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Learning content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'generation_mode' => 'balanced',
        'language' => 'en',
        'aeo_score' => 50,
        'content_health_score' => 50,
        'ai_visibility_score' => 50,
    ], $attributes));
}
