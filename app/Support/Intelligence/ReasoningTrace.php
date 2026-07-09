<?php

namespace App\Support\Intelligence;

class ReasoningTrace
{
    /**
     * @var array<int, ReasoningStep>
     */
    public readonly array $steps;

    /**
     * @param  iterable<int, ReasoningStep>  $steps
     */
    public function __construct(iterable $steps = [])
    {
        $this->steps = collect($steps)
            ->filter(fn (mixed $step): bool => $step instanceof ReasoningStep)
            ->values()
            ->all();
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    /**
     * @return array<int, string>
     */
    public function transitions(): array
    {
        return array_map(
            fn (ReasoningStep $step): string => $step->transition(),
            $this->steps,
        );
    }

    public function evidence(): EvidenceBag
    {
        return EvidenceBag::merge(...array_map(
            fn (ReasoningStep $step): EvidenceBag => $step->evidence,
            $this->steps,
        ));
    }

    /**
     * @return array<int, IntelligenceGraphReference>
     */
    public function graphReferences(): array
    {
        $references = [];

        foreach ($this->steps as $step) {
            foreach ($step->graphReferences as $reference) {
                $references[$reference->graphKey()] = $reference;
            }
        }

        return array_values($references);
    }

    /**
     * @return array<int, IntelligenceGraphEdge>
     */
    public function graphEdges(): array
    {
        $edges = [];

        foreach ($this->steps as $step) {
            foreach ($step->graphEdges as $edge) {
                $edges[$edge->key()] = $edge;
            }
        }

        return array_values($edges);
    }

    public function firstStep(): ?ReasoningStep
    {
        return $this->steps[0] ?? null;
    }

    public function lastStep(): ?ReasoningStep
    {
        return $this->steps[array_key_last($this->steps)] ?? null;
    }

    /**
     * @return array{steps:array<int,array<string,mixed>>,transitions:array<int,string>,evidence:array<string,mixed>,graph_references:array<int,array<string,mixed>>,graph_edges:array<int,array<string,mixed>>}
     */
    public function toArray(): array
    {
        return [
            'steps' => array_map(
                fn (ReasoningStep $step): array => $step->toArray(),
                $this->steps,
            ),
            'transitions' => $this->transitions(),
            'evidence' => $this->evidence()->toArray(),
            'graph_references' => array_map(
                fn (IntelligenceGraphReference $reference): array => $reference->toArray(),
                $this->graphReferences(),
            ),
            'graph_edges' => array_map(
                fn (IntelligenceGraphEdge $edge): array => $edge->toArray(),
                $this->graphEdges(),
            ),
        ];
    }
}
