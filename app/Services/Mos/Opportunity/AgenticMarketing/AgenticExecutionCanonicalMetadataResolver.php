<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;

class AgenticExecutionCanonicalMetadataResolver
{
    public const METADATA_VERSION = 'agentic-execution-canonical-context:v1';

    public function __construct(
        private readonly AgenticOpportunityExecutionContinuityService $continuity,
        private readonly AgenticOpportunityLifecycleInspectionService $lifecycle,
        private readonly AgenticOpportunityCanonicalActionOwnershipPlanner $ownershipPlanner,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function resolve(AgenticMarketingOpportunity $opportunity, ?string $executionContext = null): array
    {
        $opportunity->loadMissing('objective');

        $canonical = $this->safeLinkedCanonicalOpportunity($opportunity, $bridgeBlockedReasons);
        $continuity = $this->continuity->inspect($opportunity);
        $lifecycle = $this->lifecycle->inspect($opportunity, $canonical);
        $ownership = $this->ownershipPlanner->plan($opportunity);
        $metadata = $canonical ? $this->metadata($opportunity, $canonical) : [];
        $missingMetadataFields = $this->missingMetadataFields($metadata);
        $continuityBlockedReasons = $this->continuityBlockedReasons($continuity, $executionContext);
        $canonicalFieldBlockers = $this->canonicalFieldBlockers((array) ($continuity['missing_canonical_fields'] ?? []));
        $lifecycleBlockedReasons = $this->lifecycleBlockedReasons($lifecycle, $ownership);
        $blockedReasons = array_values(array_unique(array_filter(array_merge(
            $bridgeBlockedReasons,
            $canonical ? [] : ['missing_safe_canonical_bridge'],
            $missingMetadataFields,
            $canonicalFieldBlockers,
            $continuityBlockedReasons,
            $lifecycleBlockedReasons,
        ))));

        return [
            'safe' => $blockedReasons === [],
            'metadata' => $blockedReasons === [] ? $metadata : null,
            'blocked_reasons' => $blockedReasons,
            'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'legacy_agentic_opportunity_id' => (string) $opportunity->id,
            'execution_context' => $executionContext,
            'target_fields' => [
                'agentic_marketing_execution_pipelines.input.canonical_opportunity_context',
                'agentic_marketing_execution_assets.payload.canonical_opportunity_context',
                'agentic_action_runs.input_snapshot.canonical_opportunity_context',
                'briefs.client_refs.canonical_opportunity_id',
                'drafts.meta.canonical_opportunity_id',
            ],
            'diagnostics' => [
                'phase_3i_blocked_reasons' => $continuity['blocked_reasons'] ?? [],
                'phase_3j_lifecycle_blocked_reasons' => $lifecycle['blocked_reasons'] ?? [],
                'phase_3j_action_ownership_blocked_reasons' => $ownership['blocked_reasons'] ?? [],
            ],
        ];
    }

    private function safeLinkedCanonicalOpportunity(AgenticMarketingOpportunity $opportunity, ?array &$blockedReasons): ?Opportunity
    {
        $blockedReasons = [];
        $linked = Opportunity::query()
            ->where('agentic_marketing_opportunity_id', $opportunity->id)
            ->orderBy('id')
            ->get();

        if ($linked->count() > 1) {
            $blockedReasons[] = 'multiple_canonical_opportunities_linked_to_agentic_row';

            return null;
        }

        $canonical = $linked->first();
        if (! $canonical) {
            return null;
        }

        $legacyWorkspaceId = $this->stringValue($opportunity->objective?->workspace_id);
        if ($legacyWorkspaceId === null || (string) $canonical->workspace_id !== $legacyWorkspaceId) {
            $blockedReasons[] = $legacyWorkspaceId === null
                ? 'missing_workspace_id'
                : 'canonical_bridge_workspace_mismatch';

            return null;
        }

        $legacyOrganizationId = $this->stringValue($opportunity->objective?->organization_id);
        if ($legacyOrganizationId !== null && (string) $canonical->organization_id !== $legacyOrganizationId) {
            $blockedReasons[] = 'canonical_bridge_organization_mismatch';

            return null;
        }

        return $canonical;
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(AgenticMarketingOpportunity $opportunity, Opportunity $canonical): array
    {
        return [
            'canonical_opportunity_id' => (string) $canonical->id,
            'legacy_agentic_marketing_opportunity_id' => (string) $opportunity->id,
            'objective_id' => $this->stringValue($opportunity->objective_id),
            'workspace_id' => $this->stringValue($opportunity->objective?->workspace_id),
            'site_id' => $this->stringValue($opportunity->objective?->client_site_id),
            'detector_key' => $this->detectorKey($opportunity),
            'agentic_type' => $this->stringValue($opportunity->type),
            'agentic_status' => $this->stringValue($opportunity->status),
            'source_scoped_dedupe_key' => $this->stringValue($opportunity->dedupe_hash),
            'bridge_source' => 'opportunities.agentic_marketing_opportunity_id',
            'metadata_version' => self::METADATA_VERSION,
            'resolved_at' => now()->toIso8601String(),
            'resolver' => self::class,
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<int,string>
     */
    private function missingMetadataFields(array $metadata): array
    {
        return collect([
            'canonical_opportunity_id',
            'legacy_agentic_marketing_opportunity_id',
            'objective_id',
            'workspace_id',
            'site_id',
            'detector_key',
            'agentic_type',
            'agentic_status',
            'source_scoped_dedupe_key',
        ])
            ->filter(fn (string $field): bool => $this->stringValue($metadata[$field] ?? null) === null)
            ->map(fn (string $field): string => 'missing_metadata_field:'.$field)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $continuity
     * @return array<int,string>
     */
    private function continuityBlockedReasons(array $continuity, ?string $executionContext): array
    {
        $blocked = collect((array) ($continuity['blocked_reasons'] ?? []))
            ->reject(fn (string $reason): bool => $reason === 'missing_safe_canonical_bridge')
            ->reject(function (string $reason) use ($executionContext): bool {
                return $executionContext === 'action_run'
                    && $reason === 'canonical_parent_only_lookup_would_miss_actions';
            })
            ->values()
            ->all();

        return $blocked === [] ? [] : array_merge(['phase_3i_execution_continuity_blocked'], $blocked);
    }

    /**
     * @param  array<int,string>  $missingCanonicalFields
     * @return array<int,string>
     */
    private function canonicalFieldBlockers(array $missingCanonicalFields): array
    {
        $required = ['id', 'workspace_id', 'client_site_id', 'title', 'status', 'metadata'];

        return collect($missingCanonicalFields)
            ->intersect($required)
            ->map(fn (string $field): string => 'missing_canonical_field_for_execution_metadata:'.$field)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $lifecycle
     * @param  array<string,mixed>  $ownership
     * @return array<int,string>
     */
    private function lifecycleBlockedReasons(array $lifecycle, array $ownership): array
    {
        $reasons = [];

        $blockingLifecycleReasons = array_intersect((array) ($lifecycle['blocked_reasons'] ?? []), [
            'open_agentic_status_has_completed_execution',
            'dismissed_agentic_status_has_open_or_running_actions',
            'dismissed_agentic_status_has_execution_pipelines',
            'completed_agentic_status_has_execution_completion_scope',
            'completed_agentic_status_has_no_execution_scope',
            'unmapped_agentic_lifecycle_status',
        ]);

        if ($blockingLifecycleReasons !== []) {
            $reasons[] = 'phase_3j_lifecycle_status_ambiguous';
        }

        if ((bool) ($lifecycle['status_conflict'] ?? false)) {
            $reasons[] = 'phase_3j_lifecycle_status_conflict';
        }

        foreach ((array) ($ownership['blocked_reasons'] ?? []) as $reason) {
            if (in_array($reason, [
                'lifecycle_status_conflict',
            ], true)) {
                $reasons[] = 'phase_3j_action_ownership_'.$reason;
            }
        }

        return array_values(array_unique($reasons));
    }

    private function detectorKey(AgenticMarketingOpportunity $opportunity): ?string
    {
        return $this->stringValue(data_get($opportunity->payload, 'detector'))
            ?: $this->stringValue(data_get($opportunity->payload, 'detector_key'))
            ?: $this->stringValue(data_get($opportunity->payload, 'source_detector'))
            ?: $this->stringValue(data_get($opportunity->payload, 'signals.detector'));
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
