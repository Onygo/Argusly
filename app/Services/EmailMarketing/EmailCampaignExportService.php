<?php

namespace App\Services\EmailMarketing;

use App\Enums\EmailMarketingExportStatus;
use App\Models\CampaignContent;
use App\Models\EmailCampaignExport;
use App\Models\EmailMarketingConnection;
use Illuminate\Support\Str;

class EmailCampaignExportService
{
    public function __construct(
        private readonly EmailCampaignPayloadBuilder $payloadBuilder,
        private readonly EmailMarketingProviderRegistry $providers,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function export(CampaignContent $asset, EmailMarketingConnection $connection, array $overrides = []): EmailCampaignExport
    {
        $asset->loadMissing('campaign');
        $this->payloadBuilder->assertExportable($asset);

        if ((string) $asset->campaign?->workspace_id !== (string) $connection->workspace_id) {
            throw new EmailMarketingProviderException('Email marketing connection does not belong to this workspace.');
        }

        if (! $connection->isActive()) {
            throw new EmailMarketingProviderException('Email marketing connection is disabled.');
        }

        $payload = $this->payloadBuilder->build($asset, $this->withConnectionDefaults($connection, $overrides));
        $idempotencyKey = $this->idempotencyKey($connection, $asset, $payload);

        $export = EmailCampaignExport::query()->updateOrCreate(
            [
                'email_marketing_connection_id' => (string) $connection->id,
                'campaign_content_id' => (string) $asset->id,
            ],
            [
                'workspace_id' => (string) $connection->workspace_id,
                'campaign_id' => (string) $asset->campaign_id,
                'provider' => $connection->provider,
                'status' => EmailMarketingExportStatus::PENDING,
                'idempotency_key' => $idempotencyKey,
                'payload' => $payload,
                'error_message' => null,
            ]
        );

        data_set($payload, 'source.email_campaign_export_id', (string) $export->id);
        $export->forceFill(['payload' => $payload])->save();

        try {
            $result = $this->providers
                ->forConnection($connection)
                ->createDraft($connection, $payload, $idempotencyKey);

            $export->forceFill([
                'status' => EmailMarketingExportStatus::EXPORTED,
                'remote_campaign_id' => $result->remoteCampaignId,
                'remote_template_id' => $result->remoteTemplateId ?? data_get($payload, 'email.template_id'),
                'remote_url' => $result->remoteUrl,
                'provider_response' => $result->response,
                'error_message' => null,
                'exported_at' => now(),
                'last_synced_at' => now(),
            ])->save();

            $connection->forceFill(['last_used_at' => now()])->save();

            return $export->fresh(['connection', 'metrics']);
        } catch (EmailMarketingProviderException $exception) {
            $export->forceFill([
                'status' => EmailMarketingExportStatus::FAILED,
                'error_message' => Str::limit($exception->getMessage(), 5000, ''),
                'provider_response' => [
                    'retryable' => $exception->retryable,
                ],
            ])->save();

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function withConnectionDefaults(EmailMarketingConnection $connection, array $overrides): array
    {
        return array_filter(array_merge([
            'template_id' => $connection->configValue('default_template_id'),
            'audience_id' => $connection->configValue('default_audience_id'),
        ], $overrides), static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function idempotencyKey(EmailMarketingConnection $connection, CampaignContent $asset, array $payload): string
    {
        return hash('sha256', implode('|', [
            (string) $connection->id,
            (string) $asset->id,
            (string) data_get($payload, 'email.subject', ''),
            (string) data_get($payload, 'email.body', ''),
            (string) $asset->updated_at?->timestamp,
        ]));
    }
}
