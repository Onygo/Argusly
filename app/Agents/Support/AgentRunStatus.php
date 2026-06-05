<?php

namespace App\Agents\Support;

enum AgentRunStatus: string
{
    case RUNNING = 'running';
    case SUCCESS = 'success';
    case SKIPPED = 'skipped';
    case WARNING = 'warning';
    case FAILED = 'failed';

    public function isFinal(): bool
    {
        return $this !== self::RUNNING;
    }
}
