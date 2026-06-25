<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ContentDestinationStatus;
use App\Enums\EmailMarketingProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailMarketingConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $provider = $this->provider;
        $status = $this->status;

        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'name' => (string) $this->name,
            'provider' => $provider instanceof EmailMarketingProvider ? $provider->value : (string) $provider,
            'provider_label' => $provider instanceof EmailMarketingProvider ? $provider->label() : null,
            'status' => $status instanceof ContentDestinationStatus ? $status->value : (string) $status,
            'config' => $this->sanitizedConfig(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
