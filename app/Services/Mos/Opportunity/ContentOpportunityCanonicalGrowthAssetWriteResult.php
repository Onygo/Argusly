<?php

namespace App\Services\Mos\Opportunity;

use App\Models\GrowthAsset;

class ContentOpportunityCanonicalGrowthAssetWriteResult
{
    /**
     * @param  array<int, string>  $blockedReasons
     * @param  array<int, string>  $duplicateExecutionRisks
     * @param  array<int, array<string, mixed>>  $legacyGrowthAssets
     * @param  array<int, array<string, mixed>>  $canonicalGrowthAssets
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $applied,
        public readonly bool $safe,
        public readonly string $status,
        public readonly ?GrowthAsset $growthAsset,
        public readonly ?string $canonicalOpportunityId,
        public readonly string $legacyContentOpportunityId,
        public readonly ?string $growthProgramId,
        public readonly bool $featureEnabled,
        public readonly array $blockedReasons,
        public readonly array $duplicateExecutionRisks,
        public readonly array $legacyGrowthAssets,
        public readonly array $canonicalGrowthAssets,
        public readonly array $metadata,
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
            'growth_asset_id' => $this->growthAsset?->id ? (string) $this->growthAsset->id : null,
            'canonical_opportunity_id' => $this->canonicalOpportunityId,
            'legacy_content_opportunity_id' => $this->legacyContentOpportunityId,
            'growth_program_id' => $this->growthProgramId,
            'feature_enabled' => $this->featureEnabled,
            'blocked_reasons' => $this->blockedReasons,
            'duplicate_execution_risks' => $this->duplicateExecutionRisks,
            'legacy_growth_assets' => $this->legacyGrowthAssets,
            'canonical_growth_assets' => $this->canonicalGrowthAssets,
            'metadata' => $this->metadata,
        ];
    }
}
