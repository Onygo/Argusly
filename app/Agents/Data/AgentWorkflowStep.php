<?php

namespace App\Agents\Data;

use App\Agents\Contracts\AgentInterface;
use Closure;

class AgentWorkflowStep
{
    /**
     * @param  array<int, string>  $haltOnStatuses
     * @param  null|Closure(AgentContext, array<int, array<string, mixed>>): bool  $shouldRun
     * @param  null|Closure(AgentContext, array<int, array<string, mixed>>): AgentContext  $contextResolver
     */
    public function __construct(
        public readonly string $key,
        public readonly AgentInterface $agent,
        public readonly bool $continueOnFailure = true,
        public readonly array $haltOnStatuses = [],
        public readonly ?Closure $shouldRun = null,
        public readonly ?Closure $contextResolver = null,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $completedSteps
     */
    public function shouldExecute(AgentContext $context, array $completedSteps): bool
    {
        if ($this->shouldRun === null) {
            return true;
        }

        return (bool) ($this->shouldRun)($context, $completedSteps);
    }

    /**
     * @param  array<int, array<string, mixed>>  $completedSteps
     */
    public function resolveContext(AgentContext $context, array $completedSteps): AgentContext
    {
        if ($this->contextResolver === null) {
            return $context;
        }

        return ($this->contextResolver)($context, $completedSteps);
    }

    public function shouldHaltAfter(string $status): bool
    {
        if (in_array($status, $this->haltOnStatuses, true)) {
            return true;
        }

        return ! $this->continueOnFailure && $status === 'failed';
    }
}

