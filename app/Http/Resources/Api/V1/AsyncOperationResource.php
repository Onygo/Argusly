<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsyncOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'content_destination_id' => $this->content_destination_id,
            'api_key_id' => $this->api_key_id,
            'operation_type' => (string) ($this->operation_type?->value ?? $this->operation_type),
            'status' => (string) ($this->status?->value ?? $this->status),
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'request_payload' => is_array($this->request_payload) ? $this->request_payload : [],
            'result_payload' => is_array($this->result_payload) ? $this->result_payload : [],
            'error' => [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ],
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
