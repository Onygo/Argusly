<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\OpportunitySignal;

class AgenticOpportunitySignalPromotionResult
{
    /**
     * @param  array<int,string>  $reasons
     * @param  array<string,mixed>  $operatorContext
     */
    public function __construct(
        public readonly string $status,
        public readonly AgenticCanonicalMappingResult $mappingResult,
        public readonly ?OpportunitySignal $signal = null,
        public readonly array $reasons = [],
        public readonly bool $dryRun = true,
        public readonly array $operatorContext = [],
    ) {}

    public function signalEligible(): bool
    {
        return in_array($this->status, [
            'would_create',
            'would_update',
            'created',
            'updated',
            'already_current',
        ], true);
    }

    public function blocked(): bool
    {
        return in_array($this->status, ['missing_context', 'blocked'], true);
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    public function signalId(): ?string
    {
        return $this->signal?->id ? (string) $this->signal->id : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'would_create' => $this->status === 'would_create',
            'would_update' => $this->status === 'would_update',
            'created' => $this->status === 'created',
            'updated' => $this->status === 'updated',
            'already_current' => $this->status === 'already_current',
            'missing_context' => $this->status === 'missing_context',
            'blocked' => $this->status === 'blocked',
            'failed' => $this->status === 'failed',
            'status' => $this->status,
            'dedupe_key' => $this->mappingResult->dedupeKey,
            'reasons' => $this->reasons,
            'signal_id' => $this->signalId(),
        ];
    }
}
