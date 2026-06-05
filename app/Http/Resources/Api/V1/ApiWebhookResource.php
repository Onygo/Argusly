<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiWebhookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'content_destination_id' => $this->content_destination_id,
            'name' => (string) $this->name,
            'target_url' => (string) $this->target_url,
            'events' => is_array($this->events) ? $this->events : [],
            'is_active' => (bool) $this->is_active,
            'last_delivered_at' => $this->last_delivered_at?->toIso8601String(),
            'last_failure_at' => $this->last_failure_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
