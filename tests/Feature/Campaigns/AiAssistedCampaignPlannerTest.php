<?php

use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignContentAssetType;
use App\Enums\CampaignStatus;
use App\Enums\DistributionChannelType;
use App\Enums\SocialPostVariantStatus;
use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\Campaign;
use App\Models\CampaignContent;
use App\Models\CampaignDistributionPlan;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\SocialPostVariant;
use App\Models\StructuredAnswerBlock;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CampaignPlanning\CampaignAssetGenerationService;
use App\Services\CampaignPlanning\CampaignPlannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('generates deterministic approval gated campaign plans from a topic and opportunities', function () {
    $organization = Organization::query()->create([
        'name' => 'Planner Org',
        'slug' => 'planner-org',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Planner Workspace',
    ]);

    Opportunity::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'category' => 'content_gap',
        'status' => 'open',
        'title' => 'Agentic Marketing content gap',
        'topic' => 'Agentic Marketing',
        'summary' => 'The workspace lacks a structured agentic marketing hub.',
        'priority_score' => 86,
        'confidence_score' => 78,
        'impact_score' => 80,
        'urgency_score' => 72,
        'effort_score' => 40,
        'score_breakdown' => [],
        'recommended_actions' => [],
        'evidence' => [],
        'source_signal_summary' => [],
        'metadata' => [],
        'dedupe_hash' => 'planner-opportunity',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $campaign = app(CampaignPlannerService::class)->plan($workspace, 'Agentic Marketing', [
        'goals' => ['Build topical authority', 'Support LinkedIn distribution'],
        'audience' => 'Marketing leaders',
        'start_date' => '2026-06-01',
    ]);

    expect($campaign)->toBeInstanceOf(Campaign::class);
    expect($campaign->status)->toBe(CampaignStatus::PLANNING);
    expect($campaign->approval_status)->toBe(CampaignApprovalStatus::REQUESTED);
    expect($campaign->contents)->toHaveCount(10);
    expect($campaign->contents->pluck('asset_type')->map(fn ($type) => $type->value)->all())
        ->toContain(CampaignContentAssetType::ARTICLE->value)
        ->toContain(CampaignContentAssetType::LINKEDIN_POST->value)
        ->toContain(CampaignContentAssetType::FAQ_BLOCK->value)
        ->toContain(CampaignContentAssetType::ANSWER_BLOCK->value)
        ->toContain(CampaignContentAssetType::NEWSLETTER_SNIPPET->value);

    expect(data_get($campaign->ai_planning_context, 'dependency_graph.nodes'))->toHaveCount(10);
    expect(data_get($campaign->ai_planning_context, 'visual_map.lanes'))->not->toBeEmpty();
    expect(data_get($campaign->metadata, 'funnel_stage_map.pillar_article'))->toBe('awareness');
    expect(data_get($campaign->optimization_signals, 'tone_variations.technical.use_for'))->toContain('supporting_operations');
    expect(data_get($campaign->optimization_signals, 'repurposing_recommendations.pillar_article.0.target'))->toBe('linkedin_post');
    expect(data_get($campaign->internal_linking_strategy, 'by_asset.supporting_strategy'))->toContain('pillar_article');

    expect(CampaignContent::query()->where('campaign_id', $campaign->id)->where('approval_status', CampaignApprovalStatus::REQUESTED->value)->count())->toBe(10);
    expect(CampaignDistributionPlan::query()->where('campaign_id', $campaign->id)->count())->toBe(10);
    expect($campaign->contents->flatMap->distributionPlans->pluck('distributionChannel.type')->map(fn ($type) => $type->value)->all())
        ->toContain(DistributionChannelType::LINKEDIN->value)
        ->toContain(DistributionChannelType::NEWSLETTER->value)
        ->toContain(DistributionChannelType::WEBSITE->value);

    expect(Opportunity::query()->where('campaign_id', $campaign->id)->where('status', 'planned')->exists())->toBeTrue();
});

it('can generate a planner map on top of an existing campaign without duplicating planner assets', function () {
    $organization = Organization::query()->create([
        'name' => 'Existing Campaign Org',
        'slug' => 'existing-campaign-org',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Existing Campaign Workspace',
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Q3 AI Authority',
        'slug' => 'q3-ai-authority',
        'objective' => 'Build an authority campaign around AI visibility.',
        'status' => CampaignStatus::DRAFT,
        'approval_status' => CampaignApprovalStatus::NOT_REQUIRED,
        'planned_start_date' => '2026-07-01',
        'audience' => ['primary' => ['B2B marketing leaders']],
        'goals' => ['Grow authority'],
        'kpis' => [],
        'channel_mix' => [],
        'ai_planning_context' => [],
        'optimization_signals' => [],
        'internal_linking_strategy' => [],
        'metadata' => ['source' => 'manual_campaign'],
    ]);

    $planned = app(CampaignPlannerService::class)->planExistingCampaign($campaign, [
        'start_date' => '2026-07-08',
    ]);

    expect((string) $planned->id)->toBe((string) $campaign->id);
    expect($planned->name)->toBe('Q3 AI Authority');
    expect($planned->status)->toBe(CampaignStatus::PLANNING);
    expect($planned->approval_status)->toBe(CampaignApprovalStatus::REQUESTED);
    expect($planned->contents)->toHaveCount(10);
    expect(data_get($planned->ai_planning_context, 'source_topic'))->toBe('Q3 AI Authority');
    expect(data_get($planned->metadata, 'planner_version'))->toBe('deterministic_v1');
    expect(data_get($planned->metadata, 'source'))->toBe('manual_campaign');

    $replanned = app(CampaignPlannerService::class)->planExistingCampaign($planned, [
        'topic' => 'AI visibility',
        'goals' => ['Improve demand capture'],
    ]);

    expect((string) $replanned->id)->toBe((string) $campaign->id);
    expect(CampaignContent::query()->where('campaign_id', $campaign->id)->count())->toBe(10);
    expect(CampaignDistributionPlan::query()->where('campaign_id', $campaign->id)->count())->toBe(10);
    expect(data_get($replanned->fresh()->ai_planning_context, 'source_topic'))->toBe('AI visibility');
});

it('generates review gated suggested content and social drafts from an approved campaign plan', function () {
    Queue::fake();

    $organization = Organization::query()->create([
        'name' => 'Campaign Generation Org',
        'slug' => 'campaign-generation-org',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Campaign Generation Workspace',
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Campaign Site',
        'site_url' => 'https://example.test',
        'allowed_domains' => ['example.test'],
        'is_active' => true,
    ]);

    $campaign = app(CampaignPlannerService::class)->plan($workspace, 'Agentic Marketing', [
        'goals' => ['Build topical authority'],
        'audience' => 'Marketing leaders',
        'languages' => ['en', 'nl'],
        'client_site_id' => (string) $site->id,
        'owner_user_id' => $user->id,
    ]);

    $generator = app(CampaignAssetGenerationService::class);
    $estimate = $generator->estimate($campaign);

    expect($estimate)->toMatchArray([
        'estimated_credits' => 100,
        'pending_credits' => 100,
        'credits_per_draft' => 10,
        'draft_assets' => 5,
        'pending_draft_assets' => 10,
        'no_credit_assets' => 5,
        'language_count' => 2,
        'languages' => ['en', 'nl'],
    ]);

    $summary = $generator->generate($campaign, $user);

    expect($summary)->toBe([
        'generated_content' => 10,
        'generated_social' => 6,
        'generated_answer_blocks' => 8,
        'skipped' => 0,
        'scheduled_articles' => 8,
        'due_publications_queued' => 0,
    ]);

    $campaign->refresh();

    expect($campaign->approval_status)->toBe(CampaignApprovalStatus::APPROVED)
        ->and($campaign->status)->toBe(CampaignStatus::APPROVED);

    expect(Content::query()->where('workspace_id', $workspace->id)->count())->toBe(10);
    expect(Content::query()->where('workspace_id', $workspace->id)->where('language', 'en')->count())->toBe(5);
    expect(Content::query()->where('workspace_id', $workspace->id)->where('language', 'nl')->count())->toBe(5);
    expect(Content::query()->where('workspace_id', $workspace->id)->where('title', '!=', 'Newsletter snippet: Agentic Marketing')->where('publish_status', 'scheduled')->whereNotNull('scheduled_publish_at')->count())->toBe(8);
    expect(Content::query()->where('workspace_id', $workspace->id)->where('title', '!=', 'Newsletter snippet: Agentic Marketing')->where('auto_publish', true)->count())->toBe(8);
    expect(Content::query()->where('workspace_id', $workspace->id)->where('title', 'Newsletter snippet: Agentic Marketing')->whereNull('scheduled_publish_at')->where('publish_status', 'draft')->count())->toBe(2);
    expect(Brief::query()->where('client_site_id', $site->id)->count())->toBe(10);
    expect(Draft::query()->where('client_site_id', $site->id)->count())->toBe(10);
    expect(Draft::query()->where('client_site_id', $site->id)->where('status', 'queued')->count())->toBe(10);
    expect(Draft::query()->where('client_site_id', $site->id)->where('language', 'en')->count())->toBe(5);
    expect(Draft::query()->where('client_site_id', $site->id)->where('language', 'nl')->count())->toBe(5);
    expect(Draft::query()->where('client_site_id', $site->id)->pluck('credit_cost')->unique()->values()->all())->toBe([10]);
    expect(CampaignContent::query()->where('campaign_id', $campaign->id)->whereNotNull('content_id')->count())->toBe(7);
    expect(SocialPostVariant::query()->where('campaign_id', $campaign->id)->where('status', SocialPostVariantStatus::DRAFT->value)->count())->toBe(6);
    expect(SocialPostVariant::query()->where('campaign_id', $campaign->id)->get()->pluck('metadata.locale')->sort()->values()->all())->toBe(['en', 'en', 'en', 'nl', 'nl', 'nl']);
    expect(SocialPostVariant::query()->where('campaign_id', $campaign->id)->get()->pluck('metadata.planner_scheduled_for')->filter()->count())->toBe(6);
    expect(StructuredAnswerBlock::query()->count())->toBe(8);
    expect(Content::query()->where('title', 'Pillar article: Agentic Marketing')->first()?->answerBlocks()->count())->toBe(4);
    SocialPostVariant::query()
        ->where('campaign_id', $campaign->id)
        ->get()
        ->each(function (SocialPostVariant $variant): void {
            expect($variant->body)->not->toStartWith((string) $variant->hook)
                ->and($variant->publishingText())->not->toStartWith((string) $variant->hook);
        });
    Queue::assertPushed(GenerateDraftJob::class, 10);

    $postGenerateEstimate = $generator->estimate($campaign->fresh());

    expect($postGenerateEstimate)->toMatchArray([
        'estimated_credits' => 100,
        'pending_credits' => 0,
        'draft_assets' => 5,
        'pending_draft_assets' => 0,
    ]);

    $secondRun = $generator->generate($campaign, $user);

    expect($secondRun)->toBe([
        'generated_content' => 0,
        'generated_social' => 0,
        'generated_answer_blocks' => 0,
        'skipped' => 20,
        'scheduled_articles' => 8,
        'due_publications_queued' => 0,
    ]);
});
