<?php

namespace App\Support\Intelligence;

interface IntelligenceGraphProjector
{
    public function projectNode(IntelligenceGraphNode $node): static;

    public function projectEdge(IntelligenceGraphEdge $edge): static;

    /**
     * @param  iterable<int, IntelligenceGraphNode|IntelligenceGraphEdge>  $items
     */
    public function project(iterable $items): static;

    /**
     * @return array{nodes:array<int,array<string,mixed>>,edges:array<int,array<string,mixed>>}
     */
    public function graph(): array;
}
