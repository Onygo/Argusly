<?php

namespace App\Services\Attribution\Models;

use App\Contracts\Attribution\AttributionModel;
use App\Models\AttributionConversion;
use App\Services\Attribution\Models\Concerns\AllocatesAttributionCredit;
use Illuminate\Support\Collection;

class CampaignInfluencedAttributionModel implements AttributionModel
{
    use AllocatesAttributionCredit;

    public function key(): string
    {
        return 'campaign_influenced';
    }

    public function label(): string
    {
        return 'Campaign influenced';
    }

    public function allocate(Collection $matches, AttributionConversion $conversion, array $settings = []): Collection
    {
        unset($conversion, $settings);

        $matches = $matches->sortBy(fn (array $match) => $match['touchpoint']->occurred_at)->values();
        $campaignMatches = $matches->filter(fn (array $match): bool => filled($match['touchpoint']->campaign_id))->values();

        if ($campaignMatches->isEmpty()) {
            $campaignMatches = $matches;
        }

        return $this->allocateWeights($campaignMatches, array_fill(0, $campaignMatches->count(), 1.0), $this->key());
    }
}
