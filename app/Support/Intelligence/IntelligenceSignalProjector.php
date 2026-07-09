<?php

namespace App\Support\Intelligence;

interface IntelligenceSignalProjector
{
    public function projectSignal(IntelligenceSignal $signal): static;

    /**
     * @param  iterable<int, IntelligenceSignal>  $signals
     */
    public function project(iterable $signals): static;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function signals(): array;

    /**
     * @return array{nodes:array<int,array<string,mixed>>,edges:array<int,array<string,mixed>>}
     */
    public function graph(): array;
}
