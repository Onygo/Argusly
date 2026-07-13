<?php

namespace App\Support\Intelligence;

class InMemoryIntelligenceGraphProjector implements IntelligenceGraphProjector
{
    /**
     * @var array<string, IntelligenceGraphNode>
     */
    private array $nodes = [];

    /**
     * @var array<string, IntelligenceGraphEdge>
     */
    private array $edges = [];

    public function projectNode(IntelligenceGraphNode $node): static
    {
        $this->nodes[$node->key()] = $node;

        return $this;
    }

    public function projectEdge(IntelligenceGraphEdge $edge): static
    {
        $this->edges[$edge->key()] = $edge;
        $this->nodes[$edge->source->graphKey()] ??= new IntelligenceGraphNode($edge->source);
        $this->nodes[$edge->target->graphKey()] ??= new IntelligenceGraphNode($edge->target);

        return $this;
    }

    /**
     * @param  iterable<int, IntelligenceGraphNode|IntelligenceGraphEdge>  $items
     */
    public function project(iterable $items): static
    {
        foreach ($items as $item) {
            if ($item instanceof IntelligenceGraphNode) {
                $this->projectNode($item);

                continue;
            }

            $this->projectEdge($item);
        }

        return $this;
    }

    /**
     * @return array{nodes:array<int,array<string,mixed>>,edges:array<int,array<string,mixed>>}
     */
    public function graph(): array
    {
        return [
            'nodes' => array_values(array_map(
                fn (IntelligenceGraphNode $node): array => $node->toArray(),
                $this->nodes,
            )),
            'edges' => array_values(array_map(
                fn (IntelligenceGraphEdge $edge): array => $edge->toArray(),
                $this->edges,
            )),
        ];
    }
}
