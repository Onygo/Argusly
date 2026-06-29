<?php

namespace App\Services\Mos\Opportunity\Providers;

use App\Models\ContentOpportunity;
use App\Services\Mos\Opportunity\CanonicalOpportunityCandidate;
use Illuminate\Database\Eloquent\Model;

class ContentOpportunityProvider extends AbstractLegacyOpportunityProvider
{
    public function key(): string
    {
        return 'legacy-content-opportunities';
    }

    public function label(): string
    {
        return 'Legacy Content Opportunities';
    }

    public function sourceModel(): ?string
    {
        return ContentOpportunity::class;
    }

    public function supportedOpportunityTypes(): array
    {
        return ['content_gap', 'refresh_opportunity', 'ai_visibility_opportunity'];
    }

    public function supportedLifecycleStates(): array
    {
        return [
            ContentOpportunity::STATUS_OPEN,
            ContentOpportunity::STATUS_PLANNED,
            ContentOpportunity::STATUS_DISMISSED,
            ContentOpportunity::STATUS_ARCHIVED,
        ];
    }

    public function canEmitSignals(): bool
    {
        return true;
    }

    public function migrationReadiness(): string
    {
        return 'high_value_with_existing_canonical_links';
    }

    public function classification(): string
    {
        return 'consolidation_candidate';
    }

    public function riskLevel(): string
    {
        return 'high';
    }

    public function toCanonicalOpportunity(Model $source): CanonicalOpportunityCandidate
    {
        $title = $this->stringValue($source->getAttribute('title'));
        $type = $this->stringValue($source->getAttribute('type')) ?: 'content_gap';
        $status = $this->stringValue($source->getAttribute('status'));
        $dedupe = $this->stringValue($source->getAttribute('dedupe_hash'));

        return new CanonicalOpportunityCandidate(
            title: $title,
            description: $this->stringValue($source->getAttribute('why_this_matters'))
                ?? $this->stringValue($source->getAttribute('reasoning')),
            type: $type,
            source: $this->sourceType(),
            sourceModel: $source::class,
            sourceId: $source->getKey(),
            priority: $this->floatValue($source->getAttribute('priority_score')),
            confidence: $this->floatValue($source->getAttribute('confidence_score')),
            impact: $this->floatValue($source->getAttribute('expected_impact')),
            effort: null,
            businessValue: $this->floatValue($source->getAttribute('business_value_score')),
            evidence: $this->listValue($source->getAttribute('source_signals')),
            recommendedActions: array_values(array_filter([
                $source->getAttribute('suggested_cta'),
                $source->getAttribute('suggested_schema'),
                $source->getAttribute('localization_recommendation'),
            ])),
            lifecycleStatus: $status,
            context: $this->context($source),
            relatedReferences: $this->references([
                'content_opportunity_run_id' => $source->getAttribute('content_opportunity_run_id'),
                'related_entities' => $source->getAttribute('related_entities'),
                'supporting_existing_content' => $source->getAttribute('supporting_existing_content'),
                'recommended_internal_links' => $source->getAttribute('recommended_internal_links'),
            ]),
            dedupeKey: $dedupe,
            missingFields: $this->missingFields($source),
        );
    }

    public function missingFields(Model $source): array
    {
        return $this->missingFromMap([
            'title' => $source->getAttribute('title'),
            'type' => $source->getAttribute('type'),
            'lifecycle_status' => $source->getAttribute('status'),
            'workspace_id' => $source->getAttribute('workspace_id'),
            'priority' => $source->getAttribute('priority_score'),
            'confidence' => $source->getAttribute('confidence_score'),
            'dedupe_key' => $source->getAttribute('dedupe_hash'),
        ]);
    }
}
