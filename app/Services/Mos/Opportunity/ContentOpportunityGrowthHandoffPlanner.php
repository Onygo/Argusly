<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ContentOpportunity;
use App\Models\GrowthAsset;
use App\Models\GrowthAutopilotQueueItem;
use App\Models\Opportunity;
use App\Models\ProgrammaticOpportunity;
use BackedEnum;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ContentOpportunityGrowthHandoffPlanner
{
    public function plan(ContentOpportunity $contentOpportunity): ContentOpportunityGrowthHandoffPlan
    {
        $canonicalOpportunities = $this->canonicalOpportunities($contentOpportunity);
        $canonical = $canonicalOpportunities->count() === 1 ? $canonicalOpportunities->first() : null;
        $growthAssets = $this->growthAssets($contentOpportunity, $canonicalOpportunities);
        $programmaticOpportunities = $this->programmaticOpportunities($contentOpportunity, $canonicalOpportunities);
        $queueItems = $this->autopilotQueueItems($contentOpportunity, $canonicalOpportunities);
        $risks = $this->duplicateExecutionRisks($canonicalOpportunities, $growthAssets, $programmaticOpportunities, $queueItems);
        $missing = $this->missingFields($contentOpportunity, $canonicalOpportunities);

        return new ContentOpportunityGrowthHandoffPlan(
            legacyContentOpportunityId: (string) $contentOpportunity->id,
            canonicalOpportunityId: $canonical?->id ? (string) $canonical->id : null,
            canonicalOpportunityIds: $canonicalOpportunities->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            growthAssetReferences: $growthAssets->map(fn (GrowthAsset $asset): array => $this->growthAssetReference($asset))->values()->all(),
            programmaticOpportunityReferences: $programmaticOpportunities->map(fn (ProgrammaticOpportunity $opportunity): array => $this->programmaticOpportunityReference($opportunity))->values()->all(),
            autopilotQueueReferences: $queueItems->map(fn (GrowthAutopilotQueueItem $item): array => $this->autopilotQueueReference($item))->values()->all(),
            safe: $canonicalOpportunities->count() === 1 && $missing === [] && $risks === [],
            duplicateExecutionRisks: $risks,
            missingFields: $missing,
            recommendedFutureReferenceStrategy: $this->recommendedFutureReferenceStrategy($contentOpportunity, $canonical),
        );
    }

    /**
     * @return EloquentCollection<int, Opportunity>
     */
    private function canonicalOpportunities(ContentOpportunity $contentOpportunity): EloquentCollection
    {
        return Opportunity::query()
            ->where('content_opportunity_id', $contentOpportunity->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Opportunity>  $canonicalOpportunities
     * @return EloquentCollection<int, GrowthAsset>
     */
    private function growthAssets(ContentOpportunity $contentOpportunity, EloquentCollection $canonicalOpportunities): EloquentCollection
    {
        return GrowthAsset::query()
            ->where(function ($query) use ($contentOpportunity, $canonicalOpportunities): void {
                $query->where(function ($legacy) use ($contentOpportunity): void {
                    $legacy->where('assetable_type', $contentOpportunity->getMorphClass())
                        ->where('assetable_id', $contentOpportunity->id);
                });

                if ($canonicalOpportunities->isNotEmpty()) {
                    $query->orWhere(function ($canonical) use ($canonicalOpportunities): void {
                        $canonical->where('assetable_type', (new Opportunity)->getMorphClass())
                            ->whereIn('assetable_id', $canonicalOpportunities->pluck('id')->all());
                    });
                }
            })
            ->orderBy('growth_program_id')
            ->orderBy('role')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Opportunity>  $canonicalOpportunities
     * @return EloquentCollection<int, ProgrammaticOpportunity>
     */
    private function programmaticOpportunities(ContentOpportunity $contentOpportunity, EloquentCollection $canonicalOpportunities): EloquentCollection
    {
        return ProgrammaticOpportunity::query()
            ->where(function ($query) use ($contentOpportunity, $canonicalOpportunities): void {
                $query->where(function ($legacy) use ($contentOpportunity): void {
                    $legacy->where('source_type', $contentOpportunity->getMorphClass())
                        ->where('source_id', $contentOpportunity->id);
                });

                if ($canonicalOpportunities->isNotEmpty()) {
                    $query->orWhere(function ($canonical) use ($canonicalOpportunities): void {
                        $canonical->where('source_type', (new Opportunity)->getMorphClass())
                            ->whereIn('source_id', $canonicalOpportunities->pluck('id')->all());
                    });
                }
            })
            ->orderBy('source_type')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Opportunity>  $canonicalOpportunities
     * @return EloquentCollection<int, GrowthAutopilotQueueItem>
     */
    private function autopilotQueueItems(ContentOpportunity $contentOpportunity, EloquentCollection $canonicalOpportunities): EloquentCollection
    {
        return GrowthAutopilotQueueItem::query()
            ->where(function ($query) use ($contentOpportunity, $canonicalOpportunities): void {
                $query->where(function ($legacy) use ($contentOpportunity): void {
                    $legacy->where('source_type', $contentOpportunity->getMorphClass())
                        ->where('source_id', $contentOpportunity->id);
                });

                if ($canonicalOpportunities->isNotEmpty()) {
                    $query->orWhere(function ($canonical) use ($canonicalOpportunities): void {
                        $canonical->where('source_type', (new Opportunity)->getMorphClass())
                            ->whereIn('source_id', $canonicalOpportunities->pluck('id')->all());
                    });
                }
            })
            ->orderBy('source_type')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Opportunity>  $canonicalOpportunities
     * @param  EloquentCollection<int, GrowthAsset>  $growthAssets
     * @param  EloquentCollection<int, ProgrammaticOpportunity>  $programmaticOpportunities
     * @param  EloquentCollection<int, GrowthAutopilotQueueItem>  $queueItems
     * @return array<int, string>
     */
    private function duplicateExecutionRisks(
        EloquentCollection $canonicalOpportunities,
        EloquentCollection $growthAssets,
        EloquentCollection $programmaticOpportunities,
        EloquentCollection $queueItems,
    ): array {
        $risks = [];

        if ($canonicalOpportunities->count() > 1) {
            $risks[] = 'multiple_canonical_links';
        }

        if ($this->hasLegacyAndCanonicalReferences($growthAssets, GrowthAsset::class)) {
            $risks[] = 'growth_asset_legacy_and_canonical_reference';
        }

        if ($this->hasLegacyAndCanonicalReferences($programmaticOpportunities, ProgrammaticOpportunity::class)) {
            $risks[] = 'programmatic_legacy_and_canonical_reference';
        }

        if ($this->hasLegacyAndCanonicalReferences($queueItems, GrowthAutopilotQueueItem::class)) {
            $risks[] = 'autopilot_queue_legacy_and_canonical_reference';
        }

        if ($growthAssets->groupBy('growth_program_id')->contains(fn (Collection $assets): bool => $this->hasLegacyAndCanonicalReferences($assets, GrowthAsset::class))) {
            $risks[] = 'same_growth_program_dual_asset_reference';
        }

        return array_values(array_unique($risks));
    }

    /**
     * @param  EloquentCollection<int, Opportunity>  $canonicalOpportunities
     * @return array<int, string>
     */
    private function missingFields(ContentOpportunity $contentOpportunity, EloquentCollection $canonicalOpportunities): array
    {
        $missing = [];

        if ($canonicalOpportunities->isEmpty()) {
            $missing[] = 'canonical_opportunity_id';
        }

        if (blank($contentOpportunity->workspace_id)) {
            $missing[] = 'workspace_id';
        }

        if (blank($contentOpportunity->client_site_id)) {
            $missing[] = 'client_site_id';
        }

        if (blank($contentOpportunity->title)) {
            $missing[] = 'title';
        }

        return $missing;
    }

    /**
     * @return array<int, string>
     */
    private function recommendedFutureReferenceStrategy(ContentOpportunity $contentOpportunity, ?Opportunity $canonical): array
    {
        return array_values(array_filter([
            'Keep GrowthAsset role content_opportunity for legacy assets until growth execution is migrated.',
            $canonical
                ? 'Use canonical Opportunity '.$canonical->id.' as the future primary planning source while preserving legacy content_opportunity_id '.$contentOpportunity->id.' in metadata.'
                : 'Create or repair one canonical Opportunity link before moving growth consumers.',
            'Before creating canonical growth assets, check for existing legacy content_opportunity assets in the same growth program.',
            'Before creating canonical autopilot queue items, reuse the canonical-equivalent recommended action signature and avoid queueing both legacy and canonical sources.',
            'Programmatic opportunities should reference the canonical Opportunity as source later but keep their pattern, variables, scores and lifecycle state specialized.',
        ]));
    }

    private function hasLegacyAndCanonicalReferences(Collection|EloquentCollection $records, string $recordClass): bool
    {
        $sourceField = $recordClass === GrowthAsset::class ? 'assetable_type' : 'source_type';

        return $records->pluck($sourceField)->contains(ContentOpportunity::class)
            && $records->pluck($sourceField)->contains(Opportunity::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function growthAssetReference(GrowthAsset $asset): array
    {
        return [
            'growth_asset_id' => (string) $asset->id,
            'growth_program_id' => (string) $asset->growth_program_id,
            'role' => (string) $asset->role,
            'source_kind' => $this->sourceKind($asset, 'assetable_type'),
            'source_id' => (string) $asset->assetable_id,
            'status_at_link' => $asset->status_at_link,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function programmaticOpportunityReference(ProgrammaticOpportunity $opportunity): array
    {
        return [
            'programmatic_opportunity_id' => (string) $opportunity->id,
            'source_kind' => $this->sourceKind($opportunity, 'source_type'),
            'source_id' => (string) $opportunity->source_id,
            'status' => (string) $opportunity->status,
            'pattern_type' => $opportunity->pattern_type instanceof BackedEnum
                ? (string) $opportunity->pattern_type->value
                : (string) $opportunity->pattern_type,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function autopilotQueueReference(GrowthAutopilotQueueItem $item): array
    {
        return [
            'growth_autopilot_queue_item_id' => (string) $item->id,
            'source_kind' => $this->sourceKind($item, 'source_type'),
            'source_id' => (string) $item->source_id,
            'status' => (string) $item->status,
            'source_signature' => (string) $item->source_signature,
            'recommended_action_id' => $item->recommended_action_id ? (string) $item->recommended_action_id : null,
        ];
    }

    private function sourceKind(Model $model, string $field): string
    {
        return match ((string) $model->getAttribute($field)) {
            ContentOpportunity::class => 'legacy_content_opportunity',
            Opportunity::class => 'canonical_opportunity',
            default => (string) $model->getAttribute($field),
        };
    }
}
