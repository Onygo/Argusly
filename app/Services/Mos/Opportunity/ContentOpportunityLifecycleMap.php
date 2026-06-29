<?php

namespace App\Services\Mos\Opportunity;

use App\Enums\OpportunityStatus;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;

class ContentOpportunityLifecycleMap
{
    public const AUTHORITY_EXPLANATION = 'Phase 2E is diagnostic only: ContentOpportunity remains authoritative for lifecycle and brief creation; linked Opportunity status is shadow context until a later migration.';

    /**
     * @return array<string, OpportunityStatus>
     */
    public function legacyToCanonicalMap(): array
    {
        return [
            ContentOpportunity::STATUS_OPEN => OpportunityStatus::OPEN,
            ContentOpportunity::STATUS_PLANNED => OpportunityStatus::PLANNED,
            ContentOpportunity::STATUS_DISMISSED => OpportunityStatus::DISMISSED,
            ContentOpportunity::STATUS_ARCHIVED => OpportunityStatus::ARCHIVED,
        ];
    }

    public function legacyToCanonical(?string $status): ?OpportunityStatus
    {
        return $this->legacyToCanonicalMap()[$this->normalize($status)] ?? null;
    }

    public function canonicalToLegacy(OpportunityStatus|string|null $status): ?string
    {
        $value = $status instanceof OpportunityStatus ? $status->value : $this->normalize($status);

        return match ($value) {
            OpportunityStatus::OPEN->value => ContentOpportunity::STATUS_OPEN,
            OpportunityStatus::PLANNED->value => ContentOpportunity::STATUS_PLANNED,
            OpportunityStatus::DISMISSED->value => ContentOpportunity::STATUS_DISMISSED,
            OpportunityStatus::ARCHIVED->value => ContentOpportunity::STATUS_ARCHIVED,
            default => null,
        };
    }

    public function unmappedLegacyStatus(?string $status): ?string
    {
        $normalized = $this->normalize($status);

        return $this->legacyToCanonical($normalized) === null ? $normalized : null;
    }

    public function unmappedCanonicalStatus(OpportunityStatus|string|null $status): ?string
    {
        $value = $status instanceof OpportunityStatus ? $status->value : $this->normalize($status);

        return $this->canonicalToLegacy($value) === null ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(ContentOpportunity $legacy, ?Opportunity $canonical): array
    {
        $legacyStatus = $this->normalize($legacy->status);
        $canonicalStatus = $canonical?->status instanceof OpportunityStatus
            ? $canonical->status->value
            : $this->normalize($canonical?->status);
        $mappedCanonical = $this->legacyToCanonical($legacyStatus)?->value;
        $safeLegacy = $this->canonicalToLegacy($canonicalStatus);

        $missingCanonicalLink = $canonical === null;
        $unmappedLegacy = $this->unmappedLegacyStatus($legacyStatus);
        $unmappedCanonical = $canonical === null ? null : $this->unmappedCanonicalStatus($canonicalStatus);
        $conflict = ! $missingCanonicalLink
            && $mappedCanonical !== null
            && $canonicalStatus !== ''
            && $mappedCanonical !== $canonicalStatus;

        return [
            'legacy_content_opportunity_id' => (string) $legacy->id,
            'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'workspace_id' => $legacy->workspace_id ? (string) $legacy->workspace_id : null,
            'client_site_id' => $legacy->client_site_id ? (string) $legacy->client_site_id : null,
            'legacy_status' => $legacyStatus,
            'canonical_status' => $canonicalStatus !== '' ? $canonicalStatus : null,
            'mapped_canonical_status' => $mappedCanonical,
            'safe_legacy_status' => $safeLegacy,
            'aligned' => ! $missingCanonicalLink && ! $conflict && $unmappedLegacy === null && $unmappedCanonical === null,
            'conflict' => $conflict,
            'unmapped_legacy_status' => $unmappedLegacy,
            'unmapped_canonical_status' => $unmappedCanonical,
            'missing_canonical_link' => $missingCanonicalLink,
            'authority' => self::AUTHORITY_EXPLANATION,
        ];
    }

    public function authorityExplanation(): string
    {
        return self::AUTHORITY_EXPLANATION;
    }

    private function normalize(mixed $status): string
    {
        return strtolower(trim((string) $status));
    }
}
