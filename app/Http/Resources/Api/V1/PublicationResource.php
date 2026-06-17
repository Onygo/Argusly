<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'article_id' => (string) $this->content_id,
            'provider' => (string) $this->provider,
            'type' => (string) ($this->type ?? $this->provider),
            'destination' => [
                'id' => $this->destination_id ? (string) $this->destination_id : null,
                'name' => $this->destination?->name,
                'type' => $this->destination?->type?->value ?? $this->destination?->type,
            ],
            'delivery' => [
                'status' => (string) $this->delivery_status,
                'last_delivered_at' => $this->last_delivered_at?->toIso8601String(),
                'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            ],
            'remote' => [
                'id' => $this->remote_id,
                'url' => $this->remote_url,
                'type' => $this->remote_type,
                'status' => $this->remote_status,
            ],
            'error' => [
                'code' => $this->last_error_code,
                'message' => $this->publicErrorMessage(),
                'at' => $this->last_error_at?->toIso8601String(),
            ],
            'timestamps' => [
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ],
            'links' => [
                'self' => url('/api/v1/articles/'.$this->content_id.'/publications/'.$this->id),
                'article' => url('/api/v1/articles/'.$this->content_id),
            ],
        ];
    }
}
