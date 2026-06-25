<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\EmailMarketingExportStatus;
use App\Enums\EmailMarketingProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailCampaignExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $provider = $this->provider;
        $status = $this->status;

        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'campaign_id' => (string) $this->campaign_id,
            'campaign_content_id' => (string) $this->campaign_content_id,
            'connection_id' => (string) $this->email_marketing_connection_id,
            'provider' => $provider instanceof EmailMarketingProvider ? $provider->value : (string) $provider,
            'status' => $status instanceof EmailMarketingExportStatus ? $status->value : (string) $status,
            'remote' => [
                'campaign_id' => $this->remote_campaign_id,
                'template_id' => $this->remote_template_id,
                'url' => $this->remote_url,
            ],
            'payload' => $this->payload ?? [],
            'provider_response' => $this->provider_response ?? [],
            'error_message' => $this->error_message,
            'metrics' => $this->whenLoaded('metrics', fn () => [
                'sent' => (int) ($this->metrics?->sent ?? 0),
                'delivered' => (int) ($this->metrics?->delivered ?? 0),
                'opens' => (int) ($this->metrics?->opens ?? 0),
                'unique_opens' => (int) ($this->metrics?->unique_opens ?? 0),
                'clicks' => (int) ($this->metrics?->clicks ?? 0),
                'unique_clicks' => (int) ($this->metrics?->unique_clicks ?? 0),
                'bounces' => (int) ($this->metrics?->bounces ?? 0),
                'unsubscribes' => (int) ($this->metrics?->unsubscribes ?? 0),
                'conversions' => (int) ($this->metrics?->conversions ?? 0),
                'revenue' => (string) ($this->metrics?->revenue ?? '0.00'),
                'measured_at' => $this->metrics?->measured_at?->toIso8601String(),
            ]),
            'timestamps' => [
                'exported_at' => $this->exported_at?->toIso8601String(),
                'last_synced_at' => $this->last_synced_at?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
        ];
    }
}
