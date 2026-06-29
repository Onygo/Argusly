<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ContentOpportunity;
use App\Models\GrowthAsset;
use App\Models\GrowthProgram;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class ContentOpportunityCanonicalGrowthAssetWriter
{
    public function dryRun(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
        ?GrowthProgram $program,
    ): ContentOpportunityCanonicalGrowthAssetWriteResult {
        return $this->run($contentOpportunity, $canonical, $program, false);
    }

    public function apply(
        ContentOpportunity $contentOpportunity,
        Opportunity $canonical,
        GrowthProgram $program,
    ): ContentOpportunityCanonicalGrowthAssetWriteResult {
        return $this->run($contentOpportunity, $canonical, $program, true);
    }

    private function run(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
        ?GrowthProgram $program,
        bool $apply,
    ): ContentOpportunityCanonicalGrowthAssetWriteResult {
        $featureEnabled = (bool) config('features.mos_canonical_content_opportunity_growth_writer', false);
        $legacyAssets = $program ? $this->legacyAssets($contentOpportunity, $program) : new EloquentCollection;
        $canonicalAssets = $canonical && $program ? $this->canonicalAssets($canonical, $program) : new EloquentCollection;
        $duplicateRisks = $this->duplicateExecutionRisks($legacyAssets, $canonicalAssets);
        $blocked = $this->blockedReasons($contentOpportunity, $canonical, $program, $duplicateRisks, $apply, $featureEnabled);
        $safe = $blocked === [];
        $metadata = $this->metadata($contentOpportunity, $canonical);

        if (! $apply || ! $safe) {
            return new ContentOpportunityCanonicalGrowthAssetWriteResult(
                applied: false,
                safe: $safe,
                status: $safe ? 'would_create' : 'blocked',
                growthAsset: null,
                canonicalOpportunityId: $canonical?->id ? (string) $canonical->id : null,
                legacyContentOpportunityId: (string) $contentOpportunity->id,
                growthProgramId: $program?->id ? (string) $program->id : null,
                featureEnabled: $featureEnabled,
                blockedReasons: $blocked,
                duplicateExecutionRisks: $duplicateRisks,
                legacyGrowthAssets: $this->assetReferences($legacyAssets),
                canonicalGrowthAssets: $this->assetReferences($canonicalAssets),
                metadata: $metadata,
            );
        }

        $asset = DB::transaction(function () use ($contentOpportunity, $canonical, $program, $metadata): GrowthAsset {
            $lockedLegacyAssets = $this->legacyAssets($contentOpportunity, $program);
            $lockedCanonicalAssets = $this->canonicalAssets($canonical, $program);

            if ($lockedLegacyAssets->isNotEmpty() || $lockedCanonicalAssets->isNotEmpty()) {
                return $lockedCanonicalAssets->first() ?: $lockedLegacyAssets->first();
            }

            return GrowthAsset::query()->create([
                'organization_id' => $program->organization_id,
                'workspace_id' => (string) $program->workspace_id,
                'growth_program_id' => (string) $program->id,
                'role' => GrowthAsset::ROLE_OPPORTUNITY,
                'assetable_type' => $canonical->getMorphClass(),
                'assetable_id' => (string) $canonical->id,
                'status_at_link' => $this->statusValue($canonical->status),
                'source_type' => 'mos_canonical_content_opportunity_growth_writer',
                'weight' => 1,
                'metadata' => $metadata,
            ]);
        });

        $created = $asset->wasRecentlyCreated;
        $canonicalAssets = $this->canonicalAssets($canonical, $program);

        return new ContentOpportunityCanonicalGrowthAssetWriteResult(
            applied: $created,
            safe: $created,
            status: $created ? 'created' : 'duplicate',
            growthAsset: $created ? $asset->refresh() : null,
            canonicalOpportunityId: (string) $canonical->id,
            legacyContentOpportunityId: (string) $contentOpportunity->id,
            growthProgramId: (string) $program->id,
            featureEnabled: $featureEnabled,
            blockedReasons: $created ? [] : ['duplicate_growth_asset'],
            duplicateExecutionRisks: $created ? [] : ['canonical_growth_asset_exists'],
            legacyGrowthAssets: $this->assetReferences($this->legacyAssets($contentOpportunity, $program)),
            canonicalGrowthAssets: $this->assetReferences($canonicalAssets),
            metadata: $metadata,
        );
    }

    /**
     * @return EloquentCollection<int, GrowthAsset>
     */
    private function legacyAssets(ContentOpportunity $contentOpportunity, GrowthProgram $program): EloquentCollection
    {
        return GrowthAsset::query()
            ->where('growth_program_id', (string) $program->id)
            ->where('assetable_type', $contentOpportunity->getMorphClass())
            ->where('assetable_id', (string) $contentOpportunity->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return EloquentCollection<int, GrowthAsset>
     */
    private function canonicalAssets(Opportunity $canonical, GrowthProgram $program): EloquentCollection
    {
        return GrowthAsset::query()
            ->where('growth_program_id', (string) $program->id)
            ->where('assetable_type', $canonical->getMorphClass())
            ->where('assetable_id', (string) $canonical->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, GrowthAsset>  $legacyAssets
     * @param  EloquentCollection<int, GrowthAsset>  $canonicalAssets
     * @return array<int, string>
     */
    private function duplicateExecutionRisks(EloquentCollection $legacyAssets, EloquentCollection $canonicalAssets): array
    {
        return array_values(array_filter([
            $legacyAssets->isNotEmpty() ? 'legacy_growth_asset_exists_for_program' : null,
            $canonicalAssets->isNotEmpty() ? 'canonical_growth_asset_exists_for_program' : null,
        ]));
    }

    /**
     * @param  array<int, string>  $duplicateRisks
     * @return array<int, string>
     */
    private function blockedReasons(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
        ?GrowthProgram $program,
        array $duplicateRisks,
        bool $apply,
        bool $featureEnabled,
    ): array {
        $blocked = [];

        if (! $canonical) {
            $blocked[] = 'missing_canonical_opportunity';
        }

        if (! $program) {
            $blocked[] = 'missing_growth_program';
        }

        if ($canonical && (string) $canonical->content_opportunity_id !== (string) $contentOpportunity->id) {
            $blocked[] = 'canonical_legacy_link_mismatch';
        }

        if ($canonical && (string) $canonical->workspace_id !== (string) $contentOpportunity->workspace_id) {
            $blocked[] = 'canonical_workspace_mismatch';
        }

        if ($program && (string) $program->workspace_id !== (string) $contentOpportunity->workspace_id) {
            $blocked[] = 'growth_program_workspace_mismatch';
        }

        if ($apply && ! $featureEnabled) {
            $blocked[] = 'feature_flag_disabled';
        }

        return array_values(array_unique(array_merge($blocked, $duplicateRisks)));
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(ContentOpportunity $contentOpportunity, ?Opportunity $canonical): array
    {
        return [
            'source' => 'mos_canonical_content_opportunity_growth_writer',
            'legacy_content_opportunity_id' => (string) $contentOpportunity->id,
            'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'source_evidence' => [
                'legacy_content_opportunity_id' => (string) $contentOpportunity->id,
                'legacy_source_type' => $contentOpportunity->getMorphClass(),
                'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
                'canonical_source_type' => $canonical?->getMorphClass(),
                'dedupe_hash' => $contentOpportunity->dedupe_hash ?: $canonical?->dedupe_hash,
            ],
        ];
    }

    /**
     * @param  EloquentCollection<int, GrowthAsset>  $assets
     * @return array<int, array<string, mixed>>
     */
    private function assetReferences(EloquentCollection $assets): array
    {
        return $assets
            ->map(fn (GrowthAsset $asset): array => [
                'growth_asset_id' => (string) $asset->id,
                'growth_program_id' => (string) $asset->growth_program_id,
                'role' => (string) $asset->role,
                'assetable_type' => (string) $asset->assetable_type,
                'assetable_id' => (string) $asset->assetable_id,
                'source_type' => (string) $asset->source_type,
            ])
            ->values()
            ->all();
    }

    private function statusValue(mixed $status): ?string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : ($status ? (string) $status : null);
    }
}
