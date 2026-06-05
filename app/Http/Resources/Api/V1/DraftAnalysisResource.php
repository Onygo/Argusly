<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DraftAnalysis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DraftAnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->effective_status ?? $this->status ?? DraftAnalysis::STATUS_COMPLETED;
        $payload = $this->canonicalPayload();

        return [
            'id' => (string) $this->id,
            'draft_id' => (string) $this->draft_id,
            'status' => $status,
            'seo_score' => $this->seo_score,
            'readability_score' => $this->readability_score,
            'cta_score' => $this->cta_score,
            'keyword_coverage' => $this->keyword_coverage,
            'entity_coverage' => $this->entity_coverage,
            'internal_link_opportunities' => (array) ($this->internal_link_opportunities ?? []),
            'normalized_payload' => $payload,
            'suggestions' => $payload,
            'analysis_model' => $this->analysis_model,
            'analysis_provider' => $this->analysis_provider,
            'prompt_version' => $this->prompt_version,
            'tokens_used' => (int) ($this->tokens_used ?? 0),
            'parser_errors' => (array) ($this->parser_errors ?? []),
            'validation_errors' => (array) ($this->validation_errors ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_complete' => $status === DraftAnalysis::STATUS_COMPLETED,
            'is_partial' => $status === DraftAnalysis::STATUS_PARTIAL,
            'is_failed' => $status === DraftAnalysis::STATUS_FAILED,
        ];
    }
}
