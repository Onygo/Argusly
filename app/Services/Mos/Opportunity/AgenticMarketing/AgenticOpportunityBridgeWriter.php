<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use Illuminate\Support\Facades\DB;
use Throwable;

class AgenticOpportunityBridgeWriter
{
    private const FEATURE_FLAG = 'features.mos_agentic_marketing_opportunity_bridge_writer';

    private const WRITABLE_STATUSES = [
        AgenticOpportunityBridgeEligibilityService::STATUS_CANONICAL_LINK_READY,
        AgenticOpportunityBridgeEligibilityService::STATUS_SIGNAL_AND_CANONICAL_READY,
    ];

    public function __construct(
        private readonly AgenticOpportunityBridgeEligibilityService $eligibility,
    ) {}

    /**
     * @param  array<string,mixed>  $operatorContext
     */
    public function write(
        AgenticMarketingOpportunity $legacy,
        ?Opportunity $existingCanonical = null,
        bool $apply = false,
        array $operatorContext = [],
    ): AgenticOpportunityBridgeWriteResult {
        $result = $this->eligibility->inspect($legacy);
        $dryRun = ! $apply;

        if ($apply && ! $this->featureEnabled()) {
            return $this->result('blocked', $result, null, ['feature_flag_disabled'], false, $operatorContext);
        }

        if (! in_array($result->eligibilityStatus, self::WRITABLE_STATUSES, true)) {
            return $this->blockedResultForEligibility($result, $dryRun, $operatorContext);
        }

        $preview = $result->mappingResult->opportunityPreview;
        if (! $preview) {
            return $this->result('blocked', $result, null, ['missing_canonical_opportunity_preview'], $dryRun, $operatorContext);
        }

        $missing = $this->missingRequiredCanonicalFields($preview);
        if ($missing !== []) {
            return $this->result('blocked', $result, null, $missing, $dryRun, $operatorContext);
        }

        $existingByBridge = Opportunity::query()
            ->where('agentic_marketing_opportunity_id', $legacy->id)
            ->orderBy('id')
            ->get();

        if ($existingByBridge->count() > 1) {
            return $this->result('duplicate_risk', $result, null, ['multiple_canonical_opportunities_linked_to_agentic_row'], $dryRun, $operatorContext);
        }

        if ($existingByBridge->count() === 1) {
            return $this->result('already_linked', $result, $existingByBridge->first(), [], $dryRun, $operatorContext);
        }

        $dedupeMatch = $this->dedupeMatch($result, $existingCanonical);
        if ($dedupeMatch) {
            if (filled($dedupeMatch->agentic_marketing_opportunity_id)
                && (string) $dedupeMatch->agentic_marketing_opportunity_id !== (string) $legacy->id) {
                return $this->result('duplicate_risk', $result, $dedupeMatch, ['dedupe_hash_linked_to_another_agentic_opportunity'], $dryRun, $operatorContext);
            }

            return $this->linkExistingCanonical($legacy, $dedupeMatch, $result, $apply, $operatorContext);
        }

        if (! $apply) {
            return $this->result('would_create', $result, null, [], true, $operatorContext);
        }

        try {
            return DB::transaction(function () use ($legacy, $preview, $result, $operatorContext): AgenticOpportunityBridgeWriteResult {
                $opportunity = Opportunity::query()->create($this->payload($legacy, $preview, $result, []));

                return $this->result('created', $result, $opportunity, [], false, $operatorContext);
            });
        } catch (Throwable $exception) {
            return $this->result('failed', $result, null, [$exception->getMessage()], false, $operatorContext);
        }
    }

    private function featureEnabled(): bool
    {
        return (bool) config(self::FEATURE_FLAG, false);
    }

    private function blockedResultForEligibility(
        AgenticOpportunityBridgeEligibilityResult $eligibility,
        bool $dryRun,
        array $operatorContext,
    ): AgenticOpportunityBridgeWriteResult {
        $status = match ($eligibility->eligibilityStatus) {
            AgenticOpportunityBridgeEligibilityService::STATUS_DUPLICATE_RISK => 'duplicate_risk',
            AgenticOpportunityBridgeEligibilityService::STATUS_EXECUTION_BLOCKED => 'execution_blocked',
            AgenticOpportunityBridgeEligibilityService::STATUS_MISSING_CONTEXT => 'missing_context',
            default => 'blocked',
        };

        return $this->result($status, $eligibility, null, $eligibility->blockedReasons, $dryRun, $operatorContext);
    }

    private function linkExistingCanonical(
        AgenticMarketingOpportunity $legacy,
        Opportunity $canonical,
        AgenticOpportunityBridgeEligibilityResult $eligibility,
        bool $apply,
        array $operatorContext,
    ): AgenticOpportunityBridgeWriteResult {
        if (! $this->isSafeDedupeMatch($canonical, $eligibility)) {
            return $this->result('duplicate_risk', $eligibility, $canonical, ['canonical_opportunity_dedupe_match_without_safe_bridge'], ! $apply, $operatorContext);
        }

        if (! $apply) {
            return $this->result('would_link', $eligibility, $canonical, [], true, $operatorContext);
        }

        try {
            return DB::transaction(function () use ($legacy, $canonical, $eligibility, $operatorContext): AgenticOpportunityBridgeWriteResult {
                $canonical->forceFill([
                    'agentic_marketing_opportunity_id' => (string) $legacy->id,
                    'metadata' => $this->mergedMetadata($canonical->metadata ?? [], $legacy, $eligibility),
                ])->save();

                return $this->result('linked', $eligibility, $canonical->refresh(), [], false, $operatorContext);
            });
        } catch (Throwable $exception) {
            return $this->result('failed', $eligibility, $canonical, [$exception->getMessage()], false, $operatorContext);
        }
    }

    private function dedupeMatch(AgenticOpportunityBridgeEligibilityResult $eligibility, ?Opportunity $existingCanonical): ?Opportunity
    {
        if ($existingCanonical) {
            return $existingCanonical;
        }

        if (! $eligibility->workspaceId || $eligibility->mappingResult->dedupeKey === '') {
            return null;
        }

        return Opportunity::query()
            ->where('workspace_id', $eligibility->workspaceId)
            ->where('dedupe_hash', $eligibility->mappingResult->dedupeKey)
            ->first();
    }

    private function isSafeDedupeMatch(Opportunity $canonical, AgenticOpportunityBridgeEligibilityResult $eligibility): bool
    {
        return (string) $canonical->workspace_id === (string) $eligibility->workspaceId
            && (string) $canonical->dedupe_hash === (string) $eligibility->mappingResult->dedupeKey
            && blank($canonical->agentic_marketing_opportunity_id);
    }

    /**
     * @return array<int,string>
     */
    private function missingRequiredCanonicalFields(AgenticCanonicalOpportunityPreview $preview): array
    {
        return array_values(array_filter([
            blank($preview->workspaceId) ? 'missing_workspace_id' : null,
            blank($preview->title) ? 'missing_title' : null,
            blank($preview->category) ? 'missing_category' : null,
            blank($preview->type) ? 'missing_type' : null,
            blank($preview->dedupeKey) ? 'missing_source_scoped_dedupe_key' : null,
            $preview->evidence === [] ? 'missing_evidence' : null,
        ]));
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function mergedMetadata(array $metadata, AgenticMarketingOpportunity $legacy, AgenticOpportunityBridgeEligibilityResult $eligibility): array
    {
        $preview = $eligibility->mappingResult->opportunityPreview;

        return array_replace_recursive($metadata, [
            'canonical_link_phase' => '3D',
            'source' => 'agentic_marketing_opportunity_bridge_writer',
            'source_model' => AgenticMarketingOpportunity::class,
            'source_id' => (string) $legacy->id,
            'legacy_agentic_marketing_opportunity_id' => (string) $legacy->id,
            'objective_id' => $eligibility->objectiveId,
            'detector_key' => $eligibility->detectorKey,
            'agentic_type' => $eligibility->agenticType,
            'agentic_status' => $eligibility->status,
            'source_scoped_dedupe_key' => $eligibility->mappingResult->dedupeKey,
            'payload_snapshot' => $legacy->payload ?? [],
            'execution_continuity_note' => 'Phase 3D writes only the passive canonical bridge; Agentic actions and execution pipelines continue to use agentic_marketing_opportunities.id.',
            'phase_3b_preview_metadata' => $preview?->metadata ?? [],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(
        AgenticMarketingOpportunity $legacy,
        AgenticCanonicalOpportunityPreview $preview,
        AgenticOpportunityBridgeEligibilityResult $eligibility,
        array $metadata,
    ): array {
        return [
            'organization_id' => $preview->organizationId,
            'workspace_id' => (string) $preview->workspaceId,
            'client_site_id' => $preview->clientSiteId,
            'content_id' => $preview->contentId,
            'agentic_marketing_opportunity_id' => (string) $legacy->id,
            'category' => OpportunityCategory::tryFrom((string) $preview->category) ?? OpportunityCategory::CONTENT_GAP,
            'status' => OpportunityStatus::OPEN,
            'title' => (string) $preview->title,
            'topic' => data_get($preview->sourceSignalSummary, 'topic') ?: $preview->title,
            'summary' => $preview->summary,
            'priority_score' => $preview->priority,
            'confidence_score' => $preview->confidence,
            'impact_score' => $preview->impact,
            'urgency_score' => 0,
            'effort_score' => $preview->effort,
            'score_breakdown' => [
                'priority' => $preview->priority,
                'confidence' => $preview->confidence,
                'impact' => $preview->impact,
                'effort' => $preview->effort,
                'business_value' => $preview->businessValue,
                'legacy_priority_score' => $legacy->priority_score,
            ],
            'recommended_actions' => $preview->recommendedActions,
            'evidence' => $preview->evidence,
            'source_signal_summary' => array_replace_recursive($preview->sourceSignalSummary, [
                'agentic_marketing_opportunity_id' => (string) $legacy->id,
                'source_scoped_dedupe_key' => $preview->dedupeKey,
            ]),
            'metadata' => $this->mergedMetadata($metadata, $legacy, $eligibility),
            'dedupe_hash' => $preview->dedupeKey,
            'first_seen_at' => $legacy->created_at,
            'last_seen_at' => $legacy->updated_at,
        ];
    }

    /**
     * @param  array<int,string>  $reasons
     * @param  array<string,mixed>  $operatorContext
     */
    private function result(
        string $status,
        AgenticOpportunityBridgeEligibilityResult $eligibility,
        ?Opportunity $opportunity,
        array $reasons,
        bool $dryRun,
        array $operatorContext,
    ): AgenticOpportunityBridgeWriteResult {
        return new AgenticOpportunityBridgeWriteResult(
            status: $status,
            eligibility: $eligibility,
            opportunity: $opportunity,
            reasons: array_values(array_unique($reasons)),
            dryRun: $dryRun,
            operatorContext: $operatorContext,
        );
    }
}
