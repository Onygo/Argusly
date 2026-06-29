<?php

namespace App\Services\Mos\Opportunity;

class ContentOpportunityCanonicalLifecycleSyncResult
{
    /**
     * @param  array<int, string>  $blockedReasons
     */
    public function __construct(
        public readonly bool $applied,
        public readonly bool $safe,
        public readonly string $status,
        public readonly string $direction,
        public readonly string $legacyContentOpportunityId,
        public readonly ?string $canonicalOpportunityId,
        public readonly ?string $workspaceId,
        public readonly ?string $clientSiteId,
        public readonly ?string $legacyStatus,
        public readonly ?string $canonicalStatus,
        public readonly ?string $desiredLegacyStatus,
        public readonly ?string $desiredCanonicalStatus,
        public readonly bool $dryRun,
        public readonly bool $aligned,
        public readonly bool $conflict,
        public readonly ?string $unmappedLegacyStatus,
        public readonly ?string $unmappedCanonicalStatus,
        public readonly bool $missingCanonicalLink,
        public readonly bool $duplicateCanonicalLinks,
        public readonly array $blockedReasons,
        public readonly ?string $actorId = null,
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
            'direction' => $this->direction,
            'legacy_content_opportunity_id' => $this->legacyContentOpportunityId,
            'canonical_opportunity_id' => $this->canonicalOpportunityId,
            'workspace_id' => $this->workspaceId,
            'client_site_id' => $this->clientSiteId,
            'legacy_status' => $this->legacyStatus,
            'canonical_status' => $this->canonicalStatus,
            'desired_legacy_status' => $this->desiredLegacyStatus,
            'desired_canonical_status' => $this->desiredCanonicalStatus,
            'dry_run' => $this->dryRun,
            'aligned' => $this->aligned,
            'conflict' => $this->conflict,
            'unmapped_legacy_status' => $this->unmappedLegacyStatus,
            'unmapped_canonical_status' => $this->unmappedCanonicalStatus,
            'missing_canonical_link' => $this->missingCanonicalLink,
            'duplicate_canonical_links' => $this->duplicateCanonicalLinks,
            'blocked_reasons' => $this->blockedReasons,
            'actor_id' => $this->actorId,
        ];
    }
}
