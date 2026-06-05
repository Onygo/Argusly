<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignToneProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'workspace_id' => (string) $this->workspace_id,
            'brand_voice_id' => $this->brand_voice_id ? (string) $this->brand_voice_id : null,
            'name' => (string) $this->name,
            'locale' => $this->locale,
            'summary' => $this->summary,
            'voice_attributes' => $this->voice_attributes ?? [],
            'rules' => $this->rules ?? [],
            'examples' => $this->examples ?? [],
            'is_default' => (bool) $this->is_default,
        ];
    }
}
