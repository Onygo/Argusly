<?php

namespace App\Http\Resources\Api\V1;

use App\View\Presenters\CampaignContentAssetPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $asset = CampaignContentAssetPresenter::for($this->resource)->toArray();

        return [
            'id' => (string) $this->id,
            'campaign_id' => (string) $this->campaign_id,
            'content_id' => $this->content_id ? (string) $this->content_id : null,
            'source_content_id' => $this->source_content_id ? (string) $this->source_content_id : null,
            'asset_type' => (string) ($this->asset_type?->value ?? $this->asset_type),
            'asset' => $asset,
            'status' => (string) $this->status,
            'approval_status' => (string) ($this->approval_status?->value ?? $this->approval_status),
            'sequence_order' => (int) $this->sequence_order,
            'working_title' => $this->working_title,
            'target_locale' => $this->target_locale,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'brief' => $this->brief ?? [],
            'channel_requirements' => $this->channel_requirements ?? [],
            'ai_generation_context' => $this->ai_generation_context ?? [],
            'optimization_notes' => $this->optimization_notes ?? [],
            'internal_linking_targets' => $this->internal_linking_targets ?? [],
        ];
    }
}
