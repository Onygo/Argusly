<?php

namespace App\Agents\Contracts;

use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentWorkflowStep;

interface AgentWorkflowInterface
{
    public function key(): string;

    public function supports(AgentContext $context): bool;

    /**
     * @return array<int, AgentWorkflowStep>
     */
    public function steps(AgentContext $context): array;
}

