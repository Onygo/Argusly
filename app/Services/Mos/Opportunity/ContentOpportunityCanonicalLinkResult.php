<?php

namespace App\Services\Mos\Opportunity;

use App\Models\Opportunity;

class ContentOpportunityCanonicalLinkResult
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        public readonly string $status,
        public readonly ?Opportunity $opportunity = null,
        public readonly ?CanonicalOpportunityCandidate $candidate = null,
        public readonly array $reasons = [],
        public readonly bool $dryRun = true,
    ) {}

    public function skipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function duplicate(): bool
    {
        return $this->status === 'duplicate';
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }
}
