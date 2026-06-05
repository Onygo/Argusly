<?php

namespace App\Services\AgenticMarketing\Orchestration\Contracts;

use App\Services\AgenticMarketing\Orchestration\AgentDefinition;
use App\Services\AgenticMarketing\Orchestration\AgentTaskResult;

interface MarketingAgent
{
    public function definition(): AgentDefinition;

    public function handle(array $sharedContext, array $taskInput = []): AgentTaskResult;
}
