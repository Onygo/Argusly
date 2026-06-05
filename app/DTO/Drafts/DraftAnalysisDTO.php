<?php

namespace App\DTO\Drafts;

use App\Models\DraftAnalysis;

class DraftAnalysisDTO
{
    /**
     * @param array<int,array<string,mixed>> $internalLinkOpportunities
     * @param array<string,mixed> $suggestions
     * @param array<int,string> $parserErrors
     * @param array<int,string> $validationErrors
     */
    public function __construct(
        public readonly ?int $seoScore,
        public readonly ?int $readabilityScore,
        public readonly ?int $ctaScore,
        public readonly ?int $headingsScore,
        public readonly ?int $llmVisibilityScore,
        public readonly ?int $brandVoiceFitScore,
        public readonly ?int $conversionFitScore,
        public readonly ?int $trustEvidenceScore,
        public readonly ?int $publishReadinessScore,
        public readonly ?string $publishReadinessStatus,
        public readonly array $publishReadinessBlockingIssues,
        public readonly array $publishReadinessNextActions,
        public readonly ?int $keywordCoverage,
        public readonly ?int $entityCoverage,
        public readonly array $internalLinkOpportunities,
        public readonly array $normalizedPayload,
        public readonly array $signalsPayload,
        public readonly ?string $analysisModel,
        public readonly ?string $analysisProvider = null,
        public readonly ?string $promptVersion = null,
        public readonly ?string $snapshotSignature = null,
        public readonly int $tokensUsed = 0,
        public readonly string $status = DraftAnalysis::STATUS_COMPLETED,
        public readonly ?string $rawResponse = null,
        public readonly array $parserErrors = [],
        public readonly array $validationErrors = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toModelAttributes(): array
    {
        return [
            'status' => $this->status,
            'seo_score' => $this->seoScore,
            'readability_score' => $this->readabilityScore,
            'cta_score' => $this->ctaScore,
            'headings_score' => $this->headingsScore,
            'llm_visibility_score' => $this->llmVisibilityScore,
            'brand_voice_fit_score' => $this->brandVoiceFitScore,
            'conversion_fit_score' => $this->conversionFitScore,
            'trust_evidence_score' => $this->trustEvidenceScore,
            'publish_readiness_score' => $this->publishReadinessScore,
            'publish_readiness_status' => $this->publishReadinessStatus,
            'publish_readiness_blocking_issues' => $this->publishReadinessBlockingIssues,
            'publish_readiness_next_actions' => $this->publishReadinessNextActions,
            'keyword_coverage' => $this->keywordCoverage,
            'entity_coverage' => $this->entityCoverage,
            'internal_link_opportunities' => $this->internalLinkOpportunities,
            'suggestions' => $this->normalizedPayload,
            'normalized_payload' => $this->normalizedPayload,
            'signals_payload' => $this->signalsPayload,
            'analysis_model' => $this->analysisModel,
            'analysis_provider' => $this->analysisProvider,
            'prompt_version' => $this->promptVersion,
            'snapshot_signature' => $this->snapshotSignature,
            'tokens_used' => $this->tokensUsed,
            'raw_response' => $this->rawResponse,
            'parser_errors' => ! empty($this->parserErrors) ? $this->parserErrors : null,
            'validation_errors' => ! empty($this->validationErrors) ? $this->validationErrors : null,
        ];
    }

    public static function fromModel(DraftAnalysis $analysis): self
    {
        return new self(
            seoScore: $analysis->seo_score,
            readabilityScore: $analysis->readability_score,
            ctaScore: $analysis->cta_score,
            headingsScore: $analysis->headings_score,
            llmVisibilityScore: $analysis->llm_visibility_score,
            brandVoiceFitScore: $analysis->brand_voice_fit_score,
            conversionFitScore: $analysis->conversion_fit_score,
            trustEvidenceScore: $analysis->trust_evidence_score,
            publishReadinessScore: $analysis->publish_readiness_score,
            publishReadinessStatus: $analysis->publish_readiness_status,
            publishReadinessBlockingIssues: (array) ($analysis->publish_readiness_blocking_issues ?? []),
            publishReadinessNextActions: (array) ($analysis->publish_readiness_next_actions ?? []),
            keywordCoverage: $analysis->keyword_coverage,
            entityCoverage: $analysis->entity_coverage,
            internalLinkOpportunities: (array) ($analysis->internal_link_opportunities ?? []),
            normalizedPayload: $analysis->canonicalPayload(),
            signalsPayload: (array) ($analysis->signals_payload ?? []),
            analysisModel: $analysis->analysis_model,
            analysisProvider: $analysis->analysis_provider,
            promptVersion: $analysis->prompt_version,
            snapshotSignature: $analysis->snapshot_signature,
            tokensUsed: (int) ($analysis->tokens_used ?? 0),
            status: $analysis->effective_status ?? $analysis->status ?? DraftAnalysis::STATUS_COMPLETED,
            rawResponse: $analysis->raw_response,
            parserErrors: (array) ($analysis->parser_errors ?? []),
            validationErrors: (array) ($analysis->validation_errors ?? []),
        );
    }

    public function isComplete(): bool
    {
        return $this->status === DraftAnalysis::STATUS_COMPLETED;
    }

    public function isPartial(): bool
    {
        return $this->status === DraftAnalysis::STATUS_PARTIAL;
    }

    public function isFailed(): bool
    {
        return $this->status === DraftAnalysis::STATUS_FAILED;
    }
}
