<?php

namespace App\Services\Mos\Opportunity;

use App\Models\Brief;

class ContentOpportunityCanonicalBriefWriteResult
{
    /**
     * @param  array<int, string>  $missingFields
     * @param  array<int, string>  $blockedReasons
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly bool $applied,
        public readonly bool $safe,
        public readonly string $status,
        public readonly ?Brief $brief,
        public readonly ?Brief $duplicateBrief,
        public readonly ?string $canonicalOpportunityId,
        public readonly string $legacyContentOpportunityId,
        public readonly ?string $clientSiteId,
        public readonly string $mode,
        public readonly array $missingFields,
        public readonly array $blockedReasons,
        public readonly bool $duplicateRisk,
        public readonly array $payload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'safe' => $this->safe,
            'status' => $this->status,
            'brief_id' => $this->brief?->id ? (string) $this->brief->id : null,
            'duplicate_brief_id' => $this->duplicateBrief?->id ? (string) $this->duplicateBrief->id : null,
            'canonical_opportunity_id' => $this->canonicalOpportunityId,
            'legacy_content_opportunity_id' => $this->legacyContentOpportunityId,
            'client_site_id' => $this->clientSiteId,
            'mode' => $this->mode,
            'missing_fields' => $this->missingFields,
            'blocked_reasons' => $this->blockedReasons,
            'duplicate_risk' => $this->duplicateRisk,
            'payload' => $this->payload,
        ];
    }
}
