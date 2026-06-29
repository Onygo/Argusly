<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class AgenticOpportunityCanonicalReadService
{
    public function __construct(
        private readonly AgenticOpportunityCanonicalMappingService $mapping,
    ) {}

    public function read(AgenticMarketingOpportunity $legacy): AgenticOpportunityCanonicalReadModel
    {
        $legacy->loadMissing('objective');

        $linked = $this->safeLinkedCanonicalOpportunity($legacy, $blockedReasons);
        $mapping = $this->mapping->mapExisting($legacy);
        $preview = $mapping->opportunityPreview;
        $detectorKey = $mapping->detectorKey;

        $workspaceId = $this->stringValue($linked?->workspace_id)
            ?: $this->stringValue($legacy->objective?->workspace_id)
            ?: $this->stringValue(data_get($legacy->payload, 'workspace_id'));
        $siteId = $this->stringValue($linked?->client_site_id)
            ?: $this->stringValue($legacy->objective?->client_site_id)
            ?: $this->stringValue(data_get($legacy->payload, 'client_site_id'))
            ?: $this->stringValue(data_get($legacy->payload, 'signals.client_site_id'));
        $contentId = $this->stringValue($linked?->content_id)
            ?: $this->stringValue($legacy->content_id)
            ?: $this->stringValue(data_get($legacy->payload, 'content_id'));

        return new AgenticOpportunityCanonicalReadModel(
            legacyAgenticOpportunityId: (string) $legacy->id,
            canonicalOpportunityId: $linked?->id ? (string) $linked->id : null,
            objectiveId: $this->legacyValue('objective_id', $this->stringValue($legacy->objective_id), $provenance),
            workspaceId: $this->contextValue('workspace_id', $this->stringValue($linked?->workspace_id), $workspaceId, $provenance),
            siteId: $this->contextValue('site_id', $this->stringValue($linked?->client_site_id), $siteId, $provenance),
            contentId: $this->contextValue('content_id', $this->stringValue($linked?->content_id), $contentId, $provenance),
            title: $this->value('title', $linked?->title, $legacy->title, $provenance),
            summary: $this->value('summary', $linked?->summary, $this->legacySummary($legacy), $provenance),
            category: $this->value('category', $this->enumValue($linked?->category), $preview?->category ?: $legacy->type, $provenance),
            status: $this->legacyValue('status', $this->stringValue($legacy->status), $provenance),
            priorityScore: $this->floatValue('priority_score', $linked?->priority_score, $legacy->priority_score, $provenance),
            confidenceScore: $this->floatValue('confidence_score', $linked?->confidence_score, data_get($legacy->payload, 'score_explanation.confidence_score'), $provenance),
            impactScore: $this->floatValue('impact_score', $linked?->impact_score, data_get($legacy->payload, 'score_explanation.impact_score'), $provenance),
            effortScore: $this->floatValue('effort_score', $linked?->effort_score, data_get($legacy->payload, 'score_explanation.effort_score'), $provenance),
            urgencyScore: $this->floatValue('urgency_score', $linked?->urgency_score, data_get($legacy->payload, 'score_explanation.urgency_score'), $provenance),
            recommendedActions: $this->arrayValue('recommended_actions', $linked?->recommended_actions, $this->legacyRecommendedActions($legacy), $provenance),
            evidence: $this->arrayValue('evidence', $linked?->evidence, $this->legacyEvidence($legacy, $detectorKey), $provenance),
            detectorKey: $this->legacyValue('detector_key', $detectorKey, $provenance),
            agenticType: $this->legacyValue('agentic_type', $this->stringValue($legacy->type), $provenance),
            executionStateSummary: $this->executionStateSummary($legacy),
            sourceSignalSummary: $this->arrayValue('source_signal_summary', $linked?->source_signal_summary, $preview?->sourceSignalSummary ?: $this->legacySourceSignalSummary($legacy, $detectorKey), $provenance),
            provenance: $provenance ?? [],
            migrationReadiness: [
                'canonical_bridge_available' => $linked !== null,
                'canonical_enriched' => $linked !== null,
                'legacy_status_authoritative' => true,
                'legacy_execution_authoritative' => true,
                'action_planning_legacy_only' => true,
                'blocked' => $blockedReasons !== [] || $mapping->blockedReasons !== [],
            ],
            blockedReasons: array_values(array_unique(array_merge($blockedReasons, $mapping->blockedReasons))),
            legacyFields: [
                'id' => (string) $legacy->id,
                'objective_id' => $this->stringValue($legacy->objective_id),
                'content_id' => $this->stringValue($legacy->content_id),
                'type' => $this->stringValue($legacy->type),
                'status' => $this->stringValue($legacy->status),
                'priority_score' => $legacy->priority_score,
                'payload' => (array) ($legacy->payload ?? []),
            ],
        );
    }

    /**
     * @param  EloquentCollection<int, AgenticMarketingOpportunity>  $opportunities
     * @return Collection<int, AgenticOpportunityCanonicalReadModel>
     */
    public function readMany(EloquentCollection $opportunities): Collection
    {
        $opportunities->loadMissing('objective');

        return $opportunities->map(fn (AgenticMarketingOpportunity $opportunity): AgenticOpportunityCanonicalReadModel => $this->read($opportunity));
    }

    /**
     * @param  array<int, string>|null  $blockedReasons
     */
    private function safeLinkedCanonicalOpportunity(AgenticMarketingOpportunity $legacy, ?array &$blockedReasons): ?Opportunity
    {
        $blockedReasons = [];

        $linked = Opportunity::query()
            ->where('agentic_marketing_opportunity_id', $legacy->id)
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

        $legacyWorkspaceId = $this->stringValue($legacy->objective?->workspace_id);
        if ($legacyWorkspaceId && (string) $canonical->workspace_id !== $legacyWorkspaceId) {
            $blockedReasons[] = 'canonical_bridge_workspace_mismatch';

            return null;
        }

        return $canonical;
    }

    /**
     * @param  array<string, string>|null  $provenance
     */
    private function value(string $field, mixed $canonical, mixed $legacy, ?array &$provenance): mixed
    {
        if ($canonical !== null && (! is_string($canonical) || trim($canonical) !== '')) {
            $provenance[$field] = 'canonical';

            return $canonical;
        }

        $provenance[$field] = 'legacy';

        return $legacy;
    }

    /**
     * @param  array<string, string>|null  $provenance
     */
    private function contextValue(string $field, mixed $canonical, mixed $legacy, ?array &$provenance): mixed
    {
        if ($canonical !== null && trim((string) $canonical) !== '') {
            $provenance[$field] = 'canonical_context';

            return $canonical;
        }

        $provenance[$field] = 'legacy_context';

        return $legacy;
    }

    /**
     * @param  array<string, string>|null  $provenance
     */
    private function legacyValue(string $field, mixed $legacy, ?array &$provenance): mixed
    {
        $provenance[$field] = 'legacy';

        return $legacy;
    }

    /**
     * @param  array<string, string>|null  $provenance
     */
    private function floatValue(string $field, mixed $canonical, mixed $legacy, ?array &$provenance): ?float
    {
        $value = $this->value($field, $canonical, $legacy, $provenance);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, string>|null  $provenance
     * @return array<int|string, mixed>
     */
    private function arrayValue(string $field, mixed $canonical, mixed $legacy, ?array &$provenance): array
    {
        $value = $this->value($field, $canonical, $legacy, $provenance);

        return is_array($value) ? array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== '') : [];
    }

    private function legacySummary(AgenticMarketingOpportunity $legacy): ?string
    {
        return $this->stringValue(
            data_get($legacy->payload, 'summary')
            ?: data_get($legacy->payload, 'score_explanation.summary')
            ?: data_get($legacy->payload, 'reasoning')
            ?: data_get($legacy->payload, 'reason')
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function legacyRecommendedActions(AgenticMarketingOpportunity $legacy): array
    {
        return collect([
            ...(array) data_get($legacy->payload, 'recommended_actions', []),
            data_get($legacy->payload, 'recommendation'),
            data_get($legacy->payload, 'suggested_cta'),
            data_get($legacy->payload, 'suggested_schema'),
        ])->filter()->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyEvidence(AgenticMarketingOpportunity $legacy, ?string $detectorKey): array
    {
        return array_filter([
            'source' => 'legacy_agentic_marketing_opportunity',
            'source_model' => AgenticMarketingOpportunity::class,
            'source_id' => (string) $legacy->id,
            'detector_key' => $detectorKey,
            'signals' => (array) data_get($legacy->payload, 'signals', []),
            'references' => (array) data_get($legacy->payload, 'references', []),
            'reasoning' => data_get($legacy->payload, 'reasoning') ?: data_get($legacy->payload, 'reason'),
            'score_explanation' => (array) data_get($legacy->payload, 'score_explanation', []),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private function legacySourceSignalSummary(AgenticMarketingOpportunity $legacy, ?string $detectorKey): array
    {
        return array_filter([
            'source' => 'legacy_agentic_marketing_opportunity',
            'detector_key' => $detectorKey,
            'agentic_marketing_opportunity_id' => (string) $legacy->id,
            'objective_id' => $this->stringValue($legacy->objective_id),
            'opportunity_type' => $this->stringValue($legacy->type),
            'topic' => data_get($legacy->payload, 'topic') ?: data_get($legacy->payload, 'signals.topic_keyword') ?: $legacy->title,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function executionStateSummary(AgenticMarketingOpportunity $legacy): array
    {
        $actions = AgenticMarketingAction::query()
            ->where('opportunity_id', $legacy->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return [
            'agentic_actions_count' => array_sum(array_map('intval', $actions)),
            'open_agentic_actions_count' => (int) collect($actions)->only(['proposed', 'approved', 'running'])->sum(),
            'agentic_actions_by_status' => $actions,
            'execution_pipeline_count' => AgenticMarketingExecutionPipeline::query()
                ->where('opportunity_id', $legacy->id)
                ->count(),
            'legacy_opportunity_id_authoritative' => true,
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return $this->stringValue($value);
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
