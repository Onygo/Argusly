<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributionChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'content_destination_id' => $this->content_destination_id ? (string) $this->content_destination_id : null,
            'name' => (string) $this->name,
            'type' => (string) ($this->type?->value ?? $this->type),
            'provider' => $this->provider,
            'status' => (string) $this->status,
            'environment' => (string) $this->environment,
            'capabilities' => $this->capabilities ?? [],
            'planning_rules' => $this->planning_rules ?? [],
            'metadata' => $this->metadata ?? [],
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
        ];
    }
}
