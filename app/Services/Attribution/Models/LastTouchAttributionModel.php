<?php

namespace App\Services\Attribution\Models;

use App\Contracts\Attribution\AttributionModel;
use App\Models\AttributionConversion;
use App\Services\Attribution\Models\Concerns\AllocatesAttributionCredit;
use Illuminate\Support\Collection;

class LastTouchAttributionModel implements AttributionModel
{
    use AllocatesAttributionCredit;

    public function key(): string
    {
        return 'last_touch';
    }

    public function label(): string
    {
        return 'Last touch';
    }

    public function allocate(Collection $matches, AttributionConversion $conversion, array $settings = []): Collection
    {
        unset($conversion, $settings);

        $matches = $matches->sortBy(fn (array $match) => $match['touchpoint']->occurred_at)->values();
        $weights = array_fill(0, $matches->count(), 0.0);
        $weights[max(0, $matches->count() - 1)] = 1.0;

        return $this->allocateWeights($matches, $weights, $this->key());
    }
}
