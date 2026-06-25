<?php

use App\Enums\CampaignContentAssetType;
use App\Models\Campaign;
use App\Models\CampaignContent;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Services\EmailMarketing\EmailCampaignPayloadBuilder;
use Illuminate\Support\Str;

it('builds newsletter snippet copy from linked content when no explicit body exists', function (): void {
    $campaign = new Campaign([
        'id' => (string) Str::uuid(),
        'name' => 'AEO launch',
        'slug' => 'aeo-launch',
        'metadata' => [
            'tracking_parameters' => [
                'utm_campaign' => 'aeo-launch',
            ],
        ],
    ]);

    $version = new ContentVersion([
        'body' => '<p>This article explains why answer engine visibility needs clear entity signals, concise summaries, and useful follow-up paths for readers.</p>',
    ]);

    $content = new Content([
        'title' => 'Visibility Answer Engine Optimization',
        'published_url' => 'https://example.com/aeo',
    ]);
    $content->setRelation('currentVersion', $version);

    $asset = new CampaignContent([
        'id' => (string) Str::uuid(),
        'campaign_id' => (string) Str::uuid(),
        'asset_type' => CampaignContentAssetType::NEWSLETTER_SNIPPET,
        'working_title' => 'Newsletter snippet: AEO',
        'brief' => [
            'angle' => 'Summarize the pillar and point readers to the campaign path.',
            'description' => 'Planning instruction, not email copy.',
        ],
        'metadata' => [
            'cta' => ['label' => 'Lees verder'],
        ],
    ]);
    $asset->setRelation('campaign', $campaign);
    $asset->setRelation('content', $content);

    $payload = app(EmailCampaignPayloadBuilder::class)->build($asset);

    expect(data_get($payload, 'email.body'))
        ->toContain('Visibility Answer Engine Optimization')
        ->toContain('answer engine visibility')
        ->not->toContain('Summarize the pillar')
        ->and(data_get($payload, 'email.cta_url'))->toContain('https://example.com/aeo')
        ->and(data_get($payload, 'asset.source_title'))->toBe('Visibility Answer Engine Optimization');
});
