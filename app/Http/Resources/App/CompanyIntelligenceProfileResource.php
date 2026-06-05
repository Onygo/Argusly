<?php

namespace App\Http\Resources\App;

use App\Services\CompanyIntelligence\CompanyIntelligenceNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyIntelligenceProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $normalized = app(CompanyIntelligenceNormalizer::class)->normalize($this->resource);

        return [
            'id' => (string) $this->id,
            'organization_id' => (int) $this->organization_id,
            'workspace_id' => (string) $this->workspace_id,
            'brand_key' => (string) $this->brand_key,
            'company_name' => (string) $this->company_name,
            'status' => (string) $this->status,
            'is_default' => (bool) $this->is_default,
            'completeness_score' => (int) $this->completeness_score,
            'completeness_breakdown' => (array) $this->completeness_breakdown,
            'embedding_status' => (string) $this->embedding_status,
            'embedding_payload_hash' => $this->embedding_payload_hash,
            'normalized_payload_hash' => $this->normalized_payload_hash,
            'ai_payload' => $normalized->payload,
            'embedding_text' => $normalized->embeddingText,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
