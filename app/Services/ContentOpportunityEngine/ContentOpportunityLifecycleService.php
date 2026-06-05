<?php

namespace App\Services\ContentOpportunityEngine;

use App\Models\ContentOpportunity;
use Illuminate\Support\Carbon;

class ContentOpportunityLifecycleService
{
    /**
     * @return array<string,mixed>
     */
    public function freshnessPayload(?ContentOpportunity $existing = null): array
    {
        $now = now();
        $firstSeen = $existing?->first_seen_at ?: $now;
        $staleAt = $now->copy()->addDays(45);
        $expiresAt = $now->copy()->addDays(120);

        return [
            'freshness_status' => $this->freshnessStatus($existing, $now),
            'first_seen_at' => $firstSeen,
            'last_seen_at' => $now,
            'stale_at' => $staleAt,
            'expires_at' => $expiresAt,
        ];
    }

    public function freshnessStatus(?ContentOpportunity $existing, Carbon $now): string
    {
        if (! $existing) {
            return 'fresh';
        }

        if ($existing->expires_at && $existing->expires_at->isPast()) {
            return 'revived';
        }

        if ($existing->stale_at && $existing->stale_at->lessThanOrEqualTo($now)) {
            return 'refreshed';
        }

        return 'fresh';
    }
}
