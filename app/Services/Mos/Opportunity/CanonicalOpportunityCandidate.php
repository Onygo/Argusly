<?php

namespace App\Services\Mos\Opportunity;

class CanonicalOpportunityCandidate
{
    /**
     * @param  array<int, mixed>  $evidence
     * @param  array<int, mixed>  $recommendedActions
     * @param  array<string, mixed>  $context
     * @param  array<int, mixed>  $relatedReferences
     * @param  array<int, string>  $missingFields
     * @param  array<int, string>  $unsupportedReasons
     */
    public function __construct(
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?string $type,
        public readonly string $source,
        public readonly ?string $sourceModel,
        public readonly string|int|null $sourceId,
        public readonly ?float $priority,
        public readonly ?float $confidence,
        public readonly ?float $impact,
        public readonly ?float $effort,
        public readonly ?float $businessValue,
        public readonly array $evidence,
        public readonly array $recommendedActions,
        public readonly ?string $lifecycleStatus,
        public readonly array $context,
        public readonly array $relatedReferences,
        public readonly ?string $dedupeKey,
        public readonly array $missingFields = [],
        public readonly array $unsupportedReasons = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'source' => $this->source,
            'source_model' => $this->sourceModel,
            'source_id' => $this->sourceId,
            'priority' => $this->priority,
            'confidence' => $this->confidence,
            'impact' => $this->impact,
            'effort' => $this->effort,
            'business_value' => $this->businessValue,
            'evidence' => $this->evidence,
            'recommended_actions' => $this->recommendedActions,
            'lifecycle_status' => $this->lifecycleStatus,
            'context' => $this->context,
            'related_references' => $this->relatedReferences,
            'dedupe_key' => $this->dedupeKey,
            'missing_fields' => $this->missingFields,
            'unsupported_reasons' => $this->unsupportedReasons,
            'can_persist_canonically' => $this->canPersistCanonically(),
        ];
    }

    public function canPersistCanonically(): bool
    {
        return $this->missingFields === [] && $this->unsupportedReasons === [];
    }
}
