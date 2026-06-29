<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\Opportunity;

class AgenticOpportunityBridgeWriteResult
{
    /**
     * @param  array<int,string>  $reasons
     * @param  array<string,mixed>  $operatorContext
     */
    public function __construct(
        public readonly string $status,
        public readonly AgenticOpportunityBridgeEligibilityResult $eligibility,
        public readonly ?Opportunity $opportunity = null,
        public readonly array $reasons = [],
        public readonly bool $dryRun = true,
        public readonly array $operatorContext = [],
    ) {}

    public function blocked(): bool
    {
        return in_array($this->status, ['blocked', 'missing_context', 'execution_blocked'], true);
    }

    public function duplicateRisk(): bool
    {
        return $this->status === 'duplicate_risk';
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    public function canonicalId(): ?string
    {
        return $this->opportunity?->id ? (string) $this->opportunity->id : null;
    }
}
