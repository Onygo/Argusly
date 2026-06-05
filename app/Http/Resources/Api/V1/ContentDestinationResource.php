<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentDestinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'name' => (string) $this->name,
            'type' => (string) ($this->type?->value ?? $this->type),
            'status' => (string) ($this->status?->value ?? $this->status),
            'environment' => (string) ($this->environment?->value ?? $this->environment),
            'default_language' => (string) $this->default_language,
            'default_content_type' => $this->default_content_type,
            'export_format' => $this->export_format,
            'tracking_enabled' => (bool) $this->tracking_enabled,
            'seo_audit_enabled' => (bool) $this->seo_audit_enabled,
            'webhook_url' => $this->webhook_url,
            'config' => method_exists($this->resource, 'sanitizedConfig')
                ? $this->resource->sanitizedConfig()
                : (is_array($this->config) ? $this->config : []),
            'latest_sync' => $this->whenLoaded('latestSyncAttempt', fn (): array => [
                'id' => (string) $this->latestSyncAttempt->id,
                'status' => (string) $this->latestSyncAttempt->status,
                'response_status' => $this->latestSyncAttempt->response_status,
                'created_at' => $this->latestSyncAttempt->created_at?->toIso8601String(),
                'delivered_at' => $this->latestSyncAttempt->delivered_at?->toIso8601String(),
                'failed_at' => $this->latestSyncAttempt->failed_at?->toIso8601String(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
