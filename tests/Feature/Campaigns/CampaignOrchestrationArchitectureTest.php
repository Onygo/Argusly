<?php

use App\Enums\CampaignContentAssetType;
use App\Enums\DistributionChannelType;
use App\Jobs\Campaigns\PlanCampaignDistributionJob;
use App\Models\Campaign;
use App\Models\CampaignContent;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\DistributionChannel;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('connects campaigns to content assets and distribution plans without publishing content', function (): void {
    $organization = Organization::query()->create([
        'name' => 'Campaign Architecture Org',
        'slug' => 'campaign-architecture-'.Str::random(6),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Campaign Architecture Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Campaign Site',
        'site_url' => 'https://campaigns.example.com',
        'base_url' => 'https://campaigns.example.com',
        'allowed_domains' => ['campaigns.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Foundational Agentic Marketing Article',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
    ]);

    $channel = DistributionChannel::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'name' => 'LinkedIn Company Page',
        'type' => DistributionChannelType::LINKEDIN,
        'provider' => 'linkedin',
        'status' => DistributionChannel::STATUS_ACTIVE,
        'capabilities' => ['post_text', 'schedule'],
    ]);

    $campaign = Campaign::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'Agentic Marketing Launch',
        'slug' => 'agentic-marketing-launch',
        'status' => 'planning',
        'channel_mix' => [
            ['distribution_channel_id' => (string) $channel->id, 'role' => 'primary_social'],
        ],
        'internal_linking_strategy' => [
            'pillar_content_id' => (string) $content->id,
            'rules' => ['link_supporting_assets_to_pillar' => true],
        ],
    ]);

    CampaignContent::query()->create([
        'campaign_id' => $campaign->id,
        'content_id' => $content->id,
        'asset_type' => CampaignContentAssetType::ARTICLE,
        'status' => 'planned',
        'sequence_order' => 1,
        'working_title' => $content->title,
    ]);

    CampaignContent::query()->create([
        'campaign_id' => $campaign->id,
        'source_content_id' => $content->id,
        'asset_type' => CampaignContentAssetType::LINKEDIN_POST,
        'status' => 'planned',
        'sequence_order' => 2,
        'working_title' => 'LinkedIn angle for agentic marketing',
    ]);

    $publishStatusBeforePlanning = $content->fresh()->publish_status;

    (new PlanCampaignDistributionJob((string) $campaign->id))->handle();

    $campaign->refresh()->load(['contents', 'distributionPlans']);

    expect($campaign->contents)->toHaveCount(2)
        ->and($campaign->distributionPlans)->toHaveCount(2)
        ->and($campaign->last_planned_at)->not->toBeNull()
        ->and($content->fresh()->publish_status)->toBe($publishStatusBeforePlanning);
});
