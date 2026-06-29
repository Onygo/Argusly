<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticCanonicalMappingResult
{
    /**
     * @param  array<int,string>  $missingContext
     * @param  array<int,string>  $blockedReasons
     */
    public function __construct(
        public readonly string $detectorKey,
        public readonly AgenticDetectorClassification $classification,
        public readonly bool $canEmitSignal,
        public readonly bool $canEmitCanonicalOpportunityCandidate,
        public readonly bool $executionOnly,
        public readonly bool $requiredContextPresent,
        public readonly array $missingContext,
        public readonly ?AgenticCanonicalSignalPreview $signalPreview,
        public readonly ?AgenticCanonicalOpportunityPreview $opportunityPreview,
        public readonly string $dedupeKey,
        public readonly array $blockedReasons,
        public readonly string $riskLevel,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'detector_key' => $this->detectorKey,
            'classification' => $this->classification->value,
            'can_emit_signal' => $this->canEmitSignal,
            'can_emit_canonical_opportunity_candidate' => $this->canEmitCanonicalOpportunityCandidate,
            'execution_only' => $this->executionOnly,
            'required_context_present' => $this->requiredContextPresent,
            'missing_context' => $this->missingContext,
            'signal_preview' => $this->signalPreview?->toArray(),
            'opportunity_preview' => $this->opportunityPreview?->toArray(),
            'dedupe_key' => $this->dedupeKey,
            'blocked_reasons' => $this->blockedReasons,
            'risk_level' => $this->riskLevel,
        ];
    }
}
