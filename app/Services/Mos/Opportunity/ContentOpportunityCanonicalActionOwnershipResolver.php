<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\RecommendedAction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ContentOpportunityCanonicalActionOwnershipResolver
{
    public const STATUS_LEGACY = 'legacy';

    public const STATUS_CANONICAL_READY = 'canonical-ready';

    public const STATUS_CANONICAL_ACTIVE = 'canonical-active';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly ContentOpportunityRecommendedActionSignature $signature,
    ) {}

    /**
     * @param  iterable<int,RecommendedAction>|null  $recommendedActions
     * @param  array<string,mixed>|null  $duplicateRepairMetadata
     * @return array<string,mixed>
     */
    public function resolve(
        ContentOpportunity $legacy,
        ?Opportunity $canonical = null,
        ?iterable $recommendedActions = null,
        ?array $duplicateRepairMetadata = null,
        ?bool $featureEnabled = null,
    ): array {
        $canonical ??= $this->linkedCanonicalOpportunity($legacy);
        $featureEnabled ??= (bool) config('features.mos_canonical_content_opportunity_action_ownership', false);
        $actions = $this->actionRows($legacy, $canonical, $recommendedActions);
        $fallbackRoute = $this->legacyRoute($legacy);
        $canonicalRoute = $canonical ? route('app.opportunities.show', $canonical) : null;
        $blockedReasons = $this->blockedReasons($legacy, $canonical);
        $primary = $this->primaryAction($actions, $duplicateRepairMetadata);
        $duplicateIds = $this->duplicateActionIds($actions, $primary, $duplicateRepairMetadata);
        $safe = $canonical !== null && $blockedReasons === [];
        $active = $safe && $featureEnabled;
        $status = match (true) {
            $active => self::STATUS_CANONICAL_ACTIVE,
            $safe => self::STATUS_CANONICAL_READY,
            $featureEnabled => self::STATUS_BLOCKED,
            default => self::STATUS_LEGACY,
        };

        return [
            'canonical_owner_id' => $canonical?->id ? (string) $canonical->id : null,
            'legacy_source_id' => (string) $legacy->id,
            'primary_recommended_action_id' => $primary?->id ? (string) $primary->id : null,
            'duplicate_recommended_action_ids' => $duplicateIds,
            'display_action_id' => $primary?->id ? (string) $primary->id : null,
            'cta_route' => $active ? $canonicalRoute : $fallbackRoute,
            'source_link' => $active ? $canonicalRoute : $fallbackRoute,
            'legacy_source_link' => $fallbackRoute,
            'ownership_status' => $status,
            'blocked_reasons' => $blockedReasons,
            'fallback_route' => $fallbackRoute,
            'duplicate_metadata_status' => $this->duplicateMetadataStatus($actions, $duplicateRepairMetadata),
            'feature_enabled' => $featureEnabled,
        ];
    }

    private function linkedCanonicalOpportunity(ContentOpportunity $legacy): ?Opportunity
    {
        return Opportunity::query()
            ->where('content_opportunity_id', $legacy->id)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * @param  iterable<int,RecommendedAction>|null  $recommendedActions
     * @return Collection<int,RecommendedAction>
     */
    private function actionRows(ContentOpportunity $legacy, ?Opportunity $canonical, ?iterable $recommendedActions): Collection
    {
        if ($recommendedActions !== null) {
            return collect($recommendedActions)->filter(fn ($action): bool => $action instanceof RecommendedAction)->values();
        }

        $legacyPreviousSignature = $this->signature->legacySignature(
            $legacy,
            $legacy->workspace,
            RecommendedAction::SOURCE_CONTENT_INTELLIGENCE,
        );
        $sharedSignature = $this->signature->signature(
            $legacy,
            $legacy->workspace,
            RecommendedAction::SOURCE_CONTENT_INTELLIGENCE,
            'prepare_content_opportunity',
        );
        $canonicalPreviousSignature = $canonical
            ? $this->signature->legacySignature($canonical, $canonical->workspace, RecommendedAction::SOURCE_OPPORTUNITY)
            : null;

        $signatures = array_values(array_filter([
            $sharedSignature,
            $legacyPreviousSignature,
            $canonicalPreviousSignature,
        ]));

        /** @var EloquentCollection<int,RecommendedAction> $actions */
        $actions = RecommendedAction::query()
            ->where(function ($query) use ($legacy, $canonical, $signatures): void {
                $query->whereIn('source_signature', $signatures)
                    ->orWhere(function ($nested) use ($legacy): void {
                        $nested->where('source_type', ContentOpportunity::class)
                            ->where('source_id', (string) $legacy->id);
                    });

                if ($canonical) {
                    $query->orWhere(function ($nested) use ($canonical): void {
                        $nested->where('source_type', Opportunity::class)
                            ->where('source_id', (string) $canonical->id);
                    });
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $actions->unique('id')->values();
    }

    /**
     * @return array<int,string>
     */
    private function blockedReasons(ContentOpportunity $legacy, ?Opportunity $canonical): array
    {
        if (! $canonical) {
            return ['missing_canonical_link'];
        }

        return array_values(array_filter([
            $legacy->workspace_id ? null : 'missing_workspace',
            (string) $legacy->workspace_id === (string) $canonical->workspace_id ? null : 'canonical_workspace_mismatch',
            $legacy->client_site_id && $canonical->client_site_id && (string) $legacy->client_site_id !== (string) $canonical->client_site_id
                ? 'canonical_site_mismatch'
                : null,
        ]));
    }

    /**
     * @param  Collection<int,RecommendedAction>  $actions
     * @param  array<string,mixed>|null  $duplicateRepairMetadata
     */
    private function primaryAction(Collection $actions, ?array $duplicateRepairMetadata): ?RecommendedAction
    {
        $primaryId = data_get($duplicateRepairMetadata, 'primary_action_id');

        if ($primaryId) {
            $primary = $actions->first(fn (RecommendedAction $action): bool => (string) $action->id === (string) $primaryId);
            if ($primary) {
                return $primary;
            }
        }

        $metadataPrimary = $actions->first(fn (RecommendedAction $action): bool => data_get($action->metadata, 'canonical_equivalence.duplicate_role') === 'primary');
        if ($metadataPrimary) {
            return $metadataPrimary;
        }

        return $actions
            ->sortBy(fn (RecommendedAction $action): array => [
                $action->status === RecommendedAction::STATUS_OPEN ? 0 : 1,
                $action->created_at?->getTimestamp() ?? PHP_INT_MAX,
                $action->source_type === ContentOpportunity::class ? 0 : 1,
                (string) $action->id,
            ])
            ->first();
    }

    /**
     * @param  Collection<int,RecommendedAction>  $actions
     * @param  array<string,mixed>|null  $duplicateRepairMetadata
     * @return array<int,string>
     */
    private function duplicateActionIds(Collection $actions, ?RecommendedAction $primary, ?array $duplicateRepairMetadata): array
    {
        $ids = collect(data_get($duplicateRepairMetadata, 'duplicate_action_ids', []))
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->values();

        if ($ids->isNotEmpty()) {
            return $ids->all();
        }

        return $actions
            ->reject(fn (RecommendedAction $action): bool => $primary !== null && $action->is($primary))
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,RecommendedAction>  $actions
     * @param  array<string,mixed>|null  $duplicateRepairMetadata
     */
    private function duplicateMetadataStatus(Collection $actions, ?array $duplicateRepairMetadata): string
    {
        if (($duplicateRepairMetadata['repair_status'] ?? null) !== null) {
            return (string) $duplicateRepairMetadata['repair_status'];
        }

        if ($actions->contains(fn (RecommendedAction $action): bool => data_get($action->metadata, 'canonical_equivalence.repair_status') === 'annotated')) {
            return 'annotated';
        }

        return 'none';
    }

    private function legacyRoute(ContentOpportunity $legacy): string
    {
        return route('app.agentic-marketing.content-opportunities.index', array_filter([
            'workspace_id' => $legacy->workspace_id,
            'client_site_id' => $legacy->client_site_id,
        ]));
    }
}
