<?php

namespace App\Support\Intelligence;

interface ReasoningPipelineProjector
{
    public function project(ReasoningContext $context, ReasoningInput $input, ReasoningStage $targetStage): ReasoningOutput;
}
