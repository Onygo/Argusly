<?php

namespace App\Support\Intelligence\Testing;

use App\Support\Intelligence\IntelligenceGraphProjector;
use App\Support\Intelligence\IntelligenceSignal;
use App\Support\Intelligence\IntelligenceSignalProjector;

class FakeIntelligenceSignalProjector implements IntelligenceSignalProjector
{
    /**
     * @var array<string, IntelligenceSignal>
     */
    private array $signals = [];

    public function __construct(
        private readonly IntelligenceGraphProjector $graphProjector = new FakeIntelligenceGraphProjector(),
    ) {
    }

    public function projectSignal(IntelligenceSignal $signal): static
    {
        $this->signals[$signal->key] = $signal;
        $this->graphProjector->projectNode($signal->toGraphNode());

        foreach ($signal->toGraphEdges() as $edge) {
            $this->graphProjector->projectEdge($edge);
        }

        return $this;
    }

    /**
     * @param  iterable<int, IntelligenceSignal>  $signals
     */
    public function project(iterable $signals): static
    {
        foreach ($signals as $signal) {
            $this->projectSignal($signal);
        }

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function signals(): array
    {
        return array_values(array_map(
            fn (IntelligenceSignal $signal): array => $signal->toArray(),
            $this->signals,
        ));
    }

    /**
     * @return array{nodes:array<int,array<string,mixed>>,edges:array<int,array<string,mixed>>}
     */
    public function graph(): array
    {
        return $this->graphProjector->graph();
    }
}
