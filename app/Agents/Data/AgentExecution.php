<?php

namespace App\Agents\Data;

use App\Models\AgentRun;

class AgentExecution
{
    public function __construct(
        public readonly AgentRun $run,
        public readonly AgentResult $result,
    ) {
    }
}

