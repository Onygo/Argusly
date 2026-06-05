<?php

namespace App\DTO\QueryIntent;

class QueryIntentClassificationData
{
    public function __construct(
        public readonly string $primaryIntent,
        public readonly array $secondaryIntents,
        public readonly string $funnelStage,
        public readonly string $buyerRole,
        public readonly string $urgency,
        public readonly string $businessImpact,
        public readonly float $intentConfidence,
        public readonly float $urgencyScore,
        public readonly float $businessImpactScore,
        public readonly float $priorityScore,
        public readonly array $scoreBreakdown,
        public readonly array $signals,
        public readonly array $aiEnrichment,
        public readonly array $normalizedPayload,
    ) {}

    public function toArray(): array
    {
        return [
            'primary_intent' => $this->primaryIntent,
            'secondary_intents' => $this->secondaryIntents,
            'funnel_stage' => $this->funnelStage,
            'buyer_role' => $this->buyerRole,
            'urgency' => $this->urgency,
            'business_impact' => $this->businessImpact,
            'intent_confidence' => $this->intentConfidence,
            'urgency_score' => $this->urgencyScore,
            'business_impact_score' => $this->businessImpactScore,
            'priority_score' => $this->priorityScore,
            'score_breakdown' => $this->scoreBreakdown,
            'signals' => $this->signals,
            'ai_enrichment' => $this->aiEnrichment,
            'normalized_payload' => $this->normalizedPayload,
        ];
    }
}
