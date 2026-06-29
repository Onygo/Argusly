<?php

namespace App\Services\Mos\Opportunity;

class ContentOpportunityCanonicalReadModel
{
    /**
     * @param  array<int, mixed>  $recommendedActions
     * @param  array<int, mixed>  $evidence
     * @param  array<string, mixed>  $workspaceContext
     * @param  array<string, string>  $provenance
     * @param  array<string, mixed>  $legacyFields
     */
    public function __construct(
        public readonly string $legacyContentOpportunityId,
        public readonly ?string $canonicalOpportunityId,
        public readonly ?string $title,
        public readonly ?string $type,
        public readonly ?string $status,
        public readonly ?float $priority,
        public readonly ?float $confidence,
        public readonly mixed $impact,
        public readonly ?float $effort,
        public readonly ?float $urgency,
        public readonly ?float $businessValue,
        public readonly array $recommendedActions,
        public readonly array $evidence,
        public readonly array $workspaceContext,
        public readonly array $provenance,
        public readonly array $legacyFields = [],
    ) {}

    public function topic(): ?string
    {
        $topic = trim((string) ($this->legacyFields['topic'] ?? ''));

        return $topic !== '' ? $topic : $this->title;
    }
}
