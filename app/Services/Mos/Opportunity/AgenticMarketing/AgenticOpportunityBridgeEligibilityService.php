<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingOpportunity;
use App\Models\CampaignCluster;
use App\Models\CampaignClusterItem;
use App\Models\GrowthAsset;
use App\Models\Opportunity;
use App\Models\ProgrammaticOpportunity;

class AgenticOpportunityBridgeEligibilityService
{
    public const STATUS_SIGNAL_READY = 'signal_ready';

    public const STATUS_CANONICAL_LINK_READY = 'canonical_link_ready';

    public const STATUS_SIGNAL_AND_CANONICAL_READY = 'signal_and_canonical_ready';

    public const STATUS_EXECUTION_BLOCKED = 'execution_blocked';

    public const STATUS_MISSING_CONTEXT = 'missing_context';

    public const STATUS_DUPLICATE_RISK = 'duplicate_risk';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AgenticOpportunityCanonicalMappingService $mapper,
    ) {}

    public function inspect(AgenticMarketingOpportunity $opportunity): AgenticOpportunityBridgeEligibilityResult
    {
        $opportunity->loadMissing('objective');

        $mapping = $this->mapper->mapExisting($opportunity);
        $objective = $opportunity->objective;
        $workspaceId = $this->stringValue($objective?->workspace_id);
        $siteId = $this->stringValue(
            data_get($opportunity->payload, 'client_site_id')
                ?: data_get($opportunity->payload, 'signals.client_site_id')
                ?: $objective?->client_site_id
        );
        $contentId = $this->stringValue($opportunity->content_id ?: data_get($opportunity->payload, 'content_id'));

        $existingLinkedCanonicalOpportunityIds = Opportunity::query()
            ->where('agentic_marketing_opportunity_id', $opportunity->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $dedupeMatchedCanonicalOpportunityCandidates = $this->dedupeMatchedCanonicalOpportunityCandidates($mapping, $workspaceId, $opportunity);
        $openActionsCount = $opportunity->actions()->open()->count();
        $executionPipelineCount = $opportunity->executionPipelines()->count();
        $growthAssetCount = $this->growthAssetCount($opportunity);
        $programmaticOpportunityCount = $this->programmaticOpportunityCount($opportunity);
        $campaignClusterMaterializationCount = $this->campaignClusterMaterializationCount($opportunity);

        $duplicateBridgeRisk = count($existingLinkedCanonicalOpportunityIds) > 1;
        $duplicateStrategicOpportunityRisk = $dedupeMatchedCanonicalOpportunityCandidates !== []
            || $campaignClusterMaterializationCount > 0;
        $executionStateDependent = $openActionsCount > 0
            || $executionPipelineCount > 0
            || $growthAssetCount > 0
            || $programmaticOpportunityCount > 0;

        $blockedReasons = $this->blockedReasons(
            $opportunity,
            $mapping,
            $duplicateBridgeRisk,
            $dedupeMatchedCanonicalOpportunityCandidates,
            $campaignClusterMaterializationCount
        );

        $hasMissingContext = collect($blockedReasons)
            ->contains(fn (string $reason): bool => str_starts_with($reason, 'missing_'));
        $hasDuplicateRisk = $duplicateBridgeRisk || $duplicateStrategicOpportunityRisk;
        $signalEligible = $mapping->canEmitSignal && ! $hasMissingContext && $mapping->classification !== AgenticDetectorClassification::BLOCKED;
        $canonicalEligible = $mapping->canEmitCanonicalOpportunityCandidate
            && ! $hasMissingContext
            && ! $hasDuplicateRisk
            && ! $executionStateDependent
            && $mapping->classification !== AgenticDetectorClassification::BLOCKED;

        $eligibilityStatus = $this->eligibilityStatus(
            $mapping,
            $hasMissingContext,
            $hasDuplicateRisk,
            $executionStateDependent,
            $signalEligible,
            $canonicalEligible
        );

        return new AgenticOpportunityBridgeEligibilityResult(
            legacyAgenticOpportunityId: (string) $opportunity->id,
            objectiveId: $this->stringValue($opportunity->objective_id),
            workspaceId: $workspaceId,
            siteId: $siteId,
            contentId: $contentId,
            detectorKey: $mapping->detectorKey,
            agenticType: $this->stringValue($opportunity->type),
            status: (string) $opportunity->status,
            phase3bDetectorClassification: $mapping->classification,
            mappingResult: $mapping,
            existingLinkedCanonicalOpportunityIds: $existingLinkedCanonicalOpportunityIds,
            dedupeMatchedCanonicalOpportunityCandidates: $dedupeMatchedCanonicalOpportunityCandidates,
            duplicateBridgeRisk: $duplicateBridgeRisk,
            duplicateStrategicOpportunityRisk: $duplicateStrategicOpportunityRisk,
            openAgenticActionsCount: $openActionsCount,
            executionPipelineCount: $executionPipelineCount,
            growthAssetCount: $growthAssetCount,
            programmaticOpportunityCount: $programmaticOpportunityCount,
            campaignClusterMaterializationCount: $campaignClusterMaterializationCount,
            signalEligibility: $signalEligible,
            canonicalOpportunityEligibility: $canonicalEligible,
            executionBlockerStatus: $executionStateDependent ? 'execution_state_dependent' : 'clear',
            blockedReasons: $blockedReasons,
            eligibilityStatus: $eligibilityStatus,
            recommendedFutureAction: $this->recommendedFutureAction($eligibilityStatus),
        );
    }

    /**
     * @return array<int,string>
     */
    private function dedupeMatchedCanonicalOpportunityCandidates(
        AgenticCanonicalMappingResult $mapping,
        ?string $workspaceId,
        AgenticMarketingOpportunity $opportunity,
    ): array {
        if (! $workspaceId || $mapping->dedupeKey === '') {
            return [];
        }

        return Opportunity::query()
            ->where('workspace_id', $workspaceId)
            ->where('dedupe_hash', $mapping->dedupeKey)
            ->where(function ($query) use ($opportunity): void {
                $query->whereNull('agentic_marketing_opportunity_id')
                    ->orWhere('agentic_marketing_opportunity_id', '!=', $opportunity->id);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function growthAssetCount(AgenticMarketingOpportunity $opportunity): int
    {
        return GrowthAsset::query()
            ->where(function ($query) use ($opportunity): void {
                $query->where(function ($assetQuery) use ($opportunity): void {
                    $assetQuery->where('assetable_type', AgenticMarketingOpportunity::class)
                        ->where('assetable_id', $opportunity->id);
                })
                    ->orWhere(function ($assetQuery) use ($opportunity): void {
                        $assetQuery->where('role', GrowthAsset::ROLE_AGENTIC_OPPORTUNITY)
                            ->where('metadata->agentic_marketing_opportunity_id', (string) $opportunity->id);
                    });
            })
            ->count();
    }

    private function programmaticOpportunityCount(AgenticMarketingOpportunity $opportunity): int
    {
        return ProgrammaticOpportunity::query()
            ->where(function ($query) use ($opportunity): void {
                $query->where(function ($programmaticQuery) use ($opportunity): void {
                    $programmaticQuery->where('source_type', AgenticMarketingOpportunity::class)
                        ->where('source_id', $opportunity->id);
                })
                    ->orWhere('metadata->agentic_marketing_opportunity_id', (string) $opportunity->id);
            })
            ->count();
    }

    private function campaignClusterMaterializationCount(AgenticMarketingOpportunity $opportunity): int
    {
        $clusterId = $this->stringValue(data_get($opportunity->payload, 'signals.campaign_cluster_id'));
        $itemId = $this->stringValue(data_get($opportunity->payload, 'signals.campaign_cluster_item_id'));

        $count = 0;
        if ($clusterId) {
            $count += CampaignCluster::query()->whereKey($clusterId)->count();
        }

        if ($itemId) {
            $count += CampaignClusterItem::query()->whereKey($itemId)->count();
        }

        return $count;
    }

    /**
     * @param  array<int,string>  $dedupeMatchedCanonicalOpportunityCandidates
     * @return array<int,string>
     */
    private function blockedReasons(
        AgenticMarketingOpportunity $opportunity,
        AgenticCanonicalMappingResult $mapping,
        bool $duplicateBridgeRisk,
        array $dedupeMatchedCanonicalOpportunityCandidates,
        int $campaignClusterMaterializationCount,
    ): array {
        $blocked = $mapping->blockedReasons;

        if (! $opportunity->objective_id) {
            $blocked[] = 'missing_objective_id';
        }

        if (! $opportunity->objective?->workspace_id) {
            $blocked[] = 'missing_workspace_id';
        }

        if (! $opportunity->type) {
            $blocked[] = 'missing_opportunity_type';
        }

        if (trim((string) $opportunity->title) === '') {
            $blocked[] = 'missing_title';
        }

        if ($mapping->dedupeKey === '') {
            $blocked[] = 'missing_dedupe_key';
        }

        if ($duplicateBridgeRisk) {
            $blocked[] = 'multiple_canonical_opportunities_linked_to_agentic_row';
        }

        if ($dedupeMatchedCanonicalOpportunityCandidates !== []) {
            $blocked[] = 'canonical_opportunity_dedupe_match_without_bridge';
        }

        if ($campaignClusterMaterializationCount > 0) {
            $blocked[] = 'campaign_cluster_materialization_parallel_strategic_opportunity_risk';
        }

        return array_values(array_unique($blocked));
    }

    private function eligibilityStatus(
        AgenticCanonicalMappingResult $mapping,
        bool $hasMissingContext,
        bool $hasDuplicateRisk,
        bool $executionStateDependent,
        bool $signalEligible,
        bool $canonicalEligible,
    ): string {
        if ($hasMissingContext) {
            return self::STATUS_MISSING_CONTEXT;
        }

        if ($mapping->classification === AgenticDetectorClassification::BLOCKED || $mapping->blockedReasons !== []) {
            return self::STATUS_BLOCKED;
        }

        if ($hasDuplicateRisk) {
            return self::STATUS_DUPLICATE_RISK;
        }

        if ($executionStateDependent && $mapping->canEmitCanonicalOpportunityCandidate) {
            return self::STATUS_EXECUTION_BLOCKED;
        }

        if ($signalEligible && $canonicalEligible) {
            return self::STATUS_SIGNAL_AND_CANONICAL_READY;
        }

        if ($canonicalEligible) {
            return self::STATUS_CANONICAL_LINK_READY;
        }

        if ($signalEligible) {
            return self::STATUS_SIGNAL_READY;
        }

        return self::STATUS_BLOCKED;
    }

    private function recommendedFutureAction(string $eligibilityStatus): string
    {
        return match ($eligibilityStatus) {
            self::STATUS_SIGNAL_READY => 'Promote a canonical signal in a guarded writer phase; do not link a canonical Opportunity.',
            self::STATUS_CANONICAL_LINK_READY => 'Plan a guarded canonical Opportunity bridge writer after validating no execution state depends on the legacy row.',
            self::STATUS_SIGNAL_AND_CANONICAL_READY => 'Plan guarded signal promotion and canonical Opportunity linking with the Phase 3B mapping payload.',
            self::STATUS_EXECUTION_BLOCKED => 'Preserve legacy Agentic execution continuity before any canonical bridge writer changes ownership.',
            self::STATUS_MISSING_CONTEXT => 'Repair or exclude the legacy row; required mapping context is incomplete.',
            self::STATUS_DUPLICATE_RISK => 'Resolve duplicate bridge or strategic opportunity risk before canonical linking.',
            default => 'Keep the row legacy-owned until a later migration phase defines a safe path.',
        };
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
