<?php

namespace App\ValueObjects\Billing;

use Illuminate\Support\Carbon;

class CreditBucket
{
    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly int $remaining,
        public readonly ?Carbon $expiresAt,
        public readonly ?Carbon $createdAt = null,
        public readonly ?string $workspaceCreditTransactionId = null,
        public readonly ?string $referenceType = null,
        public readonly ?string $referenceId = null,
    ) {
    }

    public function expiresSoonerThan(self $other): bool
    {
        return $this->sortableExpiry() < $other->sortableExpiry();
    }

    public function sourcePriority(): int
    {
        return match ($this->source) {
            'included_plan' => 0,
            'addon_pack' => 1,
            default => 9,
        };
    }

    private function sortableExpiry(): int
    {
        return $this->expiresAt?->getTimestamp() ?? PHP_INT_MAX;
    }
}
