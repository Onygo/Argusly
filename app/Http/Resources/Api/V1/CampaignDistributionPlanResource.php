<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignDistributionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'campaign_id' => (string) $this->campaign_id,
            'campaign_content_id' => $this->campaign_content_id ? (string) $this->campaign_content_id : null,
            'distribution_channel_id' => (string) $this->distribution_channel_id,
            'asset_type' => $this->asset_type?->value ?? $this->asset_type,
            'status' => (string) ($this->status?->value ?? $this->status),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'distributed_at' => $this->distributed_at?->toIso8601String(),
            'payload' => $this->payload ?? [],
            'planning_notes' => $this->planning_notes ?? [],
            'result' => $this->result ?? [],
            'last_error' => $this->last_error,
        ];
    }
}
