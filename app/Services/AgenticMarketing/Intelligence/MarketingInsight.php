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

class MarketingInsight implements HasIntelligenceStage
{
    /**
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
        public readonly string $direction,
        public readonly int $severity,
        public readonly float $confidence,
        public readonly MarketingEvidence $evidence,
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
        return IntelligenceStage::INSIGHT;
    }

    public function evidenceBag(?TimeWindow $timeWindow = null): EvidenceBag
    {
        return EvidenceBag::merge(
            $this->evidence->toEvidenceBag($timeWindow),
            new EvidenceBag([
                EvidenceReference::marketingInsight(
                    $this->key,
                    $this->title,
                    confidence: $this->confidence,
                    reason: $this->summary,
                    timeWindow: $timeWindow,
                    metadata: [
                        'type' => $this->type,
                        'direction' => $this->direction,
                        'severity' => $this->severity,
                    ],
                ),
            ]),
        );
    }

    public function toGraphReference(): IntelligenceGraphReference
    {
        return IntelligenceGraphReference::insight($this->key, $this->title, [
            'type' => $this->type,
            'direction' => $this->direction,
            'severity' => $this->severity,
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
                    'insight_key' => $this->key,
                    'insight_type' => $this->type,
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
                    'insight_key' => $this->key,
                    'insight_type' => $this->type,
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
            key: 'agentic-marketing-insight:'.$this->key,
            timeWindow: $timeWindow,
            evidence: $bag,
            graphReferences: $graphReferences,
            metadata: [
                'domain' => 'agentic_marketing',
                'projection' => 'marketing_insight',
            ],
            provenance: [
                'projector' => 'marketing_insight_read_model',
                'storage_mutated' => false,
            ],
        );
        $input = new ReasoningInput(
            key: 'insight-input:'.$this->key,
            stage: ReasoningStage::SIGNAL,
            type: $this->type,
            summary: $this->summary,
            payload: [
                'supporting_evidence' => $this->evidence->toArray(),
                'metadata' => $this->metadata,
            ],
            evidence: $bag,
            timeWindow: $timeWindow,
            graphReferences: $graphReferences,
            confidence: $this->confidence,
            priority: $this->severity,
            provenance: [
                'projector' => 'marketing_insight_read_model',
            ],
        );
        $output = new ReasoningOutput(
            key: $this->key,
            stage: ReasoningStage::INSIGHT,
            type: $this->type,
            summary: $this->title,
            payload: [
                'insight' => $this->toArray(),
            ],
            evidence: $bag,
            timeWindow: $timeWindow,
            graphReferences: $graphReferences,
            confidence: $this->confidence,
            priority: $this->severity,
            provenance: [
                'projector' => 'marketing_insight_read_model',
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
            priority: $this->severity,
            provenance: [
                'projector' => 'marketing_insight_read_model',
            ],
        );

        return new ReasoningResult(
            pipelineKey: 'agentic_marketing_insight_projection',
            context: $context,
            input: $input,
            output: $output,
            trace: new ReasoningTrace([$step]),
            metadata: [
                'projection' => 'marketing_insight',
                'domain' => 'agentic_marketing',
            ],
            provenance: [
                'projector' => 'marketing_insight_read_model',
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
            'direction' => $this->direction,
            'severity' => $this->severity,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence->toArray(),
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
