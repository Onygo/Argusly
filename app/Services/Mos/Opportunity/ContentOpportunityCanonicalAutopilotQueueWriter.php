<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ContentOpportunity;
use App\Models\GrowthAutopilotQueueItem;
use App\Models\Opportunity;
use App\Services\GrowthAutopilot\GrowthAutopilotQueueBuilder;
use App\Services\RecommendedActions\RecommendedActionEngine;
use App\Services\RecommendedActions\RecommendedActionMapper;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class ContentOpportunityCanonicalAutopilotQueueWriter
{
    public function __construct(
        private readonly RecommendedActionMapper $mapper,
        private readonly RecommendedActionEngine $recommendedActions,
        private readonly GrowthAutopilotQueueBuilder $queueBuilder,
    ) {}

    public function dryRun(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
    ): ContentOpportunityCanonicalAutopilotQueueWriteResult {
        return $this->run($contentOpportunity, $canonical, false);
    }

    public function apply(
        ContentOpportunity $contentOpportunity,
        Opportunity $canonical,
    ): ContentOpportunityCanonicalAutopilotQueueWriteResult {
        return $this->run($contentOpportunity, $canonical, true);
    }

    private function run(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
        bool $apply,
    ): ContentOpportunityCanonicalAutopilotQueueWriteResult {
        $featureEnabled = (bool) config('features.mos_canonical_content_opportunity_autopilot_writer', false);
        $payload = $canonical ? $this->mapper->map($canonical) : null;
        $sourceSignature = $payload ? (string) $payload['source_signature'] : null;
        $queueSignature = $sourceSignature ? $this->queueSignature((string) $contentOpportunity->workspace_id, $sourceSignature) : null;
        $legacyQueueItems = $this->legacyQueueItems($contentOpportunity, $sourceSignature, $queueSignature);
        $canonicalQueueItems = $canonical ? $this->canonicalQueueItems($canonical, $queueSignature) : new EloquentCollection;
        $duplicateRisks = $this->duplicateExecutionRisks($legacyQueueItems, $canonicalQueueItems, $queueSignature);
        $blocked = $this->blockedReasons($contentOpportunity, $canonical, $duplicateRisks, $apply, $featureEnabled);
        $safe = $blocked === [];
        $metadata = $this->metadata($contentOpportunity, $canonical, $sourceSignature, $queueSignature);

        if (! $apply || ! $safe) {
            return new ContentOpportunityCanonicalAutopilotQueueWriteResult(
                applied: false,
                safe: $safe,
                status: $safe ? 'would_create' : 'blocked',
                queueItem: null,
                recommendedAction: null,
                canonicalOpportunityId: $canonical?->id ? (string) $canonical->id : null,
                legacyContentOpportunityId: (string) $contentOpportunity->id,
                sourceSignature: $sourceSignature,
                queueSignature: $queueSignature,
                featureEnabled: $featureEnabled,
                blockedReasons: $blocked,
                duplicateExecutionRisks: $duplicateRisks,
                legacyQueueItems: $this->queueReferences($legacyQueueItems),
                canonicalQueueItems: $this->queueReferences($canonicalQueueItems),
                metadata: $metadata,
            );
        }

        [$action, $item, $created] = DB::transaction(function () use ($canonical, $metadata): array {
            $action = $this->recommendedActions->upsertFromSource($canonical);
            $before = GrowthAutopilotQueueItem::query()
                ->where('source_signature', $this->queueSignature((string) $canonical->workspace_id, (string) $action->source_signature))
                ->first();

            $item = $this->queueBuilder->upsertFromAction($action);
            $item->forceFill([
                'metadata' => array_replace_recursive((array) $item->metadata, $metadata),
            ])->save();

            return [$action->refresh(), $item->refresh(), $before === null && $item->wasRecentlyCreated];
        });

        return new ContentOpportunityCanonicalAutopilotQueueWriteResult(
            applied: $created,
            safe: $created,
            status: $created ? 'created' : 'duplicate',
            queueItem: $created ? $item : null,
            recommendedAction: $action,
            canonicalOpportunityId: (string) $canonical->id,
            legacyContentOpportunityId: (string) $contentOpportunity->id,
            sourceSignature: (string) $action->source_signature,
            queueSignature: (string) $item->source_signature,
            featureEnabled: $featureEnabled,
            blockedReasons: $created ? [] : ['duplicate_queue_item'],
            duplicateExecutionRisks: $created ? [] : ['canonical_queue_item_exists'],
            legacyQueueItems: $this->queueReferences($this->legacyQueueItems($contentOpportunity, (string) $action->source_signature, (string) $item->source_signature)),
            canonicalQueueItems: $this->queueReferences($this->canonicalQueueItems($canonical, (string) $item->source_signature)),
            metadata: $metadata,
        );
    }

    /**
     * @return EloquentCollection<int, GrowthAutopilotQueueItem>
     */
    private function legacyQueueItems(ContentOpportunity $contentOpportunity, ?string $sourceSignature, ?string $queueSignature): EloquentCollection
    {
        return GrowthAutopilotQueueItem::query()
            ->where(function ($query) use ($contentOpportunity, $sourceSignature, $queueSignature): void {
                $query->where(function ($nested) use ($contentOpportunity): void {
                    $nested->where('source_type', $contentOpportunity->getMorphClass())
                        ->where('source_id', (string) $contentOpportunity->id);
                });

                if ($queueSignature) {
                    $query->orWhere('source_signature', $queueSignature);
                }

                if ($sourceSignature) {
                    $query->orWhere('metadata->recommended_action_source_signature', $sourceSignature);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @return EloquentCollection<int, GrowthAutopilotQueueItem>
     */
    private function canonicalQueueItems(Opportunity $canonical, ?string $queueSignature): EloquentCollection
    {
        return GrowthAutopilotQueueItem::query()
            ->where(function ($query) use ($canonical, $queueSignature): void {
                $query->where(function ($nested) use ($canonical): void {
                    $nested->where('source_type', $canonical->getMorphClass())
                        ->where('source_id', (string) $canonical->id);
                });

                if ($queueSignature) {
                    $query->orWhere('source_signature', $queueSignature);
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int, GrowthAutopilotQueueItem>  $legacyQueueItems
     * @param  EloquentCollection<int, GrowthAutopilotQueueItem>  $canonicalQueueItems
     * @return array<int, string>
     */
    private function duplicateExecutionRisks(EloquentCollection $legacyQueueItems, EloquentCollection $canonicalQueueItems, ?string $queueSignature): array
    {
        $legacySourceItems = $legacyQueueItems->filter(fn (GrowthAutopilotQueueItem $item): bool => $item->source_type === ContentOpportunity::class);
        $sharedSignatureItems = $queueSignature
            ? $legacyQueueItems->merge($canonicalQueueItems)->where('source_signature', $queueSignature)->unique('id')
            : collect();

        return array_values(array_unique(array_filter([
            $legacySourceItems->isNotEmpty() ? 'legacy_autopilot_queue_item_exists' : null,
            $canonicalQueueItems->isNotEmpty() ? 'canonical_autopilot_queue_item_exists' : null,
            $sharedSignatureItems->isNotEmpty() ? 'canonical_equivalent_queue_signature_exists' : null,
        ])));
    }

    /**
     * @param  array<int, string>  $duplicateRisks
     * @return array<int, string>
     */
    private function blockedReasons(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
        array $duplicateRisks,
        bool $apply,
        bool $featureEnabled,
    ): array {
        $blocked = [];

        if (! $canonical) {
            $blocked[] = 'missing_canonical_opportunity';
        }

        if ($canonical && (string) $canonical->content_opportunity_id !== (string) $contentOpportunity->id) {
            $blocked[] = 'canonical_legacy_link_mismatch';
        }

        if ($canonical && (string) $canonical->workspace_id !== (string) $contentOpportunity->workspace_id) {
            $blocked[] = 'canonical_workspace_mismatch';
        }

        if ($apply && ! $featureEnabled) {
            $blocked[] = 'feature_flag_disabled';
        }

        return array_values(array_unique(array_merge($blocked, $duplicateRisks)));
    }

    private function queueSignature(string $workspaceId, string $sourceSignature): string
    {
        return sha1(implode('|', [
            'growth-autopilot',
            $workspaceId,
            $sourceSignature,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(
        ContentOpportunity $contentOpportunity,
        ?Opportunity $canonical,
        ?string $sourceSignature,
        ?string $queueSignature,
    ): array {
        return [
            'source' => 'mos_canonical_content_opportunity_autopilot_writer',
            'legacy_content_opportunity_id' => (string) $contentOpportunity->id,
            'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'recommended_action_source_signature' => $sourceSignature,
            'canonical_equivalent_queue_signature' => $queueSignature,
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
     * @param  EloquentCollection<int, GrowthAutopilotQueueItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function queueReferences(EloquentCollection $items): array
    {
        return $items
            ->unique('id')
            ->map(fn (GrowthAutopilotQueueItem $item): array => [
                'growth_autopilot_queue_item_id' => (string) $item->id,
                'source_type' => $item->source_type,
                'source_id' => $item->source_id ? (string) $item->source_id : null,
                'source_signature' => (string) $item->source_signature,
                'recommended_action_id' => $item->recommended_action_id ? (string) $item->recommended_action_id : null,
                'status' => (string) $item->status,
            ])
            ->values()
            ->all();
    }
}
