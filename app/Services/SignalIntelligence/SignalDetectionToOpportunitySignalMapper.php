<?php

namespace App\Services\SignalIntelligence;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\SignalDetection;
use App\Services\OpportunityIntelligence\OpportunitySignalPayload;

class SignalDetectionToOpportunitySignalMapper
{
    public function map(SignalDetection $detection): OpportunitySignalPayload
    {
        [$source, $category] = $this->sourceAndCategory($detection);
        $eventIds = $detection->relationLoaded('events')
            ? $detection->events->pluck('id')->map(fn ($id): string => (string) $id)->values()->all()
            : $detection->events()->pluck('signal_events.id')->map(fn ($id): string => (string) $id)->values()->all();

        return new OpportunitySignalPayload(
            source: $source,
            category: $category,
            topic: $detection->primary_topic ?: $detection->type,
            entity: $detection->primary_entity,
            signalStrength: (float) ($detection->opportunity_score ?: $detection->priority_score ?: 0),
            confidence: (float) ($detection->confidence_score ?? 50),
            metrics: [
                'priority_score' => (float) ($detection->priority_score ?? 0),
                'confidence_score' => (float) ($detection->confidence_score ?? 0),
                'impact_score' => (float) ($detection->impact_score ?? 0),
                'urgency_score' => (float) ($detection->urgency_score ?? 0),
                'risk_score' => (float) ($detection->risk_score ?? 0),
                'opportunity_score' => (float) ($detection->opportunity_score ?? 0),
            ],
            evidence: [
                'title' => $detection->title,
                'summary' => $detection->summary,
                'score_breakdown' => $detection->score_breakdown ?? [],
                'evidence_summary' => $detection->evidence_summary ?? [],
                'recommended_actions' => $detection->recommended_actions ?? [],
            ],
            metadata: [
                'title' => $detection->title,
                'summary' => $detection->summary,
                'signal_detection_id' => (string) $detection->id,
                'signal_detection_category' => (string) $detection->category,
                'signal_detection_type' => (string) $detection->type,
                'signal_priority_score' => (float) ($detection->priority_score ?? 0),
                'linked_signal_event_ids' => $eventIds,
                'source_context' => 'signal_intelligence_promotion',
            ],
            clientSiteId: $detection->client_site_id ? (string) $detection->client_site_id : null,
            observedAt: $detection->last_seen_at ?? $detection->first_seen_at ?? now(),
        );
    }

    /**
     * @return array{0:OpportunitySignalSource,1:OpportunityCategory}
     */
    private function sourceAndCategory(SignalDetection $detection): array
    {
        return match ((string) $detection->category) {
            SignalDetection::CATEGORY_OPPORTUNITY_DETECTION => [
                OpportunitySignalSource::SIGNAL_INTELLIGENCE,
                str_contains((string) $detection->type, 'content_gap')
                    ? OpportunityCategory::CONTENT_GAP
                    : OpportunityCategory::AI_VISIBILITY_OPPORTUNITY,
            ],
            SignalDetection::CATEGORY_TREND_DETECTION => [
                OpportunitySignalSource::SIGNAL_INTELLIGENCE,
                str_contains((string) $detection->type, 'content_gap')
                    ? OpportunityCategory::CONTENT_GAP
                    : OpportunityCategory::TREND_OPPORTUNITY,
            ],
            SignalDetection::CATEGORY_COMPETITOR_MONITORING => [
                OpportunitySignalSource::COMPETITOR_INTELLIGENCE,
                OpportunityCategory::COMPETITOR_MOVEMENT,
            ],
            SignalDetection::CATEGORY_RISK_DETECTION => [
                OpportunitySignalSource::SIGNAL_INTELLIGENCE,
                str_contains((string) $detection->type, 'competitor')
                    ? OpportunityCategory::COMPETITOR_MOVEMENT
                    : OpportunityCategory::REFRESH_OPPORTUNITY,
            ],
            SignalDetection::CATEGORY_BRAND_MONITORING => [
                OpportunitySignalSource::SIGNAL_INTELLIGENCE,
                str_contains((string) $detection->type, 'visibility')
                    ? OpportunityCategory::AI_VISIBILITY_OPPORTUNITY
                    : OpportunityCategory::BRAND_VISIBILITY,
            ],
            default => [
                OpportunitySignalSource::SIGNAL_INTELLIGENCE,
                OpportunityCategory::CONTENT_GAP,
            ],
        };
    }
}
