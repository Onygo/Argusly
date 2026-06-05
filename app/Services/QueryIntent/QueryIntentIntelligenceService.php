<?php

namespace App\Services\QueryIntent;

use App\DTO\QueryIntent\QueryIntentClassificationData;
use App\DTO\QueryIntent\QueryIntentInput;
use App\Models\QueryIntentClassification;
use Illuminate\Support\Str;

class QueryIntentIntelligenceService
{
    public function __construct(
        private readonly QueryIntentTextNormalizer $normalizer,
        private readonly QueryIntentClassifier $intentClassifier,
        private readonly FunnelStageMapper $funnelStageMapper,
        private readonly BuyerRoleClassifier $buyerRoleClassifier,
        private readonly UrgencyClassifier $urgencyClassifier,
        private readonly BusinessImpactScoringEngine $businessImpactScoringEngine,
        private readonly QueryIntentScoringEngine $scoringEngine,
        private readonly QueryIntentAiEnrichmentService $aiEnrichmentService,
    ) {}

    public function classify(QueryIntentInput $input): QueryIntentClassificationData
    {
        $text = $this->normalizer->normalize($input->combinedText());
        $intent = $this->intentClassifier->classify($text);
        $funnelStage = $this->funnelStageMapper->map($intent['primary'], $text);
        $buyerRole = $this->buyerRoleClassifier->classify($text);
        $urgency = $this->urgencyClassifier->classify($text, $intent['primary']);
        $impact = $this->businessImpactScoringEngine->score($intent['primary'], $funnelStage, $buyerRole['role'], $text);
        $priority = $this->scoringEngine->score($intent['confidence'], $urgency['score'], $impact['score'], $funnelStage);

        $classification = [
            'primary_intent' => $intent['primary'],
            'secondary_intents' => $intent['secondary'],
            'funnel_stage' => $funnelStage,
            'buyer_role' => $buyerRole['role'],
            'urgency' => $urgency['level'],
            'business_impact' => $impact['level'],
            'intent_confidence' => $intent['confidence'],
            'urgency_score' => $urgency['score'],
            'business_impact_score' => $impact['score'],
            'priority_score' => $priority['priority_score'],
        ];

        $signals = array_merge($intent['signals'], $buyerRole['signals'], $urgency['signals']);
        $scoreBreakdown = array_merge($impact['breakdown'], $priority['breakdown']);
        $aiEnrichment = $this->aiEnrichmentService->enrich($input, $classification);
        $normalized = [
            'schema' => 'query_intent_intelligence.v1',
            'input' => [
                'title' => $input->title,
                'query' => $input->query,
                'locale' => $input->locale,
                'source_type' => $input->sourceType,
                'source_key' => $input->sourceKey,
                'text_excerpt' => Str::limit($text, 800, ''),
            ],
            'classification' => $classification,
            'signals' => $signals,
            'score_breakdown' => $scoreBreakdown,
            'ai_enrichment' => $aiEnrichment,
        ];

        return new QueryIntentClassificationData(
            primaryIntent: $intent['primary'],
            secondaryIntents: $intent['secondary'],
            funnelStage: $funnelStage,
            buyerRole: $buyerRole['role'],
            urgency: $urgency['level'],
            businessImpact: $impact['level'],
            intentConfidence: $intent['confidence'],
            urgencyScore: $urgency['score'],
            businessImpactScore: $impact['score'],
            priorityScore: $priority['priority_score'],
            scoreBreakdown: $scoreBreakdown,
            signals: $signals,
            aiEnrichment: $aiEnrichment,
            normalizedPayload: $normalized,
        );
    }

    public function classifyAndPersist(QueryIntentInput $input): QueryIntentClassification
    {
        $data = $this->classify($input);
        $classifiable = $input->classifiable;

        return QueryIntentClassification::query()->updateOrCreate(
            [
                'workspace_id' => $input->workspaceId,
                'payload_hash' => $input->payloadHash(),
            ],
            array_merge($data->toArray(), [
                'organization_id' => $input->organizationId,
                'client_site_id' => $input->clientSiteId,
                'classifiable_type' => $classifiable?->getMorphClass(),
                'classifiable_id' => $classifiable?->getKey(),
                'source_type' => $input->sourceType ?: 'manual',
                'source_key' => $input->sourceKey,
                'locale' => $input->locale,
                'title' => $input->title,
                'query' => $input->query,
                'text_excerpt' => Str::limit($this->normalizer->normalize((string) $input->text), 2000, ''),
                'payload_hash' => $input->payloadHash(),
                'classified_at' => now(),
            ])
        );
    }
}
