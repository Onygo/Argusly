<?php

namespace App\Services\Mos\Opportunity\Providers;

use App\Models\CompetitorContentOpportunity;
use App\Services\Mos\Opportunity\CanonicalOpportunityCandidate;
use Illuminate\Database\Eloquent\Model;

class CompetitorContentOpportunityProvider extends AbstractLegacyOpportunityProvider
{
    public function key(): string
    {
        return 'legacy-competitor-content-opportunities';
    }

    public function label(): string
    {
        return 'Legacy Competitor Content Opportunities';
    }

    public function sourceModel(): ?string
    {
        return CompetitorContentOpportunity::class;
    }

    public function supportedOpportunityTypes(): array
    {
        return ['competitor_movement', 'content_gap'];
    }

    public function supportedLifecycleStates(): array
    {
        return ['open', 'planned', 'dismissed', 'archived'];
    }

    public function canEmitSignals(): bool
    {
        return true;
    }

    public function migrationReadiness(): string
    {
        return 'signal_first_recommended';
    }

    public function classification(): string
    {
        return 'source_evidence_candidate';
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function toCanonicalOpportunity(Model $source): CanonicalOpportunityCandidate
    {
        return new CanonicalOpportunityCandidate(
            title: $this->stringValue($source->getAttribute('title')),
            description: $this->stringValue($source->getAttribute('reason'))
                ?? $this->stringValue($source->getAttribute('attackable_angle')),
            type: 'competitor_movement',
            source: $this->sourceType(),
            sourceModel: $source::class,
            sourceId: $source->getKey(),
            priority: $this->floatValue($source->getAttribute('priority_score')),
            confidence: $this->floatValue($source->getAttribute('confidence_score')),
            impact: $this->floatValue($source->getAttribute('impact_score')),
            effort: $this->floatValue($source->getAttribute('effort_score')),
            businessValue: null,
            evidence: $this->listValue($source->getAttribute('competitor_evidence')),
            recommendedActions: array_values(array_filter([
                $source->getAttribute('recommended_format'),
                $source->getAttribute('attackable_angle'),
            ])),
            lifecycleStatus: $this->stringValue($source->getAttribute('status')),
            context: $this->context($source),
            relatedReferences: $this->references([
                'site_competitor_id' => $source->getAttribute('site_competitor_id'),
                'competitor_intelligence_run_id' => $source->getAttribute('competitor_intelligence_run_id'),
                'topic' => $source->getAttribute('topic'),
                'argusly_coverage' => $source->getAttribute('argusly_coverage'),
            ]),
            dedupeKey: $this->stringValue($source->getAttribute('dedupe_hash')),
            missingFields: $this->missingFields($source),
        );
    }

    public function missingFields(Model $source): array
    {
        return $this->missingFromMap([
            'title' => $source->getAttribute('title'),
            'status' => $source->getAttribute('status'),
            'workspace_id' => $source->getAttribute('workspace_id'),
            'priority' => $source->getAttribute('priority_score'),
            'confidence' => $source->getAttribute('confidence_score'),
            'dedupe_key' => $source->getAttribute('dedupe_hash'),
        ]);
    }
}
