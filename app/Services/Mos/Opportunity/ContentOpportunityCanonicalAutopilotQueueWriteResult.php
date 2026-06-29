<?php

namespace App\Services\Mos\Opportunity;

use App\Models\GrowthAutopilotQueueItem;
use App\Models\RecommendedAction;

class ContentOpportunityCanonicalAutopilotQueueWriteResult
{
    /**
     * @param  array<int, string>  $blockedReasons
     * @param  array<int, string>  $duplicateExecutionRisks
     * @param  array<int, array<string, mixed>>  $legacyQueueItems
     * @param  array<int, array<string, mixed>>  $canonicalQueueItems
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $applied,
        public readonly bool $safe,
        public readonly string $status,
        public readonly ?GrowthAutopilotQueueItem $queueItem,
        public readonly ?RecommendedAction $recommendedAction,
        public readonly ?string $canonicalOpportunityId,
        public readonly string $legacyContentOpportunityId,
        public readonly ?string $sourceSignature,
        public readonly ?string $queueSignature,
        public readonly bool $featureEnabled,
        public readonly array $blockedReasons,
        public readonly array $duplicateExecutionRisks,
        public readonly array $legacyQueueItems,
        public readonly array $canonicalQueueItems,
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
            'growth_autopilot_queue_item_id' => $this->queueItem?->id ? (string) $this->queueItem->id : null,
            'recommended_action_id' => $this->recommendedAction?->id ? (string) $this->recommendedAction->id : null,
            'canonical_opportunity_id' => $this->canonicalOpportunityId,
            'legacy_content_opportunity_id' => $this->legacyContentOpportunityId,
            'source_signature' => $this->sourceSignature,
            'queue_signature' => $this->queueSignature,
            'feature_enabled' => $this->featureEnabled,
            'blocked_reasons' => $this->blockedReasons,
            'duplicate_execution_risks' => $this->duplicateExecutionRisks,
            'legacy_queue_items' => $this->legacyQueueItems,
            'canonical_queue_items' => $this->canonicalQueueItems,
            'metadata' => $this->metadata,
        ];
    }
}
