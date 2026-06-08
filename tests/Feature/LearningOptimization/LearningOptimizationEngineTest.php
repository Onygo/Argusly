<?php

use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignContentAssetType;
use App\Enums\CampaignStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostType;
use App\Enums\SocialPostVariantStatus;
use App\Enums\SocialPublicationStatus;
use App\Models\Campaign;
use App\Models\CampaignContent;
use App\Models\CampaignCtaPreset;
use App\Models\CampaignLearningProfile;
use App\Models\Content;
use App\Models\ContentAiVisibilitySnapshot;
use App\Models\ContentLearningProfile;
use App\Models\ContentPerformanceMetric;
use App\Models\LearningRecommendation;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialEngagementMetric;
use App\Models\SocialPostVariant;
use App\Models\SocialPublication;
use App\Models\SocialRepostSuggestion;
use App\Models\Workspace;
use App\Services\LearningOptimization\LearningOptimizationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('builds learning profiles and explainable optimization recommendations from performance signals', function () {
    $organization = Organization::query()->create([
        'name' => 'Learning Org',
        'slug' => 'learning-org',
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Learning Workspace',
    ]);

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'title' => 'Agentic Marketing operating model',
        'primary_keyword' => 'agentic marketing',
        'type' => 'article',
        'status' => 'published',
        'source' => 'api',
        'generation_mode' => 'balanced',
        'content_health_score' => 82,
        'ai_visibility_score' => 76,
        'semantic_coverage_score' => 84,
    ]);

    ContentPerformanceMetric::query()->create([
        'content_id' => $content->id,
        'views' => 1200,
        'reads' => 780,
        'read_rate' => 0.65,
        'first_seen_at' => now()->subDays(10),
        'last_seen_at' => now()->subDay(),
        'meta' => ['conversions' => 5],
    ]);

    ContentAiVisibilitySnapshot::query()->create([
        'content_id' => $content->id,
        'provider' => 'openai',
        'visibility_score' => 76,
        'citation_count' => 9,
        'entities_detected' => ['agentic marketing', 'AI visibility'],
        'captured_at' => now(),
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Agentic Marketing Growth',
        'slug' => 'agentic-marketing-growth-'.Str::lower(Str::random(6)),
        'objective' => 'Grow agentic marketing authority.',
        'status' => CampaignStatus::ACTIVE->value,
        'approval_status' => CampaignApprovalStatus::APPROVED->value,
    ]);

    $cta = CampaignCtaPreset::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'Book demo',
        'intent' => 'demo',
        'label' => 'Book a demo',
    ]);

    $campaignContent = CampaignContent::query()->create([
        'campaign_id' => $campaign->id,
        'content_id' => $content->id,
        'cta_preset_id' => $cta->id,
        'asset_type' => CampaignContentAssetType::ARTICLE->value,
        'status' => 'published',
        'approval_status' => CampaignApprovalStatus::APPROVED->value,
        'sequence_order' => 1,
        'working_title' => $content->title,
    ]);

    $account = SocialAccount::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'platform' => SocialPlatform::LINKEDIN->value,
        'account_type' => 'organization',
        'display_name' => 'Argusly LinkedIn',
        'status' => 'connected',
    ]);

    $variant = SocialPostVariant::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'campaign_id' => $campaign->id,
        'campaign_content_id' => $campaignContent->id,
        'content_id' => $content->id,
        'social_account_id' => $account->id,
        'platform' => SocialPlatform::LINKEDIN->value,
        'post_type' => SocialPostType::THOUGHT_LEADERSHIP->value,
        'status' => SocialPostVariantStatus::APPROVED->value,
        'variant_number' => 1,
        'hook' => 'Most AI content fails because it has no operating model.',
        'body' => 'Agentic marketing changes how teams plan and optimize content.',
        'generation_prompt_context' => ['tone' => 'thought_leadership'],
        'approved_at' => now(),
    ]);

    $publication = SocialPublication::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_post_variant_id' => $variant->id,
        'campaign_id' => $campaign->id,
        'platform' => SocialPlatform::LINKEDIN->value,
        'status' => SocialPublicationStatus::PUBLISHED->value,
        'published_at' => now()->subDays(3),
    ]);

    SocialEngagementMetric::query()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_publication_id' => $publication->id,
        'platform' => SocialPlatform::LINKEDIN->value,
        'measured_at' => now(),
        'impressions' => 6500,
        'reach' => 5200,
        'likes' => 310,
        'comments' => 42,
        'shares' => 36,
        'clicks' => 84,
        'follows' => 19,
        'engagement_rate' => 0.18,
    ]);

    $result = app(LearningOptimizationEngine::class)->run($workspace);

    expect($result['content_profiles'])->toBe(1);
    expect($result['campaign_profiles'])->toBe(1);
    expect(ContentLearningProfile::query()->where('content_id', $content->id)->exists())->toBeTrue();
    expect(CampaignLearningProfile::query()->where('campaign_id', $campaign->id)->exists())->toBeTrue();

    $profile = ContentLearningProfile::query()->where('content_id', $content->id)->firstOrFail();
    expect($profile->performance_score)->toBeGreaterThan(60);
    expect($profile->hook_analysis['best_hook']['hook'])->toContain('operating model');
    expect($profile->cta_analysis['cta_presets'])->toContain('Book demo');
    expect($profile->topic_analysis['primary_topic'])->toBe('agentic marketing');
    expect($profile->ai_visibility_trend['trend']['latest_citations'])->toBe(9);

    expect(LearningRecommendation::query()->where('workspace_id', $workspace->id)->where('type', 'repost')->exists())->toBeTrue();
    expect(LearningRecommendation::query()->where('workspace_id', $workspace->id)->where('type', 'campaign_expansion')->exists())->toBeTrue();
    expect(SocialRepostSuggestion::query()->where('social_publication_id', $publication->id)->exists())->toBeTrue();
});
