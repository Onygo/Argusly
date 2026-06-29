<?php

namespace App\Services\Mos\Opportunity\Providers;

use App\Enums\ProgrammaticPatternType;
use App\Models\ProgrammaticOpportunity;
use App\Services\Mos\Opportunity\CanonicalOpportunityCandidate;
use Illuminate\Database\Eloquent\Model;

class ProgrammaticOpportunityProvider extends AbstractLegacyOpportunityProvider
{
    public function key(): string
    {
        return 'legacy-programmatic-opportunities';
    }

    public function label(): string
    {
        return 'Legacy Programmatic Opportunities';
    }

    public function sourceModel(): ?string
    {
        return ProgrammaticOpportunity::class;
    }

    public function supportedOpportunityTypes(): array
    {
        return ProgrammaticPatternType::values();
    }

    public function supportedLifecycleStates(): array
    {
        return [
            ProgrammaticOpportunity::STATUS_DETECTED,
            ProgrammaticOpportunity::STATUS_VALIDATED,
            ProgrammaticOpportunity::STATUS_REJECTED,
            ProgrammaticOpportunity::STATUS_PLANNED,
            ProgrammaticOpportunity::STATUS_EXPANDED,
        ];
    }

    public function migrationReadiness(): string
    {
        return 'specialized_expansion_state_keep_separate';
    }

    public function classification(): string
    {
        return 'provider_candidate';
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function toCanonicalOpportunity(Model $source): CanonicalOpportunityCandidate
    {
        $pattern = $this->stringValue($source->getAttribute('pattern_type'));
        $baseTopic = $this->stringValue($source->getAttribute('base_topic'));

        return new CanonicalOpportunityCandidate(
            title: $baseTopic ? 'Programmatic opportunity: '.$baseTopic : null,
            description: $this->stringValue(data_get($source->getAttribute('explanation'), 'summary')),
            type: $pattern,
            source: $this->sourceType(),
            sourceModel: $source::class,
            sourceId: $source->getKey(),
            priority: $this->floatValue($source->getAttribute('scale_score')),
            confidence: $this->floatValue($source->getAttribute('confidence_score')),
            impact: $this->floatValue($source->getAttribute('ai_visibility_score'))
                ?? $this->floatValue($source->getAttribute('seo_opportunity_score')),
            effort: $this->floatValue($source->getAttribute('competition_score')),
            businessValue: $this->floatValue($source->getAttribute('business_value_score')),
            evidence: $this->listValue($source->getAttribute('explanation')),
            recommendedActions: $this->listValue($source->getAttribute('metadata')),
            lifecycleStatus: $this->stringValue($source->getAttribute('status')),
            context: array_merge($this->context($source), array_filter([
                'growth_program_id' => $source->getAttribute('growth_program_id'),
            ], static fn (mixed $value): bool => $value !== null)),
            relatedReferences: $this->references([
                'source_type' => $source->getAttribute('source_type'),
                'source_id' => $source->getAttribute('source_id'),
                'variable_axis' => $source->getAttribute('variable_axis'),
                'example_variables' => $source->getAttribute('example_variables'),
                'estimated_variants_count' => $source->getAttribute('estimated_variants_count'),
            ]),
            dedupeKey: hash('sha256', implode('|', array_filter([
                $this->key(),
                $source->getAttribute('workspace_id'),
                $pattern,
                $baseTopic,
                $source->getAttribute('variable_axis'),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''))),
            missingFields: $this->missingFields($source),
        );
    }

    public function missingFields(Model $source): array
    {
        return $this->missingFromMap([
            'base_topic' => $source->getAttribute('base_topic'),
            'pattern_type' => $source->getAttribute('pattern_type'),
            'status' => $source->getAttribute('status'),
            'workspace_id' => $source->getAttribute('workspace_id'),
            'scale_score' => $source->getAttribute('scale_score'),
            'confidence' => $source->getAttribute('confidence_score'),
        ]);
    }
}
