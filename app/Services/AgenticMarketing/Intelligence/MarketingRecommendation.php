<?php

namespace App\Services\AgenticMarketing\Intelligence;

use App\Support\Intelligence\EvidenceBag;
use App\Support\Intelligence\EvidenceReference;
use App\Support\Intelligence\HasIntelligenceStage;
use App\Support\Intelligence\IntelligenceGraphEdge;
use App\Support\Intelligence\IntelligenceGraphEdgeType;
use App\Support\Intelligence\IntelligenceGraphReference;
use App\Support\Intelligence\IntelligenceStage;
use App\Support\Intelligence\ReasoningContext;
use App\Support\Intelligence\ReasoningInput;
use App\Support\Intelligence\ReasoningOutput;
use App\Support\Intelligence\ReasoningResult;
use App\Support\Intelligence\ReasoningStage;
use App\Support\Intelligence\ReasoningStep;
use App\Support\Intelligence\ReasoningTrace;
use App\Support\Intelligence\TimeWindow;

class MarketingRecommendation implements HasIntelligenceStage
{
    /**
     * @param  array<int, string>  $recommendedActions
     * @param  array<int, string>  $supportingInsightKeys
     * @param  array<int, array<string, mixed>>  $affectedPages
     * @param  array<int, string>  $affectedTopics
     * @param  array<int, string>  $affectedChannels
     * @param  array<int, string>  $affectedCompetitors
     * @param  array<string, mixed>  $marketPackContext
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $title,
        public readonly string $summary,
        public readonly int $priority,
        public readonly float $confidence,
        public readonly MarketingEvidence $evidence,
        public readonly array $recommendedActions = [],
        public readonly array $supportingInsightKeys = [],
        public readonly array $affectedPages = [],
        public readonly array $affectedTopics = [],
        public readonly array $affectedChannels = [],
        public readonly array $affectedCompetitors = [],
        public readonly array $marketPackContext = [],
        public readonly array $metadata = [],
    ) {
    }

    public function intelligenceStage(): IntelligenceStage
    {
        return IntelligenceStage::RECOMMENDATION;
    }

    public function evidenceBag(?TimeWindow $timeWindow = null): EvidenceBag
    {
        return EvidenceBag::merge(
            $this->evidence->toEvidenceBag($timeWindow),
            new EvidenceBag([
                EvidenceReference::marketingRecommendation(
                    $this->key,
                    $this->title,
                    confidence: $this->confidence,
                    reason: $this->summary,
                    timeWindow: $timeWindow,
                    metadata: [
                        'type' => $this->type,
                        'priority' => $this->priority,
                    ],
                ),
            ]),
        );
    }

    public function toGraphReference(): IntelligenceGraphReference
    {
        return IntelligenceGraphReference::recommendation($this->key, $this->title, [
            'type' => $this->type,
            'priority' => $this->priority,
            'confidence' => $this->confidence,
        ]);
    }

    /**
     * @return array<int, IntelligenceGraphEdge>
     */
    public function toGraphEdges(?TimeWindow $timeWindow = null): array
    {
        $target = $this->toGraphReference();
        $bag = $this->evidenceBag($timeWindow);
        $edges = [];

        foreach ($this->supportingInsightKeys as $insightKey) {
            $edges[] = new IntelligenceGraphEdge(
                IntelligenceGraphEdgeType::RECOMMENDS,
                IntelligenceGraphReference::insight($insightKey),
                $target,
                confidence: $this->confidence,
                evidence: $bag->toEvidence(),
                timeWindow: $timeWindow,
                metadata: [
                    'recommendation_key' => $this->key,
                    'recommendation_type' => $this->type,
                ],
                stage: $this->intelligenceStage(),
            );
        }

        foreach ($bag->references as $reference) {
            $source = $reference->toGraphReference();

            if ($source->graphKey() === $target->graphKey()) {
                continue;
            }

            $edges[] = new IntelligenceGraphEdge(
                IntelligenceGraphEdgeType::EVIDENCES,
                $source,
                $target,
                confidence: $reference->confidence ?? $this->confidence,
                evidence: $bag->toEvidence(),
                timeWindow: $timeWindow,
                metadata: [
                    'recommendation_key' => $this->key,
                    'recommendation_type' => $this->type,
                ],
                stage: $this->intelligenceStage(),
            );
        }

        foreach ($this->affectedGraphReferences() as $reference) {
            $edges[] = new IntelligenceGraphEdge(
                IntelligenceGraphEdgeType::REFERENCES,
                $target,
                $reference,
                confidence: $this->confidence,
                evidence: $bag->toEvidence(),
                timeWindow: $timeWindow,
                metadata: [
                    'recommendation_key' => $this->key,
                    'recommendation_type' => $this->type,
                ],
                stage: $this->intelligenceStage(),
            );
        }

        foreach ($this->recommendedActions as $action) {
            $action = trim((string) $action);

            if ($action === '') {
                continue;
            }

            $edges[] = new IntelligenceGraphEdge(
                IntelligenceGraphEdgeType::ACTS_ON,
                $target,
                IntelligenceGraphReference::action('recommended-action:'.sha1($this->key.'|'.$action), $action),
                confidence: $this->confidence,
                evidence: $bag->toEvidence(),
                timeWindow: $timeWindow,
                metadata: [
                    'recommendation_key' => $this->key,
                    'recommendation_type' => $this->type,
                ],
                stage: $this->intelligenceStage(),
            );
        }

        return $this->uniqueEdges($edges);
    }

    public function toReasoningResult(?TimeWindow $timeWindow = null, ?ReasoningContext $context = null): ReasoningResult
    {
        $bag = $this->evidenceBag($timeWindow);
        $graphReferences = $this->supportingGraphReferences($timeWindow);
        $context ??= new ReasoningContext(
            key: 'agentic-marketing-recommendation:'.$this->key,
            timeWindow: $timeWindow,
            evidence: $bag,
            graphReferences: $graphReferences,
            metadata: [
                'domain' => 'agentic_marketing',
                'projection' => 'marketing_recommendation',
            ],
            provenance: [
                'projector' => 'marketing_recommendation_read_model',
                'storage_mutated' => false,
            ],
        );
        $input = new ReasoningInput(
            key: 'recommendation-input:'.$this->key,
            stage: ReasoningStage::INSIGHT,
            type: $this->type,
            summary: $this->summary,
            payload: [
                'supporting_insight_keys' => $this->supportingInsightKeys,
                'supporting_evidence' => $this->evidence->toArray(),
                'metadata' => $this->metadata,
            ],
            evidence: $bag,
            timeWindow: $timeWindow,
            graphReferences: $graphReferences,
            confidence: $this->confidence,
            priority: $this->priority,
            provenance: [
                'projector' => 'marketing_recommendation_read_model',
            ],
        );
        $output = new ReasoningOutput(
            key: $this->key,
            stage: ReasoningStage::RECOMMENDATION,
            type: $this->type,
            summary: $this->title,
            payload: [
                'recommendation' => $this->toArray(),
            ],
            evidence: $bag,
            timeWindow: $timeWindow,
            graphReferences: $graphReferences,
            confidence: $this->confidence,
            priority: $this->priority,
            provenance: [
                'projector' => 'marketing_recommendation_read_model',
            ],
            artifact: $this,
        );

        $step = new ReasoningStep(
            $input,
            $output,
            evidence: $bag,
            timeWindow: $timeWindow,
            graphReferences: $graphReferences,
            graphEdges: $this->toGraphEdges($timeWindow),
            confidence: $this->confidence,
            priority: $this->priority,
            provenance: [
                'projector' => 'marketing_recommendation_read_model',
            ],
        );

        return new ReasoningResult(
            pipelineKey: 'agentic_marketing_recommendation_projection',
            context: $context,
            input: $input,
            output: $output,
            trace: new ReasoningTrace([$step]),
            metadata: [
                'projection' => 'marketing_recommendation',
                'domain' => 'agentic_marketing',
            ],
            provenance: [
                'projector' => 'marketing_recommendation_read_model',
                'storage_mutated' => false,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'intelligence_stage' => $this->intelligenceStage()->value,
            'key' => $this->key,
            'type' => $this->type,
            'title' => $this->title,
            'summary' => $this->summary,
            'priority' => $this->priority,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence->toArray(),
            'supporting_observations' => $this->evidence->marketingObservationIds,
            'supporting_observation_ids' => $this->evidence->marketingObservationIds,
            'recommended_actions' => $this->recommendedActions,
            'supporting_insight_keys' => $this->supportingInsightKeys,
            'affected_pages' => $this->affectedPages,
            'affected_topics' => $this->affectedTopics,
            'affected_channels' => $this->affectedChannels,
            'affected_competitors' => $this->affectedCompetitors,
            'market_pack_context' => $this->marketPackContext,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<int, IntelligenceGraphReference>
     */
    private function supportingGraphReferences(?TimeWindow $timeWindow = null): array
    {
        $references = [];

        foreach ($this->supportingInsightKeys as $insightKey) {
            $reference = IntelligenceGraphReference::insight($insightKey);
            $references[$reference->graphKey()] = $reference;
        }

        foreach ($this->evidenceBag($timeWindow)->references as $reference) {
            $graphReference = $reference->toGraphReference();

            if ($graphReference->graphKey() !== $this->toGraphReference()->graphKey()) {
                $references[$graphReference->graphKey()] = $graphReference;
            }
        }

        foreach ($this->affectedGraphReferences() as $reference) {
            $references[$reference->graphKey()] = $reference;
        }

        return array_values($references);
    }

    /**
     * @return array<int, IntelligenceGraphReference>
     */
    private function affectedGraphReferences(): array
    {
        $references = [];

        foreach ($this->affectedPages as $page) {
            $key = (string) ($page['id'] ?? $page['url'] ?? $page['canonical_url'] ?? '');

            if ($key !== '') {
                $references[] = IntelligenceGraphReference::page($key, $this->labelFrom($page, ['title', 'label', 'url', 'canonical_url']));
            }
        }

        foreach ($this->affectedTopics as $topic) {
            $references[] = IntelligenceGraphReference::topic((string) $topic, (string) $topic);
        }

        foreach ($this->affectedChannels as $channel) {
            $references[] = IntelligenceGraphReference::make('channel', (string) $channel, (string) $channel);
        }

        foreach ($this->affectedCompetitors as $competitor) {
            $references[] = IntelligenceGraphReference::make('competitor', (string) $competitor, (string) $competitor);
        }

        return collect($references)
            ->unique(fn (IntelligenceGraphReference $reference): string => $reference->graphKey())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function labelFrom(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param  array<int, IntelligenceGraphEdge>  $edges
     * @return array<int, IntelligenceGraphEdge>
     */
    private function uniqueEdges(array $edges): array
    {
        return collect($edges)
            ->unique(fn (IntelligenceGraphEdge $edge): string => $edge->key())
            ->values()
            ->all();
    }
}
