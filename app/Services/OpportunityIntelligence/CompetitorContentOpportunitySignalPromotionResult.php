<?php

namespace App\Services\OpportunityIntelligence;

use App\Models\OpportunitySignal;

class CompetitorContentOpportunitySignalPromotionResult
{
    /**
     * @param  array<int,string>  $reasons
     */
    public function __construct(
        public readonly string $status,
        public readonly string $dedupeHash,
        public readonly ?OpportunitySignal $signal = null,
        public readonly array $reasons = [],
    ) {}

    public function skipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function duplicate(): bool
    {
        return $this->status === 'duplicate';
    }

    public function promoted(): bool
    {
        return in_array($this->status, ['created', 'updated', 'would_create'], true);
    }
}
