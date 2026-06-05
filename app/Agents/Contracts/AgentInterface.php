<?php

namespace App\Agents\Contracts;

use App\Agents\Data\AgentContext;
use App\Agents\Data\AgentResult;

interface AgentInterface
{
    public function key(): string;

    public function supports(AgentContext $context): bool;

    public function run(AgentContext $context): AgentResult;
}
