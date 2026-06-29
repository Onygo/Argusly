<?php

namespace App\Services\Mos\Opportunity\Providers;

use App\Enums\AgenticMarketingOpportunityStatus;
use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\CanonicalOpportunityCandidate;
use Illuminate\Database\Eloquent\Model;

class AgenticMarketingOpportunityProvider extends AbstractLegacyOpportunityProvider
{
    public function key(): string
    {
        return 'legacy-agentic-marketing-opportunities';
    }

    public function label(): string
    {
        return 'Legacy Agentic Marketing Opportunities';
    }

    public function sourceModel(): ?string
    {
        return AgenticMarketingOpportunity::class;
    }

    public function supportedOpportunityTypes(): array
    {
        return AgenticMarketingOpportunityType::values();
    }

    public function supportedLifecycleStates(): array
    {
        return AgenticMarketingOpportunityStatus::values();
    }

    public function canEmitSignals(): bool
    {
        return true;
    }

    public function migrationReadiness(): string
    {
        return 'canonical_link_exists_execution_state_blocked';
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
        $payload = (array) $source->getAttribute('payload');

        return new CanonicalOpportunityCandidate(
            title: $this->stringValue($source->getAttribute('title')),
            description: $this->stringValue(data_get($payload, 'summary'))
                ?? $this->stringValue(data_get($payload, 'reason')),
            type: $this->stringValue($source->getAttribute('type')),
            source: $this->sourceType(),
            sourceModel: $source::class,
            sourceId: $source->getKey(),
            priority: $this->floatValue($source->getAttribute('priority_score')),
            confidence: $this->floatValue(data_get($payload, 'confidence_score')),
            impact: $this->floatValue(data_get($payload, 'impact_score')),
            effort: $this->floatValue(data_get($payload, 'effort_score')),
            businessValue: $this->floatValue(data_get($payload, 'business_value_score')),
            evidence: $this->listValue(data_get($payload, 'evidence')),
            recommendedActions: $this->listValue(data_get($payload, 'recommended_actions')),
            lifecycleStatus: $this->stringValue($source->getAttribute('status')),
            context: array_filter([
                'objective_id' => $source->getAttribute('objective_id'),
                'content_id' => $source->getAttribute('content_id'),
                'workspace_id' => data_get($payload, 'workspace_id'),
                'organization_id' => data_get($payload, 'organization_id'),
            ], static fn (mixed $value): bool => $value !== null),
            relatedReferences: $this->references([
                'content_id' => $source->getAttribute('content_id'),
                'payload' => $payload,
            ]),
            dedupeKey: $this->stringValue($source->getAttribute('dedupe_hash')),
            missingFields: $this->missingFields($source),
        );
    }

    public function missingFields(Model $source): array
    {
        return $this->missingFromMap([
            'title' => $source->getAttribute('title'),
            'type' => $source->getAttribute('type'),
            'lifecycle_status' => $source->getAttribute('status'),
            'objective_id' => $source->getAttribute('objective_id'),
            'priority' => $source->getAttribute('priority_score'),
            'dedupe_key' => $source->getAttribute('dedupe_hash'),
        ]);
    }
}
