<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignCtaPresetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'name' => (string) $this->name,
            'intent' => $this->intent,
            'label' => $this->label,
            'destination_url' => $this->destination_url,
            'description' => $this->description,
            'rules' => $this->rules ?? [],
            'metadata' => $this->metadata ?? [],
            'is_default' => (bool) $this->is_default,
        ];
    }
}
