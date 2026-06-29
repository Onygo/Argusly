<?php

namespace App\Services\Mos\Opportunity\Providers;

use App\Models\LinkOpportunity;
use App\Services\Mos\Opportunity\CanonicalOpportunityCandidate;
use Illuminate\Database\Eloquent\Model;

class LinkOpportunityProvider extends AbstractLegacyOpportunityProvider
{
    public function key(): string
    {
        return 'legacy-link-opportunities';
    }

    public function label(): string
    {
        return 'Legacy Link Opportunities';
    }

    public function sourceModel(): ?string
    {
        return LinkOpportunity::class;
    }

    public function supportedOpportunityTypes(): array
    {
        return ['internal_link_recommendation'];
    }

    public function supportedLifecycleStates(): array
    {
        return [
            LinkOpportunity::STATUS_SUGGESTED,
            LinkOpportunity::STATUS_APPLIED,
            LinkOpportunity::STATUS_REJECTED,
        ];
    }

    public function canEmitCanonicalOpportunities(): bool
    {
        return false;
    }

    public function migrationReadiness(): string
    {
        return 'tactical_projection_not_strategic_opportunity';
    }

    public function classification(): string
    {
        return 'projection';
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function toCanonicalOpportunity(Model $source): CanonicalOpportunityCandidate
    {
        return new CanonicalOpportunityCandidate(
            title: $this->stringValue($source->getAttribute('anchor_text_suggestion'))
                ? 'Internal link: '.$this->stringValue($source->getAttribute('anchor_text_suggestion'))
                : null,
            description: $this->stringValue($source->getAttribute('context_snippet')),
            type: 'internal_link_recommendation',
            source: $this->sourceType(),
            sourceModel: $source::class,
            sourceId: $source->getKey(),
            priority: $this->floatValue($source->getAttribute('relevance_score')),
            confidence: $this->floatValue($source->getAttribute('relevance_score')),
            impact: null,
            effort: null,
            businessValue: null,
            evidence: $this->listValue($source->getAttribute('meta')),
            recommendedActions: array_values(array_filter([
                $source->getAttribute('anchor_text_suggestion'),
                $source->getAttribute('context_snippet'),
            ])),
            lifecycleStatus: $this->stringValue($source->getAttribute('status')),
            context: $this->context($source),
            relatedReferences: $this->references([
                'source_content_id' => $source->getAttribute('source_content_id'),
                'target_content_id' => $source->getAttribute('target_content_id'),
            ]),
            dedupeKey: hash('sha256', implode('|', array_filter([
                $this->key(),
                $source->getAttribute('workspace_id'),
                $source->getAttribute('source_content_id'),
                $source->getAttribute('target_content_id'),
                $source->getAttribute('anchor_text_suggestion'),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''))),
            missingFields: $this->missingFields($source),
            unsupportedReasons: [
                'LinkOpportunity is tactical link execution state; migrate as evidence or execution detail unless product treats links as strategic review work.',
            ],
        );
    }

    public function missingFields(Model $source): array
    {
        return $this->missingFromMap([
            'workspace_id' => $source->getAttribute('workspace_id'),
            'source_content_id' => $source->getAttribute('source_content_id'),
            'target_content_id' => $source->getAttribute('target_content_id'),
            'status' => $source->getAttribute('status'),
            'relevance_score' => $source->getAttribute('relevance_score'),
        ]);
    }
}
