<?php

use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignContentAssetType;
use App\Enums\EmailMarketingExportStatus;
use App\Enums\EmailMarketingProvider;
use App\Models\Campaign;
use App\Models\CampaignContent;
use App\Models\EmailMarketingConnection;
use App\Services\EmailMarketing\EmailCampaignExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('exports a newsletter snippet to the configured DMT provider', function (): void {
    Http::fake([
        'https://digitalmarketingtools.test/api/argusly/campaign-snippets' => Http::response([
            'campaign_id' => 'dmt-campaign-123',
            'template_id' => 'dmt-template-default',
            'edit_url' => 'https://digitalmarketingtools.test/app/campaign-mail/campaigns/123',
        ], 201),
    ]);

    $campaign = Campaign::factory()->create([
        'metadata' => [
            'tracking_parameters' => [
                'utm_campaign' => 'summer-launch',
            ],
        ],
    ]);

    $asset = CampaignContent::factory()->create([
        'campaign_id' => $campaign->id,
        'asset_type' => CampaignContentAssetType::NEWSLETTER_SNIPPET,
        'approval_status' => CampaignApprovalStatus::APPROVED,
        'working_title' => 'Newsletter snippet: Summer launch',
        'metadata' => [
            'body' => 'A short campaign update for subscribers.',
            'cta' => [
                'label' => 'Read the campaign',
                'url' => 'https://example.com/summer-launch',
            ],
        ],
    ]);

    $connection = new EmailMarketingConnection([
        'workspace_id' => $campaign->workspace_id,
        'name' => 'DMT',
        'provider' => EmailMarketingProvider::DMT,
        'status' => 'active',
        'config' => [
            'base_url' => 'https://digitalmarketingtools.test',
            'draft_endpoint' => '/api/argusly/campaign-snippets',
            'default_template_id' => 'dmt-template-default',
        ],
    ]);
    $connection->setCredentials(['api_key' => 'secret-dmt-key']);
    $connection->save();

    $export = app(EmailCampaignExportService::class)->export($asset, $connection);

    expect($export->status)->toBe(EmailMarketingExportStatus::EXPORTED)
        ->and($export->remote_campaign_id)->toBe('dmt-campaign-123')
        ->and(data_get($export->payload, 'email.template_id'))->toBe('dmt-template-default')
        ->and(data_get($export->payload, 'tracking.utm.utm_campaign'))->toBe('summer-launch')
        ->and(data_get($export->payload, 'tracking.utm.utm_content'))->toBe((string) $asset->id)
        ->and(data_get($export->payload, 'email.cta_url'))->toContain('utm_content='.(string) $asset->id);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Argusly-Idempotency-Key')
        && $request->hasHeader('Authorization', 'Bearer secret-dmt-key')
        && data_get($request->data(), 'source.campaign_content_id') === (string) $asset->id);
});
